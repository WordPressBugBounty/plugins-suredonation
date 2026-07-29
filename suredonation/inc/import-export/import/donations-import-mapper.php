<?php
/**
 * Donations import mapper.
 *
 * Imports one batch of donation rows from a SureDonation-exported CSV: matches
 * or creates the donor (by email), resolves campaign/form, dedups (source-id
 * primary, content fallback), and inserts with import_source='suredonation'
 * (which suppresses the suredonation_donation_created action so no automations
 * or webhooks fire). Receipt emails are suppressed for the batch, and donor
 * aggregates are updated date-aware.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Import_Export\Import;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Import\Givewp\Email_Suppressor;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donations import mapper.
 *
 * @phpstan-type ImportResult array{imported:int, skipped:int, errors:int, donors_created:int, donors_matched:int, error_log:array<int, string>}
 *
 * @since 1.3.0
 */
class Donations_Import_Mapper {

	use Get_Instance;

	/**
	 * Donation statuses that contribute to donor revenue aggregates.
	 *
	 * @var array<int, string>
	 * @since 1.3.0
	 */
	const REVENUE_STATUSES = [ 'completed', 'partially_refunded' ];

	/**
	 * Process one batch of donation rows.
	 *
	 * @param array<string, mixed> $progress Session progress (by reference).
	 * @param int                  $offset   Data-row offset.
	 * @return int Number of source rows fetched this batch.
	 * @since 1.3.0
	 */
	public function process_batch( array &$progress, $offset ) {
		$token   = Helper::get_string_value( $progress['token'] ?? '' );
		$mapping = is_array( $progress['mapping'] ?? null ) ? $progress['mapping'] : [];
		$options = is_array( $progress['options'] ?? null ) ? $progress['options'] : [];
		$dry_run = ! empty( $options['dry_run'] );

		// Offset 0 marks the start of the phase, so reset the byte cursor to the
		// top of the file; otherwise resume from the stored byte position.
		$byte_offset             = ( 0 === (int) $offset ) ? 0 : Helper::get_integer_value( $progress['byte_offset'] ?? 0 );
		$rows                    = Csv_File::read_batch( $token, $byte_offset, Import_Runner::BATCH_SIZE );
		$progress['byte_offset'] = $byte_offset;
		if ( empty( $rows ) ) {
			return 0;
		}

		$suppressor = Email_Suppressor::get_instance();
		if ( ! $dry_run ) {
			$suppressor->activate();
		}

		try {
			foreach ( $rows as $row ) {
				$this->process_row( $row, $mapping, $progress, $dry_run );
			}
		} finally {
			if ( ! $dry_run ) {
				$suppressor->deactivate();
			}
		}

		return count( $rows );
	}

