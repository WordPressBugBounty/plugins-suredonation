<?php
/**
 * Migration session store.
 *
 * Wraps a transient with typed accessors so the rest of the importer never
 * touches transient keys directly. Each session is keyed by a UUID that the
 * REST layer hands back to the client and expects back on subsequent /batch
 * calls.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Session class.
 *
 * @since 1.0.0
 */
class Session {
	use Get_Instance;

	/**
	 * Transient key prefix.
	 */
	const TRANSIENT_PREFIX = 'suredonation_givewp_import_';

	/**
	 * Session TTL.
	 *
	 * Long enough to survive batch gaps and brief page reloads, short enough
	 * that abandoned sessions don't pile up indefinitely.
	 */
	const TTL = HOUR_IN_SECONDS;

	/**
	 * Create a new import session and return its progress payload.
	 *
	 * Options shape:
	 *   - campaign_ids: int[] — GiveWP form IDs selected in the preview UI.
	 *   - include_standalone_donors: bool — whether to also import donors
	 *     that have no associated payments (Pro phase, off by default).
	 *
	 * Campaigns + donations phases are always registered (the user picked
	 * at least one campaign on the preview step). Subscriptions is injected
	 * by the Pro plugin via the phases filter when the give_subscriptions
	 * table exists. Standalone donors is opt-in via the option above.
	 *
	 * @param  array $options Session options.
	 * @return array Newly created progress payload (including generated import_id) or empty array if no phases are selected.
	 * @since  1.0.0
	 */
	public function create( $options ) {
		$options = is_array( $options ) ? $options : [];

		$defaults = [
			'campaign_ids'              => [],
			'include_standalone_donors' => false,
		];

		$options = wp_parse_args( $options, $defaults );

		$options['campaign_ids']              = is_array( $options['campaign_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $options['campaign_ids'] ) ) ) )
			: [];
		$options['include_standalone_donors'] = (bool) $options['include_standalone_donors'];

		if ( empty( $options['campaign_ids'] ) ) {
			return [];
		}

		// Campaigns + donations always run; the user opted in to those by
		// picking campaigns on the preview step. Pro injects subscriptions
		// and (conditionally) standalone_donors via the filter below.
		$phases = [ 'campaigns', 'donations' ];

		/**
		 * Filter the phases that a GiveWP migration session will run.
		 *
		 * Pro reads $options['include_standalone_donors'] when deciding
		 * whether to inject the standalone_donors phase. The subscriptions
		 * phase is injected unconditionally when the give_subscriptions
		 * table exists — it's still campaign-scoped because the mapper
		 * filters by $progress['options']['campaign_ids'].
		 *
		 * @param array $phases  Default phases (campaigns + donations).
		 * @param array $options Resolved options for this session.
		 * @since 1.0.0
		 */
		$phases = apply_filters( 'suredonation_import_givewp_phases', $phases, $options );
		$phases = is_array( $phases ) ? array_values( array_unique( array_filter( array_map( 'strval', $phases ) ) ) ) : [];

		if ( empty( $phases ) ) {
			return [];
		}

		$progress = [
			'import_id'     => wp_generate_uuid4(),
			'started_at'    => current_time( 'mysql', true ),
			'started_by'    => get_current_user_id(),
			'options'       => $options,
			'phases'        => $phases,
			'current_phase' => 0,
			'offset'        => 0,
			'campaign_map'  => [],
			'donor_map'     => [],
			'status'        => 'running',
			'results'       => $this->empty_results( $phases ),
		];

		$this->put( $progress['import_id'], $progress );

		/**
		 * Fires after a new GiveWP migration session has been created.
		 *
		 * @param string $import_id Session UUID.
		 * @param array  $progress  Full progress payload.
		 * @since 1.0.0
		 */
		do_action( 'suredonation_import_givewp_session_created', $progress['import_id'], $progress );

		return $progress;
	}

	/**
	 * Load progress for an existing import session.
	 *
	 * @param  string $import_id Session UUID.
	 * @return array|false Progress payload, or false if the transient is gone.
	 * @since  1.0.0
	 */
	public function get( $import_id ) {
		$import_id = $this->sanitize_id( $import_id );
		if ( '' === $import_id ) {
			return false;
		}

		$progress = get_transient( self::TRANSIENT_PREFIX . $import_id );
		return is_array( $progress ) ? $progress : false;
	}

	/**
	 * Persist updated progress to its transient.
	 *
	 * @param  string $import_id Session UUID.
	 * @param  array  $progress  Progress payload.
	 * @return bool
	 * @since  1.0.0
	 */
	public function put( $import_id, $progress ) {
		$import_id = $this->sanitize_id( $import_id );
		if ( '' === $import_id || ! is_array( $progress ) ) {
			return false;
		}

		return (bool) set_transient( self::TRANSIENT_PREFIX . $import_id, $progress, self::TTL );
	}

	/**
	 * Delete a session.
	 *
	 * @param  string $import_id Session UUID.
	 * @return bool
	 * @since  1.0.0
	 */
	public function delete( $import_id ) {
		$import_id = $this->sanitize_id( $import_id );
		if ( '' === $import_id ) {
			return false;
		}

		return (bool) delete_transient( self::TRANSIENT_PREFIX . $import_id );
	}

	/**
	 * Build a zeroed results structure for the given phases.
	 *
	 * @param  array $phases Phase names.
	 * @return array
	 * @since  1.0.0
	 */
	private function empty_results( $phases ) {
		$schema = [
			'campaigns'         => [
				'imported'  => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'error_log' => [],
			],
			'donations'         => [
				'imported'          => 0,
				'skipped'           => 0,
				'errors'            => 0,
				'error_log'         => [],
				'gateway_breakdown' => [],
			],
			'subscriptions'     => [
				'imported_live'       => 0,
				'imported_historical' => 0,
				'skipped'             => 0,
				'errors'              => 0,
				'error_log'           => [],
			],
			'standalone_donors' => [
				'imported'  => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'error_log' => [],
			],
		];

		$out = [];
		foreach ( $phases as $phase ) {
			if ( isset( $schema[ $phase ] ) ) {
				$out[ $phase ] = $schema[ $phase ];
			}
		}
		return $out;
	}

	/**
	 * Sanitize an incoming import-id string.
	 *
	 * @param  mixed $import_id Raw input.
	 * @return string Empty string if not a plausible UUID.
	 * @since  1.0.0
	 */
	private function sanitize_id( $import_id ) {
		if ( ! is_string( $import_id ) ) {
			return '';
		}
		$import_id = sanitize_text_field( $import_id );

		if ( ! preg_match( '/^[a-f0-9-]{8,64}$/i', $import_id ) ) {
			return '';
		}

		return $import_id;
	}
}
