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
	 * Post meta key storing a donation form's selected Stripe account.
	 *
	 * Value is a Stripe account id (`acct_…`) to override, or 'default'/empty
	 * to use the site default account.
	 *
	 * @since 1.3.0
	 */
	public const FORM_ACCOUNT_META_KEY = '_suredonation_form_stripe_account';

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
	 * Normalize Stripe settings into the multi-account shape.
	 *
	 * Produces an in-memory view with an `accounts` map (keyed by the
	 * immutable Stripe account id, `acct_…`) plus a `default_account_id`
	 * pointer. If the settings already carry an `accounts` map (native
	 * multi-account data), it is used as-is. Otherwise a single legacy
	 * connection stored as flat fields is projected into one account entry,
	 * so already-connected sites keep working with zero behaviour change.
	 *
	 * This is a pure projection — it does not persist. The flat fields
	 * remain the source of truth until the connect flow becomes
	 * account-native, so a reconnect that rewrites the flat fields is always
	 * reflected here (no stale snapshot).
	 *
	 * @param array<string, mixed> $settings Raw Stripe settings.
	 * @return array<string, mixed> Settings with `accounts` + `default_account_id`.
	 * @since 1.3.0
	 */
	public static function normalize_accounts( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		// Native multi-account data already present — use it as the source of truth.
		if ( isset( $settings['accounts'] ) && is_array( $settings['accounts'] ) ) {
			if ( empty( $settings['default_account_id'] ) || ! isset( $settings['accounts'][ $settings['default_account_id'] ] ) ) {
				$settings['default_account_id'] = self::pick_default_account_id( $settings['accounts'] );
			}
			return $settings;
		}

		// Project a legacy single connection (flat fields) into one account entry.
		$accounts  = [];
		$legacy_id = isset( $settings['stripe_account_id'] ) && is_string( $settings['stripe_account_id'] ) ? $settings['stripe_account_id'] : '';

		if ( ! empty( $settings['stripe_connected'] ) && '' !== $legacy_id ) {
			$accounts[ $legacy_id ] = [
				'account_id'           => $legacy_id,
				'label'                => is_string( $settings['account_name'] ?? null ) ? $settings['account_name'] : '',
				'email'                => is_string( $settings['stripe_account_email'] ?? null ) ? $settings['stripe_account_email'] : '',
				'connected'            => true,
				'live_secret_key'      => is_string( $settings['stripe_live_secret_key'] ?? null ) ? $settings['stripe_live_secret_key'] : '',
				'live_publishable_key' => is_string( $settings['stripe_live_publishable_key'] ?? null ) ? $settings['stripe_live_publishable_key'] : '',
				'test_secret_key'      => is_string( $settings['stripe_test_secret_key'] ?? null ) ? $settings['stripe_test_secret_key'] : '',
				'test_publishable_key' => is_string( $settings['stripe_test_publishable_key'] ?? null ) ? $settings['stripe_test_publishable_key'] : '',
				'live_webhook_secret'  => is_string( $settings['webhook_live_secret'] ?? null ) ? $settings['webhook_live_secret'] : '',
				'live_webhook_id'      => is_string( $settings['webhook_live_id'] ?? null ) ? $settings['webhook_live_id'] : '',
				'live_webhook_url'     => is_string( $settings['webhook_live_url'] ?? null ) ? $settings['webhook_live_url'] : '',
				'test_webhook_secret'  => is_string( $settings['webhook_test_secret'] ?? null ) ? $settings['webhook_test_secret'] : '',
				'test_webhook_id'      => is_string( $settings['webhook_test_id'] ?? null ) ? $settings['webhook_test_id'] : '',
				'test_webhook_url'     => is_string( $settings['webhook_test_url'] ?? null ) ? $settings['webhook_test_url'] : '',
			];
		}

		$settings['accounts']           = $accounts;
		$settings['default_account_id'] = self::pick_default_account_id( $accounts, $legacy_id );

		return $settings;
	}

	/**
	 * Choose the default account id from an accounts map.
	 *
	 * Prefers the given preferred id when present, otherwise falls back to
	 * the first connected account, otherwise the first account, otherwise ''.
	 *
	 * @param array<string, array<string, mixed>> $accounts     Accounts map.
	 * @param string                              $preferred_id Preferred account id.
	 * @return string Default account id, or '' when there are no accounts.
	 * @since 1.3.0
	 */
	public static function pick_default_account_id( $accounts, $preferred_id = '' ) {
		if ( ! is_array( $accounts ) || empty( $accounts ) ) {
			return '';
		}

		if ( '' !== $preferred_id && isset( $accounts[ $preferred_id ] ) ) {
			return $preferred_id;
		}

		foreach ( $accounts as $id => $account ) {
			if ( is_array( $account ) && ! empty( $account['connected'] ) ) {
				return (string) $id;
			}
		}

		return (string) array_key_first( $accounts );
	}

	/**
	 * Get all connected Stripe accounts, keyed by account id.
	 *
	 * @return array<string, array<string, mixed>> Accounts map.
	 * @since 1.3.0
	 */
	public static function get_all_accounts() {
		$settings = self::normalize_accounts( self::get_all_stripe_settings() );
		return is_array( $settings['accounts'] ) ? $settings['accounts'] : [];
	}

	/**
	 * Get the default account id.
	 *
	 * @return string Default account id, or '' when none connected.
	 * @since 1.3.0
	 */
	public static function get_default_account_id() {
		$settings = self::normalize_accounts( self::get_all_stripe_settings() );
		return is_string( $settings['default_account_id'] ) ? $settings['default_account_id'] : '';
	}

	/**
	 * Get a single account record by id.
	 *
	 * @param string $account_id Stripe account id (`acct_…`).
	 * @return array<string, mixed> Account record, or [] when not found.
	 * @since 1.3.0
	 */
	public static function get_account( $account_id ) {
		$accounts = self::get_all_accounts();
		return isset( $accounts[ $account_id ] ) && is_array( $accounts[ $account_id ] ) ? $accounts[ $account_id ] : [];
	}

	/**
	 * Resolve an account record for use at a call site.
	 *
	 * When $account_id is null/empty the default account is used. Returns []
	 * when the account cannot be resolved (callers treat this as "not
	 * connected" and read nothing).
	 *
	 * @param string|null $account_id Optional account id; default account when empty.
	 * @return array<string, mixed> Resolved account record, or [].
	 * @since 1.3.0
	 */
	public static function resolve_account_record( $account_id = null ) {
		if ( empty( $account_id ) ) {
			$account_id = self::get_default_account_id();
		}
		if ( empty( $account_id ) ) {
			return [];
		}
		return self::get_account( $account_id );
	}

	/**
	 * Resolve which connected account a donation form should charge to.
	 *
	 * Uses the form's selected account when it is set to a valid, connected
	 * account; otherwise falls back to the site default account.
	 *
	 * @param int|string $form_id Donation form post ID.
	 * @return string Account id (`acct_…`), or '' when no account is connected.
	 * @since 1.3.0
	 */
	public static function resolve_account_for_form( $form_id ) {
		$form_id = absint( $form_id );

		if ( $form_id > 0 ) {
			$selected = get_post_meta( $form_id, self::FORM_ACCOUNT_META_KEY, true );
			if ( is_string( $selected ) && '' !== $selected && 'default' !== $selected && ! empty( self::get_account( $selected ) ) ) {
				return $selected;
			}
		}

		return self::get_default_account_id();
	}

	/**
	 * Insert or update a connected account, then persist.
	 *
	 * Merges into any existing record for the same account id (so re-connecting
	 * the same Stripe account refreshes its tokens). The first account becomes
	 * the default.
	 *
	 * @param array<string, mixed> $account Account record; must include `account_id`.
	 * @return string The account id, or '' when the payload has no account id.
	 * @since 1.3.0
	 */
	public static function upsert_account( $account ) {
		$account_id = isset( $account['account_id'] ) && is_string( $account['account_id'] ) ? $account['account_id'] : '';
		if ( '' === $account_id ) {
			return '';
		}

		$accounts                = self::get_all_accounts();
		$existing                = isset( $accounts[ $account_id ] ) && is_array( $accounts[ $account_id ] ) ? $accounts[ $account_id ] : [];
		$accounts[ $account_id ] = array_merge( $existing, $account );

		$default_id = self::get_default_account_id();
		if ( '' === $default_id ) {
			$default_id = $account_id;
		}

		self::save_accounts( $accounts, $default_id );
		return $account_id;
	}

	/**
	 * Merge fields into an existing account record and persist.
	 *
	 * @param string               $account_id Account id.
	 * @param array<string, mixed> $fields     Fields to merge.
	 * @return bool True on success, false when the account does not exist.
	 * @since 1.3.0
	 */
	public static function update_account_fields( $account_id, $fields ) {
		$accounts = self::get_all_accounts();
		if ( ! isset( $accounts[ $account_id ] ) || ! is_array( $accounts[ $account_id ] ) ) {
			return false;
		}
		$accounts[ $account_id ] = array_merge( $accounts[ $account_id ], $fields );
		return self::save_accounts( $accounts, self::get_default_account_id() );
	}

	/**
	 * Remove a connected account and persist (repicking the default if needed).
	 *
	 * @param string $account_id Account id.
	 * @return bool True on success, false when the account does not exist.
	 * @since 1.3.0
	 */
	public static function remove_account( $account_id ) {
		$accounts = self::get_all_accounts();
		if ( ! isset( $accounts[ $account_id ] ) ) {
			return false;
		}
		unset( $accounts[ $account_id ] );

		$default_id = self::get_default_account_id();
		if ( $default_id === $account_id ) {
			$default_id = self::pick_default_account_id( $accounts );
		}

		return self::save_accounts( $accounts, $default_id );
	}

	/**
	 * Set the default account and persist.
	 *
	 * @param string $account_id Account id.
	 * @return bool True on success, false when the account does not exist.
	 * @since 1.3.0
	 */
	public static function set_default_account( $account_id ) {
		$accounts = self::get_all_accounts();
		if ( ! isset( $accounts[ $account_id ] ) ) {
			return false;
		}
		return self::save_accounts( $accounts, (string) $account_id );
	}

	/**
	 * Get a sanitized account list safe for the browser (no secret material).
	 *
	 * Returns only the account id, label, email, connected + default flags,
	 * the publishable keys (which are public by design), and per-mode booleans
	 * for whether a webhook has been configured. Secret keys and webhook
	 * secrets are never included.
	 *
	 * @return array<int, array<string, mixed>> Sanitized account list.
	 * @since 1.3.0
	 */
	public static function get_public_accounts() {
		$default_id = self::get_default_account_id();
		$public     = [];

		foreach ( self::get_all_accounts() as $id => $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$public[] = [
				'account_id'              => (string) $id,
				'label'                   => is_string( $account['label'] ?? null ) ? $account['label'] : '',
				'email'                   => is_string( $account['email'] ?? null ) ? $account['email'] : '',
				'connected'               => ! empty( $account['connected'] ),
				'is_default'              => ( (string) $id === $default_id ),
				'live_publishable_key'    => is_string( $account['live_publishable_key'] ?? null ) ? $account['live_publishable_key'] : '',
				'test_publishable_key'    => is_string( $account['test_publishable_key'] ?? null ) ? $account['test_publishable_key'] : '',
				'test_webhook_configured' => ! empty( $account['test_webhook_id'] ),
				'live_webhook_configured' => ! empty( $account['live_webhook_id'] ),
			];
		}

		return $public;
	}

	/**
	 * Persist the accounts map + default pointer, mirroring the default account
	 * into the legacy flat fields for back-compat readers.
	 *
	 * @param array<string, array<string, mixed>> $accounts   Accounts map.
	 * @param string|null                         $default_id Default account id; recomputed when null.
	 * @return bool True on success.
	 * @since 1.3.0
	 */
	private static function save_accounts( $accounts, $default_id = null ) {
		$settings             = self::get_all_stripe_settings();
		$settings             = is_array( $settings ) ? $settings : [];
		$settings['accounts'] = $accounts;

		if ( null === $default_id ) {
			$current    = is_string( $settings['default_account_id'] ?? null ) ? $settings['default_account_id'] : '';
			$default_id = self::pick_default_account_id( $accounts, $current );
		}
		$settings['default_account_id'] = (string) $default_id;

		$settings = self::apply_flat_mirror( $settings );

		return self::update_all_stripe_settings( $settings );
	}

	/**
	 * Mirror the default account's fields into the legacy flat settings so
	 * code paths not yet migrated to the accounts model keep working.
	 *
	 * @param array<string, mixed> $settings Settings carrying `accounts` + `default_account_id`.
	 * @return array<string, mixed> Settings with legacy flat fields synced to the default account.
	 * @since 1.3.0
	 */
	private static function apply_flat_mirror( $settings ) {
		$accounts   = isset( $settings['accounts'] ) && is_array( $settings['accounts'] ) ? $settings['accounts'] : [];
		$default_id = is_string( $settings['default_account_id'] ?? null ) ? $settings['default_account_id'] : '';
		$default    = isset( $accounts[ $default_id ] ) && is_array( $accounts[ $default_id ] ) ? $accounts[ $default_id ] : [];

		$map = [
			'stripe_account_id'           => 'account_id',
			'stripe_account_email'        => 'email',
			'account_name'                => 'label',
			'stripe_live_secret_key'      => 'live_secret_key',
			'stripe_live_publishable_key' => 'live_publishable_key',
			'stripe_test_secret_key'      => 'test_secret_key',
			'stripe_test_publishable_key' => 'test_publishable_key',
			'webhook_live_secret'         => 'live_webhook_secret',
			'webhook_live_id'             => 'live_webhook_id',
			'webhook_live_url'            => 'live_webhook_url',
			'webhook_test_secret'         => 'test_webhook_secret',
			'webhook_test_id'             => 'test_webhook_id',
			'webhook_test_url'            => 'test_webhook_url',
		];

		foreach ( $map as $flat_key => $account_key ) {
			$settings[ $flat_key ] = is_string( $default[ $account_key ] ?? null ) ? $default[ $account_key ] : '';
		}
		$settings['stripe_connected'] = ! empty( $default['connected'] );

		return $settings;
	}

	/**
	 * Check if Stripe is connected (any account).
	 *
	 * @return bool True if at least one account is connected.
	 * @since 0.0.1
	 */
	public static function is_stripe_connected() {
		foreach ( self::get_all_accounts() as $account ) {
			if ( is_array( $account ) && ! empty( $account['connected'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether the Stripe webhook is configured for a mode.
	 *
	 * A webhook counts as configured once its id has been stored for the mode
	 * (set on creation, cleared on delete). This is a cheap local check; it does
	 * not verify the endpoint still exists in Stripe.
	 *
	 * @param string $mode Optional. Payment mode ('test' or 'live'). Defaults to the current mode.
	 * @return bool True if a webhook id is stored for the mode.
	 * @since 1.3.0
	 */
	public static function is_webhook_configured( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}
		$settings = self::get_all_stripe_settings();
		return ! empty( $settings[ "webhook_{$mode}_id" ] );
	}

	/**
	 * Get Stripe secret key (mode- and account-aware)
	 *
	 * @param string      $mode       Optional. Payment mode ('test' or 'live'). Defaults to current mode.
	 * @param string|null $account_id Optional. Account id; the default account is used when empty.
	 * @return string Secret key.
	 * @since 0.0.1
	 */
	public static function get_stripe_secret_key( $mode = '', $account_id = null ) {
		if ( empty( $mode ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}
		$account = self::resolve_account_record( $account_id );
		$key     = $account[ $mode . '_secret_key' ] ?? '';
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Get Stripe publishable key (mode- and account-aware)
	 *
	 * @param string      $mode       Optional. Payment mode ('test' or 'live'). Defaults to current mode.
	 * @param string|null $account_id Optional. Account id; the default account is used when empty.
	 * @return string Publishable key.
	 * @since 0.0.1
	 */
	public static function get_stripe_publishable_key( $mode = '', $account_id = null ) {
		if ( empty( $mode ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}
		$account = self::resolve_account_record( $account_id );
		$key     = $account[ $mode . '_publishable_key' ] ?? '';
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Get Stripe webhook secret (mode- and account-aware)
	 *
	 * @param string      $mode       Optional. Payment mode ('test' or 'live'). Defaults to current mode.
	 * @param string|null $account_id Optional. Account id; the default account is used when empty.
	 * @return string Webhook secret.
	 * @since 0.0.1
	 */
	public static function get_webhook_secret( $mode = '', $account_id = null ) {
		if ( empty( $mode ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}
		$account = self::resolve_account_record( $account_id );
		$secret  = $account[ $mode . '_webhook_secret' ] ?? '';
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
	 * @param string|null $account_id Optional. When provided and connected, it is returned as-is; otherwise the default account id.
	 * @return string Account ID.
	 * @since 0.0.1
	 */
	public static function get_stripe_account_id( $account_id = null ) {
		if ( ! empty( $account_id ) && ! empty( self::get_account( $account_id ) ) ) {
			return (string) $account_id;
		}
		return self::get_default_account_id();
	}

	/**
	 * Get Stripe account email
	 *
	 * @param string|null $account_id Optional. Account id; the default account is used when empty.
	 * @return string Account email.
	 * @since 0.0.1
	 */
	public static function get_stripe_account_email( $account_id = null ) {
		$account = self::resolve_account_record( $account_id );
		$email   = $account['email'] ?? '';
		return is_string( $email ) ? $email : '';
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
	 * @param array<string,mixed> $extra_args Extra arguments: 'mode' (test/live) and 'account_id' (which connected account to use).
	 * @return array<string,mixed>|\WP_Error Response data.
	 * @since 0.0.1
	 */
	public static function stripe_api_request( $endpoint, $method = 'POST', $data = [], $extra_args = [] ) {
		// Get mode from extra_args or default to current payment mode.
		$mode       = isset( $extra_args['mode'] ) && is_string( $extra_args['mode'] ) ? $extra_args['mode'] : '';
		$account_id = isset( $extra_args['account_id'] ) && is_string( $extra_args['account_id'] ) ? $extra_args['account_id'] : null;
		$secret_key = self::get_stripe_secret_key( $mode, $account_id );

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
	 * @param string|null         $account_id    Connected account to create the customer on; default account when empty.
	 * @return array<string,mixed>|\WP_Error Customer data.
	 * @since 0.0.1
	 */
	public static function create_customer( $customer_data, $account_id = null ) {
		$data = [
			'email'       => $customer_data['email'] ?? '',
			'name'        => $customer_data['name'] ?? '',
			'description' => $customer_data['description'] ?? '',
		];

		// Remove empty values.
		$data = array_filter( $data );

		return self::stripe_api_request( 'customers', 'POST', $data, [ 'account_id' => $account_id ] );
	}

	/**
	 * Verify that a Stripe customer exists.
	 *
	 * This is useful when reusing cached customer IDs to handle mode switches
	 * (test/live) or deleted customers gracefully.
	 *
	 * @param string      $customer_id Stripe customer ID.
	 * @param string|null $account_id  Connected account the customer should exist on; default account when empty.
	 * @return bool True if customer exists, false otherwise.
	 * @since 0.0.1
	 */
	public static function verify_customer_exists( $customer_id, $account_id = null ) {
		if ( empty( $customer_id ) ) {
			return false;
		}

		$response = self::stripe_api_request( 'customers/' . $customer_id, 'GET', [], [ 'account_id' => $account_id ] );

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
	 * @param string|null         $account_id  Connected account to charge; default account when empty.
	 * @return array<string,mixed>|\WP_Error Intent data.
	 * @since 0.0.1
	 */
	public static function create_payment_intent( $intent_data, $account_id = null ) {
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

		return self::stripe_api_request( 'payment_intents', 'POST', $data, [ 'account_id' => $account_id ] );
	}

	/**
	 * Retrieve Payment Intent
	 *
	 * @param string      $intent_id  Payment intent ID.
	 * @param string|null $account_id Connected account the intent belongs to; default account when empty.
	 * @return array<string,mixed>|\WP_Error Intent data.
	 * @since 0.0.1
	 */
	public static function retrieve_payment_intent( $intent_id, $account_id = null ) {
		return self::stripe_api_request( 'payment_intents/' . $intent_id, 'GET', [], [ 'account_id' => $account_id ] );
	}

	/**
	 * Create refund
	 *
	 * @param string      $payment_intent_id Payment intent ID.
	 * @param int|null    $amount            Amount to refund (in cents). Leave empty for full refund.
	 * @param string      $reason            Refund reason.
	 * @param string|null $account_id        Account that processed the original charge; default account when empty.
	 * @return array<string,mixed>|\WP_Error Refund data.
	 * @since 0.0.1
	 */
	public static function create_refund( $payment_intent_id, $amount = null, $reason = '', $account_id = null ) {
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

		return self::stripe_api_request( 'refunds', 'POST', $data, [ 'account_id' => $account_id ] );
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
	 * @param string|null         $account_id  Connected account to charge; default account when empty.
	 * @return array<string,mixed>|\WP_Error Intent data.
	 * @since 0.0.1
	 */
	public static function create_payment_intent_via_middleware( $intent_data, $account_id = null ) {
		$secret_key = self::get_stripe_secret_key( '', $account_id );

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
	 * @param string      $payment_intent_id Payment Intent ID to capture.
	 * @param string|null $account_id        Account that created the intent; default account when empty.
	 * @return array<string,mixed>|\WP_Error Captured payment intent data.
	 * @since 0.0.1
	 */
	public static function capture_payment_intent_via_middleware( $payment_intent_id, $account_id = null ) {
		$secret_key = self::get_stripe_secret_key( '', $account_id );

		if ( empty( $secret_key ) ) {
			return new \WP_Error( 'no_secret_key', __( 'Stripe secret key not configured', 'suredonation' ) );
		}

		if ( empty( $payment_intent_id ) ) {
			return new \WP_Error( 'missing_payment_intent_id', __( 'Payment intent ID is required', 'suredonation' ) );
		}

		$middleware_data = [
			'secret_key'        => $secret_key,
			'payment_intent_id' => $payment_intent_id,
			'stripe_account_id' => self::get_stripe_account_id( $account_id ),
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
