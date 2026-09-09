<?php
/**
 * Maps Charitable donations onto SureDonation donation rows.
 *
 * The unit of import is one charitable_campaign_donations row — Charitable
 * donations can span multiple campaigns (one row each), and per-campaign
 * amounts exist only on those rows, so importing per-row keeps every
 * campaign's totals exact. The shared donation post supplies the gateway,
 * status, donor snapshot, and meta for each of its rows.
 *
 * @package SureDonation
 * @since 1.5.1
 */

namespace SureDonation\Inc\Import\Charitable;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Donation_Mapper class.
 *
 * @phpstan-import-type ImportProgress from Session
 *
 * @since 1.5.1
 */
class Donation_Mapper {
	use Get_Instance;
	use Provenance_Dedupe;

	/**
	 * Donation meta keys mapped to dedicated columns / payload slots.
	 * Everything else is preserved raw in donation_data.charitable.meta.
	 *
	 * @since 1.5.1
	 */
	private const STANDARD_META_KEYS = [
		'donation_gateway',
		'donor',
		'test_mode',
		'donation_key',
		'currency',
		'contact_consent',
		'_gateway_transaction_id',
		'_gateway_payment_id',
		'_gateway_transaction_url',
		'donation_period',
	];

	/**
	 * Process a batch of Charitable donation rows.
	 *
	 * @param  ImportProgress $progress Session progress (passed by reference).
	 * @param  int            $offset   Current offset within this phase.
	 * @return int Number of source rows processed in this batch.
	 * @since  1.5.1
	 */
	public function process_batch( &$progress, $offset ) {
		$source       = Source::get_instance();
		$campaign_ids = isset( $progress['options']['campaign_ids'] ) && is_array( $progress['options']['campaign_ids'] )
			? $progress['options']['campaign_ids']
			: [];
		$rows         = $source->get_donation_rows_batch( (int) $offset, Importer::BATCH_SIZE, $campaign_ids );

		if ( empty( $rows ) ) {
			return 0;
		}

		$suppressor = Email_Suppressor::get_instance();
		$suppressor->activate();

		try {
			foreach ( $rows as $row ) {
				$row_id_for_log = isset( $row->campaign_donation_id ) ? (int) $row->campaign_donation_id : 0;
				try {
					$this->process_one( $row, $progress );
				} catch ( \Throwable $t ) {
					// One bad record can't kill the batch — log and move on
					// so the rest of the migration runs to completion.
					$this->log_error(
						$progress,
						$row_id_for_log,
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

		return count( $rows );
	}

	/**
	 * Map a single campaign_donations row into a SureDonation donations row.
	 *
	 * @param  object         $row      campaign_donations ⋈ donation-post row.
	 * @param  ImportProgress $progress Session progress (passed by reference).
	 * @return void
	 * @since  1.5.1
	 */
	private function process_one( $row, &$progress ) {
		$cd_id            = isset( $row->campaign_donation_id ) ? (int) $row->campaign_donation_id : 0;
		$donation_post_id = isset( $row->donation_id ) ? (int) $row->donation_id : 0;
		if ( $cd_id <= 0 || $donation_post_id <= 0 ) {
			$this->log_error( $progress, $cd_id, __( 'Malformed campaign donation row.', 'suredonation' ) );
			return;
		}

		$source_campaign_id = isset( $row->campaign_id ) ? (int) $row->campaign_id : 0;

		// Dedupe on the (donation_post_id, source_campaign_id) pair via the shared
		// indexed provenance key (Provenance_Dedupe) — the same key, recurring
		// guard and unknown-campaign handling the CSV path uses, so a DB import
		// and a CSV import of the same gift skip each other in both directions.
		// import_source_id holds a different id space per path (cd_id vs donation
		// post id) and is kept only for provenance.
		if ( $this->provenance_seen( $donation_post_id, $source_campaign_id ) ) {
			++$progress['results']['donations']['skipped'];
			return;
		}

		$source   = Source::get_instance();
		$meta     = $source->get_donation_meta( $donation_post_id );
		$snapshot = $source->extract_donor_snapshot( $meta );

		$email = sanitize_email( (string) $snapshot['email'] );
		if ( '' === $email || ! is_email( $email ) ) {
			$donor_row = $source->get_donor( isset( $row->donor_id ) ? (int) $row->donor_id : 0 );
			$email     = is_array( $donor_row ) && isset( $donor_row['email'] ) && is_scalar( $donor_row['email'] ) ? sanitize_email( (string) $donor_row['email'] ) : '';
		}
		if ( '' === $email || ! is_email( $email ) ) {
			$this->log_error( $progress, $cd_id, __( 'Donation has no resolvable donor email — skipped.', 'suredonation' ) );
			return;
		}

		$amount = isset( $row->amount ) ? (float) $row->amount : 0.0;
		if ( $amount <= 0 ) {
			$this->log_error( $progress, $cd_id, __( 'Donation has no positive amount — skipped.', 'suredonation' ) );
			return;
		}

		$donor_id = Donor_Mapper::get_instance()->get_or_create_for_donation(
			$snapshot,
			isset( $row->donor_id ) ? (int) $row->donor_id : 0,
			$meta,
			$progress
		);
		if ( $donor_id <= 0 ) {
			$this->log_error( $progress, $cd_id, __( 'Could not resolve a SureDonation donor for this donation.', 'suredonation' ) );
			return;
		}

		$gateway_raw    = isset( $meta['donation_gateway'] ) ? sanitize_text_field( $meta['donation_gateway'] ) : '';
		$gateway        = Status_Map::map_gateway( $gateway_raw );
		$payment_status = Status_Map::map_donation_status( isset( $row->post_status ) ? (string) $row->post_status : '' );
		$post_date_gmt  = isset( $row->post_date_gmt ) && is_scalar( $row->post_date_gmt ) ? (string) $row->post_date_gmt : '';
		$post_date      = isset( $row->post_date ) && is_scalar( $row->post_date ) ? (string) $row->post_date : '';
		$created_at     = '' !== $post_date_gmt && '0000-00-00 00:00:00' !== $post_date_gmt ? $post_date_gmt : $post_date;

		$campaign_id = 0;
		if ( $source_campaign_id > 0 && isset( $progress['campaign_map'][ $source_campaign_id ] ) ) {
			$campaign_id = (int) $progress['campaign_map'][ $source_campaign_id ];
		}

		$donor_name = trim( trim( (string) $snapshot['first_name'] ) . ' ' . trim( (string) $snapshot['last_name'] ) );

		$charitable_block = array_filter(
			[
				'source_id'          => $cd_id,
				'donation_post_id'   => $donation_post_id,
				'source_campaign_id' => $source_campaign_id,
				'campaign_name'      => isset( $row->campaign_name ) ? sanitize_text_field( (string) $row->campaign_name ) : '',
				'import_id'          => isset( $progress['import_id'] ) ? (string) $progress['import_id'] : '',
				'donation_key'       => isset( $meta['donation_key'] ) ? sanitize_text_field( $meta['donation_key'] ) : '',
				'gateway_raw'        => $gateway_raw,
				'gateway_live'       => Status_Map::is_gateway_live( $gateway ),
				'payment_id'         => isset( $meta['_gateway_payment_id'] ) ? sanitize_text_field( $meta['_gateway_payment_id'] ) : '',
				'transaction_url'    => isset( $meta['_gateway_transaction_url'] ) ? esc_url_raw( $meta['_gateway_transaction_url'] ) : '',
				'contact_consent'    => ! empty( $meta['contact_consent'] ),
				'donation_period'    => isset( $meta['donation_period'] ) ? sanitize_text_field( $meta['donation_period'] ) : '',
				'multi_campaign'     => $this->is_multi_campaign( $donation_post_id ),
				'meta'               => $this->extract_extra_meta( $meta ),
			],
			static function ( $v ) {
				if ( is_array( $v ) ) {
					return ! empty( $v );
				}
				if ( is_string( $v ) ) {
					return '' !== $v;
				}
				return ! empty( $v );
			}
		);

		// gateway_live/contact_consent/multi_campaign are meaningful when
		// false too — restore them after the truthiness filter.
		$charitable_block['gateway_live']    = Status_Map::is_gateway_live( $gateway );
		$charitable_block['contact_consent'] = ! empty( $meta['contact_consent'] );
		$charitable_block['multi_campaign']  = $this->is_multi_campaign( $donation_post_id );

		$donation_id = Donations::add(
			[
				'campaign_id'       => $campaign_id,
				'donor_id'          => $donor_id,
				'form_id'           => 0,
				'amount'            => (string) $amount,
				'currency'          => isset( $meta['currency'] ) && '' !== $meta['currency'] ? sanitize_text_field( $meta['currency'] ) : 'USD',
				'transaction_id'    => isset( $meta['_gateway_transaction_id'] ) ? sanitize_text_field( $meta['_gateway_transaction_id'] ) : '',
				'customer_id'       => '',
				'gateway'           => $gateway,
				'payment_status'    => $payment_status,
				'payment_mode'      => ! empty( $meta['test_mode'] ) ? 'test' : 'live',
				'donor_name'        => $donor_name,
				'donor_email'       => $email,
				'donation_type'     => 'one-time',
				'donation_data'     => [ 'charitable' => $charitable_block ],
				'created_at'        => $created_at,
				'import_source_id'  => $cd_id,
				'import_source'     => 'charitable',
				'import_provenance' => $this->provenance_key( $donation_post_id, $source_campaign_id ),
			]
		);

		if ( ! $donation_id ) {
			$this->log_error( $progress, $cd_id, __( 'Database insert failed for donation.', 'suredonation' ) );
			return;
		}

		++$progress['results']['donations']['imported'];

		// Record the pair so a later row in this run (or the CSV path) skips it.
		$this->mark_provenance_seen( $donation_post_id, $source_campaign_id );

		// Per-gateway breakdown for the results panel.
		if ( ! isset( $progress['results']['donations']['gateway_breakdown'][ $gateway ] ) ) {
			$progress['results']['donations']['gateway_breakdown'][ $gateway ] = 0;
		}
		++$progress['results']['donations']['gateway_breakdown'][ $gateway ];

		$this->update_donor_aggregates( $donor_id, $amount, $created_at, $payment_status );
	}

	/**
	 * Whether the donation post spans more than one campaign.
	 *
	 * @param  int $donation_post_id Charitable donation post ID.
	 * @return bool
	 * @since  1.5.1
	 */
	private function is_multi_campaign( $donation_post_id ) {
		static $cache     = [];
		$donation_post_id = (int) $donation_post_id;
		if ( isset( $cache[ $donation_post_id ] ) ) {
			return $cache[ $donation_post_id ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scope, one-shot count per donation post (memoised).
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE donation_id = %d',
				$wpdb->prefix . 'charitable_campaign_donations',
				$donation_post_id
			)
		);

		if ( count( $cache ) > 200 ) {
			$cache = [];
		}
		$cache[ $donation_post_id ] = $count > 1;

		return $cache[ $donation_post_id ];
	}

	/**
	 * Update donor aggregates from an imported donation.
	 *
	 * Mirrors Donors::record_donation() but takes the actual donation date
	 * rather than stamping current_time(), so donors imported with historical
	 * payments get the correct first/last contribution timestamps. Numeric
	 * aggregates only accumulate for revenue-bearing statuses to match how
	 * SureDonation reports them in the dashboard.
	 *
	 * Public so the CSV import path (Csv_Parser) reuses the exact same
	 * aggregate semantics rather than duplicating them.
	 *
	 * @param  int    $donor_id       SureDonation donor row ID.
	 * @param  float  $amount         Donation amount.
	 * @param  string $donation_date  MySQL datetime of the donation (GMT).
	 * @param  string $payment_status SureDonation payment status enum value.
	 * @return void
	 * @since  1.5.1
	 */
	public function update_donor_aggregates( $donor_id, $amount, $donation_date, $payment_status ) {
		$donor = Donors::get( (int) $donor_id );
		if ( ! is_array( $donor ) ) {
			return;
		}

		$updates = [];

		// first/last date track ALL imported donations regardless of status —
		// cancelled or failed payments are still real historical engagement.
		$current_first = ! empty( $donor['first_donation_date'] ) && is_scalar( $donor['first_donation_date'] ) ? (string) $donor['first_donation_date'] : '';
		$current_last  = ! empty( $donor['last_donation_date'] ) && is_scalar( $donor['last_donation_date'] ) ? (string) $donor['last_donation_date'] : '';

		if ( '' !== $donation_date ) {
			if ( '' === $current_first || strtotime( $donation_date ) < strtotime( $current_first ) ) {
				$updates['first_donation_date'] = $donation_date;
			}
			if ( '' === $current_last || strtotime( $donation_date ) > strtotime( $current_last ) ) {
				$updates['last_donation_date'] = $donation_date;
			}
		}

		// Revenue-bearing counters: only completed / partially_refunded
		// contribute (consistent with the dashboard stats queries).
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
	 * Extract non-standard meta keys for preservation in donation_data.charitable.meta.
	 *
	 * Any Charitable add-on meta not represented by a dedicated SureDonation
	 * column is preserved as a raw key/value blob so no data is lost.
	 *
	 * @param  array<string, string> $meta Flat donation meta map.
	 * @return array<string, string>
	 * @since  1.5.1
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
	 * @param  ImportProgress $progress  Progress (passed by reference).
	 * @param  int            $source_id campaign_donations row ID.
	 * @param  string         $message   Error message.
	 * @return void
	 * @since  1.5.1
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
