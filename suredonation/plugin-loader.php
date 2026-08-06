<?php
/**
 * Plugin Loader
 *
 * @package SureDonation
 */

namespace SureDonation;

use SureDonation\Inc\Admin\Admin;
use SureDonation\Inc\Admin\Admin_Ajax;
use SureDonation\Inc\Admin\Admin_Bar;
use SureDonation\Inc\Admin\Analytics;
use SureDonation\Inc\Admin\Notice_Manager;
use SureDonation\Inc\Admin\Notices;
use SureDonation\Inc\Ajax\Donation_Handler;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Assets\Register as Assets_Register;
use SureDonation\Inc\Blocks\Register as Blocks_Register;
use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Campaigns\Campaign_Meta_Tags;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Database\Register as Database_Register;
use SureDonation\Inc\Donor_User_Link;
use SureDonation\Inc\FormEditor\Assets as FormEditor_Assets;
use SureDonation\Inc\Onboarding;
use SureDonation\Inc\Privacy\Privacy_Data;
use SureDonation\Inc\Payments\Offline\Offline_Frontend;
use SureDonation\Inc\Payments\Offline\Offline_Settings;
use SureDonation\Inc\Payments\PayPal\PayPal_Frontend;
use SureDonation\Inc\Payments\PayPal\PayPal_Settings;
use SureDonation\Inc\Payments\PayPal\PayPal_Webhook_Listener;
use SureDonation\Inc\Payments\Stripe\Stripe_Frontend;
use SureDonation\Inc\Payments\Stripe\Stripe_Settings;
use SureDonation\Inc\Payments\Stripe\Stripe_Webhook;
use SureDonation\Inc\Page_Builders\Page_Builders;
use SureDonation\Inc\Pdf\Pdf_Manager;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Rest_Api;
use SureDonation\Inc\Shortcodes\Donation_Form as Donation_Form_Shortcode;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class
 *
 * @since 0.0.1
 */
