<?php
/**
 * Admin Notices.
 *
 * Registers SureDonation's engagement admin notices:
 *
 *  - Review notice (donation)  : shown once the site has at least one live
 *                                donation, after the 3-day install grace.
 *  - Test mode notice          : shown when a payment gateway is connected but
 *                                the site is still in test mode, nudging the
 *                                admin to switch to live mode.
 *  - Review notice (gateway)   : shown when a payment gateway is connected in
 *                                live mode but no live donation exists yet,
 *                                after the 3-day install grace.
 *  - Setup gateway notice      : shown instantly (no grace) when no payment
 *                                gateway is connected at all.
 *
 * The four notices are mutually exclusive by construction. Priority is:
 * live donation > gateway configured (test mode) > gateway configured (live
 * mode) > no gateway.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
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
		add_action( 'admin_notices', [ $this, 'display_test_mode_notice' ] );
		add_action( 'admin_notices', [ $this, 'display_review_notice_gateway' ] );
		add_action( 'admin_notices', [ $this, 'display_setup_gateway_notice' ] );
		add_action( 'admin_notices', [ $this, 'display_webhook_notice' ] );

		// Load the banner-notice styles from the admin <head> (not the late
		// after-markup hook) so the banner never renders unstyled first.
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_banner_notice_style' ] );

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
				// Test-mode takes priority: while the site is in test mode the
				// "switch to live" warning is more useful than a review ask.
				'show_if'                    => $this->is_three_days_elapsed() && $this->has_live_donation() && ! $this->should_show_test_mode_notice(),
				'display-with-other-notices' => true,
			]
		);

		add_action( 'astra_notice_after_markup_sd-review-donation', [ $this, 'enqueue_notice_response_script' ] );
	}

	/**
	 * Test-mode notice shown when a gateway is connected but the site is still
	 * in test mode (notice A2).
	 *
	 * Nudges the admin to switch to live mode so real donations can be
	 * accepted. Takes priority over the gateway review notice: there is no
	 * point asking for a review while the site cannot yet take real money.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function display_test_mode_notice() {
		if ( ! Helper::current_user_can() ) {
			return;
		}

		if ( ! apply_filters( 'suredonation_show_test_mode_notice', true ) ) {
			return;
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			return;
		}

		\BSF_Admin_Notices::add_notice(
			[
				'id'                         => 'sd-test-mode',
				'type'                       => '',
				'message'                    => $this->build_test_mode_notice_markup(),
				'repeat-notice-after'        => WEEK_IN_SECONDS,
				'show_if'                    => $this->should_show_test_mode_notice() && ! $this->should_show_webhook_notice(),
				'display-with-other-notices' => true,
			]
		);

		add_action( 'astra_notice_after_markup_sd-test-mode', [ $this, 'enqueue_notice_response_script' ] );
	}

	/**
	 * Stripe webhook-not-configured notice.
	 *
	 * Shown on wp-admin pages when Stripe is connected but its webhook is not
	 * configured for the current mode, so donation/subscription statuses may not
	 * sync. Mirrors the SureForms webhook notice: a standard dismissible core
	 * notice (dismissal is per-page-load and reappears until the webhook is set).
	 * Takes priority over the test-mode banner, which is suppressed while this
	 * shows (matching the React dashboard notice chain).
	 *
	 * Hooked - admin_notices
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function display_webhook_notice() {
		if ( ! Helper::current_user_can() ) {
			return;
		}

		if ( ! $this->should_show_webhook_notice() ) {
			return;
		}

		// Load the analytics tracker so the configure-click and dismiss are
		// recorded, matching the other notices (see handle_notice_response()).
		$this->enqueue_notice_response_script();
		?>
		<div id="sd-webhook-not-configured" class="notice notice-error is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %1$s: link to configure the Stripe webhook */
					esc_html__( 'Webhooks keep SureDonation in sync with Stripe by automatically updating donation and subscription data. Please %1$s the webhook.', 'suredonation' ),
					sprintf(
						'<a class="sd-notice-cta" href="%1$s">%2$s</a>',
						esc_url( Payment_Helper::get_settings_url( 'stripe' ) ),
						esc_html__( 'configure', 'suredonation' )
					)
				);
				?>
			</p>
		</div>
		<?php
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
				// In test mode the test-mode notice takes this slot instead,
				// keeping the notice chain mutually exclusive.
				'show_if'                    => $this->is_three_days_elapsed() && ! $this->has_live_donation() && $this->is_gateway_configured() && ! $this->should_show_test_mode_notice(),
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
				'show_if'                    => $this->should_show_setup_gateway_notice(),
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
			'sd-review-donation'        => [
				'rate_suredonation' => 'review_notice_donation_cta',
				'maybe_later'       => 'review_notice_donation_snooze',
				'dismissed'         => 'review_notice_donation_dismiss',
			],
			'sd-test-mode'              => [
				'switch_to_live' => 'test_mode_notice_cta',
				'maybe_later'    => 'test_mode_notice_snooze',
				'dismissed'      => 'test_mode_notice_dismiss',
			],
			'sd-review-gateway'         => [
				'rate_suredonation' => 'review_notice_gateway_cta',
				'maybe_later'       => 'review_notice_gateway_snooze',
				'dismissed'         => 'review_notice_gateway_dismiss',
			],
			'sd-setup-gateway'          => [
				'configure_gateway' => 'setup_gateway_notice_cta',
				'maybe_later'       => 'setup_gateway_notice_snooze',
				'dismissed'         => 'setup_gateway_notice_dismiss',
			],
			'sd-webhook-not-configured' => [
				'configure_webhook' => 'webhook_notice_cta',
				'dismissed'         => 'webhook_notice_dismiss',
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
	 * @return string The notice HTML markup.
	 * @since 1.2.0
	 */
	private function build_setup_notice_markup() {
		return $this->build_banner_notice_markup(
			esc_html__( 'Your donation site is almost ready!', 'suredonation' ),
			esc_html__( 'Connect a payment gateway to start accepting donations. Set up Stripe or PayPal in just a few clicks to go live.', 'suredonation' ),
			Payment_Helper::get_settings_url( 'stripe' ),
			esc_html__( 'Configure Payment Gateway', 'suredonation' ),
			SUREDONATION_URL . 'images/payment-gateway-notice.png'
		);
	}

	/**
	 * Build the markup for the "switch to live mode" test-mode notice
	 * (notice A2).
	 *
	 * @return string The notice HTML markup.
	 * @since 1.3.0
	 */
	private function build_test_mode_notice_markup() {
		return $this->build_banner_notice_markup(
			esc_html__( 'SureDonation is in test mode', 'suredonation' ),
			esc_html__( 'No real payments are being accepted right now. Switch to live mode to start collecting real donations.', 'suredonation' ),
			Payment_Helper::get_settings_url(),
			esc_html__( 'Switch to Live Mode', 'suredonation' )
		);
	}

	/**
	 * Build the shared banner-notice markup (accent bar, icon, heading, body and
	 * a primary CTA, with an optional right-side illustration).
	 *
	 * Shared by the setup-gateway and test-mode notices; each is scoped by its
	 * wrapper id (#sd-setup-gateway / #sd-test-mode) in setup-gateway-notice.css
	 * so they can carry different accent colors from the same template.
	 *
	 * The text parameters must be pre-escaped by the caller (e.g. via
	 * esc_html__()); the URL and art path are escaped here.
	 *
	 * @param string $title    The notice heading (pre-escaped).
	 * @param string $text     The notice body text (pre-escaped).
	 * @param string $cta_url  The primary CTA URL (raw; escaped here).
	 * @param string $cta_text The primary CTA button text (pre-escaped).
	 * @param string $art      Optional right-side illustration URL (raw; escaped
	 *                         here). When empty, the banner drops the reserved
	 *                         art space via the --no-art modifier.
	 * @return string The notice HTML markup.
	 * @since 1.3.0
	 */
	private function build_banner_notice_markup( $title, $text, $cta_url, $cta_text, $art = '' ) {
		$has_art      = '' !== $art;
		$notice_class = $has_art ? 'sd-setup-notice' : 'sd-setup-notice sd-setup-notice--no-art';
		$art_markup   = $has_art
			? sprintf( '<img class="sd-setup-notice__art" src="%s" alt="" width="187" height="128" />', esc_url( $art ) )
			: '';

		return sprintf(
			'<div class="%1$s">
				<div class="sd-setup-notice__main">
					<img class="sd-setup-notice__icon" src="%2$s" alt="" width="28" height="28" />
					<div class="sd-setup-notice__body">
						<h2 class="sd-setup-notice__title">%3$s</h2>
						<p class="sd-setup-notice__text">%4$s</p>
						<a href="%5$s" class="button button-primary sd-setup-notice__button">%6$s</a>
					</div>
				</div>
				%7$s
			</div>',
			esc_attr( $notice_class ),
			esc_url( SUREDONATION_URL . 'images/suredonation-icon.svg' ),
			$title,
			$text,
			esc_url( $cta_url ),
			$cta_text,
			$art_markup
		);
	}

	/**
	 * Enqueue the banner-notice stylesheet.
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
	 * Enqueue the banner-notice stylesheet from the admin <head> when either
	 * banner notice (setup-gateway or test-mode) is eligible to show.
	 *
	 * Hooked on admin_enqueue_scripts (which runs before admin_head) and gated
	 * by the same conditions as the notices themselves, so the stylesheet is in
	 * the page head before the banner paints. This avoids the flash of unstyled
	 * content that occurred when the CSS was enqueued on the notice's
	 * after-markup hook (which fires at admin_notices priority 30, after styles
	 * have already been printed).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function maybe_enqueue_banner_notice_style() {
		if ( ! Helper::current_user_can() ) {
			return;
		}

		$setup_eligible = apply_filters( 'suredonation_show_setup_gateway_notice', true ) && $this->should_show_setup_gateway_notice();
		$test_eligible  = apply_filters( 'suredonation_show_test_mode_notice', true ) && $this->should_show_test_mode_notice();

		if ( ! $setup_eligible && ! $test_eligible ) {
			return;
		}

		$this->enqueue_setup_notice_style();
	}

	/**
	 * Whether the test-mode notice is eligible to show: a gateway is connected
	 * (in any mode) but the site is currently running in test mode. This fires
	 * regardless of past donations — a site switched back to test mode still
	 * needs the "switch to live" nudge — and takes priority over the review
	 * notices, which are suppressed while it is showing.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	private function should_show_test_mode_notice() {
		return $this->is_gateway_configured()
			&& 'test' === Payment_Helper::get_payment_mode();
	}

	/**
	 * Whether the webhook-not-configured notice is eligible to show: Stripe is
	 * connected but its webhook is not configured for the current mode.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	private function should_show_webhook_notice() {
		return Stripe_Helper::is_stripe_connected()
			&& ! Stripe_Helper::is_webhook_configured();
	}

	/**
	 * Whether the setup-gateway notice is eligible to show: no gateway is
	 * connected and no live donation has been recorded.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	private function should_show_setup_gateway_notice() {
		return ! $this->has_live_donation() && ! $this->is_gateway_configured();
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
			// "Configured" means connected in any mode; delegated to the shared
			// Payment_Helper check (memoized here so repeated notice-chain reads
			// only resolve it once per request).
			$this->gateway_configured = Payment_Helper::is_any_gateway_connected();
		}

		return $this->gateway_configured;
	}
}
