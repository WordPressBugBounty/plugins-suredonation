<?php
/**
 * Admin Notices.
 *
 * Registers SureDonation's engagement admin notices:
 *
 *  - Review notice (donation)  : shown once the site has at least one live
 *                                donation, after the 3-day install grace.
 *  - Review notice (gateway)   : shown when a payment gateway is connected but
 *                                no live donation exists yet, after the 3-day
 *                                install grace.
 *  - Setup gateway notice      : shown instantly (no grace) when no payment
 *                                gateway is connected at all.
 *
 * The three notices are mutually exclusive by construction. Priority is:
 * live donation > gateway configured > no gateway.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\PayPal\PayPal_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Notices class.
 *
 * @since 1.2.0
 */
class Notices {

	/**
	 * Install-grace period, in seconds, before the review notices may appear.
	 *
	 * @var int
	 * @since 1.2.0
	 */
	public const REVIEW_NOTICE_DELAY = 3 * DAY_IN_SECONDS;

	/**
	 * WordPress.org review URL for the CTA.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/suredonation/reviews/#new-post';

	/**
	 * Instance of this class.
	 *
	 * @var Notices|null
	 * @since 1.2.0
	 */
	private static $instance = null;

	/**
	 * Memoized "has at least one live donation" result.
	 *
	 * @var bool|null
	 * @since 1.2.0
	 */
	private $has_live_donation = null;

	/**
	 * Memoized "a payment gateway is configured" result.
	 *
	 * @var bool|null
	 * @since 1.2.0
	 */
	private $gateway_configured = null;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	private function __construct() {
		// Ensure the notices library (and its priority-30 renderer) is loaded
		// early, before the admin_notices hook fires.
		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			require_once SUREDONATION_DIR . 'inc/lib/astra-notices/class-bsf-admin-notices.php';
		}

		add_action( 'admin_notices', [ $this, 'display_review_notice_donation' ] );
		add_action( 'admin_notices', [ $this, 'display_review_notice_gateway' ] );
		add_action( 'admin_notices', [ $this, 'display_setup_gateway_notice' ] );

		// Load the setup-notice styles from the admin <head> (not the late
		// after-markup hook) so the banner never renders unstyled first.
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_setup_notice_style' ] );

