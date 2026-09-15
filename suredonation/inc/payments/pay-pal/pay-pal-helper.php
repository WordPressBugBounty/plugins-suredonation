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
	 * Maximum length of a single value stored in a donation activity log.
	 *
	 * @since 1.5.1
	 */
	private const MAX_LOG_VALUE_LENGTH = 200;

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
			'connected'        => self::is_paypal_connected( $mode ),
			'mode'             => $mode,
			'account_email'    => $settings['paypal_account_email'] ?? '',
			'account_name'     => $settings['account_name'] ?? '',
			'merchant_id'      => self::get_paypal_merchant_id( $mode ),
			'webhook_test_id'  => $settings['webhook_test_id'] ?? '',
			'webhook_live_id'  => $settings['webhook_live_id'] ?? '',
			// Connected is not the same as able to take money. Both of these
			// were already known during onboarding and discarded, which is how a
			// site could show Connected while every capture failed and no
			// webhook existed.
			'webhook_error'    => self::get_webhook_error( $mode ),
			'account_state'    => self::get_account_state( $mode ),
			'account_blockers' => self::get_account_blockers( $mode ),
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
			'paypal_sandbox_connected'  => false,
			'paypal_live_connected'     => false,
			'paypal_account_email'      => '',
			'paypal_live_merchant_id'   => '',
			'paypal_test_merchant_id'   => '',
			// The PayPal JS SDK loads with the PARTNER client id, which differs
			// per environment. A single mode-agnostic value let a test-mode
			// connect overwrite the live one (and vice versa), leaving the
			// sandbox SDK loaded against a live merchant — PayPal then renders
			// "Things don't appear to be working at the moment" and no donation
			// can be approved at all. Keyed per mode like every other credential.
			'partner_client_id_live'    => '',
			'partner_client_id_test'    => '',
			// GIT-33: per-site signing material. Established at
			// /connect-url/create and required on every subsequent
			// merchant-scoped middleware call.
			'paypal_live_tracking_id'   => '',
			'paypal_test_tracking_id'   => '',
			'paypal_live_hmac_secret'   => '',
			'paypal_test_hmac_secret'   => '',
			'webhook_test_secret'       => '',
			'webhook_test_url'          => '',
			'webhook_test_id'           => '',
			'webhook_live_secret'       => '',
			'webhook_live_url'          => '',
			'webhook_live_id'           => '',
			// Why the last webhook creation failed, per mode. Creation is
			// attempted during onboarding and its result was discarded, so a
			// merchant could connect "successfully" with no webhook and nobody
			// was told. Cleared as soon as a webhook is created.
			'webhook_live_error'        => '',
			'webhook_test_error'        => '',
			// What PayPal last reported about the merchant's ability to receive
			// payments, per mode. Fetched during onboarding and thrown away
			// before, which is why an account PayPal would not let receive
			// payments could show as Connected and fail every capture.
			'paypal_live_account_state' => [],
			'paypal_test_account_state' => [],
			'account_name'              => '',
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
	 * Get the reason the last webhook creation failed, for the given mode.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live'). Null = current mode.
	 * @since 1.5.1
	 * @return string Failure reason, or an empty string when there is none.
	 */
	public static function get_webhook_error( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'webhook_live_error' : 'webhook_test_error';

		return isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? $settings[ $key ] : '';
	}

	/**
	 * Record the outcome of a webhook creation attempt for the given mode.
	 *
	 * @param string $mode   The payment mode ('test' or 'live').
	 * @param string $reason Failure reason. An empty string clears a recorded failure.
	 * @since 1.5.1
	 * @return void
	 */
	public static function set_webhook_error( $mode, $reason = '' ) {
		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'webhook_live_error' : 'webhook_test_error';

		if ( ( $settings[ $key ] ?? '' ) === $reason ) {
			return;
		}

		$settings[ $key ] = '' === $reason ? '' : self::clean_log_value( $reason );

		self::update_all_paypal_settings( $settings );
	}

	/**
	 * Get what PayPal last reported about the merchant account, for the given mode.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live'). Null = current mode.
	 * @since 1.5.1
	 * @return array<string, mixed> Stored account state, empty when none was captured.
	 */
	public static function get_account_state( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'paypal_live_account_state' : 'paypal_test_account_state';

		return isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : [];
	}

	/**
	 * Reasons PayPal may refuse to capture donations for the connected account.
	 *
	 * Read from the stored snapshot rather than computed when it is captured, so
	 * the rule below can be corrected without every merchant reconnecting.
	 *
	 * Deliberately narrow. The healthy live merchant on the site that reported
	 * this (ticket 1521527) has CARD_PROCESSING_VIRTUAL_TERMINAL denied and
	 * takes donations perfectly well, so gating on "every capability active"
	 * would report working accounts as broken. Only the capability the donation
	 * flow actually uses is considered, and a capability PayPal did not mention
	 * is unknown rather than denied.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live'). Null = current mode.
	 * @since 1.5.1
	 * @return array<int, string> Reason codes; empty when nothing is known to be wrong.
	 */
	public static function get_account_blockers( $mode = null ) {
		$state = self::get_account_state( $mode );

		// Nothing captured: connections made before this was recorded say
		// nothing about the account either way, and guessing would be worse than
		// staying quiet. Reconnecting captures it.
		if ( empty( $state ) ) {
			return [];
		}

		$blockers = [];

		if ( isset( $state['payments_receivable'] ) && true !== $state['payments_receivable'] ) {
			$blockers[] = 'payments_not_receivable';
		}

		if ( isset( $state['primary_email_confirmed'] ) && true !== $state['primary_email_confirmed'] ) {
			$blockers[] = 'email_not_confirmed';
		}

		$capabilities = isset( $state['capabilities'] ) && is_array( $state['capabilities'] ) ? $state['capabilities'] : [];

		if ( isset( $capabilities['PAYPAL_CHECKOUT'] ) && 'ACTIVE' !== $capabilities['PAYPAL_CHECKOUT'] ) {
			$blockers[] = 'checkout_capability_inactive';
		}

		return $blockers;
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
	 * The partner client id is environment-specific: sandbox and production are
	 * separate PayPal applications. It must therefore be read for the mode being
	 * rendered, or the SDK is loaded against the wrong environment.
	 *
	 * @param string|null $mode The payment mode ('test' or 'live'). Null = current mode.
	 * @since 1.0.0
	 * @since 1.5.1 Resolved per mode.
	 * @return string The partner client ID.
	 */
	public static function get_partner_client_id( $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$settings = self::get_all_paypal_settings();
		$key      = 'live' === $mode ? 'partner_client_id_live' : 'partner_client_id_test';

		if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
			return $settings[ $key ];
		}

		// Sites onboarded before the split stored one mode-agnostic value whose
		// environment cannot be recovered after the fact. Fall back to it rather
		// than return nothing: an empty client id makes get_sdk_url()
		// return nothing, which would remove the donate button from every form on upgrade.
		// Reconnecting either mode writes the keyed value and retires this
		// fallback for that mode.
		return isset( $settings['partner_client_id'] ) && is_string( $settings['partner_client_id'] ) ? $settings['partner_client_id'] : '';
	}

	/**
	 * Build the PayPal JS SDK URL for a donation form.
	 *
	 * The URL carries the partner client id, the merchant of the given mode and
	 * the site currency, so it is mode-specific by construction. It is served
	 * to the form at runtime (Payment_Helper::get_frontend_gateway_config())
	 * rather than enqueued at render time, where a page cache kept the previous
	 * mode's merchant alive after a test/live switch.
	 *
	 * @param int         $form_id Donation form post ID, for per-form SDK arguments.
	 * @param string|null $mode    Payment mode; defaults to the current mode.
	 * @param string      $variant Which SDK build to produce: 'capture' pins the
	 *                             one-time intent, 'subscription' asks filters for
	 *                             the recurring one, 'default' keeps the historical
	 *                             single-URL behaviour.
	 * @return string SDK URL, or '' when PayPal is not usable in that mode.
	 * @since 1.5.1
	 */
	public static function get_sdk_url( $form_id, $mode = null, $variant = 'default' ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		if ( ! self::is_paypal_connected( $mode ) ) {
			return '';
		}

		$partner_client_id = self::get_partner_client_id( $mode );
		$merchant_id       = self::get_paypal_merchant_id( $mode );

		if ( empty( $partner_client_id ) || empty( $merchant_id ) ) {
			return '';
		}

		// THIRD_PARTY: the partner's client-id with the connected seller's merchant-id.
		$sdk_args = [
			'client-id'   => $partner_client_id,
			'merchant-id' => $merchant_id,
			'currency'    => Payment_Helper::get_currency(),
			'components'  => 'buttons',
			'intent'      => 'capture',
		];

		/**
		 * Filter PayPal SDK arguments.
		 *
		 * Pro adds vault=true and intent=subscription for subscription forms.
		 *
		 * `$variant` says which of the two SDK builds is being requested — see
		 * get_sdk_urls(). A filter that ignores it (an older Pro) still gets the
		 * historical single-variant behaviour, and get_sdk_urls() re-pins the
		 * capture variant afterwards so it cannot be turned into a subscription
		 * SDK by accident.
		 *
		 * @param array<string, string> $sdk_args SDK URL parameters.
		 * @param int                   $form_id  The donation form post ID.
		 * @param string                $variant  'default', 'capture' or 'subscription'.
		 * @since 1.0.0
		 */
		$sdk_args = apply_filters( 'suredonation_paypal_sdk_args', $sdk_args, absint( $form_id ), $variant );

		// A capture SDK must stay a capture SDK. PayPal bakes the intent into
		// the script at load time, so a filter that flipped it here would hand
		// the one-time flow a subscription SDK and break createOrder().
		if ( 'capture' === $variant ) {
			$sdk_args['intent'] = 'capture';
			unset( $sdk_args['vault'] );
		}

		// esc_url_raw() is what the enqueue path applied on output; the URL is
		// now served as data and injected client-side, so apply it here.
		return esc_url_raw( add_query_arg( $sdk_args, 'https://www.paypal.com/sdk/js' ) );
	}

	/**
	 * Both SDK URLs a form can need, keyed by the payment type they serve.
	 *
	 * PayPal carries the intent inside the SDK script and cannot be reloaded
	 * with a different one mid-page (its internal state persists), so a form
	 * where the donor chooses between one-time and recurring needs both builds
	 * present at once. They are told apart on the page by `data-namespace`, and
	 * the frontend picks whichever matches the active payment type.
	 *
	 * `subscription` is an empty string unless something actually asked for a
	 * subscription SDK — with no Pro, or on a form that offers only one-time,
	 * the filter leaves the intent alone and there is no second build to load.
	 *
	 * @param  int         $form_id The donation form post ID.
	 * @param  string|null $mode    Payment mode, or null for the current one.
	 * @return array{capture: string, subscription: string}
	 * @since  1.6.0
	 */
	public static function get_sdk_urls( $form_id, $mode = null ) {
		$capture      = self::get_sdk_url( $form_id, $mode, 'capture' );
		$subscription = self::get_sdk_url( $form_id, $mode, 'subscription' );

		// Identical URLs mean nothing opted the form into a subscription SDK,
		// so there is only one build to load.
		if ( $subscription === $capture ) {
			$subscription = '';
		}

		return [
			'capture'      => $capture,
			'subscription' => $subscription,
		];
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
			// `?? ` only guards null, and this is a decoded remote response — a
			// non-string `message` would be concatenated with the detail below
			// and handed to WP_Error, which expects a string.
			$message = isset( $decoded['message'] ) && is_string( $decoded['message'] ) && '' !== $decoded['message']
				? $decoded['message']
				: __( 'Middleware request failed.', 'suredonation' );

			// The middleware forwards the gateway's own error under `data`, and
			// dropping it left the admin with a generic failure message and no way
			// to tell an expired token from a duplicate webhook URL or an exceeded
			// account limit. Surface the reason PayPal actually gave.
			$detail = '';
			if ( isset( $decoded['data'] ) ) {
				$detail = self::extract_error_detail( $decoded['data'] );
			}

			if ( '' !== $detail ) {
				$message = sprintf(
					/* translators: 1: middleware error message, 2: reason reported by PayPal. */
					__( '%1$s (PayPal: %2$s)', 'suredonation' ),
					$message,
					$detail
				);
			}

			return new \WP_Error(
				is_string( $decoded['error'] ?? null ) ? $decoded['error'] : 'middleware_error',
				$message,
				[
					'detail' => $decoded['data'] ?? null,
					// The codes the message above no longer carries.
					'codes'  => isset( $decoded['data'] ) ? self::extract_error_codes( $decoded['data'] ) : '',
				]
			);
		}

		return $decoded;
	}

	/**
	 * Pull a readable reason out of a gateway error payload.
	 *
	 * PayPal reports failures either as a top-level name/message or as a list of
	 * per-field issues, so both shapes are flattened to something an admin can
	 * act on.
	 *
	 * @param mixed $data Error payload forwarded by the middleware.
	 * @return string Reason, or an empty string when none could be read.
	 * @since 1.4.0
	 */
	private static function extract_error_detail( $data ) {
		if ( is_string( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return '';
		}

		// Human sentences only. PayPal's own machine codes (NOT_AUTHORIZED,
		// PAYEE_ACCOUNT_NOT_VERIFIED, WEBHOOK_NUMBER_LIMIT_EXCEEDED…) used to be
		// spliced into this text, which made every message read like a stack
		// trace to the one person who has to act on it. They are still recorded,
		// as their own field — see extract_error_codes().
		$parts = [];

		if ( isset( $data['message'] ) && is_string( $data['message'] ) && '' !== $data['message'] ) {
			$parts[] = $data['message'];
		}

		if ( isset( $data['details'] ) && is_array( $data['details'] ) ) {
			foreach ( $data['details'] as $issue ) {
				if ( ! is_array( $issue ) ) {
					continue;
				}

				if ( isset( $issue['description'] ) && is_string( $issue['description'] ) && '' !== $issue['description'] ) {
					$parts[] = $issue['description'];
					continue;
				}

				// No description: a readable rendering of the code is still
				// better than dropping the only thing PayPal said.
				if ( isset( $issue['issue'] ) && is_string( $issue['issue'] ) && '' !== $issue['issue'] ) {
					$parts[] = self::humanize_gateway_code( $issue['issue'] );
				}
			}
		}

		// Some PayPal errors carry a name and nothing else at all.
		if ( empty( $parts ) && isset( $data['name'] ) && is_string( $data['name'] ) && '' !== $data['name'] ) {
			$parts[] = self::humanize_gateway_code( $data['name'] );
		}

		// Joined as sentences rather than dash-separated: this text is read by a
		// merchant in a notice, and PayPal does not reliably end its own strings
		// with punctuation.
		$sentences = [];
		foreach ( array_unique( $parts ) as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			$sentences[] = preg_match( '/[.!?]$/', $part ) ? $part : $part . '.';
		}

		return implode( ' ', $sentences );
	}

	/**
	 * Collect the machine codes out of a gateway error payload.
	 *
	 * Kept apart from the readable message so a support conversation can still
	 * quote the exact code — PayPal's own documentation is indexed by it — while
	 * the message a merchant reads stays in plain language.
	 *
	 * @param mixed $data Error payload forwarded by the middleware.
	 * @return string Comma-separated codes, or an empty string when there are none.
	 * @since 1.5.1
	 */
	private static function extract_error_codes( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$codes = [];

		if ( isset( $data['name'] ) && is_string( $data['name'] ) && '' !== $data['name'] ) {
			$codes[] = $data['name'];
		}

		if ( isset( $data['details'] ) && is_array( $data['details'] ) ) {
			foreach ( $data['details'] as $issue ) {
				if ( is_array( $issue ) && isset( $issue['issue'] ) && is_string( $issue['issue'] ) && '' !== $issue['issue'] ) {
					$codes[] = $issue['issue'];
				}
			}
		}

		return implode( ', ', array_unique( $codes ) );
	}

	/**
	 * Render a gateway's SHOUTING_SNAKE_CASE code as a readable phrase.
	 *
	 * Used only where the gateway gave a code and no sentence, so that the
	 * fallback still reads like something written for a person.
	 *
	 * @param string $code Gateway code.
	 * @return string Readable phrase.
	 * @since 1.5.1
	 */
	private static function humanize_gateway_code( $code ) {
		$words = strtolower( str_replace( [ '_', '-' ], ' ', $code ) );

		return ucfirst( trim( $words ) );
	}

	/**
	 * Sanitise and cap a value before it is stored in a donation activity log.
	 *
	 * The values worth recording when a payment fails are neither escaped nor
	 * size-limited at source: middleware_request() appends the gateway's own
	 * error text to its message, and the error payload it carries alongside is
	 * an arbitrarily large decoded response. The admin renders each log value as
	 * text, so a nested payload would otherwise show up as "[object Object]".
	 *
	 * @param mixed $value Raw value. Arrays and objects are JSON encoded.
	 * @return string Value safe to store.
	 * @since 1.5.1
	 */
	public static function clean_log_value( $value ) {
		if ( null === $value || is_bool( $value ) ) {
			return '';
		}

		if ( ! is_string( $value ) ) {
			$encoded = wp_json_encode( $value );
			$value   = is_string( $encoded ) ? $encoded : '';
		}

		if ( '' === $value ) {
			return '';
		}

		return mb_substr( sanitize_text_field( $value ), 0, self::MAX_LOG_VALUE_LENGTH );
	}
}
