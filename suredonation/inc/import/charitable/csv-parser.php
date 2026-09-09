<?php
/**
 * CSV import path for Charitable donation exports.
 *
 * Synchronous streaming parser for the CSV files Charitable's own
 * Reports → Export tool produces. Charitable exports one row per
 * donation × campaign (Donation ID legitimately repeats across rows of a
 * multi-campaign donation), so rows are deduped on the donation-post +
 * source-campaign pair — the same tokens the direct-DB importer stores —
 * making DB-then-CSV re-imports skip cleanly.
 *
 * @package SureDonation
 * @since 1.5.1
 */

namespace SureDonation\Inc\Import\Charitable;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Csv_Parser class.
 *
 * @since 1.5.1
 */
class Csv_Parser {
	use Get_Instance;
	use Provenance_Dedupe;

	/**
	 * Hard cap on rows per upload — protects against runaway memory/time.
	 *
	 * @since 1.5.1
	 */
	public const MAX_ROWS = 100000;

	/**
	 * Charitable donation-export header => canonical field map (headers are
	 * lowercased before lookup).
	 *
	 * @since 1.5.1
	 */
	public const COLUMN_MAP = [
		'donation id'       => 'charitable_donation_id',
		'campaign id'       => 'campaign_id',
		'campaign title'    => 'campaign_title',
		'first name'        => 'first_name',
		'last name'         => 'last_name',
		'email'             => 'email',
		'address'           => 'address',
		'address 2'         => 'address_2',
		'city'              => 'city',
		'state'             => 'state',
		'postcode'          => 'postcode',
		'country'           => 'country',
		'phone number'      => 'phone',
		'donation amount'   => 'amount',
		'date of donation'  => 'date',
		'time of donation'  => 'time',
		'donation status'   => 'status_label',
		'donation gateway'  => 'gateway_label',
		'made in test mode' => 'test_mode',
		'contact consent'   => 'contact_consent',
	];

	/**
	 * Parse a Charitable CSV file and import its rows.
	 *
	 * @param  string $file_path Absolute path to a readable CSV file.
	 * @return array{imported:int,skipped:int,errors:int,error_log:array<int,array{row:int,message:string}>,gateway_breakdown:array<string,int>}
	 * @since  1.5.1
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

		// Dedupe is a per-row indexed lookup on the import_provenance column
		// (Provenance_Dedupe) — no prior-import set to preload here.

		// Engage email suppression for the duration of the import.
		$suppressor = Email_Suppressor::get_instance();
		$suppressor->activate();

		$row_number = 1; // header was row 1.
		$progress   = [
			'donor_map' => [],
			// Charitable's export omits currency; fall back to the site's
			// configured Charitable currency (USD when unknown).
			'currency'  => $this->get_charitable_currency(),
			// A run id stamped on every row so CSV-imported donations carry
			// provenance and can participate in rollback.
			'import_id' => wp_generate_uuid4(),
		];

		try {
			while ( false !== ( $row = fgetcsv( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Idiomatic CSV stream.
				++$row_number;
				if ( $row_number - 1 > self::MAX_ROWS ) {
					++$results['errors'];
					$results['error_log'][] = [
						'row'     => (int) $row_number,
						'message' => sprintf(
							/* translators: %d: row cap for a single CSV upload. */
							__( 'CSV exceeds the per-upload row cap (%d). Split the export into smaller files.', 'suredonation' ),
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
	 * @param  array<int, string> $header_row Raw header row.
	 * @return array<string, int>
	 * @since  1.5.1
	 */
	private function build_column_index( $header_row ) {
		$index = [];
		foreach ( $header_row as $col => $header ) {
			$key = strtolower( trim( (string) $header ) );
			if ( isset( self::COLUMN_MAP[ $key ] ) ) {
				$index[ self::COLUMN_MAP[ $key ] ] = (int) $col;
			}
		}
		return $index;
	}

