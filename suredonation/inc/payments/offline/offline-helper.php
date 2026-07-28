<?php
/**
 * Offline Helper - Static helpers for offline donation settings
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Payments\Offline;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offline_Helper class
 * Provides offline donation utilities
 *
 * @since 1.0.0
 */
class Offline_Helper {
	/**
	 * Check if offline donations are enabled.
	 *
	 * @return bool True if offline donations are enabled.
	 * @since 1.0.0
	 */
	public static function is_offline_enabled() {
		$settings = self::get_all_offline_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Get offline donation instructions.
	 *
	 * @param string $campaign_name Optional campaign name for smart tag replacement.
	 * @return string Sanitized HTML instructions.
	 * @since 1.0.0
	 */
	public static function get_offline_instructions( $campaign_name = '' ) {
		$settings     = self::get_all_offline_settings();
		$instructions = $settings['instructions'] ?? '';
		$instructions = is_string( $instructions ) ? $instructions : '';

		// Replace smart tags with escaped values.
		$tags = [
			'{campaign_name}' => esc_html( $campaign_name ),
			'{site_title}'    => esc_html( get_bloginfo( 'name' ) ),
			'{site_url}'      => esc_url( home_url() ),
			'{admin_email}'   => esc_html( Helper::get_string_value( get_option( 'admin_email', '' ) ) ),
		];

		// Apply wp_kses_post first to sanitize the HTML template, then replace smart tags.
		// This avoids double-encoding of entities in smart tag values (e.g. "Tom & Jerry").
		$instructions = wp_kses_post( $instructions );
		return str_replace( array_keys( $tags ), array_values( $tags ), $instructions );
	}

	/**
	 * Get all offline donation settings with defaults.
	 *
	 * @return array<string, mixed> Offline settings.
	 * @since 1.0.0
	 */
	public static function get_all_offline_settings() {
		$settings = Payment_Helper::get_gateway_settings( 'offline' );

		$defaults = [
			'enabled'      => false,
			'instructions' => self::get_default_instructions(),
		];

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get default offline donation instructions.
	 *
	 * @return string Default instructions HTML.
	 * @since 1.0.0
	 */
	public static function get_default_instructions() {
		$instructions  = '<p>' . __( 'To make an offline donation toward this cause, follow these steps:', 'suredonation' ) . '</p>';
		$instructions .= '<ol>';
		$instructions .= '<li>' . __( 'Write a check payable to "{site_title}"', 'suredonation' ) . '</li>';
		$instructions .= '<li>' . __( 'On the memo line of the check, indicate that the donation is for "{site_title}"', 'suredonation' ) . '</li>';
		$instructions .= '<li>' . __( 'Mail your check to the address below:', 'suredonation' ) . '</li>';
		$instructions .= '</ol>';
		$instructions .= '<p><em>' . __( 'Your mailing address here', 'suredonation' ) . '</em></p>';
		$instructions .= '<p>' . __( 'Your tax-deductible donation is greatly appreciated!', 'suredonation' ) . '</p>';

		return $instructions;
	}

	/**
	 * Determine whether an instructions value is effectively blank.
	 *
	 * A cleared WYSIWYG editor serializes as empty markup (e.g. <p>&nbsp;</p> or
	 * <p><br></p>) rather than an empty string, so a plain trim() would treat it
	 * as non-blank. Strip tags and non-breaking spaces before checking.
	 *
	 * @param mixed $instructions Raw instructions value.
	 * @return bool True when the value has no meaningful content.
	 * @since 1.1.2
	 */
	public static function is_blank_instructions( $instructions ) {
		if ( ! is_string( $instructions ) ) {
			return true;
		}

		$plain = str_replace( [ '&nbsp;', "\xc2\xa0" ], ' ', $instructions );
		return '' === trim( wp_strip_all_tags( $plain ) );
	}
}
