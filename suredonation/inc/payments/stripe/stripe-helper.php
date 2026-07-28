<?php
/**
 * Stripe Helper - Stripe-specific utilities
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Payments\Stripe;

use SureDonation\Inc\Payments\Payment_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe_Helper class
 * Provides Stripe-specific utilities
 *
 * @since 0.0.1
 */
class Stripe_Helper {
	/**
	 * Get all Stripe settings
	 *
	 * @return array<string, mixed> Stripe settings.
	 * @since 0.0.1
	 */
	public static function get_all_stripe_settings() {
		return Payment_Helper::get_gateway_settings( 'stripe' );
	}

	/**
	 * Update all Stripe settings
	 *
	 * @param array<string, mixed> $settings Stripe settings.
	 * @return bool True on success.
	 * @since 0.0.1
	 */
	public static function update_all_stripe_settings( $settings ) {
		return Payment_Helper::update_gateway_settings( 'stripe', $settings );
	}

	/**
	 * Check if Stripe is connected
	 *
	 * @return bool True if connected.
	 * @since 0.0.1
	 */
	public static function is_stripe_connected() {
		$settings = self::get_all_stripe_settings();
		return ! empty( $settings['stripe_connected'] ) && ! empty( $settings['stripe_account_id'] );
	}

	/**
	 * Get Stripe secret key (mode-aware)
	 *
	 * @param string $mode Optional. Payment mode ('test' or 'live'). Defaults to current mode.
	 * @return string Secret key.
	 * @since 0.0.1
	 */
	public static function get_stripe_secret_key( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}
		$settings = self::get_all_stripe_settings();

		if ( 'live' === $mode ) {
			$key = $settings['stripe_live_secret_key'] ?? '';
			return is_string( $key ) ? $key : '';
		}