	/**
	 * Import one CSV row.
	 *
	 * @param  array<int, string|null>                                                                                                                         $row          Raw CSV row.
	 * @param  array<string, int>                                                                                                                              $column_index Field => column map.
	 * @param  array<string, mixed>                                                                                                                            $progress     Lightweight progress (donor cache).
	 * @param  array{imported: int, skipped: int, errors: int, error_log: array<int, array{row: int, message: string}>, gateway_breakdown: array<string, int>} $results      Results accumulator (by reference).
	 * @param  int                                                                                                                                             $row_number   1-based row number for error logs.
	 * @return void
	 * @since  1.5.1
	 */
	private function process_row( $row, $column_index, &$progress, &$results, $row_number ) {
		$field = static function ( $key ) use ( $row, $column_index ) {
			if ( ! isset( $column_index[ $key ] ) ) {
				return '';
			}
			$value = $row[ $column_index[ $key ] ] ?? '';
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};

		$email = sanitize_email( $field( 'email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			++$results['errors'];
			$this->log_row_error( $results, $row_number, __( 'Row has no valid donor email.', 'suredonation' ) );
			return;
		}

		$amount = $this->parse_amount( $field( 'amount' ) );
		if ( $amount <= 0 ) {
			++$results['errors'];
			$this->log_row_error( $results, $row_number, __( 'Row has no positive donation amount.', 'suredonation' ) );
			return;
		}

		$donation_post_id   = absint( $field( 'charitable_donation_id' ) );
		$source_campaign_id = absint( $field( 'campaign_id' ) );
		$campaign_title     = sanitize_text_field( $field( 'campaign_title' ) );

		// A hand-edited export can leave the Campaign ID cell blank. The DB import
		// path always carries the numeric id, so recover it from the title via the
		// campaign the campaign phase imported — otherwise the two paths key the same
		// gift differently (numeric id vs title hash) and it imports twice. Falls back
		// to the title hash only when no imported campaign matches the title.
		if ( 0 === $source_campaign_id && '' !== $campaign_title ) {
			$source_campaign_id = $this->resolve_source_campaign_id_from_title( $campaign_title );
		}

		// A row with no Donation ID cannot be deduped or rolled back (both key on
		// import_source_id > 0), so it would re-import as a duplicate on every
		// upload and escape rollback. Reject it rather than import an orphan.
		if ( $donation_post_id <= 0 ) {
			++$results['errors'];
			$this->log_row_error( $results, $row_number, __( 'Row has no Donation ID — cannot be de-duplicated or rolled back; skipped.', 'suredonation' ) );
			return;
		}

		// Dedupe on the donation-post + campaign pair (Charitable exports one row
		// per donation × campaign, so Donation ID alone is not unique). When the
		// export leaves the Campaign ID cell blank, the campaign title keeps the
		// per-campaign rows of a multi-campaign donation distinct instead of
		// collapsing to "<post>:0" (which would silently drop the 2nd+ rows).
		if ( $this->provenance_seen( $donation_post_id, $source_campaign_id, $campaign_title ) ) {
			++$results['skipped'];
			return;
		}

		// Synthesize the donor snapshot shape the shared Donor_Mapper expects.
		$snapshot = [
			'first_name' => sanitize_text_field( $field( 'first_name' ) ),
			'last_name'  => sanitize_text_field( $field( 'last_name' ) ),
			'email'      => $email,
			'address'    => sanitize_text_field( $field( 'address' ) ),
			'address_2'  => sanitize_text_field( $field( 'address_2' ) ),
			'city'       => sanitize_text_field( $field( 'city' ) ),
			'state'      => sanitize_text_field( $field( 'state' ) ),
			'postcode'   => sanitize_text_field( $field( 'postcode' ) ),
			'country'    => sanitize_text_field( $field( 'country' ) ),
			'phone'      => sanitize_text_field( $field( 'phone' ) ),
		];

		$donation_meta = [
			'contact_consent' => $this->truthy( $field( 'contact_consent' ) ) ? '1' : '',
		];

		$donor_id = Donor_Mapper::get_instance()->get_or_create_for_donation( $snapshot, 0, $donation_meta, $progress );
		if ( $donor_id <= 0 ) {
			++$results['errors'];
			$this->log_row_error( $results, $row_number, __( 'Could not resolve a SureDonation donor for this row.', 'suredonation' ) );
			return;
		}

		$gateway_label = $field( 'gateway_label' );
		$gateway       = Status_Map::map_gateway_label( $gateway_label );

		$created_at = trim( $field( 'date' ) . ' ' . $field( 'time' ) );
		$created_ts = '' !== $created_at ? strtotime( $created_at ) : false;
		$created_at = false !== $created_ts ? gmdate( 'Y-m-d H:i:s', $created_ts ) : current_time( 'mysql', true );

		$charitable_block                 = array_filter(
			[
				'donation_post_id'   => $donation_post_id,
				'source_campaign_id' => $source_campaign_id,
				'campaign_title'     => $campaign_title,
				'gateway_raw'        => sanitize_text_field( $gateway_label ),
				'import_id'          => isset( $progress['import_id'] ) ? (string) $progress['import_id'] : '',
				'contact_consent'    => $this->truthy( $field( 'contact_consent' ) ),
				'csv_row'            => (int) $row_number,
			]
		);
		$charitable_block['gateway_live'] = Status_Map::is_gateway_live( $gateway );

		$payment_status = Status_Map::map_status_label( $field( 'status_label' ) );

		$donation_id = Donations::add(
			[
				'campaign_id'       => 0,
				'donor_id'          => $donor_id,
				'form_id'           => 0,
				'amount'            => (string) $amount,
				'currency'          => isset( $progress['currency'] ) ? (string) $progress['currency'] : 'USD',
				'transaction_id'    => '',
				'customer_id'       => '',
				'gateway'           => $gateway,
				'payment_status'    => $payment_status,
				'payment_mode'      => $this->truthy( $field( 'test_mode' ) ) ? 'test' : 'live',
				'donor_name'        => trim( $snapshot['first_name'] . ' ' . $snapshot['last_name'] ),
				'donor_email'       => $email,
				'donation_type'     => 'one-time',
				'donation_data'     => [ 'charitable' => $charitable_block ],
				'created_at'        => $created_at,
				'import_source_id'  => $donation_post_id,
				'import_source'     => 'charitable',
				'import_provenance' => $this->provenance_key( $donation_post_id, $source_campaign_id, $campaign_title ),
			]
		);

		if ( ! $donation_id ) {
			++$results['errors'];
			$this->log_row_error( $results, $row_number, __( 'Database insert failed for row.', 'suredonation' ) );
			return;
		}

		// Keep donor rollups correct (mirrors the direct-DB path — without this
		// CSV-imported donors would report total_donated = 0).
		Donation_Mapper::get_instance()->update_donor_aggregates( $donor_id, $amount, $created_at, $payment_status );

		// Record the pair so later rows in this same file (and re-uploads) skip.
		$this->mark_provenance_seen( $donation_post_id, $source_campaign_id, $campaign_title );

		++$results['imported'];
		if ( ! isset( $results['gateway_breakdown'][ $gateway ] ) ) {
			$results['gateway_breakdown'][ $gateway ] = 0;
		}
		++$results['gateway_breakdown'][ $gateway ];
	}

	/**
	 * Parse a donation amount from a locale-formatted CSV cell.
	 *
	 * Charitable renders the export amount using the decimal/thousands
	 * separators configured in charitable_settings, so those are read and used
	 * to parse deterministically — strip the thousands separator, normalise the
	 * decimal separator to a dot. This removes the ambiguity a positional
	 * heuristic has with dot-grouped integers (e.g. "1.234.567", which a
	 * lone-dot-is-decimal heuristic would truncate to 1.234). When the site has
	 * no configured separators (Charitable never set them), fall back to the
	 * heuristic: the right-most of `.`/`,` is the decimal, the other groups
	 * thousands; a lone comma is a decimal only when it looks like one.
	 *
	 * @param  string $raw Raw amount cell.
	 * @return float Non-negative amount (0.0 when unparseable).
	 * @since  1.5.1
	 */
	private function parse_amount( $raw ) {
		$raw = preg_replace( '/[^0-9.,\-]/', '', (string) $raw );
		if ( null === $raw || '' === $raw ) {
			return 0.0;
		}

		$sep = $this->get_charitable_separators();
		if ( '' !== $sep['decimal'] ) {
			// Deterministic: drop the (possibly empty) thousands separator, then
			// normalise the decimal separator to a dot.
			if ( '' !== $sep['thousands'] ) {
				$raw = str_replace( $sep['thousands'], '', $raw );
			}
			if ( '.' !== $sep['decimal'] ) {
				$raw = str_replace( $sep['decimal'], '.', $raw );
			}
			return (float) $raw;
		}

		// Fallback heuristic (no configured separators).
		$has_dot   = false !== strpos( $raw, '.' );
		$has_comma = false !== strpos( $raw, ',' );

		if ( $has_dot && $has_comma ) {
			if ( strrpos( $raw, ',' ) > strrpos( $raw, '.' ) ) {
				$raw = str_replace( '.', '', $raw ); // Dots group thousands.
				$raw = str_replace( ',', '.', $raw ); // Comma is the decimal point.
			} else {
				$raw = str_replace( ',', '', $raw ); // Commas group thousands.
			}
		} elseif ( $has_comma ) {
			$raw = preg_match( '/,\d{1,2}$/', $raw )
				? str_replace( ',', '.', $raw ) // Decimal comma (e.g. 1234,56).
				: str_replace( ',', '', $raw );  // Thousands grouping (e.g. 1,234).
		}

		return (float) $raw;
	}

	/**
	 * The site's configured Charitable decimal/thousands separators, read from
	 * the same charitable_settings option as the currency. Each is a single
	 * character; an empty 'decimal' means Charitable has no configured value and
	 * parse_amount() should fall back to its heuristic.
	 *
	 * @return array{decimal:string,thousands:string}
	 * @since  1.5.1
	 */
	private function get_charitable_separators() {
		$settings = get_option( 'charitable_settings', [] );
		if ( ! is_array( $settings ) ) {
			return [
				'decimal'   => '',
				'thousands' => '',
			];
		}

		$decimal   = isset( $settings['decimal_separator'] ) && is_scalar( $settings['decimal_separator'] ) ? (string) $settings['decimal_separator'] : '';
		$thousands = isset( $settings['thousands_separator'] ) && is_scalar( $settings['thousands_separator'] ) ? (string) $settings['thousands_separator'] : '';

		// Only accept single-character separators, and never accept a decimal
		// that equals the thousands separator (would be ambiguous).
		$decimal   = 1 === strlen( $decimal ) ? $decimal : '';
		$thousands = 1 === strlen( $thousands ) ? $thousands : '';
		if ( '' !== $decimal && $decimal === $thousands ) {
			$decimal = '';
		}

		return [
			'decimal'   => $decimal,
			'thousands' => $thousands,
		];
	}

	/**
	 * The site's configured Charitable currency, used as the default for CSV
	 * rows (Charitable's export has no currency column). USD when unknown.
	 *
	 * @return string Three-letter currency code.
	 * @since  1.5.1
	 */
	private function get_charitable_currency() {
		$settings = get_option( 'charitable_settings', [] );
		$currency = is_array( $settings ) && isset( $settings['currency'] ) ? (string) $settings['currency'] : '';
		$currency = strtoupper( sanitize_text_field( $currency ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';
	}

	/**
	 * Loose truthiness for CSV cells ("1", "yes", "true", "y").
	 *
	 * @param  string $value Raw cell value.
	 * @return bool
	 * @since  1.5.1
	 */
	private function truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'yes', 'true', 'y' ], true );
	}

