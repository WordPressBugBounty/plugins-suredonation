<?php
/**
 * Column mapping for the own-data CSV importer.
 *
 * Defines the importable fields per entity (donations, donors), their header
 * aliases, and the auto-mapping that matches a CSV's header row to those
 * fields. The aliases include the exact labels SureDonation's own export
 * produces, so an exported file round-trips with a 100% auto-map.
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
 * Column map / auto-mapping helper.
 *
 * @since 1.3.0
 */
class Column_Map {

	/**
	 * Importable donation fields. Each: label, aliases (lowercased header
	 * text this field auto-matches), and whether it is required.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.3.0
	 */
	public static function donation_fields() {
		return [
			'import_source_id'       => [
				'label'    => __( 'Donation ID', 'suredonation' ),
				'aliases'  => [ 'donation id', 'donation_id', 'id' ],
				'required' => false,
			],
			'donor_email'            => [
				'label'    => __( 'Donor Email', 'suredonation' ),
				'aliases'  => [ 'donor email', 'email', 'email address', 'donor_email' ],
				'required' => true,
			],
			'amount'                 => [
				'label'    => __( 'Amount', 'suredonation' ),
				'aliases'  => [ 'amount', 'donation amount', 'donation total', 'total' ],
				'required' => true,
			],
			'donor_name'             => [
				'label'    => __( 'Donor Name', 'suredonation' ),
				'aliases'  => [ 'donor name', 'name', 'full name', 'donor_name' ],
				'required' => false,
			],
			'first_name'             => [
				'label'    => __( 'First Name', 'suredonation' ),
				'aliases'  => [ 'first name', 'first_name' ],
				'required' => false,
			],
			'last_name'              => [
				'label'    => __( 'Last Name', 'suredonation' ),
				'aliases'  => [ 'last name', 'last_name' ],
				'required' => false,
			],
			'donor_phone'            => [
				'label'    => __( 'Donor Phone', 'suredonation' ),
				'aliases'  => [ 'donor phone', 'phone', 'phone number', 'donor_phone' ],
				'required' => false,
			],
			'company'                => [
				'label'    => __( 'Company', 'suredonation' ),
				'aliases'  => [ 'company', 'company name' ],
				'required' => false,
			],
			'address'                => [
				'label'    => __( 'Address', 'suredonation' ),
				'aliases'  => [ 'address' ],
				'required' => false,
			],
			'campaign'               => [
				'label'    => __( 'Campaign', 'suredonation' ),
				'aliases'  => [ 'campaign', 'campaign name', 'campaign title', 'campaign id', 'campaign_id' ],
				'required' => false,
			],
			'form'                   => [
				'label'    => __( 'Form', 'suredonation' ),
				'aliases'  => [ 'form', 'form title', 'form id', 'form_id' ],
				'required' => false,
			],
			'currency'               => [
				'label'    => __( 'Currency', 'suredonation' ),
				'aliases'  => [ 'currency', 'currency code' ],
				'required' => false,
			],
			'gateway'                => [
				'label'    => __( 'Gateway', 'suredonation' ),
				'aliases'  => [ 'gateway', 'payment gateway', 'payment method', 'method' ],
				'required' => false,
			],
			'payment_status'         => [
				'label'    => __( 'Payment Status', 'suredonation' ),
				'aliases'  => [ 'payment status', 'status', 'donation status' ],
				'required' => false,
			],
			'payment_mode'           => [
				'label'    => __( 'Payment Mode', 'suredonation' ),
				'aliases'  => [ 'payment mode', 'mode', 'test mode' ],
				'required' => false,
			],
			'transaction_id'         => [
				'label'    => __( 'Transaction ID', 'suredonation' ),
				'aliases'  => [ 'transaction id', 'transaction_id' ],
				'required' => false,
			],
			'fees_covered'           => [
				'label'    => __( 'Fees Covered', 'suredonation' ),
				'aliases'  => [ 'fees covered', 'fees_covered', 'fee covered' ],
				'required' => false,
			],
			'refunded_amount'        => [
				'label'    => __( 'Refunded Amount', 'suredonation' ),
				'aliases'  => [ 'refunded amount', 'refunded_amount' ],
				'required' => false,
			],
			'is_anonymous'           => [
				'label'    => __( 'Anonymous', 'suredonation' ),
				'aliases'  => [ 'anonymous', 'is anonymous', 'is_anonymous' ],
				'required' => false,
			],
			'donation_type'          => [
				'label'    => __( 'Donation Type', 'suredonation' ),
				'aliases'  => [ 'donation type', 'type', 'donation_type' ],
				'required' => false,
			],
			'subscription_id'        => [
				'label'    => __( 'Subscription ID', 'suredonation' ),
				'aliases'  => [ 'subscription id', 'subscription_id' ],
				'required' => false,
			],
			'subscription_status'    => [
				'label'    => __( 'Subscription Status', 'suredonation' ),
				'aliases'  => [ 'subscription status', 'subscription_status' ],
				'required' => false,
			],
			'parent_subscription_id' => [
				'label'    => __( 'Parent Subscription ID', 'suredonation' ),
				'aliases'  => [ 'parent subscription id', 'parent_subscription_id' ],
				'required' => false,
			],
			'donor_comment'          => [
				'label'    => __( 'Donor Comment', 'suredonation' ),
				'aliases'  => [ 'donor comment', 'comment', 'donor_comment' ],
				'required' => false,
			],
			'ip_address'             => [
				'label'    => __( 'IP Address', 'suredonation' ),
				'aliases'  => [ 'ip address', 'ip', 'ip_address' ],
				'required' => false,
			],
			'donation_date'          => [
				'label'    => __( 'Date', 'suredonation' ),
				'aliases'  => [ 'date', 'donation date', 'created at', 'created_at', 'donation_date' ],
				'required' => false,
			],
		];
	}

