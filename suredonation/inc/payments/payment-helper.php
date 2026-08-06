<?php
/**
 * Payment Helper - Global payment utilities
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Payments;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Offline\Offline_Helper;
use SureDonation\Inc\Payments\PayPal\PayPal_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment_Helper class
 * Provides gateway-agnostic payment utilities
 *
 * @since 0.0.1
 */
class Payment_Helper {
	/**
	 * Option key for payment settings within consolidated options.
	 *
	 * @since 0.0.1
	 */
	public const OPTION_KEY = 'payment_settings';

	/**
	 * Allowed currency sign positions. Single source of truth for the getter
	 * and every REST write handler that validates the setting.
	 *
	 * @since 1.3.0
	 * @var array<int, string>
	 */
	public const ALLOWED_SIGN_POSITIONS = [ 'auto', 'left', 'right', 'left_space', 'right_space' ];

	/**
	 * Get all payment settings
	 *
	 * @return array<string, mixed> Payment settings.
	 * @since 0.0.1
	 */
	public static function get_all_payment_settings() {
		$options = Helper::get_suredonation_option( self::OPTION_KEY, [] );

		// Ensure default structure.
		$defaults = [
			'currency'               => 'USD',
			'payment_mode'           => 'test', // Valid values: test or live.
			// Currency symbol placement for displayed amounts. 'auto' preserves
			// the historical behavior on every surface (locale-aware in the admin
			// dashboard, symbol-left on donor-facing output); an explicit value
			// overrides it everywhere. See get_currency_sign_position().
			'currency_sign_position' => 'auto',
			'stripe'                 => [],
			// Intentionally no 'instructions' key: leaving it unset lets
			// Offline_Helper::get_all_offline_settings() fill the default template
			// for a never-configured install, while a deliberately-cleared value is
			// stored as '' and preserved. Seeding '' here would make the two
			// indistinguishable and permanently mask the default.
			'offline'                => [
				'enabled' => false,
			],
			'fee_recovery'           => [
				'fee_percentage' => 2.9,
				'fee_fixed'      => 0.30,
				'fee_mode'       => 'all_gateways',
				'gateways'       => [
					'stripe'  => [
						'fee_percentage' => 2.9,
						'fee_fixed'      => 0.30,
						'enabled'        => true,
					],
					'paypal'  => [
						'fee_percentage' => 0,
						'fee_fixed'      => 0,
						'enabled'        => false,
					],
					'offline' => [
						'fee_percentage' => 0,
						'fee_fixed'      => 0,
						'enabled'        => false,
					],
				],
			],
		];

		$options_array = is_array( $options ) ? $options : [];
		return wp_parse_args( $options_array, $defaults );
	}

