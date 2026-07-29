<?php
/**
 * Admin-bar payment-mode indicator.
 *
 * Adds a wp-admin toolbar item (front-end and admin) that surfaces the current
 * payment mode to admins once a gateway is connected: an amber "Test Mode"
 * badge while the site is in test mode, or a green "Live" badge otherwise. It
 * links to the payment settings so admins can switch modes in one click, and
 * makes it obvious — while browsing the live site — that no real payments are
 * being accepted. Modeled on the toolbar indicators in GiveWP and Charitable.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Payments\Payment_Helper;
use WP_Admin_Bar;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin_Bar class.
 *
 * @since 1.3.0
 */
class Admin_Bar {

	/**
	 * Node id for the payment-mode toolbar item.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	private const NODE_ID = 'suredonation-payment-mode';

	/**
	 * Instance of this class.
	 *
	 * @var Admin_Bar|null
	 * @since 1.3.0
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	private function __construct() {
		// Priority 100 so the badge is added after the core toolbar items.
		add_action( 'admin_bar_menu', [ $this, 'add_mode_indicator' ], 100 );
		// The toolbar shows on the front-end too, so register the styles on both
		// contexts.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_style' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_style' ] );
	}

	/**
	 * Get instance of this class.
	 *
	 * @return Admin_Bar
	 * @since 1.3.0
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Add the payment-mode indicator node to the admin bar.
	 *
	 * Only rendered for admins, and only once a gateway is connected — there is
	 * nothing to indicate before a gateway exists (the setup notice covers that
	 * state instead).
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 * @since 1.3.0
	 */
	public function add_mode_indicator( $wp_admin_bar ) {
		if ( ! $this->should_display() ) {
			return;
		}

		$is_test = 'test' === Payment_Helper::get_payment_mode();
		$variant = $is_test ? 'test' : 'live';
		$label   = $is_test
			? __( 'SureDonation: Test Mode', 'suredonation' )
			: __( 'SureDonation: Live', 'suredonation' );
		$tooltip = $is_test
			? __( 'SureDonation is in test mode — no real payments are being accepted. Click to switch to live mode.', 'suredonation' )
			: __( 'SureDonation is in live mode — real payments are being accepted.', 'suredonation' );

		$title = sprintf(
			'<span class="sd-admin-bar-mode sd-admin-bar-mode--%1$s"><span class="sd-admin-bar-mode__dot" aria-hidden="true"></span>%2$s</span>',
			esc_attr( $variant ),
			esc_html( $label )
		);

		$wp_admin_bar->add_node(
			[
				'id'     => self::NODE_ID,
				// Anchor to the right-hand group so the mode reads as a status
				// indicator rather than a navigation item.
				'parent' => 'top-secondary',
				'title'  => $title,
				'href'   => Payment_Helper::get_settings_url(),
				'meta'   => [ 'title' => $tooltip ],
			]
		);
	}

	/**
	 * Register the inline toolbar styles when the indicator will show.
	 *
	 * Attached to the always-registered core `admin-bar` stylesheet so the
	 * badge is styled in both the admin and the front-end toolbar.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function enqueue_style() {
		if ( ! is_admin_bar_showing() || ! $this->should_display() ) {
			return;
		}

		$css = '#wpadminbar #wp-admin-bar-' . self::NODE_ID . ' .ab-item{display:flex;align-items:center;}'
			. '#wpadminbar .sd-admin-bar-mode__dot{display:inline-block;width:8px;height:8px;margin-right:7px;border-radius:50%;background:currentColor;}'
			. '#wpadminbar .sd-admin-bar-mode--test{color:#f0c33c;}'
			. '#wpadminbar .sd-admin-bar-mode--live{color:#5fd07a;}';

		wp_add_inline_style( 'admin-bar', $css );
	}

	/**
	 * Whether the payment-mode indicator should be shown to the current user.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	private function should_display() {
		return current_user_can( 'manage_options' ) && Payment_Helper::is_any_gateway_connected();
	}
}
