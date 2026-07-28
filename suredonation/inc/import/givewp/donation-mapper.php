<?php
/**
 * Donation mapper for the GiveWP migration tool.
 *
 * Imports GiveWP donations (give_payment posts) into the
 * suredonation_donations table. Skips rows already imported (matched by
 * import_source_id + import_source pair), resolves the donor via Donor_Mapper, the campaign
 * via campaign_map populated by Campaign_Mapper, and translates gateway
 * slug + status via Status_Map.
 *
 * Email suppression is engaged for the duration of the batch via
 * Email_Suppressor so receipts/admin notifications are not blasted out
 * to donors when their historical records are inserted.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Donation_Mapper class.
 *
 * @since 1.0.0
 */
class Donation_Mapper {
	use Get_Instance;

	/**
	 * GiveWP payment meta keys that we map to dedicated columns.
	 * Everything else gets preserved into donation_data.givewp.meta as
	 * a raw key/value blob for later inspection.
	 */
	const STANDARD_META_KEYS = [
		'_give_payment_total',
		'_give_payment_form_id',
		'_give_payment_form_title',
		'_give_payment_donor_id',
		'_give_payment_donor_email',
		'_give_payment_user_id',
		'_give_payment_currency',
		'_give_payment_gateway',
		'_give_payment_mode',
		'_give_payment_transaction_id',
		'_give_donor_billing_first_name',
		'_give_donor_billing_last_name',
		'_give_donor_billing_address1',
		'_give_donor_billing_address2',
		'_give_donor_billing_city',
		'_give_donor_billing_state',
		'_give_donor_billing_zip',
		'_give_donor_billing_country',
		'_give_payment_customer_id',
	];

	/**
	 * Process a batch of GiveWP payments.
	 *
	 * @param  array $progress Session progress (passed by reference).
	 * @param  int   $offset   Current offset within this phase.
	 * @return int Number of source rows processed in this batch.
	 * @since  1.0.0
	 */
	public function process_batch( &$progress, $offset ) {
		$source   = Source::get_instance();
		$form_ids = isset( $progress['options']['campaign_ids'] ) && is_array( $progress['options']['campaign_ids'] )
			? $progress['options']['campaign_ids']
			: [];
		$payments = $source->get_payments_batch( (int) $offset, Importer::BATCH_SIZE, $form_ids );

		if ( empty( $payments ) ) {
			return 0;
		}

		$suppressor = Email_Suppressor::get_instance();
		$suppressor->activate();

		try {
			foreach ( $payments as $payment ) {
				$payment_id_for_log = isset( $payment->ID ) ? (int) $payment->ID : 0;
				try {
					$this->process_one( $payment, $progress );
				} catch ( \Throwable $t ) {
					// One bad record can't kill the batch — log and move
					// on so the rest of the migration runs to completion.
					$this->log_error(
						$progress,
						$payment_id_for_log,
						sprintf(
							/* translators: %s: exception message */
							__( 'Unhandled exception while processing donation: %s', 'suredonation' ),
							$t->getMessage()
						)
					);
				}
			}
		} finally {
			$suppressor->deactivate();
		}

		return count( $payments );
	}

