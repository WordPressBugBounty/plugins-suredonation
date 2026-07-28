<?php
/**
 * General Settings REST API endpoints.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API class.
 *
 * @since 0.0.1
 */
class Settings_API {
	/**
	 * Option key for email notifications within consolidated options.
	 *
	 * @since 0.0.1
	 */
	public const EMAIL_OPTION_KEY = 'email_notifications';

	/**
	 * Option key for AI settings within consolidated options.
	 *
	 * @since 1.0.0
	 */
	public const AI_OPTION_KEY = 'ai_settings';

	/**
	 * Option key for spam protection settings within consolidated options.
	 *
	 * @since 1.1.0
	 */
	public const SPAM_OPTION_KEY = 'spam_protection_settings';

	/**
	 * Option key for donor management settings within consolidated options.
	 *
	 * @since 1.0.0
	 */
	public const DONOR_OPTION_KEY = 'donor_settings';

	/**
	 * Get settings endpoints.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public function get_endpoints() {
		return [
			// Get currency data for block editor (public endpoint).
			'/settings'                 => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_currency_settings' ],
				'permission_callback' => '__return_true',
			],

			// Get and update general settings.
			'/settings/general'         => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],

			// Get available currencies.
			'/settings/currencies'      => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_currencies' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],

			// Email notifications are managed per-form via post meta.
			// See inc/form-editor/assets.php for the form-level email system.
			// AI settings.
			'/settings/ai'              => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_ai_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_ai_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],

			// Spam protection settings.
			'/settings/spam-protection' => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_spam_protection_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_spam_protection_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],

			// Miscellaneous settings (usage tracking, etc.).
			'/settings/misc'            => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_misc_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_misc_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'usage_tracking' => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			],

			// Donor management settings.
			'/settings/donor'           => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_donor_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_donor_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'create_wp_user' => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			],

			// Form validation default messages.
			'/settings/validation'      => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_validation_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_validation_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],

			// Privacy settings (data retention, consent, privacy/terms fields).
			'/settings/privacy'         => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_privacy_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_privacy_settings' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],

			// Send test email.
			'/settings/email/test'      => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'send_test_email' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
		];
	}

	/**
	 * Get the Privacy settings (stored values merged over the defaults).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 * @since 1.2.0
	 */
	public function get_privacy_settings( $request ) {
		unset( $request ); // Unused parameter.

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => \SureDonation\Inc\Privacy\Privacy_Settings::get_settings(),
			],
			200
		);
	}

	/**
	 * Update the Privacy settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 * @since 1.2.0
	 */
	public function update_privacy_settings( $request ) {
		$params    = $request->get_json_params();
		$sanitized = \SureDonation\Inc\Privacy\Privacy_Settings::sanitize( is_array( $params ) ? $params : [] );

		Helper::update_suredonation_option( \SureDonation\Inc\Privacy\Privacy_Settings::OPTION_KEY, $sanitized );

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => $sanitized,
			],
			200
		);
	}

	/**
	 * Get the form-validation default messages.
	 *
	 * Returns the stored admin overrides merged over the translatable defaults
	 * so every configurable message always has a value in the editor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 * @since 1.1.0
	 */
	public function get_validation_settings( $request ) {
		unset( $request ); // Unused parameter.

		$defaults = \SureDonation\Inc\Field_Validation::default_validation_messages();
		$stored   = Helper::get_suredonation_option( \SureDonation\Inc\Field_Validation::VALIDATION_MESSAGES_OPTION_KEY, [] );

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => wp_parse_args( is_array( $stored ) ? $stored : [], $defaults ),
			],
			200
		);
	}

	/**
	 * Update the form-validation default messages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 * @since 1.1.0
	 */
	public function update_validation_settings( $request ) {
		$current = Helper::get_suredonation_option( \SureDonation\Inc\Field_Validation::VALIDATION_MESSAGES_OPTION_KEY, [] );

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		/**
		 * Filter the list of allowed validation-message keys.
		 *
		 * Lets extensions register additional message keys for their own field
		 * types, mirroring the `suredonation.settings.tab.validationFields` and
		 * `suredonation.settings.tab.requiredValidationFields` JS filters.
		 *
		 * @since 1.1.0
		 * @param array<int, string> $keys Allowed message keys.
		 */
		$allowed_keys = apply_filters(
			'suredonation_validation_message_keys',
			array_keys( \SureDonation\Inc\Field_Validation::default_validation_messages() )
		);

		foreach ( $allowed_keys as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$value = $request->get_param( $key );
			// Guard against non-scalar input (array/object) which would make
			// sanitize_text_field() emit a warning / type error on PHP 8.1+.
			if ( null !== $value && is_scalar( $value ) ) {
				$current[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		Helper::update_suredonation_option( \SureDonation\Inc\Field_Validation::VALIDATION_MESSAGES_OPTION_KEY, $current );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Form validation settings saved', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Get currency settings for block editor.
	 *
	 * Returns minimal currency data needed for frontend/block previews.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_currency_settings( $request ) {
		unset( $request ); // Unused parameter.

		$currency = Payment_Helper::get_currency();

		return new WP_REST_Response(
			[
				'currency'       => $currency,
				'currencySymbol' => Payment_Helper::get_currency_symbol( $currency ),
				'isZeroDecimal'  => Payment_Helper::is_zero_decimal_currency( $currency ),
			],
			200
		);
	}

	/**
	 * Get general settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_settings( $request ) {
		unset( $request ); // Unused parameter.

		$settings = Payment_Helper::get_all_payment_settings();

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => [
					'currency'     => $settings['currency'] ?? 'USD',
					'payment_mode' => $settings['payment_mode'] ?? 'test',
				],
			],
			200
		);
	}

	/**
	 * Update general settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function update_settings( $request ) {
		$params = $request->get_json_params();

		if ( empty( $params ) ) {
			return new WP_Error(
				'invalid_settings',
				__( 'Invalid settings provided', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$current_settings = Payment_Helper::get_all_payment_settings();

		// Update currency if provided.
		if ( isset( $params['currency'] ) ) {
			$currency = strtoupper( sanitize_text_field( $params['currency'] ) );

			// Validate currency.
			$valid_currencies = array_keys( Payment_Helper::get_all_currencies_data() );
			if ( in_array( $currency, $valid_currencies, true ) ) {
				$current_settings['currency'] = $currency;
			}
		}

		// Update payment mode if provided.
		if ( isset( $params['payment_mode'] ) ) {
			$mode = sanitize_text_field( $params['payment_mode'] );
			if ( in_array( $mode, [ 'test', 'live' ], true ) ) {
				$current_settings['payment_mode'] = $mode;
			}
		}

		$success = Payment_Helper::update_all_payment_settings( $current_settings );

		if ( ! $success ) {
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
	 * Get available currencies.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_currencies( $request ) {
		unset( $request ); // Unused parameter.

		return new WP_REST_Response(
			[
				'success'    => true,
				'currencies' => Payment_Helper::get_currencies_list(),
			],
			200
		);
	}

	/**
	 * Get AI settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function get_ai_settings( $request ) {
		unset( $request ); // Unused parameter.

		$defaults = [
			'enable_abilities' => false,
			'allow_updates'    => false,
			'allow_delete'     => false,
			'mcp_server'       => false,
		];
		$settings = Helper::get_suredonation_option( self::AI_OPTION_KEY, [] );

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => wp_parse_args( is_array( $settings ) ? $settings : [], $defaults ),
			],
			200
		);
	}

	/**
	 * Update AI settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function update_ai_settings( $request ) {
		$params  = $request->get_json_params();
		$current = Helper::get_suredonation_option( self::AI_OPTION_KEY, [] );

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		$allowed = [ 'enable_abilities', 'allow_updates', 'allow_delete', 'mcp_server' ];
		foreach ( $allowed as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$current[ $key ] = (bool) $params[ $key ];
			}
		}

		Helper::update_suredonation_option( self::AI_OPTION_KEY, $current );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'AI settings saved', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Get spam protection settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.1.0
	 */
	public function get_spam_protection_settings( $request ) {
		unset( $request ); // Unused parameter.

		$defaults = [
			'honeypot' => false,
		];
		$settings = Helper::get_suredonation_option( self::SPAM_OPTION_KEY, [] );

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => wp_parse_args( is_array( $settings ) ? $settings : [], $defaults ),
			],
			200
		);
	}

	/**
	 * Update spam protection settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.1.0
	 */
	public function update_spam_protection_settings( $request ) {
		$current = Helper::get_suredonation_option( self::SPAM_OPTION_KEY, [] );

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		// Read each setting via get_param() so the endpoint accepts JSON, body,
		// or query params (matches the sibling /settings/* update handlers).
		$allowed = [ 'honeypot' ];
		foreach ( $allowed as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$current[ $key ] = (bool) $value;
			}
		}

		Helper::update_suredonation_option( self::SPAM_OPTION_KEY, $current );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Spam protection settings saved', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Get miscellaneous settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function get_misc_settings( $request ) {
		unset( $request ); // Unused parameter.

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => [
					// Site option - the BSF Analytics library reads this via
					// get_site_option(), so the toggle must use the same
					// scope to stay in sync on multisite.
					'usage_tracking' => 'yes' === get_site_option( 'suredonation_usage_optin', false ),
				],
			],
			200
		);
	}

	/**
	 * Update miscellaneous settings.
	 *
	 * Stores the usage-tracking opt-in as the standalone 'yes'/'no'
	 * suredonation_usage_optin option read by the BSF Analytics library.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function update_misc_settings( $request ) {
		$usage_tracking = $request->get_param( 'usage_tracking' );

		if ( null !== $usage_tracking ) {
			if ( $usage_tracking ) {
				update_site_option( 'suredonation_usage_optin', 'yes' );
			} else {
				// Mirror the library's optout() side effects (see
				// class-bsf-analytics.php) so the cross-product notice
				// throttle and the send-check transient stay consistent.
				update_site_option( 'suredonation_usage_optin', 'no' );
				update_site_option( 'bsf_usage_last_displayed_time', time() );
				delete_site_transient( 'bsf_usage_track' );
			}
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Settings saved', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Get donor management settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function get_donor_settings( $request ) {
		unset( $request ); // Unused parameter.

		$donor_settings = Helper::get_suredonation_option( self::DONOR_OPTION_KEY, [] );
		if ( ! is_array( $donor_settings ) ) {
			$donor_settings = [];
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => [
					// Off by default: guest donations never auto-create WP user accounts.
					'create_wp_user' => ! empty( $donor_settings['create_wp_user'] ),
				],
			],
			200
		);
	}

	/**
	 * Update donor management settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function update_donor_settings( $request ) {
		$create_wp_user = $request->get_param( 'create_wp_user' );

		if ( null !== $create_wp_user ) {
			$donor_settings = Helper::get_suredonation_option( self::DONOR_OPTION_KEY, [] );
			if ( ! is_array( $donor_settings ) ) {
				$donor_settings = [];
			}

			$donor_settings['create_wp_user'] = (bool) $create_wp_user;
			Helper::update_suredonation_option( self::DONOR_OPTION_KEY, $donor_settings );
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Settings saved', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Check if user has permission to manage settings.
	 *
	 * @return bool True if user has permission.
	 * @since 0.0.1
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}
}
