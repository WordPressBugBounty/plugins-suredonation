<?php
/**
 * Offline Frontend - AJAX handler for offline donations
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Payments\Offline;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offline_Frontend class
 * Handles frontend offline donation processing
 *
 * @since 1.0.0
 */
class Offline_Frontend {
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// AJAX handlers for both logged in and non-logged in users.
		add_action( 'wp_ajax_suredonation_create_offline_donation', [ $this, 'create_offline_donation' ] );
		add_action( 'wp_ajax_nopriv_suredonation_create_offline_donation', [ $this, 'create_offline_donation' ] );
	}

	/**
	 * Create offline donation via AJAX.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function create_offline_donation() {
		// Throttle abuse on this public endpoint before doing any work.
		if ( ! Helper::check_rate_limit( 'offline_create_donation' ) ) {
			wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait a moment and try again.', 'suredonation' ) ], 429 );
		}

		check_ajax_referer( 'suredonation_donation_form', 'nonce' );

		// Reject bot submissions caught by the honeypot before processing.
		if ( Helper::is_honeypot_spam() ) {
			wp_send_json_error( [ 'message' => __( 'Your submission was flagged as spam. Please try again.', 'suredonation' ) ] );
		}

		// Verify offline donations are enabled.
		if ( ! Offline_Helper::is_offline_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'Offline donations are not enabled.', 'suredonation' ) ] );
		}

		// Extract and sanitize form data.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$is_standalone = isset( $_POST['is_standalone'] ) && '1' === $_POST['is_standalone'];
		$campaign_id   = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$amount        = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;
		$donor_email   = isset( $_POST['donor_email'] ) ? sanitize_email( wp_unslash( $_POST['donor_email'] ) ) : '';
		$donor_name    = isset( $_POST['donor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_name'] ) ) : '';
		$form_id       = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		// Derive the donor phone from the validated mapped field, not a separate
		// unvalidated $_POST['donor_phone'] (see Payment_Helper::get_mapped_donor_phone).
		$donor_phone = Payment_Helper::get_mapped_donor_phone( $form_id );
		$block_id    = isset( $_POST['block_id'] ) ? sanitize_text_field( wp_unslash( $_POST['block_id'] ) ) : '';
		// Display-only flag: the donor's real name/email/phone are still stored
		// below and only public surfaces mask them.
		$is_anonymous = Payment_Helper::get_submitted_is_anonymous( $form_id );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Standalone forms must not have a campaign.
		if ( $is_standalone ) {
			$campaign_id = 0;
		}

		// Validate required fields — campaign only required for non-standalone forms.
		if ( ! $is_standalone && empty( $campaign_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign.', 'suredonation' ) ] );
		}

		if ( $amount <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid donation amount.', 'suredonation' ) ] );
		}

		if ( empty( $donor_email ) ) {
			wp_send_json_error( [ 'message' => __( 'Email address is required.', 'suredonation' ) ] );
		}

		// Validate campaign only if not standalone.
		if ( ! $is_standalone ) {
			$campaign = get_post( $campaign_id );
			if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
				wp_send_json_error( [ 'message' => __( 'Invalid campaign.', 'suredonation' ) ] );
			}

			if ( 'publish' !== $campaign->post_status ) {
				wp_send_json_error( [ 'message' => __( 'This campaign is not available for donations.', 'suredonation' ) ] );
			}

			$campaign_status = Helper::get_campaign_meta_value( $campaign_id, 'campaign_status', 'active' );
			if ( 'paused' === $campaign_status || 'completed' === $campaign_status ) {
				wp_send_json_error( [ 'message' => __( 'This campaign is not currently accepting donations.', 'suredonation' ) ] );
			}
		}

		// Validate form_id and block_id are present for amount validation.
		if ( empty( $form_id ) || empty( $block_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid form configuration.', 'suredonation' ) ] );
		}

		// Validate field values + amount against block config (skip Stripe minimum for offline).
		$currency          = Payment_Helper::get_currency();
		$validation_result = Payment_Helper::validate_submission( Payment_Helper::get_submitted_fields(), $amount, $currency, $form_id, $block_id, 'offline' );
		if ( ! $validation_result['valid'] ) {
			wp_send_json_error(
				[
					'message'     => esc_html( $validation_result['message'] ),
					'fieldErrors' => $validation_result['field_errors'],
				]
			);
		}

		// Get or create donor.
		$donor_id = Donors::get_or_create( $donor_email, $donor_name, $donor_phone );

		// Create donation record.
		$donation_id = Donations::add(
			[
				'campaign_id'    => $campaign_id,
				'donor_id'       => $donor_id ? $donor_id : 0,
				'amount'         => $amount,
				'fees_covered'   => 0,
				'currency'       => $currency,
				'gateway'        => 'offline',
				'payment_status' => 'pending',
				'payment_mode'   => Payment_Helper::get_payment_mode(),
				'donor_name'     => $donor_name,
				'donor_email'    => $donor_email,
				'donor_phone'    => $donor_phone,
				'is_anonymous'   => $is_anonymous ? 1 : 0,
				'donation_type'  => 'one-time',
				'form_id'        => $form_id,
				'ip_address'     => Helper::get_client_ip(),
				'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'referer_url'    => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			]
		);

		if ( ! $donation_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to create donation record.', 'suredonation' ) ] );
		}

		// Persist the submitted field values for the entry record.
		Donations::set_submitted_fields( $donation_id, Payment_Helper::get_submitted_field_data() );

		// Add log entry.
		Donations::add_log(
			$donation_id,
			'created',
			__( 'Offline donation created — pending payment', 'suredonation' ),
			[
				'gateway' => 'offline',
			]
		);

		// Send donation processing email (offline donations are pending, not completed).
		Email_Handler::send_donation_processing(
			$donation_id,
			$campaign_id,
			[
				'donor_name'    => $donor_name,
				'donor_email'   => $donor_email,
				'amount'        => $amount,
				'currency'      => $currency,
				'gateway'       => 'offline',
				'donation_type' => 'one-time',
			],
			$form_id
		);

		wp_send_json_success(
			[
				'donationId' => $donation_id,
				'message'    => Helper::render_confirmation_message( $donation_id ),
			]
		);
	}
}