	/**
	 * Update all payment settings
	 *
	 * @param array<string, mixed> $settings Payment settings.
	 * @return bool True on success.
	 * @since 0.0.1
	 */
	public static function update_all_payment_settings( $settings ) {
		return Helper::update_suredonation_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Get gateway-specific settings
	 *
	 * @param string $gateway Gateway name (e.g., 'stripe').
	 * @return array<string, mixed> Gateway settings.
	 * @since 0.0.1
	 */
	public static function get_gateway_settings( $gateway ) {
		$all_settings     = self::get_all_payment_settings();
		$gateway_settings = $all_settings[ $gateway ] ?? [];
		return is_array( $gateway_settings ) ? $gateway_settings : [];
	}

	/**
	 * Update gateway-specific settings
	 *
	 * @param string               $gateway  Gateway name.
	 * @param array<string, mixed> $settings Gateway settings.
	 * @return bool True on success.
	 * @since 0.0.1
	 */
	public static function update_gateway_settings( $gateway, $settings ) {
		$all_settings             = self::get_all_payment_settings();
		$all_settings[ $gateway ] = $settings;
		return self::update_all_payment_settings( $all_settings );
	}

	/**
	 * Get global payment setting
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Default value.
	 * @return mixed Setting value.
	 * @since 0.0.1
	 */
	public static function get_global_setting( $key, $default_value = '' ) {
		$all_settings = self::get_all_payment_settings();
		return $all_settings[ $key ] ?? $default_value;
	}

	/**
	 * Update global payment setting
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool True on success.
	 * @since 0.0.1
	 */
	public static function update_global_setting( $key, $value ) {
		$all_settings         = self::get_all_payment_settings();
		$all_settings[ $key ] = $value;
		return self::update_all_payment_settings( $all_settings );
	}

	/**
	 * Get current payment mode
	 *
	 * @return string 'test' or 'live'.
	 * @since 0.0.1
	 */
	public static function get_payment_mode() {
		$response = self::get_global_setting( 'payment_mode', 'test' );
		return ! empty( $response ) && is_string( $response ) ? $response : 'test';
	}

	/**
	 * Get the admin URL for the SureDonation payment settings screen.
	 *
	 * Centralizes the (hash-routed) payment-settings URL so every "go to
	 * payment settings" link across the plugin resolves to the same valid
	 * location, rather than each caller hardcoding its own — and possibly
	 * stale — path.
	 *
	 * @param string $subpage Optional gateway subpage slug (e.g. 'stripe') to deep-link into.
	 * @return string The admin payment-settings URL.
	 * @since 1.3.0
	 */
	public static function get_settings_url( $subpage = '' ) {
		$path = 'admin.php?page=suredonation#/settings?tab=payments';
		if ( is_string( $subpage ) && '' !== $subpage ) {
			$path .= '&subpage=' . rawurlencode( $subpage );
		}
		return admin_url( $path );
	}

	/**
	 * Whether any real payment gateway (Stripe or PayPal) is connected.
	 *
	 * Stripe's connection is mode-agnostic; PayPal's is per-mode, so both
	 * PayPal modes are checked. Offline is intentionally excluded — it is a
	 * manual method, not a live/test payment gateway, so it never counts as
	 * "a gateway is connected" for test-mode/live-mode prompts.
	 *
	 * @return bool True when at least one gateway is connected in any mode.
	 * @since 1.3.0
	 */
	public static function is_any_gateway_connected() {
		return Stripe_Helper::is_stripe_connected()
			|| PayPal_Helper::is_paypal_connected( 'live' )
			|| PayPal_Helper::is_paypal_connected( 'test' );
	}

	/**
	 * Whether at least one payment gateway is usable on the site right now — a
	 * gateway a payment block would actually render if it selected it.
	 *
	 * Stricter than is_any_gateway_connected(): it is scoped to the current
	 * mode. Stripe must also have a publishable key for the current mode, PayPal
	 * must be connected for the current mode, and Offline must be enabled. Used
	 * to decide whether a "no gateway available" state is a missing site-wide
	 * gateway (nothing usable) or merely a form/block that hasn't selected an
	 * already-usable gateway.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	public static function has_usable_gateway() {
		if ( Stripe_Helper::is_stripe_connected() && '' !== Stripe_Helper::get_stripe_publishable_key() ) {
			return true;
		}

		if ( PayPal_Helper::is_paypal_connected() ) {
			return true;
		}

		return Offline_Helper::is_offline_enabled();
	}

	/**
	 * Get the currency list formatted for select inputs.
	 *
	 * Returns a map of currency code to a "CODE - Name" display label, shared
	 * by the REST currencies endpoint and the admin bootstrap data so the
	 * Select renders synchronously without an extra fetch.
	 *
	 * @return array<string, string> Map of currency code to display label.
	 * @since 1.1.0
	 */
	public static function get_currencies_list() {
		$currencies = [];
		foreach ( self::get_all_currencies_data() as $code => $data ) {
			$currencies[ $code ] = $code . ' - ' . $data['name'];
		}
		return $currencies;
	}

	/**
	 * Get comprehensive currency data for all supported currencies.
	 *
	 * This is the single source of truth for all currency-related data.
	 * Contains currency name, symbol, and decimal places.
	 *
	 * @return array<string, array<string, mixed>> Array of currency data keyed by currency code.
	 * @since 0.0.1
	 */
	public static function get_all_currencies_data() {
		return [
			'USD' => [
				'name'           => __( 'US Dollar', 'suredonation' ),
				'symbol'         => '$',
				'decimal_places' => 2,
			],
			'EUR' => [
				'name'           => __( 'Euro', 'suredonation' ),
				'symbol'         => '€',
				'decimal_places' => 2,
			],
			'GBP' => [
				'name'           => __( 'British Pound', 'suredonation' ),
				'symbol'         => '£',
				'decimal_places' => 2,
			],
			'JPY' => [
				'name'           => __( 'Japanese Yen', 'suredonation' ),
				'symbol'         => '¥',
				'decimal_places' => 0,
			],
			'AUD' => [
				'name'           => __( 'Australian Dollar', 'suredonation' ),
				'symbol'         => 'A$',
				'decimal_places' => 2,
			],
			'CAD' => [
				'name'           => __( 'Canadian Dollar', 'suredonation' ),
				'symbol'         => 'C$',
				'decimal_places' => 2,
			],
			'CHF' => [
				'name'           => __( 'Swiss Franc', 'suredonation' ),
				'symbol'         => 'CHF',
				'decimal_places' => 2,
			],
			'CNY' => [
				'name'           => __( 'Chinese Yuan', 'suredonation' ),
				'symbol'         => '¥',
				'decimal_places' => 2,
			],
			'SEK' => [
				'name'           => __( 'Swedish Krona', 'suredonation' ),
				'symbol'         => 'kr',
				'decimal_places' => 2,
			],
			'NZD' => [
				'name'           => __( 'New Zealand Dollar', 'suredonation' ),
				'symbol'         => 'NZ$',
				'decimal_places' => 2,
			],
			'MXN' => [
				'name'           => __( 'Mexican Peso', 'suredonation' ),
				'symbol'         => 'MX$',
				'decimal_places' => 2,
			],
			'SGD' => [
				'name'           => __( 'Singapore Dollar', 'suredonation' ),
				'symbol'         => 'S$',
				'decimal_places' => 2,
			],
			'HKD' => [
				'name'           => __( 'Hong Kong Dollar', 'suredonation' ),
				'symbol'         => 'HK$',
				'decimal_places' => 2,
			],
			'NOK' => [
				'name'           => __( 'Norwegian Krone', 'suredonation' ),
				'symbol'         => 'kr',
				'decimal_places' => 2,
			],
			'KRW' => [
				'name'           => __( 'South Korean Won', 'suredonation' ),
				'symbol'         => '₩',
				'decimal_places' => 0,
			],
			'TRY' => [
				'name'           => __( 'Turkish Lira', 'suredonation' ),
				'symbol'         => '₺',
				'decimal_places' => 2,
			],
			'RUB' => [
				'name'           => __( 'Russian Ruble', 'suredonation' ),
				'symbol'         => '₽',
				'decimal_places' => 2,
			],
			'INR' => [
				'name'           => __( 'Indian Rupee', 'suredonation' ),
				'symbol'         => '₹',
				'decimal_places' => 2,
			],
			'BRL' => [
				'name'           => __( 'Brazilian Real', 'suredonation' ),
				'symbol'         => 'R$',
				'decimal_places' => 2,
			],
			'ZAR' => [
				'name'           => __( 'South African Rand', 'suredonation' ),
				'symbol'         => 'R',
				'decimal_places' => 2,
			],
			'AED' => [
				'name'           => __( 'UAE Dirham', 'suredonation' ),
				'symbol'         => 'د.إ',
				'decimal_places' => 2,
			],
			'PHP' => [
				'name'           => __( 'Philippine Peso', 'suredonation' ),
				'symbol'         => '₱',
				'decimal_places' => 2,
			],
			'IDR' => [
				'name'           => __( 'Indonesian Rupiah', 'suredonation' ),
				'symbol'         => 'Rp',
				'decimal_places' => 2,
			],
			'MYR' => [
				'name'           => __( 'Malaysian Ringgit', 'suredonation' ),
				'symbol'         => 'RM',
				'decimal_places' => 2,
			],
			'THB' => [
				'name'           => __( 'Thai Baht', 'suredonation' ),
				'symbol'         => '฿',
				'decimal_places' => 2,
			],
			'BIF' => [
				'name'           => __( 'Burundian Franc', 'suredonation' ),
				'symbol'         => 'FBu',
				'decimal_places' => 0,
			],
			'CLP' => [
				'name'           => __( 'Chilean Peso', 'suredonation' ),
				'symbol'         => '$',
				'decimal_places' => 0,
			],
			'DJF' => [
				'name'           => __( 'Djiboutian Franc', 'suredonation' ),
				'symbol'         => 'Fdj',
				'decimal_places' => 0,
			],
			'GNF' => [
				'name'           => __( 'Guinean Franc', 'suredonation' ),
				'symbol'         => 'FG',
				'decimal_places' => 0,
			],
			'KMF' => [
				'name'           => __( 'Comorian Franc', 'suredonation' ),
				'symbol'         => 'CF',
				'decimal_places' => 0,
			],
			'MGA' => [
				'name'           => __( 'Malagasy Ariary', 'suredonation' ),
				'symbol'         => 'Ar',
				'decimal_places' => 0,
			],
			'PYG' => [
				'name'           => __( 'Paraguayan Guaraní', 'suredonation' ),
				'symbol'         => '₲',
				'decimal_places' => 0,
			],
			'RWF' => [
				'name'           => __( 'Rwandan Franc', 'suredonation' ),
				'symbol'         => 'FRw',
				'decimal_places' => 0,
			],
			'UGX' => [
				'name'           => __( 'Ugandan Shilling', 'suredonation' ),
				'symbol'         => 'USh',
				'decimal_places' => 0,
			],
			'VND' => [
				'name'           => __( 'Vietnamese Đồng', 'suredonation' ),
				'symbol'         => '₫',
				'decimal_places' => 0,
			],
			'VUV' => [
				'name'           => __( 'Vanuatu Vatu', 'suredonation' ),
				'symbol'         => 'VT',
				'decimal_places' => 0,
			],
			'XAF' => [
				'name'           => __( 'Central African CFA Franc', 'suredonation' ),
				'symbol'         => 'FCFA',
				'decimal_places' => 0,
			],
			'XOF' => [
				'name'           => __( 'West African CFA Franc', 'suredonation' ),
				'symbol'         => 'CFA',
				'decimal_places' => 0,
			],
			'XPF' => [
				'name'           => __( 'CFP Franc', 'suredonation' ),
				'symbol'         => '₣',
				'decimal_places' => 0,
			],
		];
	}

	/**
	 * Get currency names for all supported currencies.
	 *
	 * @return array<string, mixed> Array of currency names keyed by currency code.
	 * @since 0.0.1
	 */
	public static function get_currency_names() {
		$currencies = self::get_all_currencies_data();
		$names      = [];

		foreach ( $currencies as $code => $data ) {
			$names[ $code ] = $data['name'];
		}

		return $names;
	}

	/**
	 * Get currency
	 *
	 * @return string Currency code (e.g., 'USD').
	 * @since 0.0.1
	 */
	public static function get_currency() {
		$currency = self::get_global_setting( 'currency', 'USD' );
		return is_string( $currency ) ? $currency : 'USD';
	}

	/**
	 * Get currency symbol.
	 *
	 * @param string $currency Currency code.
	 * @return string Currency symbol or empty string.
	 * @since 0.0.1
	 */
	public static function get_currency_symbol( $currency = '' ) {
		if ( empty( $currency ) ) {
			$currency = self::get_currency();
		}

		if ( empty( $currency ) || ! is_string( $currency ) ) {
			return '';
		}

		$currency      = strtoupper( $currency );
		$currencies    = self::get_all_currencies_data();
		$currency_data = $currencies[ $currency ] ?? null;

		$symbol = ! empty( $currency_data ) ? $currency_data['symbol'] : '';
		return is_string( $symbol ) ? $symbol : '';
	}

	/**
	 * Get list of zero-decimal currencies.
	 *
	 * Zero-decimal currencies don't use decimal points in payment APIs.
	 * For these currencies, amounts are passed as-is without multiplying/dividing by 100.
	 *
	 * @return array<string> Array of zero-decimal currency codes.
	 * @since 0.0.1
	 */
	public static function get_zero_decimal_currencies() {
		$currencies         = self::get_all_currencies_data();
		$zero_decimal_codes = [];

		foreach ( $currencies as $code => $data ) {
			if ( 0 === $data['decimal_places'] ) {
				$zero_decimal_codes[] = $code;
			}
		}

		return $zero_decimal_codes;
	}

	/**
	 * Check if currency is zero-decimal.
	 *
	 * @param string $currency Currency code.
	 * @return bool True if zero-decimal currency.
	 * @since 0.0.1
	 */
	public static function is_zero_decimal_currency( $currency ) {
		if ( empty( $currency ) || ! is_string( $currency ) ) {
			return false;
		}

		$currency      = strtoupper( $currency );
		$currencies    = self::get_all_currencies_data();
		$currency_data = $currencies[ $currency ] ?? null;

		return $currency_data && 0 === $currency_data['decimal_places'];
	}

	/**
	 * Get the comparison tolerance (epsilon) for a currency, in major units.
	 *
	 * Amount comparisons need a small tolerance to absorb floating-point
	 * rounding. The correct tolerance is one minor unit of the currency:
	 * 0.01 for 2-decimal currencies (e.g. USD) and 1 for zero-decimal
	 * currencies (e.g. JPY) — rather than a hardcoded 0.01, which is
	 * meaningless for zero-decimal currencies.
	 *
	 * @param string $currency Currency code. Defaults to the configured currency.
	 * @return float Tolerance in major currency units.
	 * @since 1.1.1
	 */
	public static function get_amount_epsilon( $currency = '' ) {
		if ( empty( $currency ) ) {
			$currency = self::get_currency();
		}

		$currency      = is_string( $currency ) ? strtoupper( $currency ) : '';
		$currencies    = self::get_all_currencies_data();
		$currency_data = $currencies[ $currency ] ?? null;

		$decimal_places = ( is_array( $currency_data ) && isset( $currency_data['decimal_places'] ) && is_numeric( $currency_data['decimal_places'] ) )
			? (int) $currency_data['decimal_places']
			: 2;

		return (float) pow( 10, -$decimal_places );
	}

	/**
	 * Format amount for display
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string Formatted amount.
	 * @since 0.0.1
	 */
	public static function format_amount( $amount, $currency = '' ) {
		if ( empty( $currency ) ) {
			$currency = self::get_currency();
		}

		$symbol         = self::get_currency_symbol( $currency );
		$decimal_places = self::is_zero_decimal_currency( $currency ) ? 0 : 2;
		$formatted      = number_format( (float) $amount, $decimal_places, '.', ',' );

		// Fall back to the uppercased currency code when we have no symbol for
		// this currency (e.g. a historical/imported donation in a currency not
		// in our list). Positioning a multi-letter code isn't meaningful, so
		// keep the legacy "CODE 100.00" form rather than routing an empty
		// symbol through the switch (which would emit a bare number and stray
		// spaces under the *_space positions).
		if ( '' === $symbol ) {
			$code = strtoupper( (string) $currency );
			return '' === $code ? $formatted : $code . ' ' . $formatted;
		}

		return self::position_currency_symbol( $symbol, $formatted );
	}

	/**
	 * Place a currency symbol relative to an already-formatted amount per the
	 * global sign position setting. Kept separate from format_amount() so
	 * callers that format the number themselves (e.g. raw preset labels that
	 * must not gain decimals) can still honor the setting.
	 *
	 * 'auto' (and the historical 'left') keep the symbol on the left, so
	 * existing output is unchanged.
	 *
	 * @param string $symbol           Currency symbol.
	 * @param string $formatted_amount Amount already formatted for display.
	 * @return string Amount with the symbol positioned per the setting.
	 * @since 1.3.0
	 */
	public static function position_currency_symbol( $symbol, $formatted_amount ) {
		switch ( self::get_currency_sign_position() ) {
			case 'right':
				return $formatted_amount . $symbol;
			case 'left_space':
				return $symbol . ' ' . $formatted_amount;
			case 'right_space':
				return $formatted_amount . ' ' . $symbol;
			case 'left':
			case 'auto':
			default:
				return $symbol . $formatted_amount;
		}
	}

	/**
	 * Get the configured currency sign position.
	 *
	 * Controls where the currency symbol sits relative to the amount in
	 * displayed values. 'auto' preserves the historical behavior (symbol on
	 * the left for server-rendered donor-facing output; locale-aware in the
	 * admin dashboard, which formats via Intl). An explicit value overrides
	 * this consistently across every surface.
	 *
	 * @return string One of 'auto', 'left', 'right', 'left_space', 'right_space'.
	 * @since 1.3.0
	 */
	public static function get_currency_sign_position() {
		$position = self::get_global_setting( 'currency_sign_position', 'auto' );

		return is_string( $position ) && in_array( $position, self::ALLOWED_SIGN_POSITIONS, true ) ? $position : 'auto';
	}

	/**
	 * Convert amount to Stripe format (cents)
	 *
	 * @param float  $amount   Amount in dollars.
	 * @param string $currency Currency code.
	 * @return int Amount in cents.
	 * @since 0.0.1
	 */
	public static function amount_to_stripe_format( $amount, $currency = '' ) {
		if ( empty( $currency ) ) {
			$currency = self::get_currency();
		}

		$amount = floatval( $amount );
		return self::is_zero_decimal_currency( $currency )
			? (int) round( $amount )
			: (int) round( $amount * 100 );
	}

	/**
	 * Convert amount from Stripe format (cents) to dollars
	 *
	 * @param int    $amount   Amount in cents.
	 * @param string $currency Currency code.
	 * @return float Amount in dollars.
	 * @since 0.0.1
	 */
	public static function amount_from_stripe_format( $amount, $currency = '' ) {
		if ( empty( $currency ) ) {
			$currency = self::get_currency();
		}

		$amount = floatval( $amount );
		return self::is_zero_decimal_currency( $currency )
			? $amount
			: $amount / 100;
	}

	/**
	 * Get fee recovery settings from global payment settings.
	 *
	 * @return array<string, mixed> Fee recovery settings.
	 * @since 1.0.0
	 */
	public static function get_fee_recovery_settings() {
		$all_settings = self::get_all_payment_settings();
		$fee_recovery = isset( $all_settings['fee_recovery'] ) && is_array( $all_settings['fee_recovery'] )
			? $all_settings['fee_recovery']
			: [];

		$defaults = [
			'fee_percentage' => 2.9,
			'fee_fixed'      => 0.30,
			'fee_mode'       => 'all_gateways',
			'gateways'       => [
				'stripe'  => [
					'fee_percentage' => 2.9,
					'fee_fixed'      => 0.30,
					'enabled'        => true,
				],
				'paypal'  => [
					'fee_percentage' => 3.49,
					'fee_fixed'      => 0.49,
					'enabled'        => true,
				],
				'offline' => [
					'fee_percentage' => 0,
					'fee_fixed'      => 0,
					'enabled'        => false,
				],
			],
		];

		return wp_parse_args( $fee_recovery, $defaults );
	}

	/**
	 * Get fee rates for a specific gateway.
	 *
	 * In 'per_gateway' mode, returns the gateway-specific rates (or zeros if disabled).
	 * In 'all_gateways' mode, returns the single global rate.
	 *
	 * @param string              $gateway      Gateway identifier (e.g., 'stripe', 'offline').
	 * @param array<string,mixed> $fee_recovery Optional pre-fetched fee recovery settings.
	 * @return array{fee_percentage: float, fee_fixed: float} Fee rates for the gateway.
	 * @since 1.0.0
	 */
	public static function get_fee_rates_for_gateway( $gateway, $fee_recovery = null ) {
		if ( null === $fee_recovery ) {
			$fee_recovery = self::get_fee_recovery_settings();
		}

		$mode = $fee_recovery['fee_mode'] ?? 'all_gateways';

		$gateways = is_array( $fee_recovery['gateways'] ?? null ) ? $fee_recovery['gateways'] : [];
		if ( 'per_gateway' === $mode && isset( $gateways[ $gateway ] ) ) {
			$gw = is_array( $gateways[ $gateway ] ) ? $gateways[ $gateway ] : [];

			if ( ! ( $gw['enabled'] ?? true ) ) {
				return [
					'fee_percentage' => 0,
					'fee_fixed'      => 0,
				];
			}

			return [
				'fee_percentage' => (float) ( $gw['fee_percentage'] ?? 0 ),
				'fee_fixed'      => (float) ( $gw['fee_fixed'] ?? 0 ),
			];
		}

		// All-gateways mode — return the single rate.
		$pct   = $fee_recovery['fee_percentage'] ?? 2.9;
		$fixed = $fee_recovery['fee_fixed'] ?? 0.30;
		return [
			'fee_percentage' => is_numeric( $pct ) ? (float) $pct : 2.9,
			'fee_fixed'      => is_numeric( $fixed ) ? (float) $fixed : 0.30,
		];
	}

	/**
	 * Get cover-fees configuration for a specific gateway from block config, with global fallback.
	 *
	 * Looks up the suredonation/cover-fees block in the form's block config to extract per-gateway or
	 * global fee rates. Falls back to global payment settings if block config is unavailable.
	 *
	 * @param int    $form_id Form post ID.
	 * @param string $gateway Gateway identifier (e.g., 'stripe', 'offline').
	 * @return array{enabled: bool, fee_percentage: float, fee_fixed: float} Fee config for the gateway.
	 * @since 1.0.0
	 */
	public static function get_cover_fees_config( $form_id, $gateway ) {
		$fee_percentage = null;
		$fee_fixed      = null;
		$enabled        = true;

		// Look up cover-fees block config for the form.
		if ( $form_id > 0 ) {
			$block_config = \SureDonation\Inc\Field_Validation::get_or_migrate_block_config_for_legacy_form( $form_id );
			if ( ! empty( $block_config ) && is_array( $block_config ) ) {
				foreach ( $block_config as $config ) {
					if ( ! is_array( $config ) || ! isset( $config['block_name'] ) || 'suredonation/cover-fees' !== $config['block_name'] ) {
						continue;
					}

					$block_fee_mode = $config['fee_mode'] ?? 'all_gateways';
					$gateway_fees   = is_array( $config['gateway_fees'] ?? null ) ? $config['gateway_fees'] : [];

					if ( 'per_gateway' === $block_fee_mode && ! empty( $gateway_fees[ $gateway ] ) ) {
						$gw = is_array( $gateway_fees[ $gateway ] ) ? $gateway_fees[ $gateway ] : [];
						if ( empty( $gw['enabled'] ) ) {
							$enabled = false;
						} else {
							$fee_percentage = (float) ( $gw['fee_percentage'] ?? 0 );
							$fee_fixed      = (float) ( $gw['fee_fixed'] ?? 0 );
						}
					} else {
						$fee_percentage = is_numeric( $config['fee_percentage'] ?? null ) ? (float) $config['fee_percentage'] : null;
						$fee_fixed      = is_numeric( $config['fee_fixed'] ?? null ) ? (float) $config['fee_fixed'] : null;
					}
					break;
				}
			}
		}

		// Fall back to global gateway-specific settings.
		if ( $enabled && ( null === $fee_percentage || null === $fee_fixed ) ) {
			$rates          = self::get_fee_rates_for_gateway( $gateway );
			$fee_percentage = null === $fee_percentage ? (float) $rates['fee_percentage'] : $fee_percentage;
			$fee_fixed      = null === $fee_fixed ? (float) $rates['fee_fixed'] : $fee_fixed;
		}

		return [
			'enabled'        => $enabled,
			'fee_percentage' => $fee_percentage ?? 0.0,
			'fee_fixed'      => $fee_fixed ?? 0.0,
		];
	}

	/**
	 * Calculate fee using the inclusive (gross-up) formula.
	 *
	 * Formula: total = (base + fixed) / (1 - rate), fee = total - base
	 * This ensures the organization receives exactly the base amount after the gateway takes its cut.
	 *
	 * @param float      $base_amount    The base donation amount.
	 * @param float|null $fee_percentage Fee percentage (e.g., 2.9 for 2.9%). Null to use global setting.
	 * @param float|null $fee_fixed      Fixed fee amount (e.g., 0.30). Null to use global setting.
	 * @return float The calculated fee amount, rounded to 2 decimal places.
	 * @since 1.0.0
	 */
	public static function calculate_fee( $base_amount, $fee_percentage = null, $fee_fixed = null ) {
		if ( $base_amount <= 0 ) {
			return 0.0;
		}

		if ( null === $fee_percentage || null === $fee_fixed ) {
			$settings = self::get_fee_recovery_settings();
			if ( null === $fee_percentage ) {
				$pct            = $settings['fee_percentage'] ?? 2.9;
				$fee_percentage = is_numeric( $pct ) ? (float) $pct : 2.9;
			}
			if ( null === $fee_fixed ) {
				$fixed     = $settings['fee_fixed'] ?? 0.30;
				$fee_fixed = is_numeric( $fixed ) ? (float) $fixed : 0.30;
			}
		}

		$rate = (float) $fee_percentage / 100;

		// Prevent division by zero.
		if ( $rate >= 1 ) {
			return 0.0;
		}

		$total = ( $base_amount + (float) $fee_fixed ) / ( 1 - $rate );
		$fee   = $total - $base_amount;

		return round( $fee, 2 );
	}

	/**
	 * Get supported payment gateways
	 *
	 * @return array<string, array<string, mixed>> Gateway configurations.
	 * @since 0.0.1
	 */
	public static function get_supported_gateways() {
		return apply_filters(
			'suredonation_payment_gateways',
			[
				'stripe'  => [
					'label'              => __( 'Stripe', 'suredonation' ),
					'description'        => __( 'Accept payments via Stripe', 'suredonation' ),
					'enabled'            => true,
					'supports_recurring' => true,
				],
				'offline' => [
					'label'              => __( 'Offline Donations', 'suredonation' ),
					'description'        => __( 'Accept offline donations', 'suredonation' ),
					'enabled'            => true,
					'supports_recurring' => false,
				],
			]
		);
	}

	/**
	 * Check if a gateway is enabled
	 *
	 * @param string $gateway Gateway name.
	 * @return bool True if enabled.
	 * @since 0.0.1
	 */
	public static function is_gateway_enabled( $gateway ) {
		$gateways = self::get_supported_gateways();
		return ! empty( $gateways[ $gateway ]['enabled'] );
	}

	/**
	 * Validate payment amount against stored form configuration.
	 *
	 * This function verifies that the payment amount submitted matches the
	 * configured values in the form's payment block settings stored in post meta.
	 * It handles both fixed and variable (minimum) amount validations.
	 *
	 * This is the PRIMARY security function that prevents payment amount manipulation.
	 * It validates against IMMUTABLE configuration stored when the form was saved,
	 * not against request data which can be manipulated.
	 *
	 * @since 0.0.1
	 * @param float  $amount   Amount in major currency units (e.g., dollars, not cents).
	 * @param string $currency Currency code (e.g., 'USD', 'EUR').
	 * @param int    $form_id  WordPress post ID of the donation form.
	 * @param string $block_id Block identifier for the payment block.
	 * @param string $gateway  Payment gateway identifier (default 'stripe').
	 * @return array<mixed> Validation result.
	 */
	public static function validate_payment_amount( $amount, $currency, $form_id, $block_id, $gateway = 'stripe' ) {
		// Retrieve block configuration from post meta.
		$block_config = \SureDonation\Inc\Field_Validation::get_or_migrate_block_config_for_legacy_form( $form_id );

		// Check if block config exists.
		if ( empty( $block_config ) || ! is_array( $block_config ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Invalid form configuration.', 'suredonation' ),
			];
		}

		// Check if payment block exists in configuration.
		if ( ! isset( $block_config[ $block_id ] ) || ! is_array( $block_config[ $block_id ] ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Payment configuration not found for this form.', 'suredonation' ),
			];
		}

		$payment_config = $block_config[ $block_id ];

		// The submitted block_id must reference an actual payment block. Every
		// other field block (input/email/number/dropdown/phone/url/donation-
		// amount/cover-fees) also has a config entry but carries no amount_type/
		// fixed_amount — validating against one would silently collapse the
		// checks below to their fallback defaults and let a caller pay an
		// arbitrary (default) amount regardless of the block's real configuration.
		if ( ! isset( $payment_config['block_name'] ) || 'suredonation/payment' !== $payment_config['block_name'] ) {
			return [
				'valid'   => false,
				'message' => __( 'Payment configuration not found for this form.', 'suredonation' ),
			];
		}

		// Validate currency matches global setting.
		$global_currency    = strtolower( self::get_currency() );
		$submitted_currency = strtolower( $currency );
		if ( $global_currency !== $submitted_currency ) {
			return [
				'valid'   => false,
				/* translators: 1: expected currency, 2: received currency */
				'message' => sprintf( __( 'Currency mismatch: expected %1$s, received %2$s.', 'suredonation' ), strtoupper( $global_currency ), strtoupper( $submitted_currency ) ),
			];
		}

		// Get amount type (fixed or variable).
		// Default to 'fixed' if not set - this is the safest default for security.
		$amount_type = $payment_config['amount_type'] ?? 'fixed';

		// Validate based on amount type.
		if ( 'fixed' === $amount_type ) {
			// Fixed amount validation - must match exactly. A payment block with
			// no configured fixed_amount fails closed rather than defaulting to a
			// chargeable amount.
			if ( ! isset( $payment_config['fixed_amount'] ) ) {
				return [
					'valid'   => false,
					'message' => __( 'Payment configuration is incomplete for this form.', 'suredonation' ),
				];
			}
			$configured_amount = floatval( Helper::get_string_value( $payment_config['fixed_amount'] ) );

			// Allow one minor currency unit of tolerance for float rounding.
			if ( abs( $amount - $configured_amount ) > self::get_amount_epsilon( $currency ) ) {
				return [
					'valid'   => false,
					/* translators: %s: expected amount with currency */
					'message' => sprintf( __( 'Payment amount must be exactly %s.', 'suredonation' ), self::format_amount( $configured_amount, $currency ) ),
				];
			}
		} elseif ( 'variable' === $amount_type ) {
			// Variable amount validation — only enforce a minimum when one
			// is explicitly configured in the block. Default is no minimum.
			$minimum_amount = isset( $payment_config['minimum_amount'] ) ? floatval( Helper::get_string_value( $payment_config['minimum_amount'] ) ) : 0.0;

			if ( $minimum_amount > 0 && $amount < $minimum_amount ) {
				return [
					'valid'   => false,
					/* translators: %s: minimum amount with currency */
					'message' => sprintf( __( 'Payment amount must be at least %s.', 'suredonation' ), self::format_amount( $minimum_amount, $currency ) ),
				];
			}

			if ( $amount <= 0 ) {
				return [
					'valid'   => false,
					'message' => __( 'Payment amount must be greater than zero.', 'suredonation' ),
				];
			}

			// Additional validation for donation-amount fields.
			$dynamic_validation = self::validate_dynamic_amount_field( $payment_config, $block_config, $amount, $currency );
			if ( null !== $dynamic_validation ) {
				return $dynamic_validation;
			}
		}

		// Gateway-specific minimum amounts. Offline has no minimum.
		$gateway_minimums = [
			'stripe' => 0.50,
			'paypal' => 1.00,
		];

		if ( isset( $gateway_minimums[ $gateway ] ) ) {
			$minimum = $gateway_minimums[ $gateway ];
			if ( $amount < $minimum ) {
				return [
					'valid'   => false,
					/* translators: %s: minimum amount */
					'message' => sprintf( __( 'Payment amount must be at least %s.', 'suredonation' ), self::format_amount( $minimum, $currency ) ),
				];
			}
		}

		// Validation passed.
		return [
			'valid'   => true,
			'message' => '',
		];
	}

	/**
	 * Validate that the submitted payment type matches the block configuration.
	 *
	 * Prevents attackers from requesting a subscription on a block configured
	 * for one-time payments (or vice versa).
	 *
	 * @param string $expected_type Expected payment type ('one-time' or 'subscription').
	 * @param int    $form_id       Form ID.
	 * @param string $block_id      Block ID.
	 * @return array{valid: bool, message: string} Validation result.
	 * @since 1.0.0
	 */
	public static function validate_payment_type( $expected_type, $form_id, $block_id ) {
		if ( empty( $form_id ) || empty( $block_id ) ) {
			// Cannot validate without form/block context — allow to proceed.
			return [
				'valid'   => true,
				'message' => '',
			];
		}

		$block_config = \SureDonation\Inc\Field_Validation::get_or_migrate_block_config_for_legacy_form( $form_id );

		if ( empty( $block_config ) || ! is_array( $block_config ) || ! isset( $block_config[ $block_id ] ) ) {
			return [
				'valid'   => true,
				'message' => '',
			];
		}

		$payment_config  = $block_config[ $block_id ];
		$configured_type = $payment_config['payment_type'] ?? 'one-time';

		if ( $configured_type !== $expected_type ) {
			return [
				'valid'   => false,
				'message' => __( 'Payment type mismatch. This form does not support the requested payment type.', 'suredonation' ),
			];
		}

		return [
			'valid'   => true,
			'message' => '',
		];
	}

	/**
	 * Validate a full donation submission server-side.
	 *
	 * Centralizes the two server-side checks every donation-creation entry point
	 * must run before any payment intent / record is created:
	 *  1. Field-level validation (required, max length, email format, number
	 *     range) via Field_Validation::validate_form_data().
	 *  2. Payment amount validation against the immutable block configuration.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $fields   Submitted field values keyed by field slug.
	 * @param float                $amount   Amount in major currency units.
	 * @param string               $currency Currency code (e.g. 'USD').
	 * @param int                  $form_id  Donation form post ID.
	 * @param string               $block_id Payment block identifier.
	 * @param string               $gateway  Payment gateway identifier (default 'stripe').
	 * @return array{valid: bool, message: string, field_errors: array<string, string>} Combined result.
	 */
	public static function validate_submission( $fields, $amount, $currency, $form_id, $block_id, $gateway = 'stripe' ) {
		$result = [
			'valid'        => true,
			'message'      => '',
			'field_errors' => [],
		];

		// Field-level validation (source of truth for required/format/length/range).
		$field_errors = \SureDonation\Inc\Field_Validation::validate_form_data( $fields, (int) $form_id );
		if ( ! empty( $field_errors ) ) {
			$result['valid']        = false;
			$result['field_errors'] = $field_errors;
			$result['message']      = __( 'Please correct the highlighted fields and try again.', 'suredonation' );
		}

		// Contact-consent requirement (Privacy settings). Enforced here at the shared
		// validation choke point so it applies to every gateway (stripe/paypal/
		// offline/ajax) before any donor/intent is persisted.
		$consent_error = \SureDonation\Inc\Privacy\Privacy_Frontend::validate_consent();
		if ( '' !== $consent_error ) {
			$result['valid'] = false;
			// Key by the consent input's data-slug so the client renders it inline
			// against the checkbox (showServerFieldErrors), like other field errors.
			$result['field_errors'][ \SureDonation\Inc\Privacy\Privacy_Frontend::CONSENT_FIELD ] = $consent_error;
			if ( '' === $result['message'] ) {
				$result['message'] = __( 'Please correct the highlighted fields and try again.', 'suredonation' );
			}
		}

		// The persisted donor email comes from the POST donor_email param, which
		// is separate from the validation-only fields[] copy inspected above and
		// is never run through validate_form_data(). Length-cap it here too, or a
		// crafted request could store an oversized value against the VARCHAR(255)
		// donor-email columns.
		$donor_email = self::get_submitted_donor_email();
		if ( '' !== $donor_email ) {
			$email_error = \SureDonation\Inc\Field_Validation::validate_email_length( $donor_email );
			if ( '' !== $email_error ) {
				$result['valid']                       = false;
				$result['field_errors']['donor_email'] = $email_error;
				if ( '' === $result['message'] ) {
					$result['message'] = __( 'Please correct the highlighted fields and try again.', 'suredonation' );
				}
			}
		}

		// Payment amount validation (prevents amount/type tampering).
		$amount_result = self::validate_payment_amount( $amount, $currency, $form_id, $block_id, $gateway );
		if ( empty( $amount_result['valid'] ) ) {
			$result['valid'] = false;
			// Surface the specific amount message only when no field errors took precedence.
			if ( empty( $result['field_errors'] ) ) {
				$result['message'] = isset( $amount_result['message'] ) && is_string( $amount_result['message'] ) ? $amount_result['message'] : '';
			}
		}

		return $result;
	}

	/**
	 * Read submitted form field values from the request, keyed by field slug.
	 *
	 * The donation form frontend posts every rendered field's value under the
	 * `fields[slug]` key so the server can enforce field validation on values
	 * it would not otherwise receive (text, phone, comment, etc.). Values are
	 * used for validation only — not persisted — so sanitize_text_field is a
	 * safe normalizer here. The caller is responsible for nonce/token checks.
	 *
	 * @since 1.1.0
	 * @return array<string, string> Map of field slug => sanitized value.
	 */
	public static function get_submitted_fields() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		if ( ! isset( $_POST['fields'] ) || ! is_array( $_POST['fields'] ) ) {
			return [];
		}

		// The outer array can contain nested arrays (`['label'=>.., 'value'=>..]`),
		// so each value is sanitized individually rather than with array_map() on
		// the whole structure. Field slugs (keys) are sanitized below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/HMAC verified by the calling handler; each value sanitized individually below.
		$raw    = wp_unslash( $_POST['fields'] );
		$fields = [];

		foreach ( $raw as $slug => $field ) {
			$slug = sanitize_text_field( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}

			// New nested shape: ['label'=>.., 'value'=>..]. Backward-compat: plain string.
			$value = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;

			$fields[ $slug ] = is_string( $value ) ? sanitize_text_field( $value ) : '';
		}

		return $fields;
	}

