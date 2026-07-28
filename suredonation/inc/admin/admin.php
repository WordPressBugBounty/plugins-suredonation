<?php
/**
 * Admin Interface
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Onboarding;
use SureDonation\Inc\Pdf\Pdf_Utils;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\PayPal\PayPal_Helper;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 *
 * @since 0.0.1
 */
class Admin {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_menu', [ $this, 'hide_forms_submenu' ], 999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'admin_init', [ $this, 'redirect_cpt_listings' ] );
		add_action( 'admin_init', [ $this, 'register_privacy_policy_content' ] );
	}

	/**
	 * Register suggested privacy-policy text for the plugin's data transfers.
	 *
	 * Surfaces the Phone field's automatic country detection (which sends the
	 * visitor's IP to ipapi.co) in the WordPress Privacy Policy Guide
	 * (Settings → Privacy → Policy Guide) so site owners can disclose it in
	 * their own policy. This only suggests text to admins — it publishes
	 * nothing and is not shown to donors.
	 *
	 * @since 1.1.1
	 * @return void
	 */
	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = __( 'When a Phone field with automatic country detection is used, this site sends the visitor’s IP address to a third-party service (ipapi.co) to determine their country code. The IP address is not stored for this purpose; the resulting country code is cached temporarily. Automatic detection can be disabled per Phone field, or site-wide via the “suredonation_phone_geo_enabled” filter.', 'suredonation' );

		wp_add_privacy_policy_content( 'SureDonation', wp_kses_post( wpautop( $content ) ) );
	}

	/**
	 * Redirect the default CPT list screens for suredonation_form and
	 * suredonation_cmpgn to their corresponding plugin pages.
	 *
	 * For the form listing, attempt to resolve the originating campaign
	 * from the referer (the "back" arrow on the form editor sends the
	 * user here) and redirect to that campaign's single view.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_cpt_listings() {
		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post_type from query, no state mutation.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

		if ( 'suredonation_form' === $post_type ) {
			$campaign_id = $this->resolve_campaign_id_from_referer();
			$target      = $campaign_id
				? admin_url( 'admin.php?page=suredonation#/campaigns/' . $campaign_id )
				: admin_url( 'admin.php?page=suredonation#/campaigns' );

			wp_safe_redirect( $target );
			exit;
		}

		if ( 'suredonation_cmpgn' === $post_type ) {
			wp_safe_redirect( admin_url( 'admin.php?page=suredonation#/campaigns' ) );
			exit;
		}
	}

	/**
	 * Resolve the campaign ID from the referer when the form list page
	 * is reached from the form editor's "back" button.
	 *
	 * @return int Campaign ID, or 0 when it cannot be determined.
	 * @since 1.0.0
	 */
	private function resolve_campaign_id_from_referer() {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			return 0;
		}

		$parts = wp_parse_url( $referer );
		if ( empty( $parts['query'] ) ) {
			return 0;
		}

		$query = [];
		parse_str( $parts['query'], $query );

		if ( empty( $query['post'] ) || ! is_numeric( $query['post'] ) ) {
			return 0;
		}

		$form_id = absint( $query['post'] );
		if ( 'suredonation_form' !== get_post_type( $form_id ) ) {
			return 0;
		}

		$campaign_id = get_post_meta( $form_id, '_suredonation_campaign_id', true );
		if ( ! is_numeric( $campaign_id ) ) {
			return 0;
		}
		return absint( $campaign_id );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 * @since 0.0.1
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on our plugin pages.
		if ( strpos( $hook, 'suredonation' ) === false ) {
			return;
		}

		// Onboarding gets its own lean bundle — bail before enqueuing the
		// main admin app on that page.
		if ( false !== strpos( $hook, Onboarding::PAGE_SLUG ) ) {
			$this->enqueue_onboarding_assets();
			return;
		}

		$asset_file = SUREDONATION_DIR . 'assets/build/admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		// Enqueue WordPress editor for TinyMCE support.
		wp_enqueue_editor();

		// Enqueue the media library so the campaign image picker can open it.
		wp_enqueue_media();

		// Enqueue editor buttons style (needed for TinyMCE icons).
		wp_enqueue_style( 'editor-buttons' );

		// Enqueue admin app script.
		wp_enqueue_script(
			'suredonation-admin',
			SUREDONATION_URL . 'assets/build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Enqueue admin app styles.
		wp_enqueue_style(
			'suredonation-admin',
			SUREDONATION_URL . 'assets/build/admin.css',
			[],
			$asset['version']
		);

		// The campaign drawer sits at a very high z-index. Lift the media library
		// above it, and keep the media modal above its OWN backdrop so the picker
		// is not dimmed by either overlay.
		wp_add_inline_style(
			'suredonation-admin',
			'.media-modal-backdrop{z-index:2147483646 !important;}.media-modal{z-index:2147483647 !important;}'
		);

		// Load JS translations for the admin app.
		wp_set_script_translations( 'suredonation-admin', 'suredonation' );

		// Localize script with plugin data.
		wp_localize_script(
			'suredonation-admin',
			'suredonation_admin',
			[
				'version'                   => SUREDONATION_VER,
				'apiUrl'                    => rest_url( 'suredonation/v1' ),
				'nonce'                     => wp_create_nonce( 'wp_rest' ),
				'docsURL'                   => 'https://suredonation.com/docs/',
				'plugin_url'                => SUREDONATION_URL,
				'site_url'                  => site_url(),
				'admin_url'                 => admin_url(),
				'is_first_campaign_created' => $this->has_campaigns(),
				'is_onboarding_completed'   => Onboarding::get_instance()->is_completed(),
				'rotating_plugin_banner'    => $this->get_rotating_plugin_banner(),
				'smart_tags'                => Helper::get_smart_tags(),
				'is_pro_active'             => defined( 'SUREDONATION_PRO_VER' ),
				'pro_plugin_version'        => defined( 'SUREDONATION_PRO_VER' ) ? SUREDONATION_PRO_VER : '',
				'pro_plugin_name'           => defined( 'SUREDONATION_PRO_PRODUCT' ) ? SUREDONATION_PRO_PRODUCT : 'SureDonation Pro',
				'is_license_active'         => defined( 'SUREDONATION_PRO_VER' ) && class_exists( 'SureDonationPro\Inc\Licensing\Licensing' ) ? \SureDonationPro\Inc\Licensing\Licensing::is_license_active() : false,
				'pdf_library_exists'        => Pdf_Utils::check_if_library_exists(),
				'php_version_compatible'    => Pdf_Utils::is_php_compatible(),
				'pdf_nonce'                 => wp_create_nonce( 'suredonation_pdf_nonce' ),
				'ajax_url'                  => admin_url( 'admin-ajax.php' ),
				'plugin_installer_nonce'    => wp_create_nonce( 'updates' ),
				'plugin_manager_nonce'      => wp_create_nonce( 'suredonation_plugin_manager' ),
				'has_abilities_api'         => class_exists( 'WP_Ability' ),
				'current_user_login'        => wp_get_current_user()->user_login,
				'integrations'              => [
					'sure_triggers' => Helper::get_ottokit_integration(),
				],
				// Bootstrap settings data so the General currency list and the
				// PayPal connection state render synchronously (no post-mount
				// fetch flicker). PayPal data is the non-sensitive subset only.
				'payments'                  => [
					'currencies' => Payment_Helper::get_currencies_list(),
					'paypal'     => PayPal_Helper::get_connection_state(),
				],
			]
		);
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_menu() {
		$menu_slug = 'suredonation';

		// SureDonation mark for the admin menu — single path with evenodd
		// fill so the inner heart cuts a hole that reveals the sidebar
		// background (mirrors the pattern used by SureForms).
		$menu_icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M0 3.64405C0 1.6315 1.62712 0 3.63425 0H20.2517C22.2588 0 23.886 1.6315 23.886 3.64405V20.3063C23.886 22.3188 22.2588 23.9503 20.2517 23.9503H3.63425C1.62712 23.9503 0 22.3188 0 20.3063V3.64405ZM12.6516 5.79655C14.4525 3.99075 17.3723 3.99075 19.1734 5.79655C20.9742 7.60234 20.9742 10.5301 19.1734 12.3359L17.5686 13.9451C17.2776 14.2368 16.8057 14.2368 16.5147 13.9451C16.2237 13.6533 16.2237 13.1802 16.5147 12.8884L18.1196 11.2793C19.3385 10.0571 19.3385 8.07537 18.1196 6.85312C16.9007 5.6309 14.9243 5.63092 13.7054 6.85312L11.3607 9.20415C11.0701 9.49552 11.0701 9.96791 11.3607 10.2593C11.6513 10.5506 12.1224 10.5506 12.413 10.2593L13.4659 9.20346C13.7569 8.91169 14.2288 8.91169 14.5198 9.20346C14.8107 9.49523 14.8107 9.96828 14.5198 10.26L13.4667 11.3159C12.5942 12.1909 11.1794 12.1909 10.3069 11.3159C9.43429 10.441 9.43429 9.02249 10.3069 8.14757L10.8879 7.56486L10.1781 6.85312C8.95914 5.63089 6.98277 5.63089 5.76382 6.85312C4.54486 8.07537 4.54488 10.0571 5.76382 11.2793L12.6985 18.2326C12.9895 18.5244 12.9895 18.9974 12.6985 19.2892C12.4075 19.581 11.9358 19.581 11.6448 19.2892L4.71008 12.3359C2.90915 10.5301 2.90913 7.60233 4.71008 5.79655C6.51102 3.99075 9.43091 3.99075 11.2318 5.79655L11.9417 6.50827L12.6516 5.79655ZM11.8605 14.9781C12.1515 14.6863 12.6233 14.6863 12.9143 14.9781L14.5397 16.6079C14.8307 16.8996 14.8307 17.3727 14.5397 17.6645C14.2487 17.9562 13.7769 17.9562 13.4859 17.6645L11.8605 16.0347C11.5695 15.7429 11.5695 15.2699 11.8605 14.9781ZM13.6482 13.1856C13.9392 12.8939 14.4109 12.8939 14.7019 13.1856L16.3273 14.8154C16.6183 15.1072 16.6183 15.5802 16.3273 15.872C16.0363 16.1638 15.5645 16.1638 15.2735 15.872L13.6482 14.2422C13.3572 13.9504 13.3572 13.4774 13.6482 13.1856Z" fill="#D1D5DB"/></svg>';

		// Main menu page.
		add_menu_page(
			__( 'SureDonation', 'suredonation' ),
			__( 'SureDonation', 'suredonation' ),
			'manage_options',
			$menu_slug,
			[ $this, 'render_dashboard' ],
			'data:image/svg+xml;base64,' . base64_encode( $menu_icon_svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building data URI for menu icon.
			25
		);

		// Register submenus.
		$this->register_submenus( $menu_slug );
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function render_dashboard() {
		?>
		<div id="suredonation-admin-root"></div>
		<?php
	}

	/**
	 * Hide the "All Forms" submenu item.
	 *
	 * WordPress automatically adds a submenu for the suredonation_form CPT.
	 * We hide it since forms are managed through campaigns.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function hide_forms_submenu() {
		remove_submenu_page( 'suredonation', 'edit.php?post_type=suredonation_form' );
	}

	/**
	 * Check if any campaigns have been created.
	 *
	 * @return bool True if at least one campaign exists.
	 * @since 0.0.1
	 */
	private function has_campaigns() {
		$campaigns = get_posts(
			[
				'post_type'      => 'suredonation_cmpgn',
				'posts_per_page' => 1,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'fields'         => 'ids',
			]
		);

		return ! empty( $campaigns );
	}

	/**
	 * Get rotating plugin banner data.
	 *
	 * @return array<string,mixed>|null Plugin banner data, or null when the plugin is already active.
	 * @since 0.0.1
	 */
	private function get_rotating_plugin_banner() {
		// Match SureForms: never nudge a plugin that is already active, so the
		// dashboard card is hidden once SureRank is activated.
		$status = $this->get_plugin_status( 'surerank/surerank.php' );
		if ( 'Activated' === $status ) {
			return null;
		}

		// Get SVG logo as base64.
		$logo_svg  = file_get_contents( SUREDONATION_DIR . 'images/surerank.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		$logo_data = $logo_svg ? 'data:image/svg+xml;base64,' . base64_encode( $logo_svg ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		// SureRank plugin data.
		return [
			'slug'                  => 'surerank',
			'path'                  => 'surerank/surerank.php',
			'title'                 => 'SureRank',
			'singleLineDescription' => __( 'SEO Made Easy for Your Website', 'suredonation' ),
			'subtitle'              => __( 'Beautiful pages, persuasive content, and custom code in seconds. The possibilities are endless!', 'suredonation' ),
			'logo'                  => $logo_data,
			'status'                => $status,
		];
	}

	/**
	 * Get plugin installation status.
	 *
	 * @param string $plugin_file Plugin file path (e.g., 'plugin-folder/plugin-file.php').
	 * @return string Plugin status: 'Activated', 'Installed', or 'Install'.
	 * @since 0.0.1
	 */
	private function get_plugin_status( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return 'Activated';
		}

		$all_plugins = get_plugins();
		if ( isset( $all_plugins[ $plugin_file ] ) ) {
			return 'Installed';
		}

		return 'Install';
	}

	/**
	 * Register submenus.
	 *
	 * @param string $menu_slug Menu slug.
	 * @return void
	 * @since 0.0.1
	 */
	private function register_submenus( $menu_slug ) {
		$capability = 'manage_options';

		$submenus = [
			[
				'id'         => $menu_slug,
				'page_title' => __( 'Dashboard', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/campaigns',
				'page_title' => __( 'Campaigns', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/donations',
				'page_title' => __( 'Donations', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/donors',
				'page_title' => __( 'Donors', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/reports',
				'page_title' => __( 'Reports', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/settings',
				'page_title' => __( 'Settings', 'suredonation' ),
			],
		];

		// Register the submenus.
		foreach ( $submenus as $submenu ) {
			add_submenu_page(
				$menu_slug,
				$submenu['page_title'],
				$submenu['page_title'],
				$capability,
				$submenu['id'],
				[ $this, 'render_dashboard' ]
			);
		}

		// Hidden onboarding page (no menu parent so it never shows in
		// the sidebar but is still routable via admin.php?page=...).
		add_submenu_page(
			'',
			__( 'SureDonation Setup', 'suredonation' ),
			__( 'SureDonation Setup', 'suredonation' ),
			$capability,
			Onboarding::PAGE_SLUG,
			[ $this, 'render_onboarding' ]
		);
	}

	/**
	 * Render onboarding root container.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_onboarding() {
		?>
		<div id="suredonation-onboarding-root"></div>
		<?php
	}

	/**
	 * Enqueue the dedicated onboarding bundle.
	 *
	 * Loaded only on the onboarding admin page so the main admin app
	 * doesn't pay the cost on activation redirects.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function enqueue_onboarding_assets() {
		$asset_file = SUREDONATION_DIR . 'assets/build/admin/onboarding/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'suredonation-onboarding',
			SUREDONATION_URL . 'assets/build/admin/onboarding/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'suredonation-onboarding',
			SUREDONATION_URL . 'assets/build/admin/onboarding/index.css',
			[],
			$asset['version']
		);

		wp_set_script_translations( 'suredonation-onboarding', 'suredonation' );

		$current_user = wp_get_current_user();
		$is_pro       = defined( 'SUREDONATION_PRO_VER' );

		// Detect GiveWP rows once at enqueue time so the JS doesn't have to
		// spin a loader (and won't ever flash an error) just to decide whether
		// to render the optional migration step.
		$has_givewp_data = false;
		try {
			$has_givewp_data = \SureDonation\Inc\Import\Givewp\Source::get_instance()->has_givewp_data();
		} catch ( \Throwable $e ) {
			$has_givewp_data = false;
		}

		wp_localize_script(
			'suredonation-onboarding',
			'suredonation_onboarding',
			[
				'apiUrl'        => rest_url( 'suredonation/v1' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'pluginUrl'     => SUREDONATION_URL,
				'adminUrl'      => admin_url(),
				'dashboardUrl'  => admin_url( 'admin.php?page=suredonation' ),
				'paymentsUrl'   => admin_url( 'admin.php?page=suredonation#/settings?tab=payments' ),
				'campaignsUrl'  => admin_url( 'admin.php?page=suredonation#/campaigns' ),
				'docsUrl'       => 'https://suredonation.com/docs/',
				'isProActive'   => $is_pro,
				'hasGiveWPData' => $has_givewp_data,
				'currentUser'   => [
					'firstName' => $current_user ? $current_user->first_name : '',
					'lastName'  => $current_user ? $current_user->last_name : '',
					'email'     => $current_user ? $current_user->user_email : '',
				],
				'privacyUrl'    => 'https://suredonation.com/privacy-policy/',
			]
		);

		// Shim suredonation_admin on the onboarding page too — shared API
		// helpers (e.g. importApi.js) read apiUrl + nonce from that global,
		// so without this the migration session fails the REST cookie check.
		wp_localize_script(
			'suredonation-onboarding',
			'suredonation_admin',
			[
				'apiUrl'        => rest_url( 'suredonation/v1' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'is_pro_active' => $is_pro,
			]
		);
	}
}