		$key = $settings['stripe_test_secret_key'] ?? '';
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Get Stripe publishable key (mode-aware)
	 *
	 * @return string Publishable key.
	 * @since 0.0.1
	 */
	public static function get_stripe_publishable_key() {
		$mode     = Payment_Helper::get_payment_mode();
		$settings = self::get_all_stripe_settings();

		if ( 'live' === $mode ) {
			$key = $settings['stripe_live_publishable_key'] ?? '';
			return is_string( $key ) ? $key : '';
		}

		$key = $settings['stripe_test_publishable_key'] ?? '';
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Get Stripe webhook secret (mode-aware)
	 *
	 * @return string Webhook secret.
	 * @since 0.0.1
	 */
	public static function get_webhook_secret() {
		$mode     = Payment_Helper::get_payment_mode();
		$settings = self::get_all_stripe_settings();

		if ( 'live' === $mode ) {
			$secret = $settings['webhook_live_secret'] ?? '';
			return is_string( $secret ) ? $secret : '';
		}

		$secret = $settings['webhook_test_secret'] ?? '';
		return is_string( $secret ) ? $secret : '';
	}

	/**
	 * Get webhook URL (mode-aware)
	 *
	 * @param string $mode Payment mode ('test' or 'live').
	 * @return string Webhook URL.
	 * @since 0.0.1
	 */
	public static function get_webhook_url( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		return rest_url( 'suredonation/webhook_' . $mode );
	}

	/**
	 * Get Stripe Connect URL for OAuth
	 *
	 * @return string Connect URL.
	 * @since 0.0.1
	 */
	public static function get_stripe_connect_url() {
		// Generate nonce and store in user-specific transient.
		$nonce = wp_create_nonce( 'stripe-connect' );
		set_transient( 'suredonation_stripe_connect_nonce_' . get_current_user_id(), $nonce, HOUR_IN_SECONDS );

		// Redirect URL after OAuth with nonce parameter for verification.
		$redirect_url        = admin_url( 'admin.php?page=suredonation' );
		$redirect_with_nonce = add_query_arg( 'suredonation_stripe_connect_nonce', $nonce, $redirect_url );

		// State parameter with base64-encoded redirect info.
		$json_state = wp_json_encode(
			[
				'redirect' => $redirect_with_nonce,
			]
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$state = base64_encode( is_string( $json_state ) ? $json_state : '' );

		// Stripe Connect OAuth URL.
		return add_query_arg(
			[
				'response_type'  => 'code',
				'client_id'      => 'ca_KOXfLe7jv1m4L0iC4KNEMc5fT8AXWWuL',
				'stripe_landing' => 'login',
				'always_prompt'  => 'true',
				'scope'          => 'read_write',
				'state'          => $state,
			],
			'https://connect.stripe.com/oauth/authorize'
		);
	}

	/**
	 * Get Stripe account ID
	 *
	 * @return string Account ID.
	 * @since 0.0.1
	 */
	public static function get_stripe_account_id() {
		$settings   = self::get_all_stripe_settings();
		$account_id = $settings['stripe_account_id'] ?? '';
		return is_string( $account_id ) ? $account_id : '';
	}

	/**
	 * Get Stripe account email
	 *
	 * @return string Account email.
	 * @since 0.0.1
	 */
	public static function get_stripe_account_email() {
		$settings      = self::get_all_stripe_settings();
		$account_email = $settings['stripe_account_email'] ?? '';
		return is_string( $account_email ) ? $account_email : '';
	}

	/**
	 * Flatten nested array for Stripe API format.
	 *
	 * Converts nested arrays to Stripe's bracket notation format.
	 * For example: ['metadata' => ['key' => 'value']] becomes ['metadata[key]' => 'value']
	 *
	 * @param array<string, mixed> $data   The data to flatten.
	 * @param string               $prefix The prefix for nested keys.
	 * @return array<string, mixed> Flattened data.
	 * @since 0.0.1
	 */
	public static function flatten_stripe_data( $data, $prefix = '' ) {
		$result = [];

		foreach ( $data as $key => $value ) {
			$new_key = '' === $prefix ? $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				// Check if it's a sequential array (list).
				if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
					// Sequential array - use indexed notation.
					foreach ( $value as $index => $item ) {
						if ( is_array( $item ) ) {
							$result = array_merge( $result, self::flatten_stripe_data( $item, $new_key . '[' . $index . ']' ) );
						} else {
							$result[ $new_key . '[' . $index . ']' ] = $item;
						}
					}
				} else {
					// Associative array - recurse.
					$result = array_merge( $result, self::flatten_stripe_data( $value, $new_key ) );
				}
			} else {
				$result[ $new_key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Make Stripe API request
	 *
	 * @param string              $endpoint   Endpoint path.
	 * @param string              $method     HTTP method.
	 * @param array<string,mixed> $data       Request data.
	 * @param array<string,mixed> $extra_args Extra arguments (e.g., 'mode' to specify test/live).
	 * @return array<string,mixed>|\WP_Error Response data.
	 * @since 0.0.1
	 */
	public static function stripe_api_request( $endpoint, $method = 'POST', $data = [], $extra_args = [] ) {
		// Get mode from extra_args or default to current payment mode.
		$mode       = isset( $extra_args['mode'] ) && is_string( $extra_args['mode'] ) ? $extra_args['mode'] : '';
		$secret_key = self::get_stripe_secret_key( $mode );

		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'no_secret_key', __( 'Stripe secret key not configured', 'suredonation' ) );
		}

		$url = 'https://api.stripe.com/v1/' . ltrim( $endpoint, '/' );

		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'timeout' => 30,
		];

		if ( ! empty( $data ) ) {
			// Flatten nested arrays for Stripe API format.
			$flattened_data = self::flatten_stripe_data( $data );
			$args['body']   = http_build_query( $flattened_data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			$error_message = is_array( $data ) && isset( $data['error']['message'] )
				? strval( $data['error']['message'] )
				: __( 'Unknown error', 'suredonation' );
			return new \WP_Error( 'stripe_api_error', $error_message, $data );
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from Stripe API', 'suredonation' ) );
		}

		return $data;
	}

	/**
	 * Create Stripe customer
	 *
	 * @param array<string,mixed> $customer_data Customer data.
	 * @return array<string,mixed>|\WP_Error Customer data.
	 * @since 0.0.1
	 */
	public static function create_customer( $customer_data ) {
		$data = [
			'email'       => $customer_data['email'] ?? '',
			'name'        => $customer_data['name'] ?? '',
			'description' => $customer_data['description'] ?? '',
		];

		// Remove empty values.
		$data = array_filter( $data );

		return self::stripe_api_request( 'customers', 'POST', $data );
	}

	/**
	 * Verify that a Stripe customer exists.
	 *
	 * This is useful when reusing cached customer IDs to handle mode switches
	 * (test/live) or deleted customers gracefully.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @return bool True if customer exists, false otherwise.
	 * @since 0.0.1
	 */
	public static function verify_customer_exists( $customer_id ) {
		if ( empty( $customer_id ) ) {
			return false;
		}

		$response = self::stripe_api_request( 'customers/' . $customer_id, 'GET' );

		// If it's an error or customer was deleted, return false.
		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Check if customer was deleted.
		if ( isset( $response['deleted'] ) && true === $response['deleted'] ) {
			return false;
		}

		// Customer exists if we got an ID back.
		return ! empty( $response['id'] );
	}

	/**
	 * Create Payment Intent
	 *
	 * @param array<string,mixed> $intent_data Payment intent data.
	 * @return array<string,mixed>|\WP_Error Intent data.
	 * @since 0.0.1
	 */
	public static function create_payment_intent( $intent_data ) {
		$data = [
			'amount'                             => $intent_data['amount'],
			'currency'                           => $intent_data['currency'] ?? Payment_Helper::get_currency(),
			'automatic_payment_methods[enabled]' => 'true',
		];

		if ( ! empty( $intent_data['customer'] ) ) {
			$data['customer'] = $intent_data['customer'];
		}

		if ( ! empty( $intent_data['description'] ) ) {
			$data['description'] = $intent_data['description'];
		}

		if ( ! empty( $intent_data['metadata'] ) && is_array( $intent_data['metadata'] ) ) {
			foreach ( $intent_data['metadata'] as $key => $value ) {
				$data[ 'metadata[' . strval( $key ) . ']' ] = $value;
			}
		}

		return self::stripe_api_request( 'payment_intents', 'POST', $data );
	}

	/**
	 * Retrieve Payment Intent
	 *
	 * @param string $intent_id Payment intent ID.
	 * @return array<string,mixed>|\WP_Error Intent data.
	 * @since 0.0.1
	 */
	public static function retrieve_payment_intent( $intent_id ) {
		return self::stripe_api_request( 'payment_intents/' . $intent_id, 'GET' );
	}

	/**
	 * Create refund
	 *
	 * @param string   $payment_intent_id Payment intent ID.
	 * @param int|null $amount            Amount to refund (in cents). Leave empty for full refund.
	 * @param string   $reason            Refund reason.
	 * @return array<string,mixed>|\WP_Error Refund data.
	 * @since 0.0.1
	 */
	public static function create_refund( $payment_intent_id, $amount = null, $reason = '' ) {
		// Stripe accepts either 'payment_intent' or 'charge' — detect by prefix.
		$key  = 0 === strpos( $payment_intent_id, 'ch_' ) ? 'charge' : 'payment_intent';
		$data = [
			$key => $payment_intent_id,
		];

		if ( ! empty( $amount ) ) {
			$data['amount'] = $amount;
		}

		if ( ! empty( $reason ) ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- This is documentation, not code.
			// Valid values: 'duplicate', 'fraudulent', 'requested_by_customer'.
			$data['reason'] = $reason;
		}

		return self::stripe_api_request( 'refunds', 'POST', $data );
	}

	/**
	 * Retrieve the middleware base URL for Stripe API communication.
	 *
	 * The middleware handles platform fee calculation based on license tier and
	 * securely proxies requests between the plugin and Stripe's API.
	 *
	 * Developers working in local or staging environments can override the
	 * SUREDONATION_MIDDLEWARE_BASE_URL constant to point to a locally running
	 * payments middleware app.
	 *
	 * @return string The middleware base URL.
	 * @since 0.0.1
	 */
	public static function middle_ware_base_url() {
		return SUREDONATION_MIDDLEWARE_BASE_URL . 'payments/suredonation-stripe/';
	}

	/**
	 * Create Payment Intent via Middleware.
	 *
	 * Uses the middleware for payment processing which handles platform fees
	 * based on license tier automatically.
	 *
	 * @param array<string,mixed> $intent_data Payment intent data.
	 * @return array<string,mixed>|\WP_Error Intent data.
	 * @since 0.0.1
	 */
	public static function create_payment_intent_via_middleware( $intent_data ) {
		$secret_key = self::get_stripe_secret_key();

		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'no_secret_key', __( 'Stripe secret key not configured', 'suredonation' ) );
		}

		// Extract and validate currency.
		$currency = isset( $intent_data['currency'] ) && is_string( $intent_data['currency'] )
			? $intent_data['currency']
			: Payment_Helper::get_currency();

		// Extract metadata, ensuring it's an array.
		$metadata = isset( $intent_data['metadata'] ) && is_array( $intent_data['metadata'] )
			? $intent_data['metadata']
			: [];

		// Add source identifier to metadata.
		$metadata['source'] = 'SureDonation';

		// Prepare data for middleware.
		$middleware_data = [
			'secret_key'                => $secret_key,
			'amount'                    => $intent_data['amount'],
			'currency'                  => strtolower( $currency ),
			'description'               => $intent_data['description'] ?? __( 'SureDonation Payment', 'suredonation' ),
			'confirm'                   => false,
			'license_key'               => '',
			'automatic_payment_methods' => [
				'enabled'         => true,
				'allow_redirects' => 'never',
			],
			'metadata'                  => $metadata,
		];

		// Add customer_id if provided (middleware maps this to Stripe's 'customer' field).
		if ( ! empty( $intent_data['customer'] ) ) {
			$middleware_data['customer_id'] = $intent_data['customer'];
		}

		// Add receipt_email if provided.
		if ( ! empty( $intent_data['receipt_email'] ) ) {
			$middleware_data['receipt_email'] = $intent_data['receipt_email'];
		}

		/**
		 * Filter payment intent data before sending to middleware.
		 *
		 * @param array<string,mixed> $middleware_data The payment intent data.
		 * @param mixed               $customer_id     The Stripe customer ID if available.
		 */
		$middleware_data = apply_filters(
			'suredonation_create_payment_intent_data',
			$middleware_data,
			$intent_data['customer'] ?? null
		);

		// Re-enforce secret key after filter to prevent diversion via malicious filter callback.
		$middleware_data['secret_key'] = $secret_key;

		$json_data = wp_json_encode( $middleware_data );
		$json_data = is_string( $json_data ) ? $json_data : '';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for middleware API.
		$encoded_data = base64_encode( $json_data );

		$response = wp_remote_post(
			self::middle_ware_base_url() . 'payment-intent/create',
			[
				'body'    => $encoded_data,
				'headers' => [
					'Content-Type' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'middleware_error', __( 'Failed to connect to payment processor', 'suredonation' ) );
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from payment processor', 'suredonation' ) );
		}

		// Check for error response from middleware.
		if ( isset( $result['status'] ) && 'error' === $result['status'] && ! empty( $result['code'] ) ) {
			$error_message = $result['message'] ?? __( 'Payment processing failed', 'suredonation' );
			return new \WP_Error( $result['code'], $error_message );
		}

		// Validate required fields.
		if ( empty( $result['client_secret'] ) || empty( $result['id'] ) ) {
			return new \WP_Error( 'invalid_response', __( 'Missing required payment data', 'suredonation' ) );
		}

		return $result;
	}

	/**
	 * Capture a PaymentIntent via the middleware API.
	 *
	 * Called after payment confirmation when the PaymentIntent status is 'requires_capture'.
	 * This is required because the middleware creates PaymentIntents with capture_method='manual'.
	 *
	 * @param string $payment_intent_id Payment Intent ID to capture.
	 * @return array<string,mixed>|\WP_Error Captured payment intent data.
	 * @since 0.0.1
	 */
	public static function capture_payment_intent_via_middleware( $payment_intent_id ) {
		$secret_key = self::get_stripe_secret_key();

		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'no_secret_key', __( 'Stripe secret key not configured', 'suredonation' ) );
		}

