<?php
/**
 * Plugin Name: SureDonation
 * Plugin URI: https://suredonation.com
 * Description: A powerful donation management plugin for WordPress with campaign tracking, payment processing, and donor management.
 * Version: 1.3.0
 * Author: SureDonation
 * Text Domain: suredonation
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SureDonation
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define plugin constants.
 */
define( 'SUREDONATION_VER', '1.3.0' );
define( 'SUREDONATION_FILE', __FILE__ );
define( 'SUREDONATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'SUREDONATION_URL', plugin_dir_url( __FILE__ ) );
define( 'SUREDONATION_BASENAME', plugin_basename( __FILE__ ) );
define( 'SUREDONATION_POST_TYPE', 'suredonation_cmpgn' );

/**
 * Middleware base URL for payment processing.
 *
 * Pre-define this constant in `wp-config.php` or a mu-plugin to point at a
 * local/staging middleware. The `defined()` guard prevents the production
 * default from emitting a redefinition warning when an override is in place.
 */
if ( ! defined( 'SUREDONATION_MIDDLEWARE_BASE_URL' ) ) {
	define( 'SUREDONATION_MIDDLEWARE_BASE_URL', 'https://api.sureforms.com/' );
}

/**
 * Abilities API constants.
 */
define( 'SUREDONATION_ABILITY_API_NAMESPACE', 'suredonation/' );

/**
 * OttoKit (formerly SureTriggers) integration base URL.
 */
define( 'SUREDONATION_SURETRIGGERS_INTEGRATION_BASE_URL', 'https://app.ottokit.com/' );

/**
 * Load the plugin loader.
 */
require_once SUREDONATION_DIR . 'plugin-loader.php';

/**
 * Register Abilities API integration (WordPress 6.9+).
 * Graceful degradation: if Abilities API is not available, the plugin functions normally.
 */
if ( class_exists( 'WP_Ability' ) ) {
	$suredonation_ai_settings = SureDonation\Inc\Helper::get_suredonation_option( 'ai_settings', [] );
	if ( is_array( $suredonation_ai_settings ) && ! empty( $suredonation_ai_settings['enable_abilities'] ) ) {
		$suredonation_ability = new SureDonation\Inc\Abilities\Runtime();
		add_action( 'wp_abilities_api_categories_init', [ $suredonation_ability, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $suredonation_ability, 'register' ] );

		// Register dedicated MCP server when enabled and MCP adapter is available.
		if ( ! empty( $suredonation_ai_settings['mcp_server'] ) && class_exists( 'WP\MCP\Plugin' ) ) {
			add_action( 'mcp_adapter_init', [ $suredonation_ability, 'register_mcp_server' ] );
		}
	}
}