	/**
	 * Read the submitted donor email from the request.
	 *
	 * Mirrors get_submitted_fields(): the value is used for validation, and the
	 * caller is responsible for nonce/token checks. sanitize_email() matches how
	 * the gateway handlers extract donor_email before persisting it.
	 *
	 * @since 1.1.1
	 * @return string Sanitized donor email, or '' when absent.
	 */
	public static function get_submitted_donor_email() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		if ( ! isset( $_POST['donor_email'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		return sanitize_email( wp_unslash( $_POST['donor_email'] ) );
	}

	/**
	 * Read submitted form fields as label/value pairs for storage.
	 *
	 * Mirrors get_submitted_fields() but preserves each field's visible label so
	 * the submission can be persisted in a human-readable form. Handles both the
	 * new nested POST shape (`fields[slug][label]`, `fields[slug][value]`) and the
	 * legacy plain-string shape (`fields[slug]`), in which case the label is empty.
	 * The caller is responsible for nonce/token checks.
	 *
	 * @since 1.1.1
	 * @return array<string, array{label: string, value: string}> Map of field slug => label/value.
	 */
	public static function get_submitted_field_data() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		if ( ! isset( $_POST['fields'] ) || ! is_array( $_POST['fields'] ) ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/HMAC verified by the calling handler; each label/value sanitized individually below.
		$raw    = wp_unslash( $_POST['fields'] );
		$fields = [];

		// Core donor fields (name, email, amount) are stored in their own
		// columns, so they are omitted from the stored "additional" set. Their
		// slugs are derived server-side from the form's saved payment block
		// (not trusted from the request) so the exclusion can't be bypassed.
		// Empty values are skipped too. Neither affects validation, which reads
		// the full set via get_submitted_fields().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler; form_id is a lookup key, the slugs/labels come from the saved form.
		$form_id    = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$core_slugs = \SureDonation\Inc\Field_Validation::get_core_field_slugs( $form_id );

		// Resolve each field's label from the saved form (authoritative) rather
		// than the request, so the stored label can't be tampered with and does
		// not depend on the rendered markup. Slugs absent here (e.g. labels left
		// at their Gutenberg default, which are not persisted) fall back to the
		// submitted label below.
		$field_labels = \SureDonation\Inc\Field_Validation::get_field_labels_map( $form_id );

		foreach ( $raw as $slug => $field ) {
			$slug = sanitize_text_field( (string) $slug );
			if ( '' === $slug || in_array( $slug, $core_slugs, true ) ) {
				continue;
			}

			if ( is_array( $field ) ) {
				$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
				$value = isset( $field['value'] ) && is_string( $field['value'] ) ? $field['value'] : '';
				$group = isset( $field['group'] ) && is_string( $field['group'] ) ? $field['group'] : '';
			} else {
				// Legacy plain-string shape — no label/group available.
				$label = '';
				$value = is_string( $field ) ? $field : '';
				$group = '';
			}

			$value = sanitize_text_field( $value );

			// Multi-select dropdown values arrive '|'-delimited (an option label
			// may contain a comma). Re-join with ', ' for a readable stored/
			// displayed value. The flag is client-sent and only affects display
			// formatting here — server-side validation is unaffected.
			if ( is_array( $field ) && isset( $field['multiple'] ) && 'true' === $field['multiple'] ) {
				$value = implode( ', ', array_filter( array_map( 'trim', explode( '|', $value ) ), 'strlen' ) );
			}

			// Skip empty values so blank/optional fields don't clutter the entry.
			if ( '' === trim( $value ) ) {
				continue;
			}

			// Prefer the saved-form label; fall back to the submitted one only
			// when the slug has no persisted (customized) label.
			$resolved_label = isset( $field_labels[ $slug ] ) ? $field_labels[ $slug ] : sanitize_text_field( $label );

			$fields[ $slug ] = [
				'label' => $resolved_label,
				'value' => $value,
				// Parent block label (e.g. "Address") used to nest sub-fields on
				// the entry screen; '' for standalone fields.
				'group' => sanitize_text_field( $group ),
			];
		}

		return $fields;
	}

	/**
	 * Resolve the donor phone for storage from the submitted form fields.
	 *
	 * When a Phone field is mapped to the donor phone on the payment block, its
	 * value is read here from the already-validated submitted field set (keyed by
	 * the mapped slug, derived server-side) rather than from a separate, unchecked
	 * $_POST['donor_phone']. The value is length-capped to the donor_phone column
	 * width (VARCHAR(50)) so an over-long number cannot truncate or abort the
	 * write. Returns '' when no phone field is mapped. The caller verifies the
	 * nonce/HMAC token.
	 *
	 * @since 1.1.1
	 * @param int $form_id The donation form post ID.
	 * @return string The donor phone value, or '' when unmapped/absent.
	 */
	public static function get_mapped_donor_phone( $form_id ) {
		$form_id = (int) $form_id;
		if ( $form_id <= 0 ) {
			return '';
		}

		$phone_slug = \SureDonation\Inc\Field_Validation::get_mapped_phone_slug( $form_id );
		if ( '' === $phone_slug ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		if ( ! isset( $_POST['fields'] ) || ! is_array( $_POST['fields'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Token verified by caller; value sanitized below.
		$raw   = wp_unslash( $_POST['fields'] );
		$field = $raw[ $phone_slug ] ?? '';
		$value = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;
		$value = sanitize_text_field( is_string( $value ) ? $value : '' );

		// Cap to the donor_phone column width to avoid truncation/abort on write.
		return mb_substr( $value, 0, 50 );
	}

	/**
	 * Resolve the anonymous-donation flag for storage from the request.
	 *
	 * The Anonymous Donation checkbox renders with a per-block name and no
	 * data-slug, so it is not part of the submitted field set; the gateway JS
	 * forwards it as a dedicated `is_anonymous` key instead (see
	 * GatewayBase.appendAnonymousFlag). The flag is a display-only marker — the
	 * donor's real name, email and phone are still stored and processed as usual,
	 * and only the public donor wall / recent donations / top donors mask them.
	 *
	 * Whether the form offers the option is resolved from the saved form rather
	 * than trusted from the request, matching how the mapped phone field and the
	 * cover-fees configuration are derived server-side. A flag posted against a
	 * form with no Anonymous Donation block is therefore ignored. The caller
	 * verifies the nonce/HMAC token.
	 *
	 * @since 1.4.0
	 * @param int $form_id The donation form post ID.
	 * @return bool True when the donation should be flagged anonymous.
	 */
	public static function get_submitted_is_anonymous( $form_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		if ( empty( $_POST['is_anonymous'] ) ) {
			return false;
		}

		$form_id = (int) $form_id;
		if ( $form_id <= 0 || ! function_exists( 'parse_blocks' ) ) {
			return false;
		}

		// form_id is attacker-chosen on a public endpoint, so confirm it really is
		// a donation form before parsing its content — otherwise the request can
		// aim a full block parse at any post in the database.
		$post = get_post( $form_id );
		if ( ! ( $post instanceof \WP_Post )
			|| \SureDonation\Inc\Post_Types\Donation_Form::POST_TYPE !== $post->post_type
			|| empty( $post->post_content ) ) {
			return false;
		}

		return Helper::block_tree_contains( parse_blocks( $post->post_content ), 'suredonation/anonymous-donation' );
	}

	/**
	 * Store payment intent metadata for verification.
	 *
	 * This stores the expected payment amount when creating a payment intent,
	 * allowing the webhook to verify the actual charged amount matches.
	 *
	 * @param string               $payment_intent_id The Stripe payment intent ID.
	 * @param array<string, mixed> $metadata          The metadata to store (amount, currency, campaign_id, donation_id).
	 * @return bool True on success.
	 * @since 0.0.1
	 */
	public static function store_payment_intent_metadata( $payment_intent_id, $metadata ) {
		$transient_key = 'suredonation_pi_' . $payment_intent_id;
		// Store for 24 hours (webhook should arrive within minutes).
		return set_transient( $transient_key, $metadata, DAY_IN_SECONDS );
	}

	/**
	 * Get stored payment intent metadata.
	 *
	 * @param string $payment_intent_id The Stripe payment intent ID.
	 * @return array<string, mixed>|false The stored metadata or false if not found.
	 * @since 0.0.1
	 */
	public static function get_payment_intent_metadata( $payment_intent_id ) {
		$transient_key = 'suredonation_pi_' . $payment_intent_id;
		$metadata      = get_transient( $transient_key );
		if ( is_array( $metadata ) ) {
			return $metadata;
		}
		return false;
	}

	/**
	 * Delete stored payment intent metadata after verification.
	 *
	 * @param string $payment_intent_id The Stripe payment intent ID.
	 * @return bool True on success.
	 * @since 0.0.1
	 */
	public static function delete_payment_intent_metadata( $payment_intent_id ) {
		$transient_key = 'suredonation_pi_' . $payment_intent_id;
		return delete_transient( $transient_key );
	}

	/**
	 * Verify payment intent amount matches expected amount.
	 *
	 * This is called by the webhook handler to detect amount manipulation.
	 *
	 * @param string $payment_intent_id The Stripe payment intent ID.
	 * @param int    $actual_amount     The actual amount charged (in cents).
	 * @param string $currency          The currency code.
	 * @return bool|WP_Error True if amounts match, WP_Error if mismatch or not found.
	 * @since 0.0.1
	 */
	public static function verify_payment_intent_amount( $payment_intent_id, $actual_amount, $currency ) {
		$metadata = self::get_payment_intent_metadata( $payment_intent_id );

		if ( is_array( $metadata ) ) {
			$amount_value      = $metadata['amount_cents'] ?? 0;
			$expected_amount   = is_numeric( $amount_value ) ? (int) $amount_value : 0;
			$currency_value    = $metadata['currency'] ?? '';
			$expected_currency = is_string( $currency_value ) ? strtolower( $currency_value ) : '';
		} else {
			// The metadata transient is single-use (deleted after the first
			// successful verify) and expires in 24h, while Stripe retries
			// webhooks for days. Rather than failing open, resolve the expected
			// amount durably from the donation record (the amount is fixed
			// server-side at intent creation), and fail closed for any payment
			// we actually created.
			$donation = Donations::get_by_transaction_id( $payment_intent_id );

			if ( ! is_array( $donation ) || empty( $donation['id'] ) ) {
				// No donation we created matches this intent — a genuinely
				// external or legacy payment. Permit, but log explicitly so the
				// no-op is never silent.
				return true;
			}

			$donation_amount   = isset( $donation['amount'] ) && is_numeric( $donation['amount'] ) ? (float) $donation['amount'] : null;
			$donation_currency = isset( $donation['currency'] ) && is_string( $donation['currency'] ) ? strtolower( $donation['currency'] ) : '';

			if ( null === $donation_amount || '' === $donation_currency ) {
				// Known intent but the expected amount cannot be resolved — fail closed.
				return new \WP_Error(
					'amount_unverifiable',
					__( 'Unable to verify the expected donation amount for this payment. Flagged for manual review.', 'suredonation' )
				);
			}

			$expected_amount   = self::amount_to_stripe_format( $donation_amount, $donation_currency );
			$expected_currency = $donation_currency;
		}

		// Verify currency matches.
		if ( strtolower( $currency ) !== $expected_currency ) {
			return new \WP_Error(
				'currency_mismatch',
				sprintf(
					/* translators: 1: expected currency, 2: actual currency */
					__( 'Currency mismatch. Expected %1$s but received %2$s.', 'suredonation' ),
					strtoupper( $expected_currency ),
					strtoupper( $currency )
				)
			);
		}

		// Amounts here are already in the gateway's minor units (cents for
		// 2-decimal currencies, whole units for zero-decimal ones), so a
		// tolerance of 1 is one minor currency unit for rounding regardless
		// of currency.
		if ( abs( $actual_amount - $expected_amount ) > 1 ) {
			return new \WP_Error(
				'amount_mismatch',
				sprintf(
					/* translators: 1: expected amount, 2: actual amount */
					__( 'Amount mismatch detected. Expected %1$s but received %2$s. Possible payment manipulation.', 'suredonation' ),
					self::format_amount( self::amount_from_stripe_format( $expected_amount, $currency ), $currency ),
					self::format_amount( self::amount_from_stripe_format( $actual_amount, $currency ), $currency )
				)
			);
		}

		// Cleanup after successful verification.
		self::delete_payment_intent_metadata( $payment_intent_id );

		return true;
	}

	/**
	 * Validate dynamic amount field from donation-amount or number block.
	 *
	 * @param array<string, mixed> $payment_config Payment block configuration.
	 * @param array<string, mixed> $block_config   All block configurations.
	 * @param float                $amount         Submitted amount.
	 * @param string               $currency       Currency code.
	 * @return array<mixed>|null Validation result array or null if validation passes.
	 * @since 0.0.1
	 */
	private static function validate_dynamic_amount_field( $payment_config, $block_config, $amount, $currency ) {
		// Check if variable amount field block name is set.
		$dynamic_amount_field_block_name = isset( $payment_config['variable_amount_field_block_name'] ) && is_string( $payment_config['variable_amount_field_block_name'] ) ? $payment_config['variable_amount_field_block_name'] : '';

		if ( empty( $dynamic_amount_field_block_name ) ) {
			// No variable-amount field is declared for this block — an older form
			// layout where the donor's amount is a free choice. The amount-type,
			// minimum and gateway-minimum checks in validate_payment_amount()
			// still apply, so allow it through here.
			return null;
		}

		// Get the slug of the variable amount field.
		$variable_amount_field_slug = ! empty( $payment_config['variable_amount_field'] ) && is_string( $payment_config['variable_amount_field'] ) ? $payment_config['variable_amount_field'] : '';

		// Find the block config for the variable amount field by matching slug and block name.
		$variable_amount_block_config = self::get_block_config_by_name_and_slug( $block_config, $dynamic_amount_field_block_name, $variable_amount_field_slug );

		// The form declares a variable-amount field but its block config cannot be
		// resolved. Fail-safe reject instead of allowing an unvalidated amount
		// through: never trust a client-supplied amount we cannot re-resolve
		// server-side (mirrors SureForms #2855 hardening).
		if ( empty( $variable_amount_block_config ) || ! is_array( $variable_amount_block_config ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Unable to verify the donation amount for this form. Please reload the page and try again.', 'suredonation' ),
			];
		}

		// Handle number block validation.
		if ( 'suredonation/number' === $dynamic_amount_field_block_name ) {
			return self::validate_number_field_amount( $variable_amount_block_config, $amount, $currency );
		}

		// Handle donation-amount block validation.
		if ( 'suredonation/donation-amount' === $dynamic_amount_field_block_name ) {
			return self::validate_multi_choice_amount( $variable_amount_block_config, $amount, $currency );
		}

		// A variable-amount field is declared with a block type we have no
		// validator for. We cannot re-resolve the payable amount, so fail-safe
		// reject rather than fall through as accepted.
		return [
			'valid'   => false,
			'message' => __( 'Unsupported variable amount field configuration.', 'suredonation' ),
		];
	}

	/**
	 * Validate amount from number field against configured min/max.
	 *
	 * @param array<string, mixed>|null $number_block_config Number block configuration.
	 * @param float                     $amount              Submitted amount.
	 * @param string                    $currency            Currency code.
	 * @return array<string, mixed>|null Validation result array or null if validation passes.
	 * @since 0.0.1
	 */
	private static function validate_number_field_amount( $number_block_config, $amount, $currency ) {
		// Fail-safe reject when the field config is missing. The caller already
		// rejects an unresolvable config, so this is defensive: a configured
		// number field must never validate an amount against no constraints.
		if ( empty( $number_block_config ) || ! is_array( $number_block_config ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Variable amount field configuration not found.', 'suredonation' ),
			];
		}

		// One minor currency unit of tolerance for float rounding.
		$epsilon = self::get_amount_epsilon( $currency );

		// Validate min value if configured.
		if ( isset( $number_block_config['min'] ) && is_numeric( $number_block_config['min'] ) ) {
			$min_value = (float) $number_block_config['min'];
			if ( $amount < $min_value - $epsilon ) {
				return [
					'valid'   => false,
					/* translators: %s: minimum amount with currency */
					'message' => sprintf( __( 'Payment amount must be at least %s.', 'suredonation' ), self::format_amount( $min_value, $currency ) ),
				];
			}
		}

		// Validate max value if configured.
		if ( isset( $number_block_config['max'] ) && is_numeric( $number_block_config['max'] ) ) {
			$max_value = (float) $number_block_config['max'];
			if ( $amount > $max_value + $epsilon ) {
				return [
					'valid'   => false,
					/* translators: %s: maximum amount with currency */
					'message' => sprintf( __( 'Payment amount cannot exceed %s.', 'suredonation' ), self::format_amount( $max_value, $currency ) ),
				];
			}
		}

		return null;
	}

	/**
	 * Validate amount from donation-amount field against configured options.
	 *
	 * @param array<string, mixed>|null $multi_choice_config Multi-choice block configuration.
	 * @param float                     $amount              Submitted amount.
	 * @param string                    $currency            Currency code.
	 * @return array<string, mixed>|null Validation result array or null if validation passes.
	 * @since 0.0.1
	 */
	private static function validate_multi_choice_amount( $multi_choice_config, $amount, $currency ) {
		// Verify the variable amount block config was found.
		if ( empty( $multi_choice_config ) || ! is_array( $multi_choice_config ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Variable amount field configuration not found.', 'suredonation' ),
			];
		}

		// One minor currency unit of tolerance for float rounding.
		$epsilon = self::get_amount_epsilon( $currency );

		// Donation Amount is a single-select radio group. Extract the preset
		// option values and check whether the submitted amount matches one.
		$allowed_options = $multi_choice_config['options'] ?? [];
		$allowed_values  = [];
		if ( is_array( $allowed_options ) ) {
			foreach ( $allowed_options as $option ) {
				if ( isset( $option['value'] ) && is_numeric( $option['value'] ) ) {
					$allowed_values[] = (float) $option['value'];
				}
			}
		}

		foreach ( $allowed_values as $allowed_value ) {
			if ( abs( $amount - $allowed_value ) <= $epsilon ) {
				return null; // Matches a configured preset — valid.
			}
		}

		// Not a preset value. Only accept it when the custom amount input is
		// enabled for this block; otherwise fail closed.
		$allow_custom = ! empty( $multi_choice_config['allow_custom_amount'] );
		if ( ! $allow_custom ) {
			if ( empty( $allowed_values ) ) {
				return [
					'valid'   => false,
					'message' => __( 'No payment options are configured for this field.', 'suredonation' ),
				];
			}
			return [
				'valid'   => false,
				'message' => __( 'Invalid payment amount. Please select a valid amount from the available options.', 'suredonation' ),
			];
		}

		// Custom amount is enabled — enforce the configured min/max (0 = none).
		$min = isset( $multi_choice_config['custom_amount_min'] ) && is_numeric( $multi_choice_config['custom_amount_min'] )
			? (float) $multi_choice_config['custom_amount_min']
			: 0.0;
		$max = isset( $multi_choice_config['custom_amount_max'] ) && is_numeric( $multi_choice_config['custom_amount_max'] )
			? (float) $multi_choice_config['custom_amount_max']
			: 0.0;

		if ( $min > 0 && $amount < $min - $epsilon ) {
			return [
				'valid'   => false,
				/* translators: %s: minimum amount with currency */
				'message' => sprintf( __( 'Payment amount must be at least %s.', 'suredonation' ), self::format_amount( $min, $currency ) ),
			];
		}

		if ( $max > 0 && $amount > $max + $epsilon ) {
			return [
				'valid'   => false,
				/* translators: %s: maximum amount with currency */
				'message' => sprintf( __( 'Payment amount cannot exceed %s.', 'suredonation' ), self::format_amount( $max, $currency ) ),
			];
		}

		// Validation passed for donation-amount field.
		return null;
	}

	/**
	 * Get block configuration by block name and slug.
	 *
	 * @param array<mixed> $block_config All block configurations.
	 * @param string       $block_name   Block name to search for.
	 * @param string       $slug         Slug to match.
	 * @return array<string, mixed>|null Block configuration if found, null otherwise.
	 * @since 0.0.1
	 */
	private static function get_block_config_by_name_and_slug( $block_config, $block_name, $slug ) {
		foreach ( $block_config as $config ) {
			if ( empty( $config ) || ! is_array( $config ) ) {
				continue;
			}

			if ( isset( $config['slug'] ) && $config['slug'] === $slug && isset( $config['block_name'] ) && $config['block_name'] === $block_name ) {
				return $config;
			}
		}
		return null;
	}
}