	/**
	 * Import a single donation row.
	 *
	 * @param array<int, string>       $row      Raw CSV cells.
	 * @param array<int|string, mixed> $mapping  Header index => field.
	 * @param array<string, mixed>     $progress Session progress (by reference).
	 * @param bool                     $dry_run  Whether to write.
	 * @return void
	 * @since 1.3.0
	 */
	private function process_row( $row, $mapping, &$progress, $dry_run ) {
		/** @var array<string, ImportResult> $results */
		$results = &$progress['results'];
		$result  = &$results['donations'];
		$options = is_array( $progress['options'] ?? null ) ? $progress['options'] : [];
		$data    = Column_Map::apply_row( $row, $mapping );

		$email  = sanitize_email( Helper::get_string_value( $data['donor_email'] ?? '' ) );
		$amount = $this->normalize_amount( $data['amount'] ?? '' );

		// Reject a missing/invalid email or a non-positive amount. A blank or
		// non-numeric amount normalizes to 0, so the <= 0 check also rejects
		// "abc" and "0" (which would otherwise import a meaningless $0 donation).
		if ( '' === $email || ! is_email( $email ) || (float) $amount <= 0 ) {
			++$result['errors'];
			$this->log_error( $result, __( 'Row skipped: missing/invalid email or non-positive amount.', 'suredonation' ) );
			return;
		}

		$source_id = isset( $data['import_source_id'] ) ? absint( $data['import_source_id'] ) : 0;
		// Every imported donation goes into the campaign chosen at import time;
		// the file's own campaign column is not used for linking.
		$campaign_id = Helper::get_integer_value( $options['campaign_id'] ?? 0 );
		$gateway     = sanitize_text_field( Helper::get_string_value( $data['gateway'] ?? '' ) );
		$mode        = $this->normalize_mode( $data['payment_mode'] ?? '' );
		$status      = $this->normalize_status( $data['payment_status'] ?? '' );
		$date        = $this->normalize_date( $data['donation_date'] ?? '' );

		if ( $this->is_duplicate( $source_id, $email, $amount, $date, $campaign_id, $gateway, $mode ) ) {
			++$result['skipped'];
			return;
		}

		$donor_id = $this->resolve_donor( $email, $data, $progress, $dry_run, $result );

		if ( $dry_run ) {
			++$result['imported'];
			return;
		}

		$donation = [
			'campaign_id'      => $campaign_id,
			'donor_id'         => $donor_id,
			'form_id'          => $this->resolve_form( $data ),
			'amount'           => $amount,
			'fees_covered'     => $this->normalize_amount( $data['fees_covered'] ?? 0 ),
			'refunded_amount'  => $this->normalize_amount( $data['refunded_amount'] ?? 0 ),
			'currency'         => $this->fallback( sanitize_text_field( Helper::get_string_value( $data['currency'] ?? '' ) ), 'USD' ),
			'transaction_id'   => sanitize_text_field( Helper::get_string_value( $data['transaction_id'] ?? '' ) ),
			'gateway'          => $this->fallback( $gateway, 'offline' ),
			'payment_status'   => $status,
			'payment_mode'     => $mode,
			'donor_name'       => sanitize_text_field( Helper::get_string_value( $data['donor_name'] ?? '' ) ),
			'donor_email'      => $email,
			'donor_phone'      => sanitize_text_field( Helper::get_string_value( $data['donor_phone'] ?? '' ) ),
			'is_anonymous'     => $this->to_bool( $data['is_anonymous'] ?? '' ),
			'donation_type'    => 'one-time',
			'donor_comment'    => sanitize_textarea_field( Helper::get_string_value( $data['donor_comment'] ?? '' ) ),
			'ip_address'       => sanitize_text_field( Helper::get_string_value( $data['ip_address'] ?? '' ) ),
			'import_source'    => 'suredonation',
			'import_source_id' => $source_id,
		];

		if ( '' !== $date ) {
			$donation['created_at'] = $date;
		}

		/**
		 * Filter the donation row before it is inserted. Pro uses this to add
		 * subscription fields (subscription_id, subscription_status,
		 * donation_type) from the mapped CSV row.
		 *
		 * @param array<string, mixed>  $donation Donation data to insert.
		 * @param array<string, string> $data     Mapped CSV row fields.
		 */
		$filtered = apply_filters( 'suredonation_import_donation_row', $donation, $data );
		if ( is_array( $filtered ) ) {
			$donation = $filtered;
		}

		$donation_id = Donations::add( $donation );

		if ( $donation_id ) {
			$donation_id = (int) $donation_id;
			++$result['imported'];
			$this->update_donor_aggregates( $donor_id, $amount, $status, $date );
			Import_Runner::track_created( $progress, 'donations', $donation_id );
			Import_Runner::track_id_map( $progress, $source_id, $donation_id );

			/**
			 * Fires after an imported donation is inserted. Pro uses this to
			 * track created rows (for rollback) and old→new id mapping (to
			 * relink recurring renewals on completion).
			 *
			 * @param int                   $donation_id New donation id.
			 * @param array<string, string> $data        Mapped CSV row fields.
			 * @param array<string, mixed>  $donation    Inserted donation data.
			 */
			do_action( 'suredonation_import_donation_inserted', $donation_id, $data, $donation );

			// Preserve per-form custom fields (columns beyond the standard
			// export set) under donation_data['fields'] so the round-trip is
			// lossless.
			$headers = is_array( $options['headers'] ?? null ) ? $options['headers'] : [];
			$custom  = Column_Map::extract_custom_fields( $headers, $row );
			if ( ! empty( $custom ) ) {
				Donations::set_submitted_fields( $donation_id, $custom );
			}
		} else {
			++$result['errors'];
			$this->log_error( $result, __( 'Row skipped: could not insert donation.', 'suredonation' ) );
		}
	}

