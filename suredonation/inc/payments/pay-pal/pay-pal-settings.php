<?php
/**
 * PayPal Settings - Configuration and gateway registration.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Payments\PayPal;

use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal_Settings class.
 *
 * Handles PayPal onboarding, settings, gateway registration, and SDK enqueue.
 *
 * @since 1.0.0
 */
class PayPal_Settings {

	/**
	 * Cron hook that performs the relay registration.
	 *
	 * @since 1.6.1
	 */
	public const RELAY_REGISTER_HOOK = 'suredonation_register_paypal_relay_routing';

	/**
	 * Held after a failed registration so a broken middleware is not retried
	 * on every admin page load.
	 *
	 * @since 1.6.1
	 */
	private const RELAY_REGISTER_BACKOFF = 'suredonation_paypal_relay_backoff';
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// REST API endpoints for admin operations.
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// Intercept PayPal onboarding callback in popup window.
		add_action( 'admin_init', [ $this, 'intercept_paypal_onboarding_callback' ] );
		add_action( 'admin_init', [ $this, 'backfill_relay_host' ] );
		add_action( 'admin_init', [ $this, 'maybe_register_relay_routing' ] );
		add_action( self::RELAY_REGISTER_HOOK, [ $this, 'run_relay_registration' ] );

		// Gateway registration hooks.
		add_filter( 'suredonation_payment_gateways', [ $this, 'register_gateway' ] );
		add_filter( 'suredonation_editor_payment_gateways', [ $this, 'register_editor_gateway' ] );
		add_filter( 'suredonation_available_payment_methods', [ $this, 'add_paypal_availability' ], 10, 2 );
		add_filter( 'suredonation_registered_payment_methods', [ $this, 'register_paypal_payment_method' ], 10, 2 );