	/**
	 * Map a single GiveWP payment into a SureDonation donations row.
	 *
	 * @param  object $payment  GiveWP wp_posts row.
	 * @param  array  $progress Session progress (passed by reference).
	 * @return void
	 * @since  1.0.0
	 */
	private function process_one( $payment, &$progress ) {
		$give_payment_id = isset( $payment->ID ) ? (int) $payment->ID : 0;
		if ( $give_payment_id <= 0 ) {
			++$progress['results']['donations']['errors'];
			return;
		}

		// Duplicate detection via the import_source_id + import_source pair.
		if ( $this->already_imported( $give_payment_id ) ) {
			++$progress['results']['donations']['skipped'];
			return;
		}

		$source = Source::get_instance();
		$meta   = $source->get_payment_meta( $give_payment_id );
		$email  = isset( $meta['_give_payment_donor_email'] ) ? sanitize_email( $meta['_give_payment_donor_email'] ) : '';
		$amount = $source->extract_donation_amount( $meta );

		if ( '' === $email ) {
			$this->log_error(
				$progress,
				$give_payment_id,
				sprintf(
					/* translators: %s: raw value from GiveWP payment meta */
					__( 'Missing donor email (got "%s").', 'suredonation' ),
					isset( $meta['_give_payment_donor_email'] ) ? (string) $meta['_give_payment_donor_email'] : ''
				)
			);
			return;
		}

		if ( $amount <= 0 ) {
			$this->log_error(
				$progress,
				$give_payment_id,
				sprintf(
					/* translators: %s: raw value from GiveWP payment meta */
					__( 'Donation amount is zero or missing (raw _give_payment_total "%s").', 'suredonation' ),
					isset( $meta['_give_payment_total'] ) ? (string) $meta['_give_payment_total'] : ''
				)
			);
			return;
		}

		// Gateway/status translation.
		$give_gateway   = isset( $meta['_give_payment_gateway'] ) ? (string) $meta['_give_payment_gateway'] : '';
		$gateway        = Status_Map::map_gateway( $give_gateway );
		$payment_status = Status_Map::map_donation_status( isset( $payment->post_status ) ? (string) $payment->post_status : '' );

		// Track the per-gateway breakdown for results.
		$progress['results']['donations']['gateway_breakdown'][ $gateway ] = isset( $progress['results']['donations']['gateway_breakdown'][ $gateway ] )
			? (int) $progress['results']['donations']['gateway_breakdown'][ $gateway ] + 1
			: 1;

		$donor_id = Donor_Mapper::get_instance()->get_or_create_for_payment( $meta, $progress );
		if ( $donor_id <= 0 ) {
			$this->log_error( $progress, $give_payment_id, __( 'Failed to resolve donor.', 'suredonation' ) );
			return;
		}

		$give_form_id = isset( $meta['_give_payment_form_id'] ) ? absint( $meta['_give_payment_form_id'] ) : 0;
		$campaign_id  = 0;
		if ( $give_form_id > 0 && isset( $progress['campaign_map'][ $give_form_id ] ) ) {
			$campaign_id = (int) $progress['campaign_map'][ $give_form_id ];
		}

		$currency       = isset( $meta['_give_payment_currency'] ) ? sanitize_text_field( $meta['_give_payment_currency'] ) : 'USD';
		$payment_mode   = isset( $meta['_give_payment_mode'] ) ? sanitize_text_field( $meta['_give_payment_mode'] ) : 'live';
		$transaction_id = isset( $meta['_give_payment_transaction_id'] ) ? sanitize_text_field( $meta['_give_payment_transaction_id'] ) : '';
		$customer_id    = isset( $meta['_give_payment_customer_id'] ) ? sanitize_text_field( $meta['_give_payment_customer_id'] ) : '';

		$first_name = isset( $meta['_give_donor_billing_first_name'] ) ? sanitize_text_field( $meta['_give_donor_billing_first_name'] ) : '';
		$last_name  = isset( $meta['_give_donor_billing_last_name'] ) ? sanitize_text_field( $meta['_give_donor_billing_last_name'] ) : '';
		$donor_name = trim( $first_name . ' ' . $last_name );

		$donation_data = [
			'givewp' => [
				'source_id'    => $give_payment_id,
				'import_id'    => isset( $progress['import_id'] ) ? (string) $progress['import_id'] : '',
				'form_id'      => $give_form_id,
				'form_title'   => isset( $meta['_give_payment_form_title'] ) ? sanitize_text_field( $meta['_give_payment_form_title'] ) : '',
				'gateway_raw'  => $give_gateway,
				'gateway_live' => Status_Map::is_gateway_live( $gateway ),
				'meta'         => $this->extract_extra_meta( $meta ),
			],
		];

		$data = [
			'campaign_id'      => $campaign_id,
			'donor_id'         => $donor_id,
			'form_id'          => 0,
			'amount'           => (string) $amount,
			'currency'         => '' !== $currency ? $currency : 'USD',
			'transaction_id'   => $transaction_id,
			'customer_id'      => $customer_id,
			'gateway'          => $gateway,
			'payment_status'   => $payment_status,
			'payment_mode'     => 'test' === $payment_mode ? 'test' : 'live',
			'donor_name'       => $donor_name,
			'donor_email'      => $email,
			'donation_type'    => 'one-time',
			'donation_data'    => $donation_data,
			'created_at'       => isset( $payment->post_date_gmt ) ? (string) $payment->post_date_gmt : current_time( 'mysql', true ),
			'import_source_id' => $give_payment_id,
			'import_source'    => 'givewp',
		];

		$donation_id = Donations::add( $data );
		if ( ! $donation_id ) {
			global $wpdb;
			$db_error = $wpdb->last_error ? (string) $wpdb->last_error : __( 'unknown DB error', 'suredonation' );
			$this->log_error(
				$progress,
				$give_payment_id,
				sprintf(
					/* translators: 1: gateway slug, 2: amount, 3: DB error message */
					__( 'Failed to insert donation row (gateway=%1$s, amount=%2$s): %3$s', 'suredonation' ),
					$gateway,
					(string) $amount,
					$db_error
				)
			);
			return;
		}

		// Update donor aggregates (count, total, largest, first/last date)
		// using the actual payment date — Donors::record_donation() uses
		// current_time() for last_donation_date, which would falsely stamp
		// every imported donor with the import timestamp.
		$this->update_donor_aggregates( $donor_id, $amount, (string) $data['created_at'], $payment_status );

		++$progress['results']['donations']['imported'];
	}