	/**
	 * Append a row error, capping the log at 50 entries.
	 *
	 * @param  array{imported: int, skipped: int, errors: int, error_log: array<int, array{row: int, message: string}>, gateway_breakdown: array<string, int>} $results    Results accumulator (by reference).
	 * @param  int                                                                                                                                             $row_number Row number.
	 * @param  string                                                                                                                                          $message    Error message.
	 * @return void
	 * @since  1.5.1
	 */
	private function log_row_error( &$results, $row_number, $message ) {
		if ( count( $results['error_log'] ) >= 50 ) {
			return;
		}
		$results['error_log'][] = [
			'row'     => (int) $row_number,
			'message' => (string) $message,
		];
	}

	/**
	 * Charitable campaign title => Charitable campaign id, from the SD campaigns the
	 * campaign phase created (which store the source id in post meta). Lets a CSV row
	 * whose Campaign ID cell is blank key on the same numeric id the DB path wrote,
	 * instead of a title hash the two paths can never share. Memoized per import.
	 *
	 * @var array<string,int>|null
	 * @since 1.5.1
	 */
	private $campaign_title_to_source_id = null;

	/**
	 * Resolve a Charitable campaign title to its Charitable campaign id.
	 *
	 * @param  string $title Campaign title from the CSV row.
	 * @return int Charitable campaign id, or 0 when no single imported campaign matches.
	 * @since  1.5.1
	 */
	private function resolve_source_campaign_id_from_title( $title ) {
		$title = strtolower( trim( (string) $title ) );

		if ( '' === $title ) {
			return 0;
		}

		if ( null === $this->campaign_title_to_source_id ) {
			$this->campaign_title_to_source_id = $this->build_campaign_title_map();
		}

		return isset( $this->campaign_title_to_source_id[ $title ] ) ? $this->campaign_title_to_source_id[ $title ] : 0;
	}

	/**
	 * Build the campaign-title => Charitable-source-id map from imported SD campaigns.
	 *
	 * A title that maps to more than one distinct source id is ambiguous and left out,
	 * so those rows fall back to the title hash rather than resolve to the wrong id.
	 *
	 * @return array<string,int>
	 * @since  1.5.1
	 */
	private function build_campaign_title_map() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scope; one lookup per import.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_title AS title, pm.meta_value AS source_id FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = %s AND pm.meta_value > 0",
				Campaign_Mapper::META_SOURCE_ID
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$map       = [];
		$ambiguous = [];

		foreach ( $rows as $row ) {
			$title = strtolower( trim( (string) ( $row['title'] ?? '' ) ) );
			$sid   = absint( $row['source_id'] ?? 0 );

			if ( '' === $title || $sid <= 0 ) {
				continue;
			}

			if ( isset( $map[ $title ] ) && $map[ $title ] !== $sid ) {
				$ambiguous[ $title ] = true;
			} elseif ( ! isset( $map[ $title ] ) ) {
				$map[ $title ] = $sid;
			}
		}

		foreach ( array_keys( $ambiguous ) as $ambiguous_title ) {
			unset( $map[ $ambiguous_title ] );
		}

		return $map;
	}
}
