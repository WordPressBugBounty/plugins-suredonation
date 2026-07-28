<?php
/**
 * Onboarding service.
 *
 * Persists onboarding completion state, captured user-details, and the
 * one-shot activation redirect that drops a freshly-activated admin onto
 * the guided setup screen.
 *
 * Backed by the consolidated `suredonation_options` row via Helper so we
 * don't create extra option rows for a single boolean.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc;

use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Onboarding class.
 *
 * @since 1.0.0
 */
class Onboarding {
	use Get_Instance;

	/**
	 * Key inside suredonation_options for the completion flag.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const STATUS_KEY = 'onboarding_completed';

	/**
	 * Key inside suredonation_options for cached user-details capture.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const USER_DETAILS_KEY = 'onboarding_user_details';

	/**
	 * Key inside suredonation_options recording when the onboarding lead was
	 * forwarded to the CRM. Stores a Unix timestamp; 0 means not yet sent.
	 *
	 * @since 1.1.2
	 * @var string
	 */
	private const LEAD_SENT_KEY = 'onboarding_lead_sent_at';

	/**
	 * Hidden admin page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PAGE_SLUG = 'suredonation-onboarding';

	/**
	 * Option key set during activation to trigger the one-shot redirect.
	 *
	 * Already declared by Plugin_Loader on activation; we just consume it
	 * here.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const REDIRECT_FLAG = '__suredonation_do_redirect';

	/**
	 * Wire up activation redirect.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_redirect_to_onboarding' ] );
	}

	/**
	 * Read completion state.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_completed() {
		return 'yes' === Helper::get_suredonation_option( self::STATUS_KEY, 'no' );
	}

	/**
	 * Persist completion state.
	 *
	 * @param string $value 'yes' or 'no'.
	 * @return void
	 * @since 1.0.0
	 */
	public function set_completed( $value = 'yes' ) {
		Helper::update_suredonation_option( self::STATUS_KEY, 'yes' === $value ? 'yes' : 'no' );
	}

	/**
	 * Read the cached lead-capture payload.
	 *
	 * @return array<string,mixed>
	 * @since 1.0.0
	 */
	public function get_user_details() {
		$details = Helper::get_suredonation_option( self::USER_DETAILS_KEY, [] );
		return is_array( $details ) ? $details : [];
	}

	/**
	 * Persist the lead-capture payload.
	 *
	 * @param array<string,mixed> $details Raw payload (already sanitised).
	 * @return void
	 * @since 1.0.0
	 */
	public function set_user_details( array $details ) {
		Helper::update_suredonation_option( self::USER_DETAILS_KEY, $details );
	}

	/**
	 * Whether the onboarding lead has already been forwarded to the CRM.
	 *
	 * @return bool
	 * @since 1.1.2
	 */
	public function is_lead_sent() {
		$sent_at = Helper::get_suredonation_option( self::LEAD_SENT_KEY, 0 );
		return is_numeric( $sent_at ) && (int) $sent_at > 0;
	}

	/**
	 * Record that the onboarding lead has been forwarded to the CRM.
	 *
	 * @return void
	 * @since 1.1.2
	 */
	public function mark_lead_sent() {
		Helper::update_suredonation_option( self::LEAD_SENT_KEY, time() );
	}

	/**
	 * On the first admin pageload after activation, send the admin to the
	 * onboarding screen — but only if onboarding isn't already done.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function maybe_redirect_to_onboarding() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// admin_init fires for REST and network-admin contexts too — neither
		// should ever 302 to the onboarding screen.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_network_admin() ) {
			return;
		}

		// Only honour the redirect on the initial GET that lands the admin
		// on a wp-admin page — a POST hitting admin_init would otherwise
		// 302 mid-form-submission.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a flag, not processing form input.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		if ( ! get_option( self::REDIRECT_FLAG ) ) {
			return;
		}

		// One-shot — drop the row entirely so the option table doesn't
		// accumulate dead flags. The activation hook will recreate it on
		// the next plugin activation.
		delete_option( self::REDIRECT_FLAG );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->is_completed() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