	/**
	 * Importable donor fields.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.3.0
	 */
	public static function donor_fields() {
		return [
			'import_source_id'    => [
				'label'    => __( 'Donor ID', 'suredonation' ),
				'aliases'  => [ 'donor id', 'donor_id', 'id' ],
				'required' => false,
			],
			'email'               => [
				'label'    => __( 'Email', 'suredonation' ),
				'aliases'  => [ 'email', 'donor email', 'email address' ],
				'required' => true,
			],
			'name'                => [
				'label'    => __( 'Name', 'suredonation' ),
				'aliases'  => [ 'name', 'donor name', 'full name' ],
				'required' => false,
			],
			'phone'               => [
				'label'    => __( 'Phone', 'suredonation' ),
				'aliases'  => [ 'phone', 'phone number', 'donor phone' ],
				'required' => false,
			],
			'company'             => [
				'label'    => __( 'Company', 'suredonation' ),
				'aliases'  => [ 'company', 'company name' ],
				'required' => false,
			],
			'address'             => [
				'label'    => __( 'Address', 'suredonation' ),
				'aliases'  => [ 'address' ],
				'required' => false,
			],
			'donor_status'        => [
				'label'    => __( 'Status', 'suredonation' ),
				'aliases'  => [ 'status', 'donor status' ],
				'required' => false,
			],
			'donor_tags'          => [
				'label'    => __( 'Tags', 'suredonation' ),
				'aliases'  => [ 'tags', 'donor tags' ],
				'required' => false,
			],
			'total_donated'       => [
				'label'    => __( 'Total Donated', 'suredonation' ),
				'aliases'  => [ 'total donated', 'total_donated' ],
				'required' => false,
			],
			'donation_count'      => [
				'label'    => __( 'Donation Count', 'suredonation' ),
				'aliases'  => [ 'donation count', 'donation_count', 'number of donations' ],
				'required' => false,
			],
			'largest_donation'    => [
				'label'    => __( 'Largest Donation', 'suredonation' ),
				'aliases'  => [ 'largest donation', 'largest_donation' ],
				'required' => false,
			],
			'first_donation_date' => [
				'label'    => __( 'First Donation', 'suredonation' ),
				'aliases'  => [ 'first donation', 'first donation date', 'first_donation_date' ],
				'required' => false,
			],
			'last_donation_date'  => [
				'label'    => __( 'Last Donation', 'suredonation' ),
				'aliases'  => [ 'last donation', 'last donation date', 'last_donation_date' ],
				'required' => false,
			],
			'created_at'          => [
				'label'    => __( 'Created At', 'suredonation' ),
				'aliases'  => [ 'created at', 'created_at' ],
				'required' => false,
			],
		];
	}