	/**
	 * Resolve the form id for a row (by numeric id, verified as a donation form).
	 *
	 * @param array<string, string> $data Row fields.
	 * @return int Form id, or 0.
	 * @since 1.3.0
	 */
	private function resolve_form( $data ) {
		$value = trim( Helper::get_string_value( $data['form'] ?? '' ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return 0;
		}
		$post = get_post( absint( $value ) );
		return ( $post instanceof \WP_Post && Donation_Form::POST_TYPE === $post->post_type ) ? (int) $post->ID : 0;
	}

	/**
	 * Match or create the donor for a row (by email), cached per session.
	 *
	 * Uses Donors::add() (never get_or_create) so no WP user is auto-created;
	 * an existing WP user is linked by email only.
	 *
	 * @param string                $email    Donor email.
	 * @param array<string, string> $data     Row fields.
	 * @param array<string, mixed>  $progress Session (by reference).
	 * @param bool                  $dry_run  Whether to write.
	 * @param ImportResult          $result   Phase result counters (by reference).
	 * @return int Donor id, or 0.
	 * @since 1.3.0
	 */
	private function resolve_donor( $email, $data, &$progress, $dry_run, &$result ) {
		$map = is_array( $progress['donor_map'] ?? null ) ? $progress['donor_map'] : [];
		if ( isset( $map[ $email ] ) ) {
			return Helper::get_integer_value( $map[ $email ] );
		}

		$existing = Donors::get_by_email( $email );
		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$id                    = Helper::get_integer_value( $existing['id'] );
			$map[ $email ]         = $id;
			$progress['donor_map'] = $map;
			++$result['donors_matched'];
			return $id;
		}

		++$result['donors_created'];

		if ( $dry_run ) {
			$map[ $email ]         = 0;
			$progress['donor_map'] = $map;
			return 0;
		}

		$name = trim( Helper::get_string_value( $data['donor_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( Helper::get_string_value( $data['first_name'] ?? '' ) . ' ' . Helper::get_string_value( $data['last_name'] ?? '' ) );
		}

		$user = get_user_by( 'email', $email );

		$id = Donors::add(
			[
				'email'            => $email,
				'name'             => sanitize_text_field( $name ),
				'phone'            => sanitize_text_field( Helper::get_string_value( $data['donor_phone'] ?? '' ) ),
				'company'          => sanitize_text_field( Helper::get_string_value( $data['company'] ?? '' ) ),
				'address'          => sanitize_textarea_field( Helper::get_string_value( $data['address'] ?? '' ) ),
				'donor_status'     => 'active',
				'user_id'          => $user instanceof \WP_User ? (int) $user->ID : null,
				'import_source'    => 'suredonation',
				'import_source_id' => 0,
			]
		);

		$id = $id ? (int) $id : 0;
		if ( $id > 0 ) {
			Import_Runner::track_created( $progress, 'donors', $id );

			/**
			 * Fires after an imported donor is created. Pro uses this to track
			 * created donors for rollback.
			 *
			 * @param int    $donor_id New donor id.
			 * @param string $email    Donor email.
			 */
			do_action( 'suredonation_import_donor_inserted', $id, $email );
		}
		$map[ $email ]         = $id;
		$progress['donor_map'] = $map;
		return $id;
	}

	/**
	 * Detect a duplicate donation: source-id first, then content fallback.
	 *
	 * @param int    $source_id   Original donation id from the CSV (0 if absent).
	 * @param string $email       Donor email.
	 * @param string $amount      Normalized amount.
	 * @param string $date        Normalized created_at.
	 * @param int    $campaign_id Campaign id.
	 * @param string $gateway     Gateway.
	 * @param string $mode        Payment mode.
	 * @return bool True if a matching donation already exists.
	 * @since 1.3.0
	 */
	private function is_duplicate( $source_id, $email, $amount, $date, $campaign_id, $gateway, $mode ) {
		global $wpdb;
		$table = Donations::get_instance()->get_tablename();

		if ( $source_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedup lookup against live data.
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE import_source = %s AND import_source_id = %d LIMIT 1', $table, 'suredonation', $source_id ) );
			if ( ! empty( $found ) ) {
				return true;
			}
		}

		// Content fallback. Include created_at when the row carries a date; when
		// it does not (a hand-made CSV missing both the source id and the date),
		// match on the remaining fields so a re-run still dedups instead of
		// inserting the row again every time.
		$gw = $this->fallback( $gateway, 'offline' );
		if ( '' !== $date ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedup lookup against live data.
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE donor_email = %s AND amount = %s AND created_at = %s AND campaign_id = %d AND gateway = %s AND payment_mode = %s LIMIT 1', $table, $email, $amount, $date, $campaign_id, $gw, $mode ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedup lookup against live data.
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE donor_email = %s AND amount = %s AND campaign_id = %d AND gateway = %s AND payment_mode = %s LIMIT 1', $table, $email, $amount, $campaign_id, $gw, $mode ) );
		}

		return ! empty( $found );
	}

	/**
	 * Update a donor's rolling aggregates from an imported donation, date-aware.
	 *
	 * @param int    $donor_id Donor id.
	 * @param string $amount   Normalized amount.
	 * @param string $status   Payment status.
	 * @param string $date     Donation date (mysql), or '' for now.
	 * @return void
	 * @since 1.3.0
	 */
	private function update_donor_aggregates( $donor_id, $amount, $status, $date ) {
		if ( $donor_id <= 0 ) {
			return;
		}
		$donor = Donors::get( $donor_id );
		if ( ! is_array( $donor ) ) {
			return;
		}

		$donation_date = '' !== $date ? $date : current_time( 'mysql' );
		$update        = [];

		$first = Helper::get_string_value( $donor['first_donation_date'] ?? '' );
		$last  = Helper::get_string_value( $donor['last_donation_date'] ?? '' );
		if ( '' === $first || $donation_date < $first ) {
			$update['first_donation_date'] = $donation_date;
		}
		if ( '' === $last || $donation_date > $last ) {
			$update['last_donation_date'] = $donation_date;
		}

		if ( in_array( $status, self::REVENUE_STATUSES, true ) ) {
			$amount_f                 = (float) $amount;
			$update['total_donated']  = number_format( (float) ( $donor['total_donated'] ?? 0 ) + $amount_f, 8, '.', '' );
			$update['donation_count'] = (int) ( $donor['donation_count'] ?? 0 ) + 1;
			if ( $amount_f > (float) ( $donor['largest_donation'] ?? 0 ) ) {
				$update['largest_donation'] = number_format( $amount_f, 8, '.', '' );
			}
		}

		if ( ! empty( $update ) ) {
			Donors::update( $donor_id, $update );
		}
	}

	/**
	 * Normalize an amount to a plain decimal string.
	 *
	 * @param mixed $value Raw amount.
	 * @return string Decimal string.
	 * @since 1.3.0
	 */
	private function normalize_amount( $value ) {
		$clean = preg_replace( '/[^0-9.\-]/', '', Helper::get_string_value( $value ) );
		return number_format( (float) $clean, 8, '.', '' );
	}

	/**
	 * Normalize the payment mode to 'live' or 'test' (default test).
	 *
	 * @param mixed $value Raw mode.
	 * @return string 'live' or 'test'.
	 * @since 1.3.0
	 */
	private function normalize_mode( $value ) {
		return 'live' === strtolower( trim( Helper::get_string_value( $value ) ) ) ? 'live' : 'test';
	}

	/**
	 * Normalize the payment status to a valid enum (default 'pending').
	 *
	 * @param mixed $value Raw status.
	 * @return string Valid payment status.
	 * @since 1.3.0
	 */
	private function normalize_status( $value ) {
		$status = strtolower( trim( Helper::get_string_value( $value ) ) );
		$valid  = Donations::get_valid_statuses();
		return in_array( $status, $valid, true ) ? $status : 'pending';
	}

	/**
	 * Normalize a date to `Y-m-d H:i:s`, or '' when unparseable.
	 *
	 * @param mixed $value Raw date.
	 * @return string Formatted date or empty string.
	 * @since 1.3.0
	 */
	private function normalize_date( $value ) {
		$raw = trim( Helper::get_string_value( $value ) );
		if ( '' === $raw ) {
			return '';
		}
		$ts = strtotime( $raw );
		return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Coerce a truthy CSV value to a bool int.
	 *
	 * @param mixed $value Raw value.
	 * @return int 1 or 0.
	 * @since 1.3.0
	 */
	private function to_bool( $value ) {
		$v = strtolower( trim( Helper::get_string_value( $value ) ) );
		return in_array( $v, [ '1', 'yes', 'true', 'y' ], true ) ? 1 : 0;
	}

	/**
	 * Return $value, or the fallback when $value is empty.
	 *
	 * @param string $value          Value.
	 * @param string $default_value  Fallback.
	 * @return string
	 * @since 1.3.0
	 */
	private function fallback( $value, $default_value ) {
		return '' !== (string) $value ? (string) $value : $default_value;
	}

	/**
	 * Append an error message to the phase log (capped at 50 entries).
	 *
	 * @param ImportResult $result  Phase results (by reference).
	 * @param string       $message Message.
	 * @return void
	 * @since 1.3.0
	 */
	private function log_error( &$result, $message ) {
		if ( count( $result['error_log'] ) < 50 ) {
			$result['error_log'][] = $message;
		}
	}
}
