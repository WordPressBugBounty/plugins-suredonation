<?php
/**
 * GiveWP migration orchestrator.
 *
 * Walks a session through its phases by delegating each batch to the
 * appropriate phase mapper. Mappers are registered via the filter
 * `suredonation_import_givewp_phase_mappers` so the Pro plugin can plug
 * in subscription / standalone-donor mappers without Free knowing
 * about them.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Importer class.
 *
 * @since 1.0.0
 */
class Importer {
	use Get_Instance;

	/**
	 * Records processed per AJAX batch. Matches Charitable's 25.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Get pre-flight counts and per-gateway breakdown.
	 *
	 * @return array{has_data:bool, give_version:?string, counts:array<string,int>, gateway_breakdown:array<int,array<string,mixed>>}
	 * @since  1.0.0
	 */
	public function get_counts() {
		$source = Source::get_instance();

		$gateway_breakdown = [];
		foreach ( $source->get_gateway_breakdown() as $row ) {
			$slug                = isset( $row['slug'] ) ? (string) $row['slug'] : '';
			$count               = isset( $row['count'] ) ? (int) $row['count'] : 0;
			$sd_slug             = Status_Map::map_gateway( $slug );
			$live                = Status_Map::is_gateway_live( $sd_slug );
			$sub_live            = Status_Map::is_subscription_handler_live( $sd_slug );
			$gateway_breakdown[] = [
				'slug'                      => $slug,
				'sd_slug'                   => $sd_slug,
				'count'                     => $count,
				'live'                      => $live,
				'subscription_handler_live' => $sub_live,
			];
		}

		return [
			'has_data'          => $source->has_givewp_data(),
			'give_version'      => $source->get_givewp_version(),
			'counts'            => [
				'campaigns'         => $source->get_form_count(),
				'donations'         => $source->get_payment_count(),
				'subscriptions'     => $source->get_subscription_count(),
				'standalone_donors' => $source->get_standalone_donor_count(),
			],
			'gateway_breakdown' => $gateway_breakdown,
		];
	}

	/**
	 * Run the next batch for a given session.
	 *
	 * Loads progress, dispatches the current phase to its mapper, persists
	 * progress, and advances the phase index when the mapper signals the
	 * phase is exhausted (processed < batch size).
	 *
	 * @param  string $import_id Session UUID.
	 * @return array|false Updated progress payload, or false if the session is missing or no longer running.
	 * @since  1.0.0
	 */
	public function run_batch( $import_id ) {
		$session  = Session::get_instance();
		$progress = $session->get( $import_id );

		if ( ! is_array( $progress ) || empty( $progress['status'] ) || 'running' !== $progress['status'] ) {
			return false;
		}

		$current_index = isset( $progress['current_phase'] ) ? (int) $progress['current_phase'] : 0;
		$phases        = isset( $progress['phases'] ) && is_array( $progress['phases'] ) ? $progress['phases'] : [];

		if ( $current_index >= count( $phases ) ) {
			$progress['status'] = 'complete';
			$session->put( $import_id, $progress );
			$this->fire_batch_complete( $import_id, $progress );
			return $progress;
		}

		$phase  = (string) $phases[ $current_index ];
		$offset = isset( $progress['offset'] ) ? (int) $progress['offset'] : 0;

		$mapper = $this->get_phase_mapper( $phase );

		try {
			if ( ! $mapper || ! is_callable( [ $mapper, 'process_batch' ] ) ) {
				// Unknown phase — skip it and advance so the session never stalls.
				$progress['current_phase'] = $current_index + 1;
				$progress['offset']        = 0;
			} else {
				$processed = (int) $mapper->process_batch( $progress, $offset );

				if ( $processed < self::BATCH_SIZE ) {
					$progress['current_phase'] = $current_index + 1;
					$progress['offset']        = 0;
				} else {
					$progress['offset'] = $offset + $processed;
				}
			}
		} catch ( \Throwable $t ) {
			// Whole-batch failure (mapper threw something the per-record
			// try/catch didn't catch, or a fatal in the dispatcher).
			// Mark the session as failed so the import history doesn't
			// sit at 'running' forever, persist the cause for diagnosis,
			// and short-circuit further phase advancement.
			$progress['status']       = 'failed';
			$progress['completed_at'] = current_time( 'mysql', true );
			$progress['error']        = $t->getMessage();
			$session->put( $import_id, $progress );
			$this->fire_batch_complete( $import_id, $progress );
			return $progress;
		}

		if ( $progress['current_phase'] >= count( $phases ) ) {
			$progress['status']       = 'complete';
			$progress['completed_at'] = current_time( 'mysql', true );
		}

		$session->put( $import_id, $progress );

		$this->fire_batch_complete( $import_id, $progress );

		return $progress;
	}

	/**
	 * Resolve the mapper instance responsible for a given phase.
	 *
	 * @param  string $phase Phase name.
	 * @return object|null Mapper with a process_batch( &$progress, $offset ) method.
	 * @since  1.0.0
	 */
	private function get_phase_mapper( $phase ) {
		$mappers = [
			'campaigns' => Campaign_Mapper::class,
			'donations' => Donation_Mapper::class,
		];

		/**
		 * Filter the map of phase => mapper class.
		 *
		 * Pro plugin registers `subscriptions` and `standalone_donors` mappers
		 * here. Each class must expose a public process_batch( &$progress, $offset )
		 * method that returns the number of source records processed in the batch.
		 *
		 * @param array<string,string> $mappers Phase => mapper class.
		 * @since 1.0.0
		 */
		$mappers = apply_filters( 'suredonation_import_givewp_phase_mappers', $mappers );

		if ( ! is_array( $mappers ) || ! isset( $mappers[ $phase ] ) ) {
			return null;
		}

		$class = (string) $mappers[ $phase ];
		if ( ! class_exists( $class ) || ! is_callable( [ $class, 'get_instance' ] ) ) {
			return null;
		}

		return call_user_func( [ $class, 'get_instance' ] );
	}

	/**
	 * Fire the batch-complete action so observers (e.g. Pro history recorder) can persist snapshots.
	 *
	 * @param  string $import_id Session UUID.
	 * @param  array  $progress  Progress payload after this batch.
	 * @return void
	 * @since  1.0.0
	 */
	private function fire_batch_complete( $import_id, $progress ) {
		/**
		 * Fires after each migration batch is processed (running or final).
		 *
		 * @param string $import_id Session UUID.
		 * @param array  $progress  Updated progress payload.
		 * @since 1.0.0
		 */
		do_action( 'suredonation_import_givewp_batch_complete', $import_id, $progress );
	}
}
