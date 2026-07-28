<?php
/**
 * AJAX Donation Handler
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Ajax;

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
 * Donation_Handler class.
 *
 * @since 0.0.1
 */
class Donation_Handler {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'wp_ajax_suredonation_submit_donation', [ $this, 'handle_donation_submission' ] );
		add_action( 'wp_ajax_nopriv_suredonation_submit_donation', [ $this, 'handle_donation_submission' ] );
	}

	/**
	 * Handle donation form submission.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function handle_donation_submission() {
		// Throttle abuse on this public endpoint before doing any work.
		if ( ! Helper::check_rate_limit( 'submit_donation' ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait a moment and try again.', 'suredonation' ), 429 );
		}

		// First check if nonce exists before accessing any other POST data.
		if ( ! isset( $_POST['suredonation_nonce'] ) ) {
			wp_send_json_error( __( 'Security check failed', 'suredonation' ) );
		}

		// Sanitize nonce value.
		$nonce = sanitize_text_field( wp_unslash( $_POST['suredonation_nonce'] ) );

		// Now get values needed to determine nonce action.
		$is_standalone = isset( $_POST['is_standalone'] ) && '1' === $_POST['is_standalone'];
		$campaign_id   = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		// Standalone forms must not have a campaign — prevent bypass of campaign validation.
		if ( $is_standalone ) {
			$campaign_id = 0;
		}

		// Verify nonce - different nonce for standalone vs campaign-linked forms.
		$nonce_action = Helper::get_donation_nonce_action( $campaign_id );

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_send_json_error( __( 'Security check failed', 'suredonation' ) );
		}

		// Reject bot submissions caught by the honeypot before processing.
		if ( Helper::is_honeypot_spam() ) {
			wp_send_json_error( __( 'Your submission was flagged as spam. Please try again.', 'suredonation' ) );
		}

		// Validate campaign only if not standalone.
		$campaign = null;
		if ( ! $is_standalone ) {
			if ( ! $campaign_id ) {
				wp_send_json_error( __( 'Invalid campaign', 'suredonation' ) );
			}

			$campaign = get_post( $campaign_id );
			if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
				wp_send_json_error( __( 'Invalid campaign', 'suredonation' ) );
			}
		}

		// Get form data.
		$amount        = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
		$cover_fees    = isset( $_POST['cover_fees'] ) && 'true' === $_POST['cover_fees'];
		$is_anonymous  = isset( $_POST['is_anonymous'] ) ? true : false;
		$donor_name    = $is_anonymous ? __( 'Anonymous', 'suredonation' ) : sanitize_text_field( wp_unslash( $_POST['donor_name'] ?? '' ) );
		$donor_email   = sanitize_email( wp_unslash( $_POST['donor_email'] ?? '' ) );
		$donor_comment = sanitize_textarea_field( wp_unslash( $_POST['donor_comment'] ?? '' ) );

		// Get form_id and block_id for amount validation.
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		// Derive the donor phone from the validated mapped field, not a separate
		// unvalidated $_POST['donor_phone'] (see Payment_Helper::get_mapped_donor_phone).
		$donor_phone = Payment_Helper::get_mapped_donor_phone( $form_id );
		$block_id    = isset( $_POST['block_id'] ) ? sanitize_text_field( wp_unslash( $_POST['block_id'] ) ) : '';

		// Validate required fields.
		if ( $amount <= 0 ) {
			wp_send_json_error( __( 'Invalid donation amount', 'suredonation' ) );
		}

		// Require form_id and block_id for amount validation — reject if missing to prevent bypass.
		if ( empty( $form_id ) || empty( $block_id ) ) {
			wp_send_json_error( __( 'Invalid form configuration.', 'suredonation' ) );
		}

		// Validate field values + amount against block configuration. Pass the
		// offline gateway so the Stripe-only minimum floor is not applied here.
		$currency          = Payment_Helper::get_currency();
		$validation_result = Payment_Helper::validate_submission( Payment_Helper::get_submitted_fields(), $amount, $currency, $form_id, $block_id, 'offline' );
		if ( ! $validation_result['valid'] ) {
			wp_send_json_error( esc_html( $validation_result['message'] ) );
		}

		if ( ! $is_anonymous ) {
			if ( empty( $donor_name ) ) {
				wp_send_json_error( __( 'Donor name is required', 'suredonation' ) );
			}
			if ( empty( $donor_email ) || ! is_email( $donor_email ) ) {
				wp_send_json_error( __( 'Valid email address is required', 'suredonation' ) );
			}
		}

		// Server-side fee calculation — ignore client-supplied base_amount to prevent manipulation.
		$base_amount  = $amount;
		$fees_covered = 0;

		if ( $cover_fees && $base_amount > 0 ) {
			$fee_config = Payment_Helper::get_cover_fees_config( $form_id, 'offline' );

			if ( ! $fee_config['enabled'] ) {
				$cover_fees = false;
			}

			if ( $cover_fees ) {
				$fees_covered = Payment_Helper::calculate_fee( $base_amount, $fee_config['fee_percentage'], $fee_config['fee_fixed'] );
			} else {
				$fees_covered = 0;
			}
		}

		// Get or create donor.
		$donor_id = 0;
		if ( ! empty( $donor_email ) ) {
			$donor_id = Donors::get_or_create( $donor_email, $donor_name, $donor_phone );
		}

		// Get payment mode.
		$payment_mode = 'live';
		if ( class_exists( 'SureDonation\Inc\Payments\Payment_Helper' ) ) {
			$payment_mode = Payment_Helper::get_payment_mode();
		}

		// Create donation in database.
		$donation_id = Donations::add(
			[
				'campaign_id'    => $campaign_id,
				'donor_id'       => $donor_id ? $donor_id : 0,
				'amount'         => number_format( $base_amount, 2, '.', '' ),
				'fees_covered'   => number_format( $fees_covered, 2, '.', '' ),
				'currency'       => Payment_Helper::get_currency(),
				'gateway'        => 'manual',
				'payment_status' => 'pending',
				'payment_mode'   => $payment_mode,
				'donor_name'     => $donor_name,
				'donor_email'    => $donor_email,
				'donor_phone'    => $donor_phone,
				'is_anonymous'   => $is_anonymous ? 1 : 0,
				'donation_type'  => 'one-time',
				'donor_comment'  => $donor_comment,
				'form_id'        => $form_id,
				'ip_address'     => Helper::get_client_ip(),
				'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'referer_url'    => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			]
		);

		if ( ! $donation_id ) {
			wp_send_json_error( __( 'Failed to create donation', 'suredonation' ) );
		}

		// Persist the submitted field values for the entry record.
		Donations::set_submitted_fields( $donation_id, Payment_Helper::get_submitted_field_data() );

		// Note: Donation status will be updated by payment gateway webhooks or manual confirmation.

		// This donation is created as pending/manual, so send the "processing"
		// (donation received) email rather than the completed-confirmation
		// email. The confirmation email is reserved for when payment is
		// actually confirmed, matching the gateway flows.
		$donation_data = [
			'id'            => $donation_id,
			'donor_name'    => $donor_name,
			'donor_email'   => $donor_email,
			'amount'        => $base_amount,
			'fees_covered'  => $fees_covered,
			'currency'      => Payment_Helper::get_currency(),
			'gateway'       => 'manual',
			'donation_type' => 'one-time',
		];

		Email_Handler::send_donation_processing( $donation_id, $campaign_id, $donation_data, $form_id );

		// Build the confirmation/thank-you HTML from the form's confirmation message.
		$confirmation_html = Helper::render_confirmation_message( $donation_id );
		if ( '' === $confirmation_html ) {
			$confirmation_html = esc_html__( 'Your generous contribution will make a real difference. A confirmation email has been sent to you.', 'suredonation' );
		}

		// Send success response.
		wp_send_json_success(
			[
				'donation_id' => $donation_id,
				'message'     => $confirmation_html,
			]
		);
	}
}
