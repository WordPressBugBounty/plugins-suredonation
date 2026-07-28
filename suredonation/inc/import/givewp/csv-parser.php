<?php
/**
 * GiveWP CSV donation export parser.
 *
 * Parses the CSV file produced by GiveWP » Donations » Tools » Exports
 * and inserts each row as a SureDonation donation, reusing
 * Donor_Mapper + Status_Map so the logic stays consistent with the
 * direct DB path.
 *
 * Synchronous single-request flow — for very large files this could be
 * lifted into a batched/streamed mode later; current implementation
 * processes the whole file in one request and returns aggregated
 * results.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Csv_Parser class.
 *
 * @since 1.0.0
 */
class Csv_Parser {
	use Get_Instance;

	/**
	 * Hard cap on rows processed per upload. Guards against accidental
	 * (or malicious) huge files that would OOM or time out the request.
	 * GiveWP donation exports rarely exceed 100k rows; sites that need
	 * more should chunk their exports.
	 *
	 * @since 1.0.0
	 */
	const MAX_ROWS = 100000;

	/**
	 * GiveWP donation export column headers we know how to map.
	 * Header names match GiveWP's default CSV export field labels.
	 */
	const COLUMN_MAP = [
		'donation id'          => 'givewp_donation_id',
		'donation total'       => 'amount',
		'donation status'      => 'status',
		'payment gateway'      => 'gateway',
		'currency code'        => 'currency',
		'donor first name'     => 'first_name',
		'donor last name'      => 'last_name',
		'donor email'          => 'email',
		'form title'           => 'form_title',
		'donation date'        => 'date',
		'donation id (legacy)' => 'givewp_donation_id',
	];