	/**
	 * Update an imported donor's aggregate columns after a donation insert.
	 *
	 * Mirrors Donors::record_donation() but takes the actual donation date
	 * rather than stamping current_time(), so donors imported with
	 * historical payments get the correct first/last contribution
	 * timestamps. Numeric aggregates (donation_count, total_donated,
	 * largest_donation) only accumulate for revenue-bearing statuses to
	 * match how SureDonation reports them in the dashboard.
	 *
	 * @param  int    $donor_id        SureDonation donor row ID.
	 * @param  float  $amount          Donation amount.
	 * @param  string $donation_date   ISO/MySQL datetime of the donation (GMT).
	 * @param  string $payment_status  SureDonation payment status enum value.
	 * @return void
	 * @since  1.0.0
	 */
	private function update_donor_aggregates( $donor_id, $amount, $donation_date, $payment_status ) {
		$donor = Donors::get( (int) $donor_id );
		if ( ! is_array( $donor ) ) {
			return;
		}

		$updates = [];

		// first/last date track ALL imported payments regardless of
		// status — cancelled or failed payments are still real
		// historical engagement worth surfacing in the donor profile.
		$current_first = ! empty( $donor['first_donation_date'] ) ? (string) $donor['first_donation_date'] : '';
		$current_last  = ! empty( $donor['last_donation_date'] ) ? (string) $donor['last_donation_date'] : '';

		if ( '' !== $donation_date ) {
			if ( '' === $current_first || strtotime( $donation_date ) < strtotime( $current_first ) ) {
				$updates['first_donation_date'] = $donation_date;
			}
			if ( '' === $current_last || strtotime( $donation_date ) > strtotime( $current_last ) ) {
				$updates['last_donation_date'] = $donation_date;
			}
		}

		// Revenue-bearing counters: only completed / partially_refunded
		// contribute (consistent with Donations::get_dashboard_stats and
		// the campaign stats query).
		if ( in_array( $payment_status, [ 'completed', 'partially_refunded' ], true ) ) {
			$current_total   = isset( $donor['total_donated'] ) && is_numeric( $donor['total_donated'] ) ? (float) $donor['total_donated'] : 0.0;
			$current_count   = isset( $donor['donation_count'] ) && is_numeric( $donor['donation_count'] ) ? (int) $donor['donation_count'] : 0;
			$current_largest = isset( $donor['largest_donation'] ) && is_numeric( $donor['largest_donation'] ) ? (float) $donor['largest_donation'] : 0.0;

			$updates['total_donated']  = $current_total + (float) $amount;
			$updates['donation_count'] = $current_count + 1;
			if ( (float) $amount > $current_largest ) {
				$updates['largest_donation'] = (float) $amount;
			}
		}

		if ( ! empty( $updates ) ) {
			Donors::update( (int) $donor_id, $updates );
		}
	}

	/**
	 * Check if a GiveWP payment has already been imported in any prior session.
	 *
	 * @param  int $give_payment_id GiveWP payment ID.
	 * @return bool
	 * @since  1.0.0
	 */
	private function already_imported( $give_payment_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'suredonation_donations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scope, one row lookup.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE import_source_id = %d AND import_source = "givewp" LIMIT 1',
				$table,
				absint( $give_payment_id )
			)
		);

		return is_numeric( $existing ) && (int) $existing > 0;
	}

	/**
	 * Extract non-standard meta keys for preservation in donation_data.givewp.meta.
	 *
	 * Any GiveWP add-on meta not represented by a dedicated SureDonation
	 * column is preserved here as a raw key/value blob so no data is lost.
	 *
	 * @param  array $meta Flat assoc of GiveWP payment meta.
	 * @return array<string,string>
	 * @since  1.0.0
	 */
	private function extract_extra_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$extra = [];
		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, self::STANDARD_META_KEYS, true ) ) {
				continue;
			}
			// Skip empty and obviously-irrelevant keys.
			if ( '' === $value || null === $value ) {
				continue;
			}
			$extra[ sanitize_key( $key ) ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $extra;
	}

	/**
	 * Append an error entry to the donations error log, capping at 50.
	 *
	 * @param  array  $progress Progress (passed by reference).
	 * @param  int    $source_id GiveWP payment ID.
	 * @param  string $message  Error message.
	 * @return void
	 * @since  1.0.0
	 */
	private function log_error( &$progress, $source_id, $message ) {
		++$progress['results']['donations']['errors'];
		if ( ! isset( $progress['results']['donations']['error_log'] ) || ! is_array( $progress['results']['donations']['error_log'] ) ) {
			$progress['results']['donations']['error_log'] = [];
		}
		$progress['results']['donations']['error_log'][] = [
			'source_id' => (int) $source_id,
			'message'   => (string) $message,
		];
		if ( count( $progress['results']['donations']['error_log'] ) > 50 ) {
			$progress['results']['donations']['error_log'] = array_slice( $progress['results']['donations']['error_log'], -50 );
		}
	}
}
