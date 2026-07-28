<?php
/**
 * Offline Settings - REST API for offline donation configuration
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Payments\Offline;

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
 * Offline_Settings class
 * Manages offline donation REST API endpoints
 *
 * @since 1.0.0
 */
class Offline_Settings {
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Get offline settings.
		register_rest_route(
			'suredonation/v1',
			'/payments/offline/settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);

		// Update offline settings.
		register_rest_route(
			'suredonation/v1',
			'/payments/offline/settings',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'enabled'      => [
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
					'instructions' => [
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					],
				],
			]
		);
	}

	/**
	 * Get offline settings
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function get_settings( $request ) {
		unset( $request ); // Unused parameter.

		// get_all_offline_settings() already fills the default template only when
		// nothing has ever been saved (wp_parse_args on a missing key). We must NOT
		// coerce an explicitly-stored blank back to the default here: that would show
		// the default in the editor while the frontend correctly renders blank — an
		// inconsistent, unrepresentable "blank" state. Editor and frontend read the
		// same stored value, so they stay in sync.
		$settings = Offline_Helper::get_all_offline_settings();

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => $settings,
			],
			200
		);
	}

	/**
	 * Update offline settings
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function update_settings( $request ) {
		$params = $request->get_json_params();

		// Use the raw stored settings as the write baseline — not the coerced
		// read (get_all_offline_settings), which would otherwise materialize the
		// locale-frozen default into the DB when only the enable toggle changes.
		$settings = Payment_Helper::get_gateway_settings( 'offline' );

		if ( isset( $params['enabled'] ) ) {
			$settings['enabled'] = rest_sanitize_boolean( (bool) $params['enabled'] );
		}

		if ( isset( $params['instructions'] ) ) {
			$instructions             = is_string( $params['instructions'] ) ? $params['instructions'] : '';
			$settings['instructions'] = wp_kses_post( $instructions );
		}

		$updated = Payment_Helper::update_gateway_settings( 'offline', $settings );

		if ( ! $updated ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Failed to update offline donation settings.', 'suredonation' ),
				],
				500
			);
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Offline donation settings updated successfully.', 'suredonation' ),
				'settings' => $settings,
			],
			200
		);
	}

	/**
	 * Check if user has permission
	 *
	 * @return bool True if user has permission.
	 * @since 1.0.0
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}
}