		add_action( 'wp_ajax_suredonation_notice_response', [ $this, 'handle_notice_response' ] );
	}

	/**
	 * Get instance of this class.
	 *
	 * @return Notices
	 * @since 1.2.0
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Review notice shown after the first live donation (notice A).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function display_review_notice_donation() {
		if ( ! Helper::current_user_can() ) {
			return;
		}

		if ( ! apply_filters( 'suredonation_show_review_notice_donation', true ) ) {
			return;
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			return;
		}

		\BSF_Admin_Notices::add_notice(
			[
				'id'                         => 'sd-review-donation',
				'type'                       => '',
				'message'                    => $this->build_notice_markup(
					esc_html__( 'You received your first donation with SureDonation!', 'suredonation' ),
					esc_html__( 'That is a big milestone. If SureDonation is helping power your cause, would you take a moment to leave a 5-star review on WordPress.org? It really helps.', 'suredonation' ),
					esc_url( self::REVIEW_URL ),
					esc_html__( 'Rate SureDonation', 'suredonation' ),
					esc_html__( 'Maybe later', 'suredonation' ),
					esc_html__( 'I already did', 'suredonation' ),
					WEEK_IN_SECONDS,
					true
				),
				'repeat-notice-after'        => WEEK_IN_SECONDS,
				'show_if'                    => $this->is_three_days_elapsed() && $this->has_live_donation(),
				'display-with-other-notices' => true,
			]
		);

		add_action( 'astra_notice_after_markup_sd-review-donation', [ $this, 'enqueue_notice_response_script' ] );
	}

	/**
	 * Review notice shown when a gateway is configured but there are no live
	 * donations yet (notice B).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function display_review_notice_gateway() {
		if ( ! Helper::current_user_can() ) {
			return;
		}

		if ( ! apply_filters( 'suredonation_show_review_notice_gateway', true ) ) {
			return;
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			return;
		}

		\BSF_Admin_Notices::add_notice(
			[
				'id'                         => 'sd-review-gateway',
				'type'                       => '',
				'message'                    => $this->build_notice_markup(
					esc_html__( 'Your payment gateway is all set up!', 'suredonation' ),
					esc_html__( 'You have connected a payment gateway and SureDonation is ready to start raising funds. If you are enjoying it so far, a quick 5-star review on WordPress.org would mean a lot.', 'suredonation' ),
					esc_url( self::REVIEW_URL ),
					esc_html__( 'Rate SureDonation', 'suredonation' ),
					esc_html__( 'Maybe later', 'suredonation' ),
					esc_html__( 'I already did', 'suredonation' ),
					WEEK_IN_SECONDS,
					true
				),
				'repeat-notice-after'        => WEEK_IN_SECONDS,
				'show_if'                    => $this->is_three_days_elapsed() && ! $this->has_live_donation() && $this->is_gateway_configured(),
				'display-with-other-notices' => true,
			]
		);

		add_action( 'astra_notice_after_markup_sd-review-gateway', [ $this, 'enqueue_notice_response_script' ] );
	}

	/**
	 * Setup notice shown instantly when no payment gateway is connected
	 * (notice C). No install-grace applies to this notice.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function display_setup_gateway_notice() {
		if ( ! Helper::current_user_can() ) {
			return;
		}

		if ( ! apply_filters( 'suredonation_show_setup_gateway_notice', true ) ) {
			return;
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			return;
		}

		\BSF_Admin_Notices::add_notice(
			[
				'id'                         => 'sd-setup-gateway',
				'type'                       => '',
				'message'                    => $this->build_setup_notice_markup(),
				'repeat-notice-after'        => WEEK_IN_SECONDS,
				'show_if'                    => ! $this->has_live_donation() && ! $this->is_gateway_configured(),
				'display-with-other-notices' => true,
			]
		);

		add_action( 'astra_notice_after_markup_sd-setup-gateway', [ $this, 'enqueue_notice_response_script' ] );
	}

	/**
	 * Enqueue the notice-response analytics script.
	 *
	 * Called via the astra_notice_after_markup_{id} hook so the script only
	 * loads when a SureDonation notice is actually rendered.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function enqueue_notice_response_script() {
		if ( wp_script_is( 'suredonation-notice-response', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'suredonation-notice-response',
			SUREDONATION_URL . 'assets/js/notice-response.js',
			[],
			SUREDONATION_VER,
			true
		);

		wp_localize_script(
			'suredonation-notice-response',
			'suredonationNoticeResponse',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'suredonation_notice_response' ),
			]
		);
	}

	/**
	 * Handle the notice-response AJAX request.
	 *
	 * Validates the request and records the analytics event for the notice
	 * button that was clicked.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function handle_notice_response() {
		if ( ! check_ajax_referer( 'suredonation_notice_response', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'suredonation' ) ], 403 );
		}

		if ( ! Helper::current_user_can() ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'suredonation' ) ], 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_id'] ) ) : '';
		$button    = isset( $_POST['button'] ) ? sanitize_text_field( wp_unslash( $_POST['button'] ) ) : '';

		$valid = [
			'sd-review-donation' => [
				'rate_suredonation' => 'review_notice_donation_cta',
				'maybe_later'       => 'review_notice_donation_snooze',
				'dismissed'         => 'review_notice_donation_dismiss',
			],
			'sd-review-gateway'  => [
				'rate_suredonation' => 'review_notice_gateway_cta',
				'maybe_later'       => 'review_notice_gateway_snooze',
				'dismissed'         => 'review_notice_gateway_dismiss',
			],
			'sd-setup-gateway'   => [
				'configure_gateway' => 'setup_gateway_notice_cta',
				'maybe_later'       => 'setup_gateway_notice_snooze',
				'dismissed'         => 'setup_gateway_notice_dismiss',
			],
		];

		if ( ! isset( $valid[ $notice_id ][ $button ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'suredonation' ) ], 400 );
		}

		$event_name = $valid[ $notice_id ][ $button ];

		$events = Analytics::events();
		if ( null !== $events ) {
			$events->track( $event_name, $button );
		}

		wp_send_json_success();
	}

	/**
	 * Build the shared HTML markup for admin notices.
	 *
	 * All text parameters must be pre-escaped by the caller (e.g. via
	 * esc_html__()). URL parameters must be pre-escaped via esc_url().
	 *
	 * @param string $heading         The notice heading text (pre-escaped).
	 * @param string $message         The notice body text (pre-escaped).
	 * @param string $cta_url         The primary CTA URL (pre-escaped).
	 * @param string $cta_text        The primary CTA button text (pre-escaped).
	 * @param string $snooze_text     The snooze button text (pre-escaped).
	 * @param string $dismiss_text    The dismiss button text (pre-escaped).
	 * @param int    $snooze_duration Snooze duration in seconds for the data-repeat-notice-after attribute.
	 * @param bool   $external_cta    Whether the CTA opens in a new tab and also dismisses the notice
	 *                                via the astra-notice-close class. Default false.
	 * @return string The notice HTML markup.
	 * @since 1.2.0
	 */
	private function build_notice_markup( $heading, $message, $cta_url, $cta_text, $snooze_text, $dismiss_text, $snooze_duration, $external_cta = false ) {
		$image_path = esc_url( SUREDONATION_URL . 'images/suredonation-icon.svg' );
		$cta_class  = $external_cta ? 'astra-notice-close button-primary' : 'button-primary';
		$cta_attrs  = $external_cta ? ' target="_blank" rel="noopener noreferrer"' : '';

		return sprintf(
			'<div class="notice-image">
				<img src="%1$s" class="custom-logo" alt="SureDonation" width="64" height="64" itemprop="logo">
			</div>
			<div class="notice-content">
				<div class="notice-heading">
					%2$s
				</div>
				%3$s<br />
				<div class="astra-review-notice-container">
					<a href="%4$s" class="%5$s"%6$s>
					%7$s
					</a>
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<a href="#" data-repeat-notice-after="%8$s" class="astra-notice-close">
					%9$s
					</a>
				<span class="dashicons dashicons-smiley" aria-hidden="true"></span>
					<a href="#" class="astra-notice-close">
					%10$s
					</a>
				</div>
			</div>',
			$image_path,
			$heading,
			$message,
			$cta_url,
			esc_attr( $cta_class ),
			$cta_attrs,
			$cta_text,
			$snooze_duration,
			$snooze_text,
			$dismiss_text
		);
	}

	/**
	 * Build the markup for the "configure a payment gateway" setup notice
	 * (notice C).
	 *
	 * This notice uses a dedicated banner layout (accent bar, icon, heading,
	 * body, primary CTA and a right-side illustration) styled via
	 * setup-gateway-notice.css, rather than the shared review-notice markup.
	 *
	 * @return string The notice HTML markup.
	 * @since 1.2.0
	 */
	private function build_setup_notice_markup() {
		return sprintf(
			'<div class="sd-setup-notice">
				<div class="sd-setup-notice__main">
					<img class="sd-setup-notice__icon" src="%5$s" alt="" width="28" height="28" />
					<div class="sd-setup-notice__body">
						<h2 class="sd-setup-notice__title">%1$s</h2>
						<p class="sd-setup-notice__text">%2$s</p>
						<a href="%3$s" class="button button-primary sd-setup-notice__button">%4$s</a>
					</div>
				</div>
				<img class="sd-setup-notice__art" src="%6$s" alt="" width="187" height="128" />
			</div>',
			esc_html__( 'Your donation site is almost ready!', 'suredonation' ),
			esc_html__( 'Connect a payment gateway to start accepting donations. Set up Stripe or PayPal in just a few clicks to go live.', 'suredonation' ),
			esc_url( admin_url( 'admin.php?page=suredonation#/settings?tab=payments&subpage=stripe' ) ),
			esc_html__( 'Configure Payment Gateway', 'suredonation' ),
			esc_url( SUREDONATION_URL . 'images/suredonation-icon.svg' ),
			esc_url( SUREDONATION_URL . 'images/payment-gateway-notice.png' )
		);
	}

	/**
	 * Enqueue the setup-notice stylesheet.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function enqueue_setup_notice_style() {
		if ( wp_style_is( 'suredonation-setup-notice', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'suredonation-setup-notice',
			SUREDONATION_URL . 'assets/css/setup-gateway-notice.css',
			[],
			SUREDONATION_VER
		);
	}

	/**
	 * Enqueue the setup-notice stylesheet from the admin <head> when the setup
	 * notice is eligible to show.
	 *
	 * Hooked on admin_enqueue_scripts (which runs before admin_head) and gated
	 * by the same conditions as the notice itself, so the stylesheet is in the
	 * page head before the banner paints. This avoids the flash of unstyled
	 * content that occurred when the CSS was enqueued on the notice's
	 * after-markup hook (which fires at admin_notices priority 30, after styles
	 * have already been printed).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function maybe_enqueue_setup_notice_style() {
		if ( ! Helper::current_user_can() ) {
			return;
		}

		if ( ! apply_filters( 'suredonation_show_setup_gateway_notice', true ) ) {
			return;
		}

		if ( $this->has_live_donation() || $this->is_gateway_configured() ) {
			return;
		}

		$this->enqueue_setup_notice_style();
	}

	/**
	 * Whether the 3-day install grace has elapsed.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function is_three_days_elapsed() {
		return ( time() - $this->get_install_time() ) >= self::REVIEW_NOTICE_DELAY;
	}

	/**
	 * Get (creating if missing) the plugin install timestamp.
	 *
	 * The activation hook seeds this on fresh installs; this getter back-fills
	 * it for sites that were already active before the option existed, so their
	 * grace period starts from the first admin pageload after the update.
	 *
	 * @return int Unix timestamp.
	 * @since 1.2.0
	 */
	private function get_install_time() {
		$install_time = Helper::get_integer_value( get_option( 'suredonation_install_time', 0 ) );

		if ( ! $install_time ) {
			$install_time = time();
			update_option( 'suredonation_install_time', $install_time );
		}

		return $install_time;
	}

	/**
	 * Whether the site has at least one completed, live-mode donation.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function has_live_donation() {
		if ( null === $this->has_live_donation ) {
			// Persist a monotonic flag: once the site has recorded a completed
			// live donation it stays "true" for this notice's purpose, so we
			// stop running COUNT(*) on every admin pageload once it is set.
			if ( get_option( 'suredonation_has_live_donation' ) ) {
				$this->has_live_donation = true;
			} else {
				$this->has_live_donation = Donations::count_live_completed() >= 1;

				if ( $this->has_live_donation ) {
					update_option( 'suredonation_has_live_donation', 1, false );
				}
			}
		}

		return $this->has_live_donation;
	}

	/**
	 * Whether a payment gateway (Stripe or PayPal) is connected, in any mode.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function is_gateway_configured() {
		if ( null === $this->gateway_configured ) {
			// "Configured" means connected in any mode. Stripe's check is
			// mode-agnostic; PayPal's is per-mode, so check both explicitly.
			$this->gateway_configured = Stripe_Helper::is_stripe_connected()
				|| PayPal_Helper::is_paypal_connected( 'live' )
				|| PayPal_Helper::is_paypal_connected( 'test' );
		}

		return $this->gateway_configured;
	}
}
