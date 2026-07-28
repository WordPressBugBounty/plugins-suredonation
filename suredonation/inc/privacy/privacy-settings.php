<?php
/**
 * Privacy Settings
 *
 * Single source of truth for the SureDonation Privacy settings (Global Settings →
 * Privacy): the option key, defaults, and the configurable option lists. Shared by
 * the settings REST API, the donation-form renderer, and (in a later phase) the
 * WordPress personal-data export/erase integration, so the schema is defined once.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Privacy;

use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Privacy_Settings class.
 *
 * @since 1.2.0
 */
class Privacy_Settings {
	/**
	 * Key within the consolidated suredonation_options array that stores the
	 * Privacy settings.
	 *
	 * @since 1.2.0
	 */
	public const OPTION_KEY = 'privacy_settings';

	/**
	 * Default Privacy settings.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>
	 */
	public static function get_defaults() {
		return [
			// Data retention (consumed by the export/erase integration). 'none',
			// a number of years '1'..'10', or 'forever'.
			'minimum_data_retention_period' => 'none',

			// Contact-consent checkbox on the donation form.
			'contact_consent_field'         => false,
			'contact_consent_required'      => false,
			'contact_consent_label'         => __( 'I consent to being contacted.', 'suredonation' ),

			// Privacy policy statement on the donation form.
			'privacy_policy_field'          => false,
			'privacy_policy_text'           => __( 'Your personal data will be used to process your donation. Read our [privacy_policy].', 'suredonation' ),
			// Page assigned for the [privacy_policy] link. Falls back to WP core's
			// wp_page_for_privacy_policy when unset (0).
			'privacy_page_id'               => 0,

			// Terms & conditions statement on the donation form.
			'terms_conditions_field'        => false,
			'terms_conditions_text'         => __( 'By donating you agree to our [terms].', 'suredonation' ),
			// Page assigned for the [terms] link ([privacy_policy] uses WP core's
			// wp_page_for_privacy_policy).
			'terms_page_id'                 => 0,
		];
	}

	/**
	 * Resolve the stored Privacy settings merged over the defaults.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$stored = Helper::get_suredonation_option( self::OPTION_KEY, [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::get_defaults() );
	}

	/**
	 * Options for the "Minimum Data Retention Period" select.
	 *
	 * @since 1.2.0
	 * @return array<int|string, string> Map of value => label (numeric-string keys become int keys in PHP).
	 */
	public static function get_retention_period_options() {
		$options = [
			'none' => __( 'None', 'suredonation' ),
		];

		for ( $years = 1; $years <= 10; $years++ ) {
			$options[ (string) $years ] = sprintf(
				/* translators: %d: number of years. */
				_n( '%d year', '%d years', $years, 'suredonation' ),
				$years
			);
		}

		$options['forever'] = __( 'Forever', 'suredonation' );

		return $options;
	}

	/**
	 * Sanitize a raw Privacy settings payload against the schema.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $raw Raw values (e.g. from a REST request).
	 * @return array<string, mixed> Sanitized settings (only known keys).
	 */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];

		$retention   = isset( $raw['minimum_data_retention_period'] ) ? sanitize_text_field( Helper::get_string_value( $raw['minimum_data_retention_period'] ) ) : 'none';
		$valid_terms = array_map( 'strval', array_keys( self::get_retention_period_options() ) );
		if ( ! in_array( $retention, $valid_terms, true ) ) {
			$retention = 'none';
		}

		return [
			'minimum_data_retention_period' => $retention,
			'contact_consent_field'         => ! empty( $raw['contact_consent_field'] ),
			'contact_consent_required'      => ! empty( $raw['contact_consent_required'] ),
			'contact_consent_label'         => isset( $raw['contact_consent_label'] ) ? sanitize_text_field( Helper::get_string_value( $raw['contact_consent_label'] ) ) : '',
			'privacy_policy_field'          => ! empty( $raw['privacy_policy_field'] ),
			'privacy_policy_text'           => isset( $raw['privacy_policy_text'] ) ? wp_kses_post( Helper::get_string_value( $raw['privacy_policy_text'] ) ) : '',
			'privacy_page_id'               => isset( $raw['privacy_page_id'] ) ? absint( Helper::get_string_value( $raw['privacy_page_id'] ) ) : 0,
			'terms_conditions_field'        => ! empty( $raw['terms_conditions_field'] ),
			'terms_conditions_text'         => isset( $raw['terms_conditions_text'] ) ? wp_kses_post( Helper::get_string_value( $raw['terms_conditions_text'] ) ) : '',
			'terms_page_id'                 => isset( $raw['terms_page_id'] ) ? absint( Helper::get_string_value( $raw['terms_page_id'] ) ) : 0,
		];
	}

	/**
	 * Whether a donation dated $created_at may have its personal data erased,
	 * per the configured Minimum Data Retention Period.
	 *
	 * - 'none'    → always erasable.
	 * - 'forever' → never erasable.
	 * - '1'..'10' → erasable only once older than that many years.
	 *
	 * @since 1.2.0
	 * @param string $created_at Donation created-at timestamp (MySQL datetime).
	 * @return bool True when the donation's personal data may be erased.
	 */
	public static function is_donation_erasable( $created_at ) {
		$settings = self::get_settings();
		$period   = isset( $settings['minimum_data_retention_period'] ) ? Helper::get_string_value( $settings['minimum_data_retention_period'] ) : 'none';

		if ( 'none' === $period ) {
			return true;
		}

		if ( 'forever' === $period ) {
			return false;
		}

		$years = (int) $period;
		if ( $years <= 0 ) {
			// Unexpected/garbage period with a numeric window configured — fail
			// closed (retain) rather than erase data that may be legally retained.
			return false;
		}

		// created_at is stored in GMT (Donations::add() uses current_time( 'mysql', true )),
		// and WordPress runs PHP with the UTC timezone, so strtotime() parses it as UTC —
		// comparable against time() directly. No conversion: get_gmt_from_date() would treat
		// it as site-local and shift the retention boundary by the site's UTC offset.
		$created_ts = strtotime( (string) $created_at );
		if ( false === $created_ts ) {
			// Unparseable date — fail closed (retain) while a retention window is set.
			return false;
		}

		// Erasable once the donation is older than the retention window.
		return $created_ts < time() - ( $years * YEAR_IN_SECONDS );
	}
}
