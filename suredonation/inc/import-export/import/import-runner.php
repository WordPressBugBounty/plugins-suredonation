<?php
/**
 * Own-data import runner: transient session store + batch orchestrator.
 *
 * Self-contained sibling of the GiveWP migration importer — it mirrors the
 * proven batched-session pattern (BATCH_SIZE chunks, `process_batch()` returns
 * the number of source rows handled, `< BATCH_SIZE` ends the phase) but with
 * its own transient prefix, results schema, and phase-mapper filter. It does
 * NOT touch the GiveWP importer.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Import_Export\Import;

use SureDonation\Inc\Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import session store + orchestrator.
 *
 * @since 1.3.0
 */
class Import_Runner {

	/**
	 * Transient key prefix for import sessions.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	const TRANSIENT_PREFIX = 'suredonation_import_';

	/**
	 * Session lifetime.
	 *
	 * @var int
	 * @since 1.3.0
	 */
	const TTL = HOUR_IN_SECONDS;

	/**
	 * Rows processed per batch.
	 *
	 * @var int
	 * @since 1.3.0
	 */
	const BATCH_SIZE = 25;

	/**
	 * Create a new import session.
	 *
	 * @param string               $entity     'donations' or 'donors'.
	 * @param string               $token      Stored CSV token.
	 * @param array<int, string>   $mapping    Header index => field.
	 * @param array<string, mixed> $options    Import options (dry_run, mode, ...).
	 * @param int                  $total_rows Total data rows.
	 * @return array<string, mixed> The created progress payload.
	 * @since 1.3.0
	 */
	public static function create( $entity, $token, $mapping, $options, $total_rows ) {
		$import_id = wp_generate_uuid4();

		$progress = [
			'import_id'     => $import_id,
			'started_at'    => current_time( 'mysql', true ),
			'started_by'    => get_current_user_id(),
			'entity'        => $entity,
			'token'         => $token,
			'mapping'       => $mapping,
			'options'       => $options,
			'phases'        => [ $entity ],
			'current_phase' => 0,
			'offset'        => 0,
			'byte_offset'   => 0,
			'total_rows'    => (int) $total_rows,
			'donor_map'     => [],
			'campaign_map'  => [],
			'id_map'        => [],
			'created'       => [
				'donations' => [],
				'donors'    => [],
			],
			'status'        => 'running',
			'results'       => [ $entity => self::empty_result() ],
		];

		self::put( $import_id, $progress );

		/**
		 * Fires when an import session is created (before the first batch).
		 * Pro uses this to open an import-history record.
		 *
		 * @param string               $import_id Session id.
		 * @param array<string, mixed> $progress  Session payload.
		 */
		do_action( 'suredonation_import_session_created', $import_id, $progress );

		return $progress;
	}

	/**
	 * Empty per-phase results counters.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.0
	 */
	private static function empty_result() {
		return [
			'imported'       => 0,
			'skipped'        => 0,
			'errors'         => 0,
			'donors_created' => 0,
			'donors_matched' => 0,
			'error_log'      => [],
		];
	}

	/**
	 * Record a created row id in the session (for rollback), keyed by type.
	 *
	 * @param array<string, mixed> $progress Session (by reference).
	 * @param string               $type     'donations' or 'donors'.
	 * @param int                  $id       Created row id.
	 * @return void
	 * @since 1.3.0
	 */
	public static function track_created( &$progress, $type, $id ) {
		if ( (int) $id <= 0 ) {
			return;
		}
		$created = is_array( $progress['created'] ?? null ) ? $progress['created'] : [];
		$list    = is_array( $created[ $type ] ?? null ) ? $created[ $type ] : [];
		$list[]  = (int) $id;

		$created[ $type ]    = $list;
		$progress['created'] = $created;
	}

	/**
	 * Record an old-id => new-id mapping in the session (for relinking recurring
	 * renewals to their new parent donation on completion).
	 *
	 * @param array<string, mixed> $progress Session (by reference).
	 * @param int                  $old_id   Original (source) id.
	 * @param int                  $new_id   New id.
	 * @return void
	 * @since 1.3.0
	 */
	public static function track_id_map( &$progress, $old_id, $new_id ) {
		if ( (int) $old_id <= 0 ) {
			return;
		}
		$map                  = is_array( $progress['id_map'] ?? null ) ? $progress['id_map'] : [];
		$map[ (int) $old_id ] = (int) $new_id;
		$progress['id_map']   = $map;
	}

	/**
	 * Read a session.
	 *
	 * @param string $import_id Session id.
	 * @return array<string, mixed>|false Progress payload, or false.
	 * @since 1.3.0
	 */
	public static function get( $import_id ) {
		$id = self::sanitize_id( $import_id );
		if ( '' === $id ) {
			return false;
		}
		$progress = get_transient( self::TRANSIENT_PREFIX . $id );
		return is_array( $progress ) ? $progress : false;
	}

	/**
	 * Write a session.
	 *
	 * @param string               $import_id Session id.
	 * @param array<string, mixed> $progress  Progress payload.
	 * @return bool
	 * @since 1.3.0
	 */
	public static function put( $import_id, $progress ) {
		$id = self::sanitize_id( $import_id );
		if ( '' === $id ) {
			return false;
		}
		return set_transient( self::TRANSIENT_PREFIX . $id, $progress, self::TTL );
	}

	/**
	 * Delete a session.
	 *
	 * @param string $import_id Session id.
	 * @return bool
	 * @since 1.3.0
	 */
	public static function delete( $import_id ) {
		$id = self::sanitize_id( $import_id );
		if ( '' === $id ) {
			return false;
		}
		return delete_transient( self::TRANSIENT_PREFIX . $id );
	}