final class Plugin_Loader {
	/**
	 * Instance of this class.
	 *
	 * @var Plugin_Loader
	 */
	private static $instance = null;

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->setup_autoloader();
		$this->init_hooks();
	}

	/**
	 * Get instance of this class.
	 *
	 * @return Plugin_Loader
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Autoload classes.
	 *
	 * @param string $class_name Class name.
	 * @return void
	 */
	public function autoload( $class_name ) {
		// Check if class belongs to our namespace.
		if ( strpos( $class_name, 'SureDonation\\' ) !== 0 ) {
			return;
		}

		// Remove namespace prefix.
		$class_name = str_replace( 'SureDonation\\', '', $class_name );

		// Remove 'Inc\' prefix if exists.
		$class_name = str_replace( 'Inc\\', '', $class_name );

		// Convert namespace separators to directory separators.
		$class_name = str_replace( '\\', DIRECTORY_SEPARATOR, $class_name );

		// Convert class name parts to lowercase with hyphens.
		$parts = explode( DIRECTORY_SEPARATOR, $class_name );
		$parts = array_map(
			static function ( $part ) {
				// Convert CamelCase to kebab-case.
				$converted = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $part );
				return strtolower( str_replace( '_', '-', is_string( $converted ) ? $converted : $part ) );
			},
			$parts
		);

		$class_name = implode( DIRECTORY_SEPARATOR, $parts );

		// Build file path.
		$file = SUREDONATION_DIR . 'inc/' . $class_name . '.php';

		// Load file if exists.
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Load plugin components.
	 *
	 * @return void
	 */
	public function load_plugin() {
		// Initialize database tables.
		Database_Register::init();

		// Back-fill notification identities once, in admin context only — it
		// writes, and the path it repairs runs during donor payment requests.
		add_action( 'admin_init', [ Email_Handler::class, 'backfill_notification_keys' ] );

		// Initialize Campaign CPT.
		Campaign_Cpt::get_instance();

		// Initialize Campaign Page (default layout seeding + display block helpers).
		Campaign_Page::get_instance();

		// Output Open Graph / Twitter Card tags on singular campaign pages.
		Campaign_Meta_Tags::get_instance();

		// Page-builder integrations (Bricks now; each self-gates on the builder).
		// Page-builder integrations (Elementor now; each self-gates on the builder).
		Page_Builders::get_instance();

		// Initialize Donation Form CPT.
		Donation_Form::get_instance();

		// Initialize Admin.
		if ( is_admin() ) {
			Admin::get_instance();
			Admin_Ajax::get_instance();
			Pdf_Manager::get_instance();
			Onboarding::get_instance();
			Notices::get_instance();
			Notice_Manager::get_instance();
		}

		// Payment-mode toolbar indicator. Loaded outside is_admin() so it also
		// appears in the front-end admin bar; it self-gates on capability.
		Admin_Bar::get_instance();

		// Initialize Analytics. Loaded outside is_admin() so usage events
		// fired during REST requests (onboarding, campaign publish) are
		// captured; the class gates its own admin-only behavior internally.
		Analytics::get_instance();

		// Initialize REST API.
		Rest_Api::get_instance();

		// Initialize blocks (must be before init hook fires).
		Blocks_Register::get_instance();

		// Initialize frontend assets.
		Assets_Register::get_instance();

		// Initialize form editor assets (sidebar, meta, etc.).
		FormEditor_Assets::get_instance();

		// Initialize AJAX handlers.
		Donation_Handler::get_instance();

		// Initialize shortcodes.
		Donation_Form_Shortcode::get_instance();

		// Register WordPress personal-data export/erase integration.
		Privacy_Data::get_instance();

		// Initialize Stripe payment gateway.
		Stripe_Settings::get_instance();
		Stripe_Webhook::get_instance();
		Stripe_Frontend::get_instance();

		// Initialize Offline payment gateway.
		Offline_Settings::get_instance();
		Offline_Frontend::get_instance();

		// Initialize PayPal payment gateway.
		PayPal_Settings::get_instance();
		PayPal_Frontend::get_instance();
		PayPal_Webhook_Listener::get_instance();

		// Initialize donor-user linking.
		Donor_User_Link::get_instance();
	}

	/**
	 * Setup autoloader for plugin classes.
	 *
	 * @return void
	 */
	private function setup_autoloader() {
		spl_autoload_register( [ $this, 'autoload' ] );
	}

	/**
	 * Initialize plugin hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Activation hook.
		register_activation_hook(
			SUREDONATION_FILE,
			static function () {
				// Reset rewrite rules.
				delete_option( 'rewrite_rules' );

				// Set redirect flag for onboarding.
				update_option( '__suredonation_do_redirect', true );

				// Record install time for the review-notice grace period.
				// add_option is a no-op if the value already exists, so it
				// never clobbers a real install time on re-activation.
				add_option( 'suredonation_install_time', time() );

				// Register the donor role.
				if ( ! get_role( 'suredonation_donor' ) ) {
					add_role(
						'suredonation_donor',
						__( 'Donor', 'suredonation' ),
						[ 'read' => true ]
					);
				}
			}
		);

		// Deactivation hook.
		register_deactivation_hook(
			SUREDONATION_FILE,
			static function () {
				update_option( '__suredonation_do_redirect', false );

				// Otherwise the webhook reconciliation stays in cron after the
				// plugin is gone, firing at a callback that no longer exists.
				wp_clear_scheduled_hook( Stripe_Settings::WEBHOOK_SYNC_HOOK );
			}
		);

		// Ensure donor role exists (for upgrades without re-activation).
		add_action(
			'init',
			static function () {
				if ( ! get_role( 'suredonation_donor' ) ) {
					add_role(
						'suredonation_donor',
						__( 'Donor', 'suredonation' ),
						[ 'read' => true ]
					);
				}
				// Load plugin text domain.
				load_plugin_textdomain( 'suredonation', false, dirname( plugin_basename( SUREDONATION_FILE ) ) . '/languages' );
			}
		);

		// Initialize plugin after WordPress loads.
		add_action( 'plugins_loaded', [ $this, 'load_plugin' ], 99 );
	}
}

// Initialize plugin.
Plugin_Loader::get_instance();