	/**
	 * Get the field definitions for an entity.
	 *
	 * @param string $entity 'donations' or 'donors'.
	 * @return array<string, array<string, mixed>> Field definitions.
	 * @since 1.3.0
	 */
	public static function fields_for( $entity ) {
		return 'donors' === $entity ? self::donor_fields() : self::donation_fields();
	}

	/**
	 * Auto-map a CSV header row to entity fields.
	 *
	 * Case-insensitive exact match of each (trimmed) header against the field
	 * aliases; each field is auto-assigned to at most one header (first match
	 * wins), so duplicate-labelled columns don't collide.
	 *
	 * @param array<int, string> $headers CSV header cells.
	 * @param string             $entity  'donations' or 'donors'.
	 * @return array<int, string> Map of header index => field key ('' = unmapped).
	 * @since 1.3.0
	 */
	public static function auto_map( $headers, $entity ) {
		$fields = self::fields_for( $entity );
		$used   = [];
		$map    = [];

		foreach ( $headers as $index => $header ) {
			$needle        = strtolower( trim( (string) $header ) );
			$map[ $index ] = '';

			if ( '' === $needle ) {
				continue;
			}

			foreach ( $fields as $field => $def ) {
				if ( isset( $used[ $field ] ) ) {
					continue;
				}
				if ( in_array( $needle, self::aliases_for( $def ), true ) ) {
					$map[ $index ]  = $field;
					$used[ $field ] = true;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * The header text a field auto-matches: its declared (English) aliases plus
	 * its own localized label.
	 *
	 * The exporters write translated labels as the header row, so on a non-English
	 * site the site's own export would not match the English aliases at all. The
	 * label is therefore an implicit alias, which keeps the round-trip working in
	 * every locale while still accepting the English headers of a hand-made file.
	 *
	 * @param array<string, mixed> $def Field definition.
	 * @return array<int, string> Lowercased header text this field matches.
	 * @since 1.3.0
	 */
	private static function aliases_for( $def ) {
		$aliases = isset( $def['aliases'] ) && is_array( $def['aliases'] ) ? $def['aliases'] : [];
		$label   = strtolower( trim( Helper::get_string_value( $def['label'] ?? '' ) ) );

		if ( '' !== $label && ! in_array( $label, $aliases, true ) ) {
			$aliases[] = $label;
		}

		return $aliases;
	}

	/**
	 * Header text that signals an entity, collected from the given fields'
	 * aliases and localized labels.
	 *
	 * @param string            $entity 'donations' or 'donors'.
	 * @param array<int,string> $keys   Field keys that only that entity exports.
	 * @return array<int, string> Lowercased signal header text.
	 * @since 1.3.0
	 */
	private static function signals_for( $entity, $keys ) {
		$fields  = self::fields_for( $entity );
		$signals = [];

		foreach ( $keys as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$signals = array_merge( $signals, self::aliases_for( $fields[ $key ] ) );
			}
		}

		return array_values( array_unique( $signals ) );
	}

	/**
	 * Guess whether a header row describes donations or donors.
	 *
	 * Scores the headers against each entity's donor-only / donation-only
	 * signal columns; the higher score wins, defaulting to donations on a tie.
	 * Signals are derived from the field definitions so they carry the localized
	 * labels too — otherwise a translated export scores zero on both sides.
	 *
	 * @param array<int, string> $headers CSV header cells.
	 * @return string 'donations' or 'donors'.
	 * @since 1.3.0
	 */
	public static function detect_entity( $headers ) {
		$normalized = array_map(
			static function ( $header ) {
				return strtolower( trim( (string) $header ) );
			},
			$headers
		);

		$donation_signals = self::signals_for( 'donations', [ 'amount', 'payment_status', 'transaction_id', 'gateway' ] );
		$donor_signals    = self::signals_for( 'donors', [ 'total_donated', 'donation_count', 'largest_donation' ] );

		$donation_score = count( array_intersect( $normalized, $donation_signals ) );
		$donor_score    = count( array_intersect( $normalized, $donor_signals ) );

		return $donor_score > $donation_score ? 'donors' : 'donations';
	}

	/**
	 * Apply a column mapping to a raw CSV row, producing a field => value map.
	 *
	 * @param array<int, string>       $row     Raw cell values (column order).
	 * @param array<int|string, mixed> $mapping Header index => field key.
	 * @return array<string, string> Field => value (unmapped columns dropped).
	 * @since 1.3.0
	 */
	public static function apply_row( $row, $mapping ) {
		$data = [];
		if ( ! is_array( $mapping ) ) {
			return $data;
		}
		foreach ( $mapping as $index => $field ) {
			$field_key = Helper::get_string_value( $field );
			if ( '' === $field_key ) {
				continue;
			}
			$data[ $field_key ] = isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
		}
		return $data;
	}

	/**
	 * The fixed set of standard column labels a SureDonation donations export
	 * emits, in order, before any custom form-field columns.
	 *
	 * Single source of truth: the exporter uses this for its header row, and the
	 * importer treats any column NOT in this set as a submitted custom field. The
	 * order here must match the value order the exporter writes per row.
	 *
	 * @return array<int, string> Column labels.
	 * @since 1.3.0
	 */
	public static function standard_donation_export_labels() {
		return [
			__( 'Donation ID', 'suredonation' ),
			__( 'Campaign ID', 'suredonation' ),
			__( 'Campaign Name', 'suredonation' ),
			__( 'Form ID', 'suredonation' ),
			__( 'Form Title', 'suredonation' ),
			__( 'Donor ID', 'suredonation' ),
			__( 'Donor Name', 'suredonation' ),
			__( 'Donor Email', 'suredonation' ),
			__( 'Donor Phone', 'suredonation' ),
			__( 'Amount', 'suredonation' ),
			__( 'Fees Covered', 'suredonation' ),
			__( 'Refunded Amount', 'suredonation' ),
			__( 'Currency', 'suredonation' ),
			__( 'Gateway', 'suredonation' ),
			__( 'Payment Status', 'suredonation' ),
			__( 'Payment Mode', 'suredonation' ),
			__( 'Transaction ID', 'suredonation' ),
			__( 'Donation Type', 'suredonation' ),
			__( 'Subscription ID', 'suredonation' ),
			__( 'Subscription Status', 'suredonation' ),
			__( 'Parent Subscription ID', 'suredonation' ),
			__( 'Anonymous', 'suredonation' ),
			__( 'Donor Comment', 'suredonation' ),
			__( 'IP Address', 'suredonation' ),
			__( 'Date', 'suredonation' ),
			__( 'Import Source', 'suredonation' ),
			__( 'Import Source ID', 'suredonation' ),
		];
	}

	/**
	 * Pull submitted custom form-field values out of a donation row: any column
	 * whose header is not a standard export column is treated as a custom field
	 * (these are the per-form fields flattened into trailing export columns).
	 *
	 * @param array<int, string> $headers CSV header cells.
	 * @param array<int, string> $row     Raw row cells (column order).
	 * @return array<string, array{label: string, value: string}> Custom fields keyed by slug.
	 * @since 1.3.0
	 */
	public static function extract_custom_fields( $headers, $row ) {
		$standard = array_map(
			static function ( $label ) {
				return strtolower( trim( (string) $label ) );
			},
			self::standard_donation_export_labels()
		);

		$fields = [];
		foreach ( $headers as $index => $header ) {
			$label = trim( (string) $header );
			if ( '' === $label || in_array( strtolower( $label ), $standard, true ) ) {
				continue;
			}
			$value = isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
			if ( '' === $value ) {
				continue;
			}
			$key = sanitize_key( $label );
			if ( '' === $key ) {
				$key = 'field_' . (int) $index;
			}
			$fields[ $key ] = [
				'label' => sanitize_text_field( $label ),
				'value' => sanitize_text_field( $value ),
			];
		}
		return $fields;
	}

	/**
	 * Field keys that are required but missing from a mapping.
	 *
	 * @param array<int, string> $mapping Header index => field key.
	 * @param string             $entity  'donations' or 'donors'.
	 * @return array<int, string> Missing required field labels.
	 * @since 1.3.0
	 */
	public static function missing_required( $mapping, $entity ) {
		$fields  = self::fields_for( $entity );
		$mapped  = array_values( array_filter( $mapping ) );
		$missing = [];

		foreach ( $fields as $field => $def ) {
			if ( ! empty( $def['required'] ) && ! in_array( $field, $mapped, true ) ) {
				$missing[] = Helper::get_string_value( $def['label'] );
			}
		}

		return $missing;
	}
}
