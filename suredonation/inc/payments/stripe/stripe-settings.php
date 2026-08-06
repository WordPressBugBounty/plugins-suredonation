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
	 * Cron hook that reconciles webhook events away from the request path.
	 *
	 * @since 1.4.0
	 */
	public const WEBHOOK_SYNC_HOOK = 'suredonation_sync_stripe_webhook_events';

	/**
	 * Option holding the fingerprint of the last successfully synced event list.
	 *
	 * @since 1.4.0
	 */
	private const WEBHOOK_SYNC_OPTION = 'suredonation_stripe_webhook_events_synced';

	/**
	 * Transient that backs off retries after a failed sync.
	 *
	 * @since 1.4.0
	 */
	private const WEBHOOK_SYNC_BACKOFF = 'suredonation_stripe_webhook_sync_backoff';

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_init', [ $this, 'intercept_stripe_callback' ] );
		add_action( 'admin_init', [ $this, 'maybe_sync_webhook_events' ] );
		add_action( self::WEBHOOK_SYNC_HOOK, [ $this, 'run_webhook_event_sync' ] );
		add_filter( 'suredonation_stripe_account_usage_blockers', [ $this, 'add_form_usage_blocker' ], 10, 2 );
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

		// List connected accounts (sanitized).
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/accounts',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_accounts' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Set the default account.
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/accounts/default',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'set_default_account' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'account_id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Disconnect Stripe (a specific account, or the default when omitted).
		register_rest_route(
			'suredonation/v1',
			'/payments/stripe/disconnect',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'disconnect_stripe' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'account_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
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
					// No default: an omitted mode resolves to the site's current
					// payment mode in the callback. Defaulting to 'all' made a
					// caller working in test mode fail on the live account.
					'mode'       => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $param ) {
							if ( ! in_array( $param, [ 'all', 'test', 'live' ], true ) ) {
								return new \WP_Error(
									'invalid_mode',
									sprintf(
										/* translators: %s: provided mode value */
										__( 'Invalid mode "%s". Must be "all", "test" or "live".', 'suredonation' ),
										$param
									)
								);
							}
							return true;
						},
					],
					'account_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
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
					'mode'       => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return in_array( $param, [ 'test', 'live' ], true );
						},
					],
					'account_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
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

		// Never expose the raw accounts map (it holds secret keys + webhook secrets);
		// replace it with the sanitized, publishable-only list.
		unset( $safe_settings['accounts'] );
		$safe_settings['accounts']           = Stripe_Helper::get_public_accounts();
		$safe_settings['default_account_id'] = Stripe_Helper::get_default_account_id();

		// Add global settings (currency, payment_mode, fee_recovery).
		$safe_settings['currency']               = $global_settings['currency'] ?? 'USD';
		$safe_settings['currency_symbol']        = Payment_Helper::get_currency_symbol( is_string( $safe_settings['currency'] ) ? $safe_settings['currency'] : 'USD' );
		$safe_settings['payment_mode']           = $global_settings['payment_mode'] ?? 'test';
		$safe_settings['currency_sign_position'] = Payment_Helper::get_currency_sign_position();
		$safe_settings['fee_recovery']           = Payment_Helper::get_fee_recovery_settings();

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

		// Handle global settings (currency, payment_mode, currency_sign_position, fee_recovery) separately.
		$global_updated = true;
		if ( isset( $settings['currency'] ) || isset( $settings['payment_mode'] ) || isset( $settings['currency_sign_position'] ) || isset( $settings['fee_recovery'] ) ) {
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

			if ( isset( $settings['currency_sign_position'] ) ) {
				$position = sanitize_text_field( $settings['currency_sign_position'] );
				if ( in_array( $position, Payment_Helper::ALLOWED_SIGN_POSITIONS, true ) ) {
					$global_settings['currency_sign_position'] = $position;
				}
				unset( $settings['currency_sign_position'] );
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

			// Preserve the multi-account map + default pointer. They are managed by
			// the connect/disconnect/default flows — never by this endpoint — and the
			// full-replace above would otherwise drop them.
			foreach ( [ 'accounts', 'default_account_id' ] as $preserved_key ) {
				if ( ! isset( $sanitized_settings[ $preserved_key ] ) && isset( $existing_stripe[ $preserved_key ] ) ) {
					$sanitized_settings[ $preserved_key ] = $existing_stripe[ $preserved_key ];
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
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function disconnect_stripe( $request ) {
		$account_id = $request->get_param( 'account_id' );
		$account_id = is_string( $account_id ) ? sanitize_text_field( $account_id ) : '';

		if ( '' === $account_id ) {
			$account_id = Stripe_Helper::get_default_account_id();
		}

		if ( '' === $account_id || empty( Stripe_Helper::get_account( $account_id ) ) ) {
			return new WP_Error(
				'account_not_found',
				__( 'Stripe account not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Guard rail: refuse to disconnect an account that is still in use.
		$blockers = self::get_account_usage_blockers( $account_id );
		if ( ! empty( $blockers ) ) {
			return new WP_Error(
				'account_in_use',
				__( 'This Stripe account is still in use and cannot be disconnected.', 'suredonation' ),
				[
					'status'   => 409,
					'blockers' => array_values( $blockers ),
				]
			);
		}

		// Delete this account's webhooks first, then remove the account.
		$this->delete_webhook_for_mode( 'test', $account_id );
		$this->delete_webhook_for_mode( 'live', $account_id );
		Stripe_Helper::remove_account( $account_id );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Stripe account disconnected successfully', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Set the default Stripe account.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.3.0
	 */
	public function set_default_account( $request ) {
		$account_id = $request->get_param( 'account_id' );
		$account_id = is_string( $account_id ) ? sanitize_text_field( $account_id ) : '';

		if ( '' === $account_id || ! Stripe_Helper::set_default_account( $account_id ) ) {
			return new WP_Error(
				'account_not_found',
				__( 'Stripe account not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success'            => true,
				'message'            => __( 'Default Stripe account updated.', 'suredonation' ),
				'default_account_id' => $account_id,
			],
			200
		);
	}

	/**
	 * Get the sanitized list of connected Stripe accounts (no secret material).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.3.0
	 */
	public function get_accounts( $request ) {
		unset( $request ); // Unused parameter.

		return new WP_REST_Response(
			[
				'success'  => true,
				'accounts' => Stripe_Helper::get_public_accounts(),
			],
			200
		);
	}

	/**
	 * Collect reasons an account cannot be disconnected.
	 *
	 * Extensible via the `suredonation_stripe_account_usage_blockers` filter —
	 * this class registers a blocker for donation forms assigned to the account,
	 * and Pro adds one for active subscriptions. Each blocker is a human-readable
	 * string.
	 *
	 * @param string $account_id Account id being disconnected.
	 * @return array<int, string> List of blocker messages (empty = safe to disconnect).
	 * @since 1.3.0
	 */
	public static function get_account_usage_blockers( $account_id ) {
		/**
		 * Filter the list of reasons a Stripe account cannot be disconnected.
		 *
		 * @param array<int, string> $blockers   Blocker messages.
		 * @param string             $account_id Account id being disconnected.
		 */
		$blockers = apply_filters( 'suredonation_stripe_account_usage_blockers', [], $account_id );
		return is_array( $blockers ) ? $blockers : [];
	}

	/**
	 * Block disconnecting an account that donation forms are still assigned to.
	 *
	 * @param array<int, string> $blockers   Existing blocker messages.
	 * @param string             $account_id Account id being disconnected.
	 * @return array<int, string> Blocker messages.
	 * @since 1.3.0
	 */
	public function add_form_usage_blocker( $blockers, $account_id ) {
		if ( ! is_array( $blockers ) ) {
			$blockers = [];
		}

		if ( empty( $account_id ) || ! is_string( $account_id ) ) {
			return $blockers;
		}

		$query = new \WP_Query(
			[
				'post_type'      => \SureDonation\Inc\Post_Types\Donation_Form::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-only disconnect guard; a meta lookup is required and infrequent.
				'meta_key'       => Stripe_Helper::FORM_ACCOUNT_META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only disconnect guard; a meta lookup is required and infrequent.
				'meta_value'     => $account_id,
			]
		);

		$count = (int) $query->found_posts;

		if ( $count > 0 ) {
			$blockers[] = sprintf(
				/* translators: %s: number of donation forms */
				_n(
					'%s donation form is assigned to this account.',
					'%s donation forms are assigned to this account.',
					$count,
					'suredonation'
				),
				number_format_i18n( $count )
			);
		}

		return $blockers;
	}

	/**
	 * Create webhook for specified mode
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function create_webhook( $request ) {
		$mode       = $request->get_param( 'mode' );
		$account_id = $request->get_param( 'account_id' );
		$account_id = is_string( $account_id ) ? sanitize_text_field( $account_id ) : null;

		// An omitted mode targets the mode the site is currently in, so a caller
		// working in test never reaches the live account. 'all' remains available
		// for programmatic setup, but is never implied.
		if ( empty( $mode ) ) {
			$mode = Payment_Helper::get_payment_mode();
			$mode = in_array( $mode, [ 'test', 'live' ], true ) ? $mode : 'test';
		}

		if ( 'all' === $mode ) {
			$result = $this->setup_stripe_webhooks( $account_id );

			if ( empty( $result['success'] ) ) {
				$message = isset( $result['message'] ) && is_string( $result['message'] ) && '' !== $result['message']
					? $result['message']
					: __( 'Failed to create webhook.', 'suredonation' );
				return new WP_Error( 'webhook_create_failed', $message );
			}

			$partial  = ! empty( $result['errors'] );
			$failures = isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : '';

			return new WP_REST_Response(
				[
					'success' => true,
					'partial' => $partial,
					'message' => $partial
						? sprintf(
							/* translators: %s: per-mode failure reasons, already prefixed with the mode name. */
							__( 'Webhook created, but some modes failed. %s', 'suredonation' ),
							$failures
						)
						: __( 'Webhook created successfully.', 'suredonation' ),
					'data'    => $result,
				],
				200
			);
		}

		$result = $this->create_webhook_for_mode( $mode, $account_id );

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
				'data'    => [
					'id'     => is_string( $result['id'] ?? null ) ? $result['id'] : '',
					'url'    => is_string( $result['url'] ?? null ) ? $result['url'] : '',
					'status' => is_string( $result['status'] ?? null ) ? $result['status'] : '',
				],
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
		$mode       = $request->get_param( 'mode' );
		$account_id = $request->get_param( 'account_id' );
		$account_id = is_string( $account_id ) ? sanitize_text_field( $account_id ) : null;

		$this->delete_webhook_for_mode( $mode, $account_id );

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
	 * Get Stripe account name for a connected account.
	 *
	 * @param string|null $account_id Optional account id; the default account is used when empty.
	 * @return string Account name or empty string if not found.
	 * @since 0.0.1
	 */
	public function get_account_name( $account_id = null ) {
		if ( empty( $account_id ) ) {
			$account_id = Stripe_Helper::get_default_account_id();
		}

		if ( empty( $account_id ) || ! is_string( $account_id ) ) {
			return '';
		}

		// Call Stripe API to get account information (using this account's key).
		$api_response = Stripe_Helper::stripe_api_request( 'accounts/' . $account_id, 'GET', [], [ 'account_id' => $account_id ] );

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
	 * Stripe events the webhook endpoint subscribes to.
	 *
	 * Handlers are useless unless the event is subscribed here, so anything
	 * adding a handler has to be able to add its event. Pro's recurring
	 * payment-failure handler was unreachable on every site because
	 * `invoice.payment_failed` was missing from this list and there was no way
	 * for Pro to add it.
	 *
	 * @return array<int, string> Stripe event names.
	 * @since 1.4.0
	 */
	public static function get_webhook_events() {
		$events = [
			'charge.succeeded',
			'charge.failed',
			'charge.refunded',
			'charge.refund.updated',
			'charge.dispute.created',
			'charge.dispute.closed',
			'invoice.payment_succeeded',
			'invoice.payment_failed',
			'customer.subscription.created',
			'customer.subscription.updated',
			'customer.subscription.deleted',
			'payment_intent.succeeded',
			'payment_intent.payment_failed',
			'payment_intent.canceled',
		];

		/**
		 * Filter the Stripe events the webhook endpoint subscribes to.
		 *
		 * @param array<int, string> $events Stripe event names.
		 * @since 1.4.0
		 */
		$events = apply_filters( 'suredonation_stripe_webhook_events', $events );

		if ( ! is_array( $events ) ) {
			return [];
		}

		return array_values( array_unique( array_filter( $events, 'is_string' ) ) );
	}

	/**
	 * Reconcile webhook events for every connected account, once per event list.
	 *
	 * Keyed on a hash of the event list rather than a plugin version, so it runs
	 * when the events actually change — including when Pro is activated and adds
	 * its own — and stays quiet otherwise. Each pass is a couple of Stripe calls
	 * per connected mode, on an admin request only.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function maybe_sync_webhook_events() {
		// Context first. admin_init also fires on admin-ajax.php, before the
		// nopriv dispatch, and the whole donation flow runs through nopriv ajax
		// actions — so a donor's payment request reaches this method. Checking
		// the signature first meant that request still paid for an option read
		// and a hash of the event list before being turned away here, which is
		// the cost this guard exists to avoid.
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( self::WEBHOOK_SYNC_OPTION ) === self::get_webhook_events_signature() ) {
			return;
		}

		// While the backoff is held the last run failed, and rescheduling on each
		// pageview would queue an event that immediately returns — busy-waiting
		// through cron until the transient expires.
		if ( get_transient( self::WEBHOOK_SYNC_BACKOFF ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::WEBHOOK_SYNC_HOOK ) ) {
			wp_schedule_single_event( time(), self::WEBHOOK_SYNC_HOOK );
		}
	}

	/**
	 * Reconcile webhook events for every connected account.
	 *
	 * Runs on cron. The signature is recorded only when nothing failed, so a
	 * transient Stripe error is retried rather than being remembered as done —
	 * which would leave that site permanently without the events it is missing.
	 * A short backoff keeps a persistent failure from retrying on every run.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function run_webhook_event_sync() {
		if ( get_transient( self::WEBHOOK_SYNC_BACKOFF ) ) {
			return;
		}

		$failed = false;

		foreach ( Stripe_Helper::get_all_accounts() as $account_id => $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			foreach ( [ 'test', 'live' ] as $mode ) {
				if ( is_wp_error( $this->sync_webhook_events( $mode, (string) $account_id ) ) ) {
					$failed = true;
				}
			}
		}

		if ( $failed ) {
			set_transient( self::WEBHOOK_SYNC_BACKOFF, true, HOUR_IN_SECONDS );
			return;
		}

		update_option( self::WEBHOOK_SYNC_OPTION, self::get_webhook_events_signature(), false );
	}

	/**
	 * Fingerprint of the current event list.
	 *
	 * Sorted before hashing so a filter that returns the same events in a
	 * different order does not look like a change and re-trigger the sync.
	 *
	 * @return string Signature.
	 * @since 1.4.0
	 */
	private static function get_webhook_events_signature() {
		$events = self::get_webhook_events();
		sort( $events );

		return md5( (string) wp_json_encode( $events ) );
	}

	/**
	 * Bring an existing webhook endpoint's event list up to date.
	 *
	 * The event list is otherwise only applied when the endpoint is created, so
	 * a site that connected Stripe before an event was added never receives it.
	 * Reconciling on read means those sites pick up new events without having to
	 * disconnect and reconnect, which would invalidate the stored signing secret.
	 *
	 * Returns a WP_Error only for a genuine API failure. Every other outcome —
	 * no account, no endpoint yet, an endpoint already current, or one
	 * subscribed to everything — is a legitimate no-op and must not be treated
	 * as a failure, or one such account would block the whole run from being
	 * recorded as done.
	 *
	 * @param string      $mode       Payment mode.
	 * @param string|null $account_id Account id; the default account is used when empty.
	 * @return bool|WP_Error True when updated, false when no action was needed, WP_Error on API failure.
	 * @since 1.4.0
	 */
	public function sync_webhook_events( $mode, $account_id = null ) {
		if ( empty( $account_id ) ) {
			$account_id = Stripe_Helper::get_default_account_id();
		}
		if ( empty( $account_id ) ) {
			return false;
		}

		$account = Stripe_Helper::get_account( $account_id );
		if ( ! is_array( $account ) ) {
			return false;
		}

		$webhook_id = isset( $account[ $mode . '_webhook_id' ] ) && is_string( $account[ $mode . '_webhook_id' ] )
			? $account[ $mode . '_webhook_id' ]
			: '';

		if ( '' === $webhook_id ) {
			return false;
		}

		$existing = Stripe_Helper::stripe_api_request(
			'webhook_endpoints/' . $webhook_id,
			'GET',
			[],
			[
				'mode'       => $mode,
				'account_id' => $account_id,
			]
		);

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$current = isset( $existing['enabled_events'] ) && is_array( $existing['enabled_events'] )
			? array_values( array_filter( $existing['enabled_events'], 'is_string' ) )
			: [];
		$wanted  = self::get_webhook_events();

		// Stripe accepts '*' as "every event"; leave such an endpoint alone
		// rather than narrowing what it already receives.
		if ( in_array( '*', $current, true ) || ! array_diff( $wanted, $current ) ) {
			return false;
		}

		$updated = Stripe_Helper::stripe_api_request(
			'webhook_endpoints/' . $webhook_id,
			'POST',
			// Merged, not replaced, so events added by hand in the Stripe
			// dashboard survive. This also makes the events filter additive:
			// removing an entry from it will not unsubscribe an existing endpoint.
			[ 'enabled_events' => array_values( array_unique( array_merge( $current, $wanted ) ) ) ],
			[
				'mode'       => $mode,
				'account_id' => $account_id,
			]
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return true;
	}

	/**
	 * Create Stripe webhook for a mode.
	 *
	 * @param string      $mode       Payment mode.
	 * @param string|null $account_id Account id; the default account is used when empty.
	 * @return array<string, mixed>|WP_Error Webhook data, or a `webhook_exists` error
	 *                                       when the mode is already fully provisioned.
	 * @since 0.0.1
	 */
	private function create_webhook_for_mode( $mode, $account_id = null ) {
		if ( empty( $account_id ) ) {
			$account_id = Stripe_Helper::get_default_account_id();
		}
		if ( empty( $account_id ) ) {
			return new WP_Error( 'no_account', __( 'No Stripe account to create a webhook for.', 'suredonation' ) );
		}

		// Refuse to create a second endpoint for a mode that already has a usable
		// one: the stored secret would be overwritten, so the existing endpoint's
		// deliveries would start failing verification while still consuming one of
		// Stripe's limited per-mode slots. Both the id and the secret must be
		// present — an id with no secret cannot verify anything, so that state has
		// to stay re-creatable rather than being locked in by this guard.
		$account = Stripe_Helper::get_account( $account_id );
		if ( is_array( $account )
			&& ! empty( $account[ "{$mode}_webhook_id" ] )
			&& ! empty( $account[ "{$mode}_webhook_secret" ] ) ) {
			return new WP_Error(
				'webhook_exists',
				__( 'A webhook is already configured for this mode.', 'suredonation' ),
				[ 'status' => 409 ]
			);
		}

		$webhook_url    = Stripe_Helper::get_webhook_url( $mode );
		$enabled_events = self::get_webhook_events();

		$webhook_data = [
			'url'            => $webhook_url,
			'enabled_events' => $enabled_events,
			'description'    => 'SureDonation ' . ucfirst( $mode ) . ' Webhook',
			'api_version'    => '2025-07-30.basil',
		];

		// Create webhook via Stripe API with explicit mode + account.
		$response = Stripe_Helper::stripe_api_request(
			'webhook_endpoints',
			'POST',
			$webhook_data,
			[
				'mode'       => $mode,
				'account_id' => $account_id,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Store the webhook data on the account record.
		Stripe_Helper::update_account_fields(
			$account_id,
			[
				"{$mode}_webhook_id"     => $response['id'] ?? '',
				"{$mode}_webhook_secret" => $response['secret'] ?? '',
				"{$mode}_webhook_url"    => $webhook_url,
			]
		);

		return $response;
	}

	/**
	 * Delete webhook for mode
	 *
	 * @param string      $mode       Payment mode.
	 * @param string|null $account_id Account id; the default account is used when empty.
	 * @return void
	 * @since 0.0.1
	 */
	private function delete_webhook_for_mode( $mode, $account_id = null ) {
		if ( empty( $account_id ) ) {
			$account_id = Stripe_Helper::get_default_account_id();
		}
		if ( empty( $account_id ) ) {
			return;
		}

		$account    = Stripe_Helper::get_account( $account_id );
		$webhook_id = isset( $account[ "{$mode}_webhook_id" ] ) && is_string( $account[ "{$mode}_webhook_id" ] ) ? $account[ "{$mode}_webhook_id" ] : '';

		if ( empty( $webhook_id ) ) {
			return;
		}

		// Delete webhook via Stripe API with explicit mode + account.
		Stripe_Helper::stripe_api_request(
			'webhook_endpoints/' . $webhook_id,
			'DELETE',
			[],
			[
				'mode'       => $mode,
				'account_id' => $account_id,
			]
		);

		// Clear the webhook data on the account record.
		Stripe_Helper::update_account_fields(
			$account_id,
			[
				"{$mode}_webhook_id"     => '',
				"{$mode}_webhook_secret" => '',
				"{$mode}_webhook_url"    => '',
			]
		);
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

		// The live block carries the account id (stripe_user_id) that keys the account.
		$live       = isset( $response['live'] ) && is_array( $response['live'] ) ? $response['live'] : [];
		$test       = isset( $response['test'] ) && is_array( $response['test'] ) ? $response['test'] : [];
		$account_id = sanitize_text_field( $live['stripe_user_id'] ?? '' );

		if ( '' === $account_id ) {
			wp_die(
				esc_html__( 'Stripe did not return an account identifier.', 'suredonation' ),
				esc_html__( 'Stripe Connect Error', 'suredonation' ),
				[ 'response' => 400 ]
			);
		}

		// Upsert the connected account (append; re-connecting the same account refreshes its tokens).
		Stripe_Helper::upsert_account(
			[
				'account_id'           => $account_id,
				'connected'            => true,
				'email'                => isset( $response['account'], $response['account']['email'] ) ? sanitize_email( $response['account']['email'] ) : '',
				'live_publishable_key' => sanitize_text_field( $live['stripe_publishable_key'] ?? '' ),
				'live_secret_key'      => sanitize_text_field( $live['access_token'] ?? '' ),
				'test_publishable_key' => sanitize_text_field( $test['stripe_publishable_key'] ?? '' ),
				'test_secret_key'      => sanitize_text_field( $test['access_token'] ?? '' ),
			]
		);

		// Fetch and store the account name/label from Stripe.
		$account_name = $this->get_account_name( $account_id );
		if ( ! empty( $account_name ) && is_string( $account_name ) ) {
			Stripe_Helper::update_account_fields( $account_id, [ 'label' => $account_name ] );
		}

		// Clean up transients.
		delete_transient( 'suredonation_stripe_connect_nonce_' . get_current_user_id() );

		// Create webhooks for both live and test mode on this account.
		$this->setup_stripe_webhooks( $account_id );

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
	 * @param string|null $account_id Account id; the default account is used when empty.
	 * @return array<string, mixed> Result of webhook creation.
	 * @since 0.0.1
	 */
	private function setup_stripe_webhooks( $account_id = null ) {
		if ( empty( $account_id ) ) {
			$account_id = Stripe_Helper::get_default_account_id();
		}

		$modes            = [ 'test', 'live' ];
		$webhooks_created = 0;
		$webhooks_skipped = 0;
		$errors           = [];

		foreach ( $modes as $mode ) {
			$secret_key = Stripe_Helper::get_stripe_secret_key( $mode, $account_id );

			if ( empty( $secret_key ) ) {
				continue;
			}

			$result = $this->create_webhook_for_mode( $mode, $account_id );

			if ( ! is_wp_error( $result ) ) {
				++$webhooks_created;
			} elseif ( 'webhook_exists' === $result->get_error_code() ) {
				// Already provisioned for this mode; the guard lives in
				// create_webhook_for_mode() so both callers share it.
				++$webhooks_skipped;
			} else {
				/* translators: 1: payment mode (test or live), 2: error message from Stripe. */
				$errors[ $mode ] = sprintf( __( '%1$s: %2$s', 'suredonation' ), ucfirst( $mode ), $result->get_error_message() );
			}
		}

		// One mode failing must not discard another mode's success: the created
		// webhook is already persisted, and reporting overall failure leaves the
		// admin retrying an action that has partly succeeded. Only the modes
		// listed in `errors` need attention.
		return [
			'success' => ( $webhooks_created + $webhooks_skipped ) > 0,
			'created' => $webhooks_created,
			'skipped' => $webhooks_skipped,
			'errors'  => $errors,
			'message' => implode( ' ', $errors ),
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