	/**
	 * Parse a GiveWP CSV file and import its rows.
	 *
	 * @param  string $file_path Absolute path to a readable CSV file.
	 * @return array{imported:int,skipped:int,errors:int,error_log:array<int,array{row:int,message:string}>,gateway_breakdown:array<string,int>}
	 * @since  1.0.0
	 */
	public function parse_donations( $file_path ) {
		$results = [
			'imported'          => 0,
			'skipped'           => 0,
			'errors'            => 0,
			'error_log'         => [],
			'gateway_breakdown' => [],
		];

		if ( ! is_string( $file_path ) || ! is_readable( $file_path ) ) {
			++$results['errors'];
			$results['error_log'][] = [
				'row'     => 0,
				'message' => __( 'CSV file is not readable.', 'suredonation' ),
			];
			return $results;
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a user-uploaded CSV.
		if ( false === $handle ) {
			++$results['errors'];
			$results['error_log'][] = [
				'row'     => 0,
				'message' => __( 'Could not open CSV file.', 'suredonation' ),
			];
			return $results;
		}

		$header_row = fgetcsv( $handle );
		if ( false === $header_row || empty( $header_row ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			++$results['errors'];
			$results['error_log'][] = [
				'row'     => 0,
				'message' => __( 'CSV is empty or missing header row.', 'suredonation' ),
			];
			return $results;
		}

		$column_index = $this->build_column_index( $header_row );

		// Engage email suppression for the duration of the import.
		$suppressor = Email_Suppressor::get_instance();
		$suppressor->activate();

		$row_number = 1; // header was row 1.
		$progress   = [ 'donor_map' => [] ];

		try {
			while ( false !== ( $row = fgetcsv( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Idiomatic CSV stream.
				++$row_number;
				if ( $row_number - 1 > self::MAX_ROWS ) {
					++$results['errors'];
					$results['error_log'][] = [
						'row'     => (int) $row_number,
						'message' => sprintf(
							/* translators: %d: row cap for a single CSV upload. */
							__(
								'CSV exceeds the per-upload row cap (%d). Split the export into smaller files.',
								'suredonation'
							),
							(int) self::MAX_ROWS
						),
					];
					break;
				}
				$this->process_row( $row, $column_index, $progress, $results, $row_number );
			}
		} finally {
			$suppressor->deactivate();
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $results;
	}

	/**
	 * Build a map of canonical field => column index from the header row.
	 *
	 * @param  array<int,string> $header_row Raw header row.
	 * @return array<string,int>
	 * @since  1.0.0
	 */
	private function build_column_index( $header_row ) {
		$index = [];
		foreach ( $header_row as $col => $heading ) {
			$key = strtolower( trim( (string) $heading ) );
			if ( isset( self::COLUMN_MAP[ $key ] ) ) {
				$index[ self::COLUMN_MAP[ $key ] ] = (int) $col;
			}
		}
		return $index;
	}

	/**
	 * Process a single CSV row.
	 *
	 * @param  array<int,string> $row           Raw row values.
	 * @param  array<string,int> $column_index  field => column index.
	 * @param  array             $progress     Progress holder (donor_map cache).
	 * @param  array             $results      Aggregate results (passed by reference).
	 * @param  int               $row_number   1-based row number for error reporting.
	 * @return void
	 * @since  1.0.0
	 */
	private function process_row( $row, $column_index, &$progress, &$results, $row_number ) {
		$value = static function ( $field ) use ( $row, $column_index ) {
			if ( ! isset( $column_index[ $field ] ) ) {
				return '';
			}
			$col = $column_index[ $field ];
			return isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
		};

		$email  = sanitize_email( $value( 'email' ) );
		$amount = (float) $value( 'amount' );
		$first  = sanitize_text_field( $value( 'first_name' ) );
		$last   = sanitize_text_field( $value( 'last_name' ) );

		if ( '' === $email || $amount <= 0 ) {
			++$results['errors'];
			$results['error_log'][] = [
				'row'     => (int) $row_number,
				'message' => __( 'Missing email or zero amount.', 'suredonation' ),
			];
			return;
		}

		$give_donation_id = absint( $value( 'givewp_donation_id' ) );

		// Duplicate detection — skip rows already imported (either via direct DB path or a previous CSV run).
		if ( $give_donation_id > 0 && $this->already_imported( $give_donation_id ) ) {
			++$results['skipped'];
			return;
		}

		// Resolve donor via the existing mapper (synthesise the payment_meta shape it expects).
		$payment_meta = [
			'_give_payment_donor_email'      => $email,
			'_give_donor_billing_first_name' => $first,
			'_give_donor_billing_last_name'  => $last,
		];
		$donor_id     = Donor_Mapper::get_instance()->get_or_create_for_payment( $payment_meta, $progress );

		if ( $donor_id <= 0 ) {
			++$results['errors'];
			$results['error_log'][] = [
				'row'     => (int) $row_number,
				'message' => __( 'Failed to resolve donor.', 'suredonation' ),
			];
			return;
		}

		$give_gateway = $value( 'gateway' );
		$gateway      = Status_Map::map_gateway( $give_gateway );
		$status       = Status_Map::map_donation_status( $value( 'status' ) );
		$currency     = strtoupper( $value( 'currency' ) );
		$donor_name   = trim( $first . ' ' . $last );
		$date         = $value( 'date' );

		$results['gateway_breakdown'][ $gateway ] = isset( $results['gateway_breakdown'][ $gateway ] )
			? (int) $results['gateway_breakdown'][ $gateway ] + 1
			: 1;

		$donation_data = [
			'givewp' => [
				'source_id'    => $give_donation_id,
				'form_title'   => sanitize_text_field( $value( 'form_title' ) ),
				'gateway_raw'  => $give_gateway,
				'gateway_live' => Status_Map::is_gateway_live( $gateway ),
				'csv_row'      => (int) $row_number,
			],
		];

		$donation_id = Donations::add(
			[
				'campaign_id'      => 0,
				'donor_id'         => $donor_id,
				'form_id'          => 0,
				'amount'           => (string) $amount,
				'currency'         => '' !== $currency ? $currency : 'USD',
				'transaction_id'   => '',
				'customer_id'      => '',
				'gateway'          => $gateway,
				'payment_status'   => $status,
				'payment_mode'     => 'live',
				'donor_name'       => $donor_name,
				'donor_email'      => $email,
				'donation_type'    => 'one-time',
				'donation_data'    => $donation_data,
				'created_at'       => '' !== $date ? $date : current_time( 'mysql', true ),
				'import_source_id' => $give_donation_id,
				'import_source'    => 'givewp',
			]
		);

		if ( ! $donation_id ) {
			++$results['errors'];
			$results['error_log'][] = [
				'row'     => (int) $row_number,
				'message' => __( 'Failed to insert donation row.', 'suredonation' ),
			];
			return;
		}

		++$results['imported'];

		// Keep error log bounded.
		if ( count( $results['error_log'] ) > 50 ) {
			$results['error_log'] = array_slice( $results['error_log'], -50 );
		}
	}

	/**
	 * Check whether a GiveWP donation source ID has already been imported.
	 *
	 * @param  int $give_donation_id GiveWP donation ID.
	 * @return bool
	 * @since  1.0.0
	 */
	private function already_imported( $give_donation_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'suredonation_donations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE import_source_id = %d AND import_source = %s LIMIT 1',
				$table,
				absint( $give_donation_id ),
				'givewp'
			)
		);

		return is_numeric( $existing ) && (int) $existing > 0;
	}
}
