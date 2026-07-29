<?php
/**
 * PayPal Helper Class.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Payments\PayPal;

use SureDonation\Inc\Payments\Payment_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal Helper.
 *
 * Static utility class for PayPal payment gateway operations.
 * With THIRD_PARTY integration, we only store the merchant's payer_id (merchant_id).
 * All API calls go through the middleware using partner credentials.
 *
 * @since 1.0.0
 */
class PayPal_Helper {
	/**
	 * Retrieve the middleware base URL for PayPal API communication.
	 *
	 * @since 1.0.0
	 * @return string The middleware base URL.
	 */
	public static function middle_ware_base_url() {
		return SUREDONATION_MIDDLEWARE_BASE_URL . 'payments/suredonation-paypal/';
	}

	/**
	 * Get all PayPal settings from payment settings.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> The PayPal settings array.
	 */
	public static function get_all_paypal_settings() {
		$paypal_settings = Payment_Helper::get_gateway_settings( 'paypal' );

		return ! empty( $paypal_settings ) ? $paypal_settings : self::get_default_paypal_settings();
	}

	/**
	 * Get the non-sensitive PayPal connection state for the admin UI.
	 *
	 * Mirrors the REST settings response (no secrets/HMAC material) so it can
	 * be bootstrapped into the admin page for instant render, and is the single
	 * source of truth shared by the REST endpoint and the localized data.
	 *
	 * @since 1.1.0
	 * @return array<string, mixed> Connection state safe to expose to the client.
	 */
	public static function get_connection_state() {
		$mode     = Payment_Helper::get_payment_mode();
		$settings = self::get_all_paypal_settings();

		return [
			'connected'       => self::is_paypal_connected( $mode ),
			'mode'            => $mode,
			'account_email'   => $settings['paypal_account_email'] ?? '',
			'account_name'    => $settings['account_name'] ?? '',
			'merchant_id'     => self::get_paypal_merchant_id( $mode ),
			'webhook_test_id' => $settings['webhook_test_id'] ?? '',
			'webhook_live_id' => $settings['webhook_live_id'] ?? '',
		];
	}

	/**
	 * Update all PayPal settings in payment settings.
	 *
	 * @param array<string, mixed> $settings The PayPal settings array to save.
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	public static function update_all_paypal_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		return Payment_Helper::update_gateway_settings( 'paypal', $settings );
	}

	/**
	 * Get default PayPal settings structure.
	 *
	 * With THIRD_PARTY integration, we only need the merchant_id (payer_id).
	 * No client_id/client_secret — all API calls use partner credentials via middleware.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Default PayPal settings array.
	 */
	public static function get_default_paypal_settings() {
		return [
			'paypal_sandbox_connected' => false,
			'paypal_live_connected'    => false,
			'paypal_account_email'     => '',
			'paypal_live_merchant_id'  => '',
			'paypal_test_merchant_id'  => '',
			// GIT-33: per-site signing material. Established at
			// /connect-url/create and required on every subsequent
			// merchant-scoped middleware call.
			'paypal_live_tracking_id'  => '',
			'paypal_test_tracking_id'  => '',
			'paypal_live_hmac_secret'  => '',
			'paypal_test_hmac_secret'  => '',
			'webhook_test_secret'      => '',
			'webhook_test_url'         => '',
			'webhook_test_id'          => '',
			'webhook_live_secret'      => '',
			'webhook_live_url'         => '',
			'webhook_live_id'          => '',
			'account_name'             => '',
		];
	}

	/**
	 * Check if PayPal is connected for the specified or current mode.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live'). If null, uses current mode.
	 * @since 1.0.0
	 * @return bool True if connected.
	 */
	public static function is_paypal_connected( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();

		if ( 'live' === $mode ) {
			return ! empty( $settings['paypal_live_connected'] )
				&& ! empty( $settings['paypal_live_merchant_id'] );
		}

		return ! empty( $settings['paypal_sandbox_connected'] )
			&& ! empty( $settings['paypal_test_merchant_id'] );
	}

	/**
	 * Get the PayPal merchant ID (payer_id) for the specified mode.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live').
	 * @since 1.0.0
	 * @return string The merchant ID.
	 */
	public static function get_paypal_merchant_id( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'paypal_live_merchant_id' : 'paypal_test_merchant_id';

		return isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? $settings[ $key ] : '';
	}

	/**
	 * Get the stored tracking_id for the specified mode (GIT-33).
	 *
	 * @param string|null $mode The payment mode ('test' or 'live').
	 * @since 1.0.0
	 * @return string The tracking ID, or empty string if not onboarded yet.
	 */
	public static function get_tracking_id( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'paypal_live_tracking_id' : 'paypal_test_tracking_id';

		return isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? $settings[ $key ] : '';
	}

	/**
	 * Get the stored per-site HMAC secret for the specified mode (GIT-33).
	 *
	 * @param string|null $mode The payment mode ('test' or 'live').
	 * @since 1.0.0
	 * @return string The HMAC secret, or empty string if not onboarded yet.
	 */
	public static function get_hmac_secret( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'paypal_live_hmac_secret' : 'paypal_test_hmac_secret';

		return isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? $settings[ $key ] : '';
	}