	/**
	 * Validate an import id.
	 *
	 * @param string $import_id Candidate id.
	 * @return string Valid id, or '' if invalid.
	 * @since 1.3.0
	 */
	private static function sanitize_id( $import_id ) {
		return is_string( $import_id ) && preg_match( '/^[a-f0-9\-]{8,64}$/i', $import_id ) ? $import_id : '';
	}

	/**
	 * Process the next batch for a session.
	 *
	 * @param string $import_id Session id.
	 * @return array<string, mixed>|false Updated progress, or false if the session isn't runnable.
	 * @since 1.3.0
	 */
	public static function run_batch( $import_id ) {
		$progress = self::get( $import_id );
		if ( false === $progress || 'running' !== ( $progress['status'] ?? '' ) ) {
			return false;
		}

		$phases = is_array( $progress['phases'] ?? null ) ? $progress['phases'] : [];
		$index  = Helper::get_integer_value( $progress['current_phase'] ?? 0 );

		if ( $index >= count( $phases ) ) {
			return self::finish( $import_id, $progress );
		}

		$phase  = Helper::get_string_value( $phases[ $index ] ?? '' );
		$offset = Helper::get_integer_value( $progress['offset'] ?? 0 );
		$mapper = self::get_phase_mapper( $phase );

		try {
			if ( null === $mapper || ! is_callable( [ $mapper, 'process_batch' ] ) ) {
				$progress['current_phase'] = $index + 1;
				$progress['offset']        = 0;
				$progress['byte_offset']   = 0;
			} else {
				// The mapper advances 'byte_offset' by reference as it reads.
				$processed = (int) $mapper->process_batch( $progress, $offset );
				if ( $processed < self::BATCH_SIZE ) {
					$progress['current_phase'] = $index + 1;
					$progress['offset']        = 0;
					$progress['byte_offset']   = 0;
				} else {
					$progress['offset'] = $offset + $processed;
				}
			}
		} catch ( \Throwable $t ) {
			// The raw message can carry filesystem paths and the table prefix, and
			// it is both returned to the client and persisted in the session, so
			// log it and hand back a generic failure instead.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic for a failed import.
				error_log( 'SureDonation import ' . $import_id . ' failed: ' . $t->getMessage() );
			}

			$progress['status']       = 'failed';
			$progress['completed_at'] = current_time( 'mysql', true );
			$progress['error']        = __( 'The import stopped because of an unexpected error. Please try again.', 'suredonation' );
			self::cleanup_file( $progress );
			self::put( $import_id, $progress );
			return $progress;
		}

		if ( Helper::get_integer_value( $progress['current_phase'] ?? 0 ) >= count( $phases ) ) {
			return self::finish( $import_id, $progress );
		}

		self::put( $import_id, $progress );

		/**
		 * Fires after each import batch. Pro uses this to update the
		 * import-history record's progress.
		 *
		 * @param string               $import_id Session id.
		 * @param array<string, mixed> $progress  Session payload.
		 */
		do_action( 'suredonation_import_batch_complete', $import_id, $progress );

		return $progress;
	}

	/**
	 * Mark a session complete and persist.
	 *
	 * @param string               $import_id Session id.
	 * @param array<string, mixed> $progress  Progress payload.
	 * @return array<string, mixed>
	 * @since 1.3.0
	 */
	private static function finish( $import_id, $progress ) {
		$progress['status']       = 'complete';
		$progress['completed_at'] = current_time( 'mysql', true );
		self::cleanup_file( $progress );
		self::put( $import_id, $progress );

		// Action documented in this class's run_batch().
		do_action( 'suredonation_import_batch_complete', $import_id, $progress );

		/**
		 * Fires once when an import session completes. Pro uses this to finalize
		 * the history record and remap recurring renewal → parent links.
		 *
		 * @param string               $import_id Session id.
		 * @param array<string, mixed> $progress  Final session payload.
		 */
		do_action( 'suredonation_import_complete', $import_id, $progress );

		return $progress;
	}

	/**
	 * Delete the session's uploaded CSV once it is no longer needed.
	 *
	 * The file holds donor PII (emails, names, phones); it is removed as soon
	 * as the run completes or fails so it does not linger in uploads.
	 *
	 * @param array<string, mixed> $progress Progress payload.
	 * @return void
	 * @since 1.3.0
	 */
	private static function cleanup_file( $progress ) {
		$token = Helper::get_string_value( $progress['token'] ?? '' );
		if ( '' !== $token ) {
			Csv_File::delete( $token );
		}
	}

	/**
	 * Resolve a phase to its mapper singleton.
	 *
	 * @param string $phase Phase name.
	 * @return object|null Mapper instance, or null.
	 * @since 1.3.0
	 */
	private static function get_phase_mapper( $phase ) {
		$mappers = [
			'donations' => Donations_Import_Mapper::class,
			'donors'    => Donors_Import_Mapper::class,
		];

		/**
		 * Filter the import phase => mapper-class map. Pro registers its own
		 * phases (e.g. subscriptions) here.
		 *
		 * @param array<string, string> $mappers Phase => fully-qualified class name.
		 */
		$mappers = apply_filters( 'suredonation_import_phase_mappers', $mappers );

		if ( ! isset( $mappers[ $phase ] ) ) {
			return null;
		}

		$class = (string) $mappers[ $phase ];
		if ( ! class_exists( $class ) || ! is_callable( [ $class, 'get_instance' ] ) ) {
			return null;
		}

		return $class::get_instance();
	}
}