		// Frontend configuration for donation forms. The SDK itself is loaded by
		// the form script from the runtime configuration, not enqueued here.
		add_action( 'suredonation_enqueue_form_frontend_scripts', [ $this, 'maybe_localize_paypal_config' ], 10, 2 );
		add_filter( 'suredonation_frontend_gateway_config', [ $this, 'add_frontend_gateway_config' ], 10, 3 );
	}

	/**
	 * Intercept PayPal onboarding callback after full-page redirect.
	 *
	 * THIRD_PARTY onboarding: PayPal redirects the full page to return_url
	 * with merchantIdInPayPal. We verify the merchant, store the ID,
	 * create a webhook, and redirect to the settings page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function intercept_paypal_onboarding_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified explicitly below.
		if ( ! isset( $_GET['paypal-process-onboard'] ) || '1' !== $_GET['paypal-process-onboard'] ) {
			return;
		}

		// Only ever fires on our own settings screen, matching the return_url
		// minted in get_connect_url().
		if ( ! isset( $_GET['page'] ) || 'suredonation' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce       = isset( $_GET['suredonation_paypal_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['suredonation_paypal_nonce'] ) ) : '';
		$merchant_id = isset( $_GET['merchantIdInPayPal'] ) ? sanitize_text_field( wp_unslash( $_GET['merchantIdInPayPal'] ) ) : '';
		$environment = isset( $_GET['environment'] ) ? sanitize_text_field( wp_unslash( $_GET['environment'] ) ) : 'sandbox';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// CSRF protection: this handler runs on admin_init (no REST cookie-nonce
		// coupling), so a cross-site request in a logged-in admin's browser would
		// otherwise reach it on the auth cookie alone. Require the nonce we minted
		// into return_url, and confirm it belongs to this admin's in-flight
		// onboarding session.
		$nonce_transient_key = 'suredonation_paypal_connect_nonce_' . get_current_user_id();
		if ( empty( $nonce )
			|| ! wp_verify_nonce( $nonce, 'suredonation-paypal-connect' )
			|| ! hash_equals( (string) get_transient( $nonce_transient_key ), $nonce ) ) {
			wp_die(
				esc_html__( 'Security verification failed. Please restart the PayPal connection from settings.', 'suredonation' ),
				esc_html__( 'PayPal Connect Error', 'suredonation' ),
				[ 'response' => 403 ]
			);
		}

		// Single use — a failed or abandoned onboarding must be restarted.
		delete_transient( $nonce_transient_key );

		$redirect_url = PayPal_Helper::get_paypal_settings_url();

		// `environment` selects which mode's merchant ID is written below, so it
		// must be one of the known values rather than "anything but production
		// means sandbox".
		if ( ! in_array( $environment, [ 'sandbox', 'production' ], true ) ) {
			wp_safe_redirect( add_query_arg( 'paypal_error', 'invalid_environment', $redirect_url ) );
			exit;
		}

		if ( empty( $merchant_id ) ) {
			wp_safe_redirect( add_query_arg( 'paypal_error', 'missing_merchant_id', $redirect_url ) );
			exit;
		}

		// Verify merchant onboarding status via middleware.
		$status_result = PayPal_Helper::middleware_request(
			'merchant/status',
			[
				'merchant_id' => $merchant_id,
				'environment' => $environment,
			]
		);

		if ( is_wp_error( $status_result ) ) {
			wp_safe_redirect( add_query_arg( 'paypal_error', 'verification_failed', $redirect_url ) );
			exit;
		}

		$verification = $this->verify_merchant_status( $status_result );
		if ( true !== $verification ) {
			wp_safe_redirect( add_query_arg( 'paypal_error', 'incomplete_onboarding', $redirect_url ) );
			exit;
		}

		$this->store_merchant_connection( $merchant_id, $environment, $status_result );

		// Redirect to settings page with success flag.
		wp_safe_redirect( add_query_arg( 'paypal_connected', '1', $redirect_url ) );
		exit;
	}

	/**
	 * Register PayPal in the supported gateways list.
	 *
	 * @param array<string, array<string, mixed>> $gateways Existing gateways.
	 * @return array<string, array<string, mixed>> Modified gateways.
	 * @since 1.0.0
	 */
	public function register_gateway( $gateways ) {
		$gateways['paypal'] = [
			'label'              => __( 'PayPal', 'suredonation' ),
			'description'        => __( 'Accept payments via PayPal', 'suredonation' ),
			'enabled'            => PayPal_Helper::is_paypal_connected(),
			'supports_recurring' => false, // Pro overrides this to true.
		];

		return $gateways;
	}

	/**
	 * Add PayPal to the block editor gateways dropdown.
	 *
	 * @param array<int, array<string, mixed>> $gateways Existing editor gateways.
	 * @return array<int, array<string, mixed>> Modified gateways.
	 * @since 1.0.0
	 */
	public function register_editor_gateway( $gateways ) {
		$gateways[] = [
			'value'              => 'paypal',
			'label'              => __( 'PayPal', 'suredonation' ),
			'supports_recurring' => false, // Pro overrides this.
		];

		return $gateways;
	}

	/**
	 * Add PayPal to available payment methods when connected.
	 *
	 * @param array<string> $available            Currently available method IDs.
	 * @param array<string> $block_payment_methods Methods selected in the block.
	 * @return array<string> Modified available methods.
	 * @since 1.0.0
	 */
	public function add_paypal_availability( $available, $block_payment_methods ) {
		if ( in_array( 'paypal', $block_payment_methods, true ) && PayPal_Helper::is_paypal_connected() ) {
			$available[] = 'paypal';
		}

		return $available;
	}

	/**
	 * Register PayPal payment method markup configuration.
	 *
	 * @param array<string, array<string, mixed>> $methods         Registered methods.
	 * @param array<string>                       $payment_methods Methods selected in block.
	 * @return array<string, array<string, mixed>> Modified methods.
	 * @since 1.0.0
	 */
	public function register_paypal_payment_method( $methods, $payment_methods ) {
		if ( ! in_array( 'paypal', $payment_methods, true ) || ! PayPal_Helper::is_paypal_connected() ) {
			return $methods;
		}

		$methods['paypal'] = [
			'id'              => 'paypal',
			'label'           => __( 'PayPal', 'suredonation' ),
			'enabled'         => true,
			'container_class' => 'sd-paypal-button-container',
		];

		return $methods;
	}

	/**
	 * Localize the PayPal frontend configuration on donation forms that use PayPal.
	 *
	 * The SDK is deliberately not enqueued here. Its URL carries the merchant of
	 * the current payment mode and the site currency, this output is stored by
	 * full-page caches, and a cached SDK tag kept serving the previous mode's
	 * merchant after a test/live switch. The form script fetches the URL at
	 * runtime instead (see add_frontend_gateway_config()) and injects the SDK
	 * itself. The render-time URL is still passed along as `sdkUrl`, but only
	 * as the fallback the script uses when that runtime read fails — the same
	 * role the rendered data-stripe-key plays for Stripe.
	 *
	 * @param int    $form_id      The donation form post ID.
	 * @param string $form_content The form post content (blocks).
	 * @since 1.5.1
	 * @return void
	 */
	public function maybe_localize_paypal_config( $form_id, $form_content ) {
		if ( ! PayPal_Helper::is_paypal_connected() ) {
			return;
		}

		// Check if form content has a payment block with PayPal enabled.
		if ( ! $this->form_has_paypal( $form_content ) ) {
			return;
		}

		wp_localize_script(
			'suredonation-form-frontend',
			'suredonationPayPalConfig',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'suredonation_donation_form' ),
				// Render-time SDK URL. Read by the script only when the runtime
				// configuration request fails; the runtime value wins otherwise.
				'sdkUrl'  => PayPal_Helper::get_sdk_url( $form_id ),
				'sdkUrls' => PayPal_Helper::get_sdk_urls( $form_id ),
				'errors'  => [
					'generic'     => __( 'An error occurred while processing your PayPal payment. Please try again.', 'suredonation' ),
					'cancelled'   => __( 'Payment was cancelled.', 'suredonation' ),
					'notLoaded'   => __( 'PayPal SDK failed to load. Please refresh the page.', 'suredonation' ),
					// Shown when the server reports that PayPal is not connected
					// for the current payment mode, typically a page cached
					// before the mode was switched.
					'unavailable' => __( 'PayPal is not available right now. Please choose another payment method.', 'suredonation' ),
					// The donor switched between one-time and recurring while
					// PayPal's approval window was open, so what came back does
					// not match what the form is now set to.
					'typeChanged' => __( 'The donation type changed while PayPal was open. Please reload the page and try again.', 'suredonation' ),
				],
			]
		);
	}

	/**
	 * Add the PayPal section to the runtime gateway configuration.
	 *
	 * @param array<string, mixed> $config  Configuration keyed for the frontend script.
	 * @param int                  $form_id Donation form post ID.
	 * @param string               $mode    Current payment mode.
	 * @return array<string, mixed>
	 * @since 1.5.1
	 */
	public function add_frontend_gateway_config( $config, $form_id, $mode ) {
		$sdk_urls = PayPal_Helper::get_sdk_urls( $form_id, $mode );

		// `sdkUrl` stays the single URL the form would have rendered with, so a
		// script from before the two-build split keeps working. `sdkUrls` is what
		// a current script reads: a form the donor can switch between one-time and
		// recurring needs both intents loaded at once.
		$sdk_url = '' !== $sdk_urls['subscription'] ? $sdk_urls['subscription'] : $sdk_urls['capture'];

		$config['paypal'] = '' !== $sdk_url
			? [
				'sdkUrl'  => $sdk_url,
				'sdkUrls' => $sdk_urls,
			]
			: null;

		return $config;
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Get PayPal settings.
		register_rest_route(
			'suredonation/v1',
			'/payments/paypal/settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Get PayPal Connect URL.
		register_rest_route(
			'suredonation/v1',
			'/payments/paypal/connect-url',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_connect_url' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Complete PayPal onboarding.
		register_rest_route(
			'suredonation/v1',
			'/payments/paypal/onboard-complete',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'onboard_complete' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'merchantIdInPayPal' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'mode'               => [
						'type'              => 'string',
						'default'           => 'test',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $param ) {
							return in_array( $param, [ 'test', 'live' ], true );
						},
					],
				],
			]
		);

		// Poll onboarding status by tracking_id.
		register_rest_route(
			'suredonation/v1',
			'/payments/paypal/onboard-status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'check_onboard_status' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Disconnect PayPal.
		register_rest_route(
			'suredonation/v1',
			'/payments/paypal/disconnect',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'disconnect' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Create webhook.
		register_rest_route(
			'suredonation/v1',
			'/payments/paypal/webhook/create',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_webhook' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'mode' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $param ) {
							return in_array( $param, [ 'test', 'live' ], true );
						},
					],
				],
			]
		);

		// Delete webhook.
		register_rest_route(
			'suredonation/v1',
			'/payments/paypal/webhook/delete',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_webhook' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'mode' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $param ) {
							return in_array( $param, [ 'test', 'live' ], true );
						},
					],
				],
			]
		);
	}

	/**
	 * Check if the current user has permissions.
	 *
	 * @return bool True if the user has manage_options capability.
	 * @since 1.0.0
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get PayPal settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function get_settings( $request ) {
		unset( $request );

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => PayPal_Helper::get_connection_state(),
			],
			200
		);
	}

	/**
	 * Get PayPal connect URL from middleware.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function get_connect_url( $request ) {
		unset( $request );

		$mode        = Payment_Helper::get_payment_mode();
		$environment = PayPal_Helper::get_middleware_environment( $mode );
		$tracking_id = 'suredonation_' . wp_generate_password( 32, false );
		// GIT-33: generate a per-site HMAC secret that we'll hand to the
		// middleware. It ties this install to the tracking_id we're about to
		// create; every subsequent merchant-scoped call is signed with it.
		$hmac_secret = bin2hex( random_bytes( 32 ) );

		// Nonce that ties the returning onboarding redirect back to the admin
		// who started it — verified in intercept_paypal_onboarding_callback().
		$connect_nonce = wp_create_nonce( 'suredonation-paypal-connect' );

		// THIRD_PARTY onboarding uses full-page redirect.
		// PayPal appends merchantIdInPayPal as a query param to return_url.
		// Use a clean admin URL without hash fragments — PayPal can't handle # in URLs.
		$return_url = add_query_arg(
			[
				'page'                      => 'suredonation',
				'paypal-process-onboard'    => '1',
				'environment'               => $environment,
				'suredonation_paypal_nonce' => $connect_nonce,
			],
			admin_url( 'admin.php' )
		);

		$result = PayPal_Helper::middleware_request(
			'connect-url/create',
			[
				'return_url'  => $return_url,
				'environment' => $environment,
				'tracking_id' => $tracking_id,
				'hmac_secret' => $hmac_secret,
			],
			false // No binding exists yet; this call creates it.
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[ 'message' => $result->get_error_message() ],
				500
			);
		}

		// Extract the action_url from PayPal's partner referral links.
		$connect_url = '';
		if ( ! empty( $result['links'] ) && is_array( $result['links'] ) ) {
			foreach ( $result['links'] as $link ) {
				if ( isset( $link['rel'] ) && 'action_url' === $link['rel'] && ! empty( $link['href'] ) ) {
					$connect_url = $link['href'];
					break;
				}
			}
		}

		if ( empty( $connect_url ) ) {
			return new WP_REST_Response(
				[ 'message' => $result['message'] ?? __( 'Failed to get PayPal connect URL.', 'suredonation' ) ],
				500
			);
		}

		// GIT-33: persist tracking_id + hmac_secret now so every subsequent
		// signed call (starting with /merchant/status on the onboarding
		// redirect) can authenticate. If the user abandons onboarding these
		// values become orphaned, but they're overwritten on the next Connect
		// click — no security impact.
		$settings = PayPal_Helper::get_all_paypal_settings();
		if ( 'live' === $mode ) {
			$settings['paypal_live_tracking_id'] = $tracking_id;
			$settings['paypal_live_hmac_secret'] = $hmac_secret;
		} else {
			$settings['paypal_test_tracking_id'] = $tracking_id;
			$settings['paypal_test_hmac_secret'] = $hmac_secret;
		}

		// Store partner_client_id for PayPal JS SDK (needed for THIRD_PARTY
		// integration), keyed by mode — sandbox and production are separate
		// PayPal applications, so a shared key let one mode clobber the other.
		// Typed as well as non-empty: this is a decoded remote response, and
		// sanitize_text_field() expects a string.
		if ( ! empty( $result['partner_client_id'] ) && is_string( $result['partner_client_id'] ) ) {
			$client_id_key              = 'live' === $mode ? 'partner_client_id_live' : 'partner_client_id_test';
			$settings[ $client_id_key ] = sanitize_text_field( $result['partner_client_id'] );
		}

		PayPal_Helper::update_all_paypal_settings( $settings );

		// Transient for the polling-status route (which reads tracking_id to
		// know which onboarding is in flight).
		set_transient( 'suredonation_paypal_onboard_tracking_id', $tracking_id, HOUR_IN_SECONDS );
		set_transient( 'suredonation_paypal_onboard_environment', $environment, HOUR_IN_SECONDS );
		// Bind the onboarding session to this admin so the returning redirect
		// cannot be forged (CSRF) by a cross-site request in their browser.
		set_transient( 'suredonation_paypal_connect_nonce_' . get_current_user_id(), $connect_nonce, HOUR_IN_SECONDS );

		return new WP_REST_Response(
			[
				'success'     => true,
				'connect_url' => $connect_url,
			],
			200
		);
	}

	/**
	 * Handle PayPal onboarding completion.
	 *
	 * With THIRD_PARTY integration, PayPal sends merchantIdInPayPal (not authCode/sharedId).
	 * We verify the merchant's onboarding status via the middleware, then store their merchant_id.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function onboard_complete( $request ) {
		$merchant_id = sanitize_text_field( $request->get_param( 'merchantIdInPayPal' ) ?? '' );
		$mode        = sanitize_text_field( $request->get_param( 'mode' ) ?? 'test' );

		if ( empty( $merchant_id ) ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Merchant ID is required.', 'suredonation' ) ],
				400
			);
		}

		$environment = PayPal_Helper::get_middleware_environment( $mode );

		// Step 1: Verify merchant onboarding status via middleware.
		$status_result = PayPal_Helper::middleware_request(
			'merchant/status',
			[
				'merchant_id' => $merchant_id,
				'environment' => $environment,
			]
		);

		if ( is_wp_error( $status_result ) ) {
			return new WP_REST_Response(
				[ 'message' => $status_result->get_error_message() ],
				500
			);
		}

		// Check if merchant can receive payments.
		$verification = $this->verify_merchant_status( $status_result );
		if ( true !== $verification ) {
			return new WP_REST_Response(
				[
					'message' => sprintf(
						/* translators: %s: comma-separated list of issues */
						__( 'PayPal account setup incomplete: %s. Please complete your PayPal account setup and try again.', 'suredonation' ),
						implode( ', ', $verification )
					),
				],
				400
			);
		}

		// Step 2: Store merchant connection and create webhook.
		$this->store_merchant_connection( $merchant_id, $environment, $status_result );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'PayPal account connected successfully!', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Poll onboarding status using the stored tracking_id.
	 *
	 * Called by the frontend every few seconds after the merchant clicks
	 * "Connect to PayPal" and is redirected to PayPal in a new tab.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function check_onboard_status( $request ) {
		unset( $request );

		$tracking_id     = get_transient( 'suredonation_paypal_onboard_tracking_id' );
		$environment_raw = get_transient( 'suredonation_paypal_onboard_environment' );
		$environment     = is_string( $environment_raw ) ? $environment_raw : 'sandbox';

		if ( empty( $tracking_id ) ) {
			return new WP_REST_Response(
				[
					'status'  => 'no_pending',
					'message' => __( 'No pending onboarding.', 'suredonation' ),
				],
				200
			);
		}

		// Check merchant status via middleware using tracking_id.
		$result = PayPal_Helper::middleware_request(
			'merchant/status',
			[
				'tracking_id' => $tracking_id,
				'environment' => $environment,
			]
		);

		if ( is_wp_error( $result ) ) {
			// Merchant not yet onboarded — keep polling.
			return new WP_REST_Response(
				[ 'status' => 'pending' ],
				200
			);
		}

		$payments_receivable = $result['payments_receivable'] ?? false;
		$merchant_id_raw     = $result['merchant_id'] ?? '';
		$merchant_id         = is_string( $merchant_id_raw ) ? $merchant_id_raw : '';

		if ( true !== $payments_receivable || empty( $merchant_id ) ) {
			return new WP_REST_Response(
				[ 'status' => 'pending' ],
				200
			);
		}

		// Onboarding complete! Store merchant connection.
		$this->store_merchant_connection( $merchant_id, $environment, $result );

		// Clean up transients.
		delete_transient( 'suredonation_paypal_onboard_tracking_id' );
		delete_transient( 'suredonation_paypal_onboard_environment' );

		return new WP_REST_Response(
			[
				'status'      => 'connected',
				'merchant_id' => $merchant_id,
			],
			200
		);
	}

	/**
	 * Disconnect PayPal.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function disconnect( $request ) {
		unset( $request );

		$mode = Payment_Helper::get_payment_mode();

		// Keep the id before deleting: delete_webhook() clears it either way, and
		// naming it is the only way a merchant can find the leftover webhook in
		// the PayPal dashboard if the deletion fails.
		$webhook_id = PayPal_Helper::get_webhook_id( $mode );

		// Delete the webhook at PayPal before anything is cleared locally.
		// Clearing the stored id on its own left the webhook live at PayPal with
		// nothing pointing at it, and since PayPal caps webhooks per application,
		// every connect/disconnect cycle spent a slot that could not be reclaimed.
		// This must run first because the call needs the stored webhook id and the
		// merchant id, both of which the disconnect below wipes. (The tracking id
		// and HMAC secret that sign the request survive a disconnect, so they are
		// not what forces the ordering.) PayPal_Webhook::delete_webhook() clears
		// the stored webhook fields itself, so the settings are read after it
		// returns rather than before, or a stale copy would restore them.
		$webhook_deleted = PayPal_Webhook::delete_webhook( $mode );

		$settings = PayPal_Helper::get_all_paypal_settings();

		// The account snapshot and the webhook failure both describe a connection
		// that no longer exists, so they go with it rather than being reported
		// against whatever is connected next.
		if ( 'live' === $mode ) {
			$settings['paypal_live_connected']   = false;
			$settings['paypal_live_merchant_id'] = '';
			// Legacy: nothing writes this any more, but an old install may still
			// hold a value and a secret must not outlive a disconnect.
			$settings['webhook_live_secret']       = '';
			$settings['webhook_live_url']          = '';
			$settings['webhook_live_id']           = '';
			$settings['webhook_live_error']        = '';
			$settings['paypal_live_account_state'] = [];
		} else {
			$settings['paypal_sandbox_connected']  = false;
			$settings['paypal_test_merchant_id']   = '';
			$settings['webhook_test_secret']       = '';
			$settings['webhook_test_url']          = '';
			$settings['webhook_test_id']           = '';
			$settings['webhook_test_error']        = '';
			$settings['paypal_test_account_state'] = [];
		}

		PayPal_Helper::update_all_paypal_settings( $settings );

		// Retire the relay registration too. The binding itself is deliberately
		// kept (reconnecting reuses it), so without this the fingerprint would
		// still match and a reconnect would never re-register.
		delete_option( 'suredonation_paypal_relay_registered_' . $mode );
		delete_option( 'suredonation_paypal_relay_claim_cursor_' . $mode );
		delete_option( 'suredonation_paypal_relay_host_' . $mode );

		// An admin who hits a middleware failure and reconnects to fix it
		// should not then wait out the backoff.
		delete_transient( self::RELAY_REGISTER_BACKOFF );

		$response = [
			'success' => true,
			'message' => __( 'PayPal disconnected.', 'suredonation' ),
		];

		// Disconnecting still succeeds when PayPal refuses the deletion — the
		// merchant asked to disconnect and blocking that would be worse. But a
		// failure here means a webhook is left behind at PayPal holding a slot,
		// so say so rather than reporting a clean disconnect. 'no_webhook' just
		// means there was nothing stored to delete, which is not a failure.
		if ( is_wp_error( $webhook_deleted ) && 'no_webhook' !== $webhook_deleted->get_error_code() ) {
			$response['warning'] = sprintf(
				/* translators: 1: PayPal webhook id, 2: reason reported by PayPal. */
				__( 'PayPal disconnected, but its webhook (%1$s) could not be removed and still counts against your PayPal webhook limit. Delete it from your PayPal dashboard, or reconnect and disconnect again to retry. Reason: %2$s', 'suredonation' ),
				'' !== $webhook_id ? $webhook_id : __( 'id unavailable', 'suredonation' ),
				$webhook_deleted->get_error_message()
			);
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Record the host for sites that registered before the guard existed.
	 *
	 * Those sites already match their fingerprint, so they never re-register and
	 * the host is never written -- leaving the guard skipping on an empty value
	 * for exactly the installs it should protect. Backfilled on an admin load,
	 * which is the original host: a copy taken later then mismatches, where
	 * waiting for the next registration would have recorded whichever host
	 * happened to register first.
	 *
	 * Runs once per mode. It writes only where a registration is already on
	 * record, so it cannot invent a binding for a site that never had one.
	 *
	 * @return void
	 * @since 1.6.1
	 */
	public function backfill_relay_host() {
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( [ 'test', 'live' ] as $mode ) {
			$registered = get_option( 'suredonation_paypal_relay_registered_' . $mode );
			$host       = get_option( 'suredonation_paypal_relay_host_' . $mode );

			if ( empty( $registered ) || ! empty( $host ) ) {
				continue;
			}

			PayPal_Webhook::record_relay_host( $mode );
		}
	}

	/**
	 * Register this site with the webhook relay when its address changes.
	 *
	 * The middleware cannot forward to a site whose endpoint it has never been
	 * told, and nothing else records it -- so a site that never registers is
	 * silently unreachable, which is the same failure as the old slot cap in a
	 * new place. Registering on an admin request rather than behind a button
	 * means an admin chasing "recurring is broken" fixes it by loading a page.
	 *
	 * Keyed on a hash of the endpoint and binding rather than a version, so it
	 * re-runs exactly when the answer changes -- a domain move, a reconnect, a
	 * staging clone -- and stays quiet otherwise. Same shape as the Stripe
	 * webhook-event sync in stripe-settings.php, including the context guards:
	 * admin_init also fires on admin-ajax.php, and the whole donation flow runs
	 * through nopriv ajax actions, so a donor's payment request reaches here.
	 *
	 * @return void
	 * @since 1.6.1
	 */
	public function maybe_register_relay_routing() {
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Cheapest check first: the fingerprint is a single option read, while
		// the backoff transient costs two uncached queries. Most page loads on
		// most sites take this exit.
		if ( ! $this->relay_registration_pending() ) {
			return;
		}

		// While the backoff is held the last run failed, and rescheduling on
		// each pageview would queue an event that immediately returns.
		if ( get_transient( self::RELAY_REGISTER_BACKOFF ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::RELAY_REGISTER_HOOK ) ) {
			wp_schedule_single_event( time(), self::RELAY_REGISTER_HOOK );
		}
	}

	/**
	 * Whether any mode still needs to be registered with the relay.
	 *
	 * Kept free of remote calls: this runs on every admin page load, and the
	 * work itself belongs on cron.
	 *
	 * @return bool
	 * @since 1.6.1
	 */
	private function relay_registration_pending() {
		foreach ( [ 'test', 'live' ] as $mode ) {
			$signature = $this->relay_signature_for( $mode );

			if ( false !== $signature
				&& get_option( 'suredonation_paypal_relay_registered_' . $mode ) !== $signature ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * The registration fingerprint for a mode, or false if it must not register.
	 *
	 * A site that already owns a PayPal webhook is deliberately excluded. Its
	 * own webhook still delivers, so registering for the relay as well would
	 * have PayPal send one event and the middleware forward the same event
	 * again -- two deliveries, and the donor receives two receipts. Existing
	 * sites move across only when their own webhook is removed first, which
	 * makes this change a no-op for every currently connected site.
	 *
	 * @param string $mode Payment mode ('test' or 'live').
	 * @return string|false Fingerprint, or false when this mode must not register.
	 * @since 1.6.1
	 */
	private function relay_signature_for( $mode ) {
		// A disconnected or half-onboarded site must not register. disconnect()
		// keeps the binding and deletes the registered option, so without this
		// the next admin load re-registers an endpoint that
		// validate_relay_signature() will then refuse deliveries for.
		if ( ! PayPal_Helper::is_paypal_connected( $mode ) ) {
			return false;
		}

		if ( '' === PayPal_Helper::get_tracking_id( $mode ) ) {
			return false;
		}

		// Deliberately NOT gated on an existing webhook_id. A stored id is not
		// evidence of a working webhook -- ids outlive the webhooks they name,
		// and a site whose webhook was removed at PayPal would be excluded from
		// the relay while receiving nothing at all. Sites that do still have one
		// briefly receive each event twice during migration; every handler
		// dedupes, and the site's own webhook is removed once it is routable.
		// A binding travels with the database. Restore production onto another
		// host and the tracking id and secret come with it, so this site can
		// sign a registration as production and repoint the live webhook stream
		// at itself -- production then receives nothing, silently, while the
		// copy processes real donations.
		//
		// The host is recorded by register_routing() on every successful
		// registration, whichever route made it. A change is a legitimate domain
		// move OR a copy, and they are indistinguishable from here, so
		// auto-registration stops and an admin re-connects. First registration is
		// unaffected: nothing is recorded yet, so nothing mismatches.
		// Same check register_routing() applies, kept in one place.
		if ( ! PayPal_Webhook::host_matches_binding( $mode ) ) {
			return false;
		}

		return md5(
			PayPal_Helper::get_webhook_url( $mode ) . '|' . PayPal_Helper::get_tracking_id( $mode )
		);
	}

	/**
	 * Register this site with the relay. Runs on cron, never in a page load.
	 *
	 * @return void
	 * @since 1.6.1
	 */
	public function run_relay_registration() {
		if ( get_transient( self::RELAY_REGISTER_BACKOFF ) ) {
			return;
		}

		$failed = false;

		foreach ( [ 'test', 'live' ] as $mode ) {
			$signature = $this->relay_signature_for( $mode );

			if ( false === $signature || get_option( 'suredonation_paypal_relay_registered_' . $mode ) === $signature ) {
				continue;
			}

			$cursor_option = 'suredonation_paypal_relay_claim_cursor_' . $mode;
			$cursor_stored = get_option( $cursor_option, 0 );
			$result        = PayPal_Webhook::register_routing( $mode, is_numeric( $cursor_stored ) ? (int) $cursor_stored : 0 );

			// Only record success. A transient middleware failure must be
			// retried rather than remembered as done, which would leave the
			// site permanently unroutable.
			if ( is_wp_error( $result ) ) {
				$failed = true;
				continue;
			}

			// Claims are sent a page at a time. Advance the cursor and leave the
			// fingerprint unwritten so the next run continues; a full page means
			// there is probably another behind it.
			$claimed = isset( $result['claim_count'] ) && is_numeric( $result['claim_count'] ) ? (int) $result['claim_count'] : 0;
			$cursor  = isset( $result['claim_cursor'] ) && is_numeric( $result['claim_cursor'] ) ? (int) $result['claim_cursor'] : 0;

			if ( $claimed > 0 && $cursor > 0 ) {
				update_option( $cursor_option, $cursor, false );
				continue;
			}

			// Nothing left to claim: the endpoint is registered and the backlog
			// is drained, so stop re-running until something actually changes.
			delete_option( $cursor_option );
			update_option( 'suredonation_paypal_relay_registered_' . $mode, $signature, false );
		}

		if ( $failed ) {
			set_transient( self::RELAY_REGISTER_BACKOFF, true, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Create PayPal webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function create_webhook( $request ) {
		$mode = $request->get_param( 'mode' );

		$result = PayPal_Webhook::create_webhook( $mode );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[ 'message' => $result->get_error_message() ],
				500
			);
		}

		// create_webhook() registers the endpoint but stops there: it reaches the
		// middleware's legacy route, which does not accept subscription claims,
		// and it records no host. Both matter -- without the claims, renewals for
		// subscriptions that predate the relay stay unrouted; without the host,
		// the guard that stops a restored database repointing the live stream
		// has nothing to compare against.
		//
		// So finish the job on the same click, through the path every other
		// route already uses. Failure here is not fatal to the response: the
		// endpoint is registered either way, and cron retries the rest.
		$relay = PayPal_Webhook::register_routing( $mode );

		$response = array_merge( [ 'success' => true ], $result );

		// Tell the client what actually happened. The webhook id is empty under
		// the relay -- nothing creates one any more -- so a UI keyed on that
		// alone reports "not connected" immediately after a successful call.
		$response['relay_registered'] = ! is_wp_error( $relay );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Delete PayPal webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function delete_webhook( $request ) {
		$mode = $request->get_param( 'mode' );

		$result = PayPal_Webhook::delete_webhook( $mode );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				[ 'message' => $result->get_error_message() ],
				500
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Webhook deleted.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Check if form content includes a payment block with PayPal enabled.
	 *
	 * @param string $form_content The form post content.
	 * @return bool True if PayPal is enabled in a payment block.
	 * @since 1.0.0
	 */
	private function form_has_paypal( $form_content ) {
		$blocks = parse_blocks( $form_content );

		foreach ( $blocks as $block ) {
			if ( 'suredonation/payment' === ( $block['blockName'] ?? '' ) ) {
				$payment_methods = $block['attrs']['paymentMethods'] ?? [];

				if ( is_array( $payment_methods ) && in_array( 'paypal', $payment_methods, true ) ) {
					return true;
				}

				// Fallback to legacy gateway attribute.
				$gateway = $block['attrs']['gateway'] ?? '';
				if ( 'paypal' === $gateway ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Verify that a merchant's onboarding is complete.
	 *
	 * @param array<string, mixed> $status_result Merchant status from middleware.
	 * @return true|array<string> True if valid, or array of issue strings.
	 * @since 1.0.0
	 */
	private function verify_merchant_status( $status_result ) {
		$payments_receivable     = $status_result['payments_receivable'] ?? false;
		$primary_email_confirmed = $status_result['primary_email_confirmed'] ?? false;

		if ( true === $payments_receivable && true === $primary_email_confirmed ) {
			return true;
		}

		$issues = [];
		if ( true !== $payments_receivable ) {
			$issues[] = __( 'Account is not set up to receive payments', 'suredonation' );
		}
		if ( true !== $primary_email_confirmed ) {
			$issues[] = __( 'Primary email is not confirmed', 'suredonation' );
		}

		return $issues;
	}

	/**
	 * Store merchant connection details in settings and create webhook.
	 *
	 * @param string               $merchant_id   PayPal merchant (payer) ID.
	 * @param string               $environment   Middleware environment ('sandbox' or 'production').
	 * @param array<string, mixed> $status_result Merchant status from middleware (optional, for email/name).
	 * @return void
	 * @since 1.0.0
	 */
	private function store_merchant_connection( $merchant_id, $environment, $status_result = [] ) {
		$mode     = 'production' === $environment ? 'live' : 'test';
		$settings = PayPal_Helper::get_all_paypal_settings();

		if ( 'live' === $mode ) {
			$settings['paypal_live_connected']   = true;
			$settings['paypal_live_merchant_id'] = $merchant_id;
		} else {
			$settings['paypal_sandbox_connected'] = true;
			$settings['paypal_test_merchant_id']  = $merchant_id;
		}

		if ( ! empty( $status_result['primary_email'] ) && is_string( $status_result['primary_email'] ) ) {
			$settings['paypal_account_email'] = sanitize_email( $status_result['primary_email'] );
		}
		if ( ! empty( $status_result['legal_name'] ) && is_string( $status_result['legal_name'] ) ) {
			$settings['account_name'] = sanitize_text_field( $status_result['legal_name'] );
		}

		// Store partner_client_id permanently (needed for PayPal JS SDK), keyed
		// by mode — see PayPal_Helper::get_partner_client_id(). The transient was
		// always per-environment; only the persisted value was not.
		$partner_client_id = get_transient( 'suredonation_paypal_partner_client_id_' . $environment );
		if ( ! empty( $partner_client_id ) && is_string( $partner_client_id ) ) {
			$client_id_key              = 'live' === $mode ? 'partner_client_id_live' : 'partner_client_id_test';
			$settings[ $client_id_key ] = sanitize_text_field( $partner_client_id );
			delete_transient( 'suredonation_paypal_partner_client_id_' . $environment );
		}

		// What PayPal says about the account's ability to take money. Captured on
		// every connect, so reconnecting refreshes it — account state changes
		// after onboarding, and a snapshot from months ago is not evidence.
		$state_key              = 'live' === $mode ? 'paypal_live_account_state' : 'paypal_test_account_state';
		$settings[ $state_key ] = $this->extract_account_state( $status_result );

		PayPal_Helper::update_all_paypal_settings( $settings );

		// Create webhook. The return value is not used here, but the failure
		// reason is recorded by create_webhook() itself and surfaced on the
		// settings screen — connecting must not report success while silently
		// leaving the site with no webhook.
		PayPal_Webhook::create_webhook( $mode );

		// And register for relay routing here, not only on cron. Registration is
		// the ONLY thing that makes a relayed site reachable, and a site with
		// DISABLE_WP_CRON and no system cron would otherwise sit unreachable
		// with every donation stuck pending and no receipt -- silently. Cron
		// remains the self-heal for a later address change.
		if ( PayPal_Helper::is_paypal_connected( $mode ) ) {
			PayPal_Webhook::register_routing( $mode );
		}
	}

	/**
	 * Reduce a merchant/status response to the account facts worth keeping.
	 *
	 * The middleware passes PayPal's merchant-integrations record through
	 * unchanged. Onboarding read two fields from it and dropped the rest, so
	 * nothing could later explain why an account that reported Connected could
	 * not actually be paid — the failure only appeared at capture time, as
	 * PAYEE_ACCOUNT_NOT_VERIFIED, in a branch that logged nothing.
	 *
	 * Only facts are stored; whether they amount to a problem is decided by
	 * PayPal_Helper::get_account_blockers().
	 *
	 * @param array<string, mixed> $status_result Merchant status from the middleware.
	 * @return array<string, mixed> Normalised account state.
	 * @since 1.5.1
	 */
	private function extract_account_state( $status_result ) {
		$state = [
			'payments_receivable'     => true === ( $status_result['payments_receivable'] ?? null ),
			'primary_email_confirmed' => true === ( $status_result['primary_email_confirmed'] ?? null ),
			'capabilities'            => [],
			'products'                => [],
			'checked_at'              => time(),
		];

		// PayPal reports these as lists of objects; flattened to name => status
		// so a lookup does not have to walk the list.
		$capabilities = $status_result['capabilities'] ?? [];
		if ( is_array( $capabilities ) ) {
			foreach ( $capabilities as $capability ) {
				if ( ! is_array( $capability ) || empty( $capability['name'] ) || ! is_string( $capability['name'] ) ) {
					continue;
				}

				$status = isset( $capability['status'] ) && is_string( $capability['status'] ) ? $capability['status'] : '';

				$state['capabilities'][ sanitize_text_field( $capability['name'] ) ] = sanitize_text_field( $status );
			}
		}

		$products = $status_result['products'] ?? [];
		if ( is_array( $products ) ) {
			foreach ( $products as $product ) {
				if ( ! is_array( $product ) || empty( $product['name'] ) || ! is_string( $product['name'] ) ) {
					continue;
				}

				$vetting = isset( $product['vetting_status'] ) && is_string( $product['vetting_status'] ) ? $product['vetting_status'] : '';

				$state['products'][ sanitize_text_field( $product['name'] ) ] = sanitize_text_field( $vetting );
			}
		}

		return $state;
	}
}