	/**
	 * Get the webhook ID for the specified mode.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live').
	 * @since 1.0.0
	 * @return string The webhook ID.
	 */
	public static function get_webhook_id( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'webhook_live_id' : 'webhook_test_id';

		return isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? $settings[ $key ] : '';
	}

	/**
	 * Get the middleware environment string for the current payment mode.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live').
	 * @since 1.0.0
	 * @return string 'production' or 'sandbox'.
	 */
	public static function get_middleware_environment( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		return 'live' === $mode ? 'production' : 'sandbox';
	}

	/**
	 * Format amount for PayPal API (handles zero-decimal currencies).
	 *
	 * @param float  $amount   Amount in major units.
	 * @param string $currency Currency code.
	 * @since 1.0.0
	 * @return string Formatted amount string.
	 */
	public static function format_amount_for_paypal( $amount, $currency ) {
		$zero_decimal_currencies = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF', 'HUF', 'TWD' ];

		if ( in_array( strtoupper( $currency ), $zero_decimal_currencies, true ) ) {
			return (string) intval( $amount );
		}

		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * Get the partner client ID for the PayPal JS SDK.
	 *
	 * With THIRD_PARTY integration, the SDK loads using the partner's client_id
	 * (not the merchant's) along with a merchant-id parameter.
	 * Stored in settings during onboarding (received from middleware).
	 *
	 * @since 1.0.0
	 * @return string The partner client ID.
	 */
	public static function get_partner_client_id() {
		$settings = self::get_all_paypal_settings();

		return isset( $settings['partner_client_id'] ) && is_string( $settings['partner_client_id'] ) ? $settings['partner_client_id'] : '';
	}

	/**
	 * Get PayPal BN code (Partner Attribution Id).
	 *
	 * @since 1.0.0
	 * @return string The BN code.
	 */
	public static function paypal_bn_code() {
		return 'BrainstormForceInc_Cart_PPCPDon';
	}

	/**
	 * Get the PayPal settings URL in admin.
	 *
	 * @since 1.0.0
	 * @return string Settings URL.
	 */
	public static function get_paypal_settings_url() {
		return Payment_Helper::get_settings_url( 'paypal' );
	}

	/**
	 * Get the webhook URL for the specified mode.
	 *
	 * @param string $mode The payment mode ('test' or 'live').
	 * @since 1.0.0
	 * @return string The webhook URL.
	 */
	public static function get_webhook_url( $mode ) {
		$endpoint = 'live' === $mode ? 'paypal_webhook_live' : 'paypal_webhook_test';
		return rest_url( 'suredonation/' . $endpoint );
	}

	/**
	 * Make a request to the middleware.
	 *
	 * Centralized method for all middleware communication. Since GIT-33,
	 * every merchant-scoped call is HMAC-signed with the per-site secret
	 * established at /connect-url/create. Two endpoints intentionally skip
	 * signing:
	 *   - /connect-url/create (no binding exists yet — this call creates it)
	 *   - /webhooks/verify-signature (middleware-side open endpoint)
	 *
	 * @param string               $endpoint Middleware endpoint (e.g., 'orders/create').
	 * @param array<string, mixed> $body     Request body.
	 * @param bool                 $sign     Whether to add HMAC auth headers. Default true.
	 * @param string|null          $mode     Payment mode ('test' or 'live'). Null = current mode.
	 * @return array<string, mixed>|\WP_Error Response data or error.
	 * @since 1.0.0
	 */
	public static function middleware_request( $endpoint, $body = [], $sign = true, $mode = null ) {
		$url       = self::middle_ware_base_url() . $endpoint;
		$body_json = (string) wp_json_encode( $body );

		$headers = [ 'Content-Type' => 'application/json' ];

		if ( $sign ) {
			$tracking_id = self::get_tracking_id( $mode );
			$hmac_secret = self::get_hmac_secret( $mode );

			if ( '' === $tracking_id || '' === $hmac_secret ) {
				return new \WP_Error(
					'not_onboarded',
					__( 'PayPal is not connected. Please reconnect from settings.', 'suredonation' )
				);
			}

			$timestamp     = (string) time();
			$nonce         = bin2hex( random_bytes( 16 ) );
			$signing_input = $timestamp . '.' . $nonce . '.' . hash( 'sha256', $body_json );
			$signature     = hash_hmac( 'sha256', $signing_input, $hmac_secret );

			$headers['X-Tracking-Id'] = $tracking_id;
			$headers['X-Timestamp']   = $timestamp;
			$headers['X-Nonce']       = $nonce;
			$headers['X-Signature']   = $signature;
		}

		$response = wp_remote_post(
			$url,
			[
				'body'      => $body_json,
				'headers'   => $headers,
				'timeout'   => 30,
				'sslverify' => 'local' !== wp_get_environment_type(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body_raw, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from middleware.', 'suredonation' ) );
		}

		if ( $status_code >= 400 ) {
			$message = $decoded['message'] ?? __( 'Middleware request failed.', 'suredonation' );
			return new \WP_Error( $decoded['error'] ?? 'middleware_error', $message );
		}

		return $decoded;
	}
}
