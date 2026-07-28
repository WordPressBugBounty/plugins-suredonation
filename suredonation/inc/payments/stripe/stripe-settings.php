<?php
/**
 * Stripe Settings - REST API and configuration management
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Payments\Stripe;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stripe_Settings class
 * Manages Stripe configuration and REST API endpoints
 *
 * @since 0.0.1
 */
class Stripe_Settings {
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_init', [ $this, 'intercept_stripe_callback' ] );
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_routes() {
		// Get Stripe settings.
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Update Stripe settings.
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/settings',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Get Stripe Connect URL.
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/connect-url',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_connect_url' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Disconnect Stripe.
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/disconnect',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'disconnect_stripe' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Create webhook.
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/webhook/create',
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
							if ( ! in_array( $param, [ 'test', 'live' ], true ) ) {
								return new \WP_Error(
									'invalid_mode',
									sprintf(
										/* translators: %s: provided mode value */
										__( 'Invalid mode "%s". Must be "test" or "live".', 'suredonation' ),
										$param
									)
								);
							}
							return true;
						},
					],
				],
			]
		);

		// Delete webhook.
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/webhook/delete',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_webhook' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'mode' => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return in_array( $param, [ 'test', 'live' ], true );
						},
					],
				],
			]
		);
	}

	/**
	 * Get Stripe settings
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_settings( $request ) {
		unset( $request ); // Unused parameter.

		$stripe_settings = Stripe_Helper::get_all_stripe_settings();
		$global_settings = Payment_Helper::get_all_payment_settings();

		// Remove sensitive data from response.
		$safe_settings = $stripe_settings;
		unset( $safe_settings['stripe_live_secret_key'] );
		unset( $safe_settings['stripe_test_secret_key'] );
		unset( $safe_settings['webhook_test_secret'] );
		unset( $safe_settings['webhook_live_secret'] );

		// Add global settings (currency, payment_mode, fee_recovery).
		$safe_settings['currency']        = $global_settings['currency'] ?? 'USD';
		$safe_settings['currency_symbol'] = Payment_Helper::get_currency_symbol( is_string( $safe_settings['currency'] ) ? $safe_settings['currency'] : 'USD' );
		$safe_settings['payment_mode']    = $global_settings['payment_mode'] ?? 'test';
		$safe_settings['fee_recovery']    = Payment_Helper::get_fee_recovery_settings();

		// Include gateway list so the settings UI knows which gateways exist.
		$safe_settings['gateways'] = array_map(
			static function ( $gw ) {
				return [
					'label'              => $gw['label'],
					'supports_recurring' => $gw['supports_recurring'] ?? false,
				];
			},
			Payment_Helper::get_supported_gateways()
		);

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => $safe_settings,
			],
			200
		);
	}

	/**
	 * Update Stripe settings
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function update_settings( $request ) {
		$settings = $request->get_json_params();

		if ( empty( $settings ) ) {
			return new WP_Error(
				'invalid_settings',
				__( 'Invalid settings provided', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		// Handle global settings (currency, payment_mode, fee_recovery) separately.
		$global_updated = true;
		if ( isset( $settings['currency'] ) || isset( $settings['payment_mode'] ) || isset( $settings['fee_recovery'] ) ) {
			$global_settings = Payment_Helper::get_all_payment_settings();

			if ( isset( $settings['currency'] ) ) {
				$global_settings['currency'] = sanitize_text_field( $settings['currency'] );
				unset( $settings['currency'] );
			}

			if ( isset( $settings['payment_mode'] ) ) {
				$mode = sanitize_text_field( $settings['payment_mode'] );
				if ( in_array( $mode, [ 'test', 'live' ], true ) ) {
					$global_settings['payment_mode'] = $mode;
				}
				unset( $settings['payment_mode'] );
			}

			if ( isset( $settings['fee_recovery'] ) && is_array( $settings['fee_recovery'] ) ) {
				$fee_recovery   = $settings['fee_recovery'];
				$fee_percentage = max( 0, min( 99.99, floatval( $fee_recovery['fee_percentage'] ?? 2.9 ) ) );
				$fee_fixed      = max( 0, floatval( $fee_recovery['fee_fixed'] ?? 0.30 ) );
				$fee_mode       = isset( $fee_recovery['fee_mode'] ) && in_array( $fee_recovery['fee_mode'], [ 'all_gateways', 'per_gateway' ], true )
					? $fee_recovery['fee_mode'] : 'all_gateways';

				$sanitized_fee = [
					'fee_percentage' => $fee_percentage,
					'fee_fixed'      => $fee_fixed,
					'fee_mode'       => $fee_mode,
				];

				// Sanitize per-gateway settings — only allow registered gateway keys.
				$allowed_gateways = array_keys( Payment_Helper::get_supported_gateways() );
				if ( isset( $fee_recovery['gateways'] ) && is_array( $fee_recovery['gateways'] ) ) {
					$gateways = [];
					foreach ( $fee_recovery['gateways'] as $gw_key => $gw_val ) {
						$gw_key = sanitize_text_field( $gw_key );
						if ( ! in_array( $gw_key, $allowed_gateways, true ) ) {
							continue;
						}
						if ( is_array( $gw_val ) ) {
							$gateways[ $gw_key ] = [
								'fee_percentage' => max( 0, min( 99.99, floatval( $gw_val['fee_percentage'] ?? 0 ) ) ),
								'fee_fixed'      => max( 0, floatval( $gw_val['fee_fixed'] ?? 0 ) ),
								'enabled'        => ! empty( $gw_val['enabled'] ),
							];
						}
					}
					$sanitized_fee['gateways'] = $gateways;
				}

				$global_settings['fee_recovery'] = $sanitized_fee;
				unset( $settings['fee_recovery'] );
			}

			$global_updated = Payment_Helper::update_all_payment_settings( $global_settings );
		}

		// Sanitize and update Stripe-specific settings.
		$stripe_updated = true;
		if ( ! empty( $settings ) ) {
			$sanitized_settings = $this->sanitize_settings( $settings );

			// Preserve stored secret keys. The GET response intentionally strips
			// these (see get_settings()), so a Save that originates from the
			// hydrated client state — e.g. changing Currency/Payment Mode on the
			// General tab — would otherwise drop them when update_gateway_settings()
			// full-replaces the gateway entry, silently breaking live charging and
			// webhook verification. Only restore a secret when it is absent from
			// the request, so an explicit update still overwrites it.
			$existing_stripe = Stripe_Helper::get_all_stripe_settings();
			$secret_keys     = [
				'stripe_live_secret_key',
				'stripe_test_secret_key',
				'webhook_test_secret',
				'webhook_live_secret',
			];
			foreach ( $secret_keys as $secret_key ) {
				if ( ! isset( $sanitized_settings[ $secret_key ] ) && ! empty( $existing_stripe[ $secret_key ] ) ) {
					$sanitized_settings[ $secret_key ] = $existing_stripe[ $secret_key ];
				}
			}

			$stripe_updated = Stripe_Helper::update_all_stripe_settings( $sanitized_settings );
		}

		if ( ! $global_updated && ! $stripe_updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update settings', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Settings updated successfully', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Get Stripe Connect URL
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_connect_url( $request ) {
		unset( $request ); // Unused parameter.

		$connect_url = Stripe_Helper::get_stripe_connect_url();

		return new WP_REST_Response(
			[
				'success'     => true,
				'connect_url' => $connect_url,
			],
			200
		);
	}

	/**
	 * Disconnect Stripe account
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function disconnect_stripe( $request ) {
		unset( $request ); // Unused parameter.

		// Delete webhooks first.
		$this->delete_webhook_for_mode( 'test' );
		$this->delete_webhook_for_mode( 'live' );

		// Clear all Stripe settings.
		Stripe_Helper::update_all_stripe_settings( [] );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Stripe account disconnected successfully', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Create webhook for specified mode
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function create_webhook( $request ) {
		$mode = $request->get_param( 'mode' );

		$result = $this->create_webhook_for_mode( $mode );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => sprintf(
					// translators: %s is the mode (test or live).
					__( 'Webhook created successfully for %s mode', 'suredonation' ),
					$mode
				),
				'data'    => $result,
			],
			200
		);
	}

	/**
	 * Delete webhook for specified mode
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function delete_webhook( $request ) {
		$mode = $request->get_param( 'mode' );

		$this->delete_webhook_for_mode( $mode );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => sprintf(
					// translators: %s is the mode (test or live).
					__( 'Webhook deleted successfully for %s mode', 'suredonation' ),
					$mode
				),
			],
			200
		);
	}

	/**
	 * Intercept Stripe OAuth callback
	 *
	 * This function validates the OAuth callback from Stripe Connect by:
	 * 1. Verifying user has admin capabilities
	 * 2. Checking for the required page parameter for the plugin
	 * 3. Validating the nonce using wp_verify_nonce()
	 * 4. Comparing the nonce with the stored transient for additional security
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function intercept_stripe_callback() {
		// Check if user has permission to connect Stripe.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if this is a Stripe callback page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified the nonce below.
		if ( ! isset( $_GET['page'] ) || 'suredonation' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		// Get and sanitize the nonce from URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verifying the custom nonce here.
		$nonce = isset( $_GET['suredonation_stripe_connect_nonce'] )
			? sanitize_text_field( wp_unslash( $_GET['suredonation_stripe_connect_nonce'] ) )
			: '';

		// Check if nonce parameter exists.
		if ( empty( $nonce ) ) {
			return;
		}

		// Verify the nonce using WordPress's built-in verification.
		if ( ! wp_verify_nonce( $nonce, 'stripe-connect' ) ) {
			wp_die(
				esc_html__( 'Security verification failed. Invalid nonce.', 'suredonation' ),
				esc_html__( 'Stripe Connect Error', 'suredonation' ),
				[ 'response' => 403 ]
			);
		}

		// Additional verification: Compare with stored transient.
		$saved_nonce = get_transient( 'suredonation_stripe_connect_nonce_' . get_current_user_id() );

		if ( $nonce !== $saved_nonce ) {
			wp_die(
				esc_html__( 'Security verification failed. OAuth session expired or nonce mismatch.', 'suredonation' ),
				esc_html__( 'Stripe Connect Error', 'suredonation' ),
				[ 'response' => 403 ]
			);
		}

		// Handle the callback.
		$this->handle_stripe_callback();
	}

	/**
	 * Get Stripe account name using stored account ID
	 *
	 * @return string Account name or empty string if not found.
	 * @since 0.0.1
	 */
	public function get_account_name() {
		$settings = Stripe_Helper::get_all_stripe_settings();

		// Check if Stripe is connected.
		if ( empty( $settings['stripe_connected'] ) ) {
			return '';
		}

		// Get account ID.
		$account_id = $settings['stripe_account_id'] ?? '';
		if ( empty( $account_id ) || ! is_string( $account_id ) ) {
			return '';
		}

		// Call Stripe API to get account information.
		$api_response = Stripe_Helper::stripe_api_request( 'accounts/' . $account_id, 'GET', [] );

		// Check for API error.
		if ( is_wp_error( $api_response ) ) {
			return '';
		}

		// API response is the account object directly, not wrapped in 'data'.
		$get_data = is_array( $api_response ) ? $api_response : [];

		// Return business name or display name.
		$business_profile = isset( $get_data['business_profile'] ) && is_array( $get_data['business_profile'] ) ? $get_data['business_profile'] : [];
		if ( isset( $business_profile['name'] ) && is_string( $business_profile['name'] ) ) {
			return sanitize_text_field( $business_profile['name'] );
		}

		$settings  = isset( $get_data['settings'] ) && is_array( $get_data['settings'] ) ? $get_data['settings'] : [];
		$dashboard = isset( $settings['dashboard'] ) && is_array( $settings['dashboard'] ) ? $settings['dashboard'] : [];
		if ( isset( $dashboard['display_name'] ) && is_string( $dashboard['display_name'] ) ) {
			return sanitize_text_field( $dashboard['display_name'] );
		}

		return '';
	}

	/**
	 * Check if user has permission
	 *
	 * @return bool True if user has permission.
	 * @since 0.0.1
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Create webhook for mode
	 *
	 * @param string $mode Payment mode.
	 * @return array<string, mixed>|WP_Error Webhook data.
	 * @since 0.0.1
	 */
	private function create_webhook_for_mode( $mode ) {
		$webhook_url = Stripe_Helper::get_webhook_url( $mode );

		// Events to listen to.
		$enabled_events = [
			'charge.succeeded',
			'charge.failed',
			'charge.refunded',
			'charge.refund.updated',
			'charge.dispute.created',
			'charge.dispute.closed',
			'invoice.payment_succeeded',
			'customer.subscription.created',
			'customer.subscription.updated',
			'customer.subscription.deleted',
			'payment_intent.succeeded',
			'payment_intent.payment_failed',
			'payment_intent.canceled',
		];

		$webhook_data = [
			'url'            => $webhook_url,
			'enabled_events' => $enabled_events,
			'description'    => 'SureDonation ' . ucfirst( $mode ) . ' Webhook',
			'api_version'    => '2025-07-30.basil',
		];

		// Create webhook via Stripe API with explicit mode.
		$response = Stripe_Helper::stripe_api_request( 'webhook_endpoints', 'POST', $webhook_data, [ 'mode' => $mode ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Save webhook data to settings.
		$settings                             = Stripe_Helper::get_all_stripe_settings();
		$settings[ "webhook_{$mode}_id" ]     = $response['id'] ?? '';
		$settings[ "webhook_{$mode}_secret" ] = $response['secret'] ?? '';
		$settings[ "webhook_{$mode}_url" ]    = $webhook_url;

		Stripe_Helper::update_all_stripe_settings( $settings );

		return $response;
	}

	/**
	 * Delete webhook for mode
	 *
	 * @param string $mode Payment mode.
	 * @return void
	 * @since 0.0.1
	 */
	private function delete_webhook_for_mode( $mode ) {
		$settings   = Stripe_Helper::get_all_stripe_settings();
		$webhook_id = $settings[ "webhook_{$mode}_id" ] ?? '';

		if ( empty( $webhook_id ) ) {
			return;
		}

		// Delete webhook via Stripe API with explicit mode.
		Stripe_Helper::stripe_api_request( 'webhook_endpoints/' . $webhook_id, 'DELETE', [], [ 'mode' => $mode ] );

		// Remove from settings.
		unset( $settings[ "webhook_{$mode}_id" ] );
		unset( $settings[ "webhook_{$mode}_secret" ] );
		unset( $settings[ "webhook_{$mode}_url" ] );

		Stripe_Helper::update_all_stripe_settings( $settings );
	}

	/**
	 * Handle Stripe OAuth callback
	 * Routes to success or error handler based on response.
	 *
	 * SECURITY: This private method is ONLY called from intercept_stripe_callback() after:
	 * 1. current_user_can('manage_options') check passed
	 * 2. wp_verify_nonce() validated the 'stripe-connect' nonce
	 * 3. Nonce matched the user-specific transient
	 *
	 * @return void
	 * @since 0.0.1
	 */
	private function handle_stripe_callback() {
		// Sanitize callback parameters immediately.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in intercept_stripe_callback() before this private method is called.
		$response = isset( $_GET['response'] ) ? sanitize_text_field( wp_unslash( $_GET['response'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in intercept_stripe_callback() before this private method is called.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		// Success response.
		if ( ! empty( $response ) ) {
			$this->process_oauth_success( $response );
			return;
		}

		// Error response.
		if ( ! empty( $error ) ) {
			$this->process_oauth_error( $error );
			return;
		}

		// No response or error, redirect with generic error.
		$redirect_url  = add_query_arg(
			[
				'page'  => 'suredonation',
				'error' => rawurlencode( __( 'OAuth callback missing response data.', 'suredonation' ) ),
			],
			admin_url( 'admin.php' )
		);
		$redirect_url .= '#/settings?tab=payments&subpage=stripe';

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Process OAuth success response
	 * Handles successful OAuth callback and stores API keys.
	 *
	 * SECURITY: This private method is ONLY called from handle_stripe_callback() after
	 * intercept_stripe_callback() has verified:
	 * 1. User capability: current_user_can('manage_options')
	 * 2. Nonce verification: wp_verify_nonce($nonce, 'stripe-connect')
	 * 3. Transient match: nonce matches stored user-specific transient
	 *
	 * @param string $response_data Sanitized response data from OAuth callback.
	 * @return void
	 * @since 0.0.1
	 */
	private function process_oauth_success( $response_data ) {
		$decoded  = base64_decode( $response_data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$response = false;

		if ( is_string( $decoded ) ) {
			$response = json_decode( $decoded, true );
		}

		if ( ! is_array( $response ) ) {
			wp_die(
				esc_html__( 'Invalid OAuth response format.', 'suredonation' ),
				esc_html__( 'Stripe Connect Error', 'suredonation' ),
				[ 'response' => 400 ]
			);
		}

		// Extract OAuth data.
		$settings = Stripe_Helper::get_all_stripe_settings();

		// Store live keys.
		if ( isset( $response['live'] ) && is_array( $response['live'] ) ) {
			$settings['stripe_live_publishable_key'] = sanitize_text_field( $response['live']['stripe_publishable_key'] ?? '' );
			$settings['stripe_live_secret_key']      = sanitize_text_field( $response['live']['access_token'] ?? '' );
			$settings['stripe_account_id']           = sanitize_text_field( $response['live']['stripe_user_id'] ?? '' );
		}

		// Store test keys.
		if ( isset( $response['test'] ) && is_array( $response['test'] ) ) {
			$settings['stripe_test_publishable_key'] = sanitize_text_field( $response['test']['stripe_publishable_key'] ?? '' );
			$settings['stripe_test_secret_key']      = sanitize_text_field( $response['test']['access_token'] ?? '' );
		}

		// Mark as connected.
		$settings['stripe_connected']     = true;
		$settings['stripe_account_email'] = isset( $response['account'], $response['account']['email'] )
			? sanitize_email( $response['account']['email'] )
			: '';

		// Save settings.
		Stripe_Helper::update_all_stripe_settings( $settings );

		// Get account name from Stripe.
		$account_name = $this->get_account_name();

		if ( ! empty( $account_name ) && is_string( $account_name ) ) {
			$settings['account_name'] = $account_name;
			Stripe_Helper::update_all_stripe_settings( $settings );
		}

		// Clean up transients.
		delete_transient( 'suredonation_stripe_connect_nonce_' . get_current_user_id() );

		// Create webhooks for both live and test mode.
		$this->setup_stripe_webhooks();

		// Redirect to SureDonation payments settings.
		wp_safe_redirect( admin_url( 'admin.php?page=suredonation&connected=1#/settings?tab=payments&subpage=stripe' ) );
		exit;
	}

	/**
	 * Process OAuth error response
	 * Handles errors from the Stripe OAuth callback.
	 *
	 * SECURITY: This private method is ONLY called from handle_stripe_callback() after
	 * intercept_stripe_callback() has verified:
	 * 1. User capability: current_user_can('manage_options')
	 * 2. Nonce verification: wp_verify_nonce($nonce, 'stripe-connect')
	 * 3. Transient match: nonce matches stored user-specific transient
	 *
	 * @param string $error_data Sanitized error data from OAuth callback.
	 * @return void
	 * @since 0.0.1
	 */
	private function process_oauth_error( $error_data ) {
		// Defense-in-depth: Re-verify user capabilities (already checked in intercept_stripe_callback).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to connect Stripe.', 'suredonation' ),
				esc_html__( 'Permission Denied', 'suredonation' ),
				[ 'response' => 403 ]
			);
		}

		// Decode error data (already sanitized in handle_stripe_callback).
		$decoded = base64_decode( $error_data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$error   = is_string( $decoded ) ? json_decode( $decoded, true ) : [];
		if ( ! is_array( $error ) ) {
			$error = [];
		}

		$error_message = __( 'Failed to connect to Stripe.', 'suredonation' );
		if ( isset( $error['message'] ) && is_string( $error['message'] ) ) {
			$error_message = sanitize_text_field( $error['message'] );
		}

		// Clean up transients.
		delete_transient( 'suredonation_stripe_connect_nonce_' . get_current_user_id() );

		// Redirect with error.
		$redirect_url  = add_query_arg(
			[
				'page'  => 'suredonation',
				'error' => rawurlencode( $error_message ),
			],
			admin_url( 'admin.php' )
		);
		$redirect_url .= '#/settings?tab=payments&subpage=stripe';

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Setup Stripe webhooks for both test and live modes
	 *
	 * @return array<string, mixed> Result of webhook creation.
	 * @since 0.0.1
	 */
	private function setup_stripe_webhooks() {
		$settings         = Stripe_Helper::get_all_stripe_settings();
		$modes            = [ 'test', 'live' ];
		$webhooks_created = 0;
		$error_message    = '';

		foreach ( $modes as $mode ) {
			$secret_key = 'live' === $mode
				? ( $settings['stripe_live_secret_key'] ?? '' )
				: ( $settings['stripe_test_secret_key'] ?? '' );

			if ( empty( $secret_key ) ) {
				continue;
			}

			$result = $this->create_webhook_for_mode( $mode );

			if ( ! is_wp_error( $result ) ) {
				++$webhooks_created;
			} else {
				$error_message = $result->get_error_message();
			}
		}

		return [
			'success' => $webhooks_created > 0,
			'created' => $webhooks_created,
			'message' => $error_message,
		];
	}

	/**
	 * Sanitize settings
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return array<string, mixed> Sanitized settings.
	 * @since 0.0.1
	 */
	private function sanitize_settings( $settings ) {
		$sanitized = [];

		$text_fields = [
			'stripe_account_id',
			'stripe_account_email',
			'account_name',
			'stripe_live_publishable_key',
			'stripe_live_secret_key',
			'stripe_test_publishable_key',
			'stripe_test_secret_key',
			'webhook_test_secret',
			'webhook_test_url',
			'webhook_test_id',
			'webhook_live_secret',
			'webhook_live_url',
			'webhook_live_id',
		];

		foreach ( $text_fields as $field ) {
			if ( isset( $settings[ $field ] ) ) {
				$value               = $settings[ $field ];
				$sanitized[ $field ] = is_string( $value ) ? sanitize_text_field( $value ) : '';
			}
		}

		if ( isset( $settings['stripe_connected'] ) ) {
			$sanitized['stripe_connected'] = (bool) $settings['stripe_connected'];
		}

		return $sanitized;
	}
}