		if ( empty( $payment_intent_id ) ) {
			return new \WP_Error( 'missing_payment_intent_id', __( 'Payment intent ID is required', 'suredonation' ) );
		}

		$middleware_data = [
			'secret_key'        => $secret_key,
			'payment_intent_id' => $payment_intent_id,
			'stripe_account_id' => self::get_stripe_account_id(),
			'plugin_name'       => 'SureDonation',
		];

		$json_data = wp_json_encode( $middleware_data );
		$json_data = is_string( $json_data ) ? $json_data : '';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for middleware API.
		$encoded_data = base64_encode( $json_data );

		$response = wp_remote_post(
			self::middle_ware_base_url() . 'payment-intent/capture',
			[
				'body'    => $encoded_data,
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'middleware_error', __( 'Failed to connect to payment processor', 'suredonation' ) );
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from payment processor', 'suredonation' ) );
		}

		if ( isset( $result['status'] ) && 'error' === $result['status'] && ! empty( $result['code'] ) ) {
			$error_message = $result['message'] ?? __( 'Payment capture failed', 'suredonation' );
			return new \WP_Error( $result['code'], $error_message );
		}

		return $result;
	}

	/**
	 * Report a settled charge to the middleware intersect endpoint for analytics.
	 *
	 * @param string $charge_id         Stripe charge ID (ch_ or py_ format).
	 * @param string $secret_key        Stripe secret key. Resolved from settings when empty.
	 * @param string $stripe_account_id Stripe account ID. Resolved from settings when empty.
	 * @param string $plugin_name       Plugin source label for analytics attribution.
	 * @return void
	 * @since 1.1.2
	 */
	public static function intersect_payment( $charge_id, $secret_key = '', $stripe_account_id = '', $plugin_name = 'SureDonation' ) {
		if ( empty( $charge_id ) || ! preg_match( '/^(?:ch|py)_[a-zA-Z0-9]+$/', $charge_id ) ) {
			return;
		}

		if ( empty( $secret_key ) ) {
			$secret_key = self::get_stripe_secret_key();
		}

		if ( empty( $secret_key ) ) {
			return;
		}

		// Analytics is reported for live-mode transactions only; never report test charges.
		if ( 0 !== strpos( $secret_key, 'sk_live_' ) && 0 !== strpos( $secret_key, 'rk_live_' ) ) {
			return;
		}

		if ( empty( $stripe_account_id ) ) {
			$stripe_account_id = self::get_stripe_account_id();
		}

		$request_data = [
			'plugin_name'    => ! empty( $plugin_name ) ? $plugin_name : 'SureDonation',
			'secret_key'     => $secret_key,
			'transaction_id' => $charge_id,
			'account_id'     => $stripe_account_id,
		];

		$request_body = wp_json_encode( $request_data );
		$request_body = is_string( $request_body ) ? $request_body : '';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for middleware API.
		$request_body = base64_encode( $request_body );

		if ( empty( $request_body ) ) {
			return;
		}

		wp_remote_post(
			self::middle_ware_base_url() . 'payment/intersect',
			[
				'timeout'  => 5,
				'blocking' => false,
				'body'     => $request_body,
				'headers'  => [
					'Content-Type' => 'application/json',
				],
			]
		);
	}
}
