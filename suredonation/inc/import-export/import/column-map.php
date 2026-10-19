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

use SureDonation\Inc\Field_Validation;
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
			// Recognised so a file this plugin exported round-trips with each
			// donation's own valuation intact. Absent, Donations::add() stamps
			// today's rate, which restates history.
			'exchange_rate'          => [
				'label'    => __( 'Exchange Rate', 'suredonation' ),
				// No bare 'rate': an unrelated Rate column in a third-party
				// export would otherwise be read as an exchange rate.
				'aliases'  => [ 'exchange rate', 'exchange_rate' ],
				'required' => false,
			],
			'base_currency'          => [
				'label'    => __( 'Base Currency', 'suredonation' ),
				'aliases'  => [ 'base currency', 'base_currency' ],
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
			'donor_comment_status'   => [
				'label'    => __( 'Comment Status', 'suredonation' ),
				'aliases'  => [ 'comment status', 'donor comment status', 'donor_comment_status' ],
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
			// Exported so a round trip keeps a donation's own valuation. Without
			// them a re-import re-prices every historical row at today's rate.
			// Position matters: this list and the exporter's row array are read
			// in lockstep.
			__( 'Exchange Rate', 'suredonation' ),
			__( 'Base Currency', 'suredonation' ),
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
			__( 'Comment Status', 'suredonation' ),
			__( 'IP Address', 'suredonation' ),
			__( 'Date', 'suredonation' ),
			__( 'Import Source', 'suredonation' ),
			__( 'Import Source ID', 'suredonation' ),
		];
	}

	/**
	 * Length cap for a slug or group read out of an imported CSV.
	 *
	 * Mirrors the cap a live submission is held to in
	 * Payment_Helper::get_submitted_field_data(), so an uploaded file cannot
	 * write unbounded array keys into donation_data.
	 *
	 * @since 1.7.0
	 */
	private const MAX_FIELD_SLUG_LENGTH = 255;

	/**
	 * Entry cap for the "Other Fields" overflow object on import.
	 *
	 * Matches the per-submission field cap in
	 * Payment_Helper::get_submitted_field_data().
	 *
	 * @since 1.7.0
	 */
	private const MAX_OVERFLOW_FIELDS = 50;

	/**
	 * The trailing export column carrying each custom field's stored slug.
	 *
	 * The other custom-field columns are headed by the field's LABEL, which is
	 * all a human needs but is not what the value is stored under: submissions
	 * key donation_data['fields'] by the block slug. Without this column an
	 * importer can only guess the slug from the label, which breaks as soon as
	 * the label is renamed, and cannot work at all for a cross-site import.
	 *
	 * Written as a JSON object of label => { slug, group }, so the values stay
	 * in their own readable columns and a consumer that ignores this column is
	 * unaffected.
	 *
	 * Deliberately untranslated for the same reason as the overflow column
	 * below: it is written on export and matched on import, so a site locale
	 * change between the two must not stop it matching.
	 *
	 * @return string Column label.
	 * @since 1.7.0
	 */
	public static function field_slugs_export_label() {
		return 'Field Slugs';
	}

	/**
	 * The trailing export column a donation's custom fields spill into once
	 * they exceed the exporter's per-export column cap.
	 *
	 * Single source of truth shared with Import_Export_Api::export_donations(),
	 * which writes this same label as a plain JSON object of label => value.
	 * Kept separate from standard_donation_export_labels() (which the importer
	 * treats as columns to skip entirely) because this one instead needs its
	 * own value decoded and expanded — see extract_custom_fields().
	 *
	 * Deliberately untranslated: this same string is written on export and
	 * matched on import (extract_custom_fields()), so an export made under one
	 * site locale would fail to match on import under another, silently
	 * storing the JSON blob as one literal field instead of expanding it —
	 * the exact corruption this column exists to prevent. Interchange data,
	 * not display copy, same reasoning as the anonymous yes/no export tokens.
	 *
	 * @return string Column label.
	 * @since 1.6.1
	 */
	public static function other_fields_export_label() {
		return 'Other Fields';
	}

	/**
	 * Pull submitted custom form-field values out of a donation row: any column
	 * whose header is not a standard export column is treated as a custom field
	 * (these are the per-form fields flattened into trailing export columns).
	 *
	 * The "Other Fields" column is the one exception: it is itself a JSON object
	 * of label => value for whatever didn't fit in the export's own column cap,
	 * so its cell is decoded and expanded into individual fields rather than
	 * stored verbatim as one field literally named "Other Fields" — otherwise a
	 * re-import of a capped export would silently corrupt donation_data with a
	 * single bogus field holding a raw JSON blob.
	 *
	 * KEYS MUST MATCH THE SUBMISSION PATH. A form submission keys these by the
	 * block slug (Payment_Helper::get_submitted_field_data()), which is what the
	 * {field_<slug>} smart tag and the entry screen look them up by. The CSV only
	 * carries labels, so the slug is resolved in this order:
	 *
	 *   1. The "Field Slugs" column the exporter writes — exact, survives a
	 *      label rename, and the only option that works cross-site.
	 *   2. A case-insensitive label match against the target form's own blocks.
	 *      Correct until someone renames a field after the export was taken.
	 *   3. sanitize_key( label ), the pre-1.x behaviour, for a third-party CSV
	 *      that carries neither. Deliberately unchanged so those imports behave
	 *      exactly as they did before.
	 *
	 * Applies to keys derived at import time only; rows imported earlier keep
	 * the key they were stored with.
	 *
	 * @param array<int, string> $headers CSV header cells.
	 * @param array<int, string> $row     Raw row cells (column order).
	 * @param int                $form_id Target form, for step 2. 0 to skip it.
	 * @return array<string, array{label: string, value: string, group: string}> Custom fields keyed by slug.
	 * @since 1.3.0
	 */
	public static function extract_custom_fields( $headers, $row, $form_id = 0 ) {
		$standard = array_map(
			static function ( $label ) {
				return strtolower( trim( (string) $label ) );
			},
			self::standard_donation_export_labels()
		);

		$other_fields_label = strtolower( trim( self::other_fields_export_label() ) );
		$field_slugs_label  = strtolower( trim( self::field_slugs_export_label() ) );

		// Step 1 source: label => { slug, group }, written by the exporter.
		$slug_meta = self::decode_field_slug_meta( $headers, $row, $field_slugs_label );

		// Step 2 source: the form's own label => slug map, lower-cased for a
		// case-insensitive match (the standard columns above are compared the
		// same way).
		// Slugs whose values live in their own donation column; the submission
		// path drops them from the custom-field set, so import must too.
		$core_slugs = ( is_int( $form_id ) && $form_id > 0 )
			? Field_Validation::get_core_field_slugs( $form_id )
			: [];

		$label_to_slug = [];
		if ( is_int( $form_id ) && $form_id > 0 ) {
			foreach ( Field_Validation::get_field_labels_map( $form_id ) as $slug => $slug_label ) {
				$slug_label = strtolower( trim( $slug_label ) );
				if ( '' !== $slug_label && ! isset( $label_to_slug[ $slug_label ] ) ) {
					$label_to_slug[ $slug_label ] = $slug;
				}
			}
		}

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

			// Metadata, not a field of its own.
			if ( strtolower( $label ) === $field_slugs_label ) {
				continue;
			}

			if ( strtolower( $label ) === $other_fields_label ) {
				$overflow = json_decode( $value, true );
				if ( is_array( $overflow ) ) {
					// Bounded like the submission path: this object comes from
					// an uploaded file, and one crafted cell could otherwise add
					// tens of thousands of entries to a single donation_data.
					$overflow = array_slice( $overflow, 0, self::MAX_OVERFLOW_FIELDS, true );

					foreach ( $overflow as $overflow_label => $overflow_value ) {
						$overflow_label = trim( (string) $overflow_label );
						if ( '' === $overflow_label || ! is_scalar( $overflow_value ) ) {
							continue;
						}
						// Same resolution as a regular column: the two must
						// agree or one row keys the same field two ways.
						$overflow_resolved = self::resolve_field_slug(
							$overflow_label,
							$slug_meta,
							$label_to_slug,
							$core_slugs
						);
						if ( null === $overflow_resolved ) {
							continue;
						}
						[ $overflow_key, $overflow_group ] = $overflow_resolved;
						if ( '' === $overflow_key ) {
							continue;
						}
						$fields[ $overflow_key ] = [
							'label' => sanitize_text_field( $overflow_label ),
							'value' => sanitize_text_field( (string) $overflow_value ),
							'group' => $overflow_group,
						];
					}
				}
				continue;
			}

			$resolved = self::resolve_field_slug( $label, $slug_meta, $label_to_slug, $core_slugs );
			if ( null === $resolved ) {
				continue;
			}
			[ $key, $group ] = $resolved;
			if ( '' === $key ) {
				$key = 'field_' . (int) $index;
			}
			$fields[ $key ] = [
				'label' => sanitize_text_field( $label ),
				'value' => sanitize_text_field( $value ),
				// Parent block label for a sub-field (e.g. "Address"), so an
				// imported donation matches the shape a submitted one has.
				'group' => $group,
			];
		}
		return $fields;
	}

	/**
	 * Decode the exporter's "Field Slugs" column for one row.
	 *
	 * @param array<int, string> $headers            CSV header cells.
	 * @param array<int, string> $row                Raw row cells.
	 * @param string             $field_slugs_label  Lower-cased column label to find.
	 * @return array<string, array{slug: string, group: string}> Metadata keyed by label, [] when absent.
	 * @since 1.7.0
	 */
	private static function decode_field_slug_meta( $headers, $row, $field_slugs_label ) {
		foreach ( $headers as $index => $header ) {
			if ( strtolower( trim( (string) $header ) ) !== $field_slugs_label ) {
				continue;
			}

			$cell = isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
			if ( '' === $cell ) {
				return [];
			}

			$decoded = json_decode( $cell, true );
			return is_array( $decoded ) ? $decoded : [];
		}

		return [];
	}

	/**
	 * Resolve one export column's label to the slug its value is stored under.
	 *
	 * See extract_custom_fields() for why the order matters. Returns the slug and
	 * the field's group; the group is only ever known from step 1, since a form's
	 * label map does not carry it.
	 *
	 * @param string                                            $label         Column label.
	 * @param array<string, array{slug: string, group: string}> $slug_meta     Decoded "Field Slugs" map.
	 * @param array<string, string>                             $label_to_slug Lower-cased label => slug from the form.
	 * @param array<int, string>                                $core_slugs    Slugs owned by a core donation column.
	 * @return array{0: string, 1: string}|null Slug and group, or null to drop the column.
	 * @since 1.7.0
	 */
	private static function resolve_field_slug( $label, $slug_meta, $label_to_slug, $core_slugs = [] ) {
		$slug  = '';
		$group = '';

		// 1. Exact, from the export itself. The cell is JSON from an uploaded
		// file, so each part is type-checked before use and capped to the same
		// lengths a live submission is (Payment_Helper::get_submitted_field_data).
		// Casting blindly would emit "Array to string conversion" mid-import on
		// a crafted cell, which WP_DEBUG_DISPLAY would push into the REST body
		// and break the importer's JSON response.
		if ( isset( $slug_meta[ $label ] ) && is_array( $slug_meta[ $label ] ) ) {
			$raw_slug  = $slug_meta[ $label ]['slug'] ?? '';
			$raw_group = $slug_meta[ $label ]['group'] ?? '';

			if ( is_string( $raw_slug ) ) {
				$slug = mb_substr( sanitize_text_field( $raw_slug ), 0, self::MAX_FIELD_SLUG_LENGTH );

				if ( '' !== $slug && is_string( $raw_group ) ) {
					$group = mb_substr( sanitize_text_field( $raw_group ), 0, self::MAX_FIELD_SLUG_LENGTH );
				}
			}
		}

		// 2. The target form's own blocks, matched case-insensitively.
		if ( '' === $slug ) {
			$lookup = strtolower( trim( $label ) );
			if ( isset( $label_to_slug[ $lookup ] ) ) {
				$slug = $label_to_slug[ $lookup ];
			}
		}

		// A slug mapped to a core donation column is dropped on the submission
		// path, because that value lives in its own column. Honouring it here
		// keeps an imported donation from carrying a duplicate of its own email
		// or amount as a "custom" field. Only applied once the form is known —
		// $core_slugs is empty otherwise, and tier 3 below has no form context
		// to justify second-guessing a third-party CSV's columns.
		if ( '' !== $slug && in_array( $slug, $core_slugs, true ) ) {
			return null;
		}

		if ( '' !== $slug ) {
			return [ $slug, $group ];
		}

		// 3. Pre-existing behaviour for a CSV that carries neither.
		return [ sanitize_key( $label ), '' ];
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
