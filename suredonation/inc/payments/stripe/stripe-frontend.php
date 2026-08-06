<?php
/**
 * Stripe Frontend - Frontend payment processing
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Payments\Stripe;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;

/**
 * Stripe_Frontend class
 * Handles frontend Stripe payment processing
 *
 * @since 0.0.1
 */
class Stripe_Frontend {
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		// AJAX handlers for both logged in and non-logged in users.
		add_action( 'wp_ajax_suredonation_create_payment_intent', [ $this, 'create_payment_intent' ] );
		add_action( 'wp_ajax_nopriv_suredonation_create_payment_intent', [ $this, 'create_payment_intent' ] );

		// AJAX handler for completing donation after payment confirmation (captures and updates status).
		add_action( 'wp_ajax_suredonation_complete_donation', [ $this, 'complete_donation' ] );
		add_action( 'wp_ajax_nopriv_suredonation_complete_donation', [ $this, 'complete_donation' ] );

		// Enqueue Stripe.js on frontend.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_stripe_scripts' ] );
	}

	/**
	 * Enqueue Stripe.js
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function enqueue_stripe_scripts() {
		// Only enqueue on pages with donation form.
		if ( ! has_block( 'suredonation/donation-form' ) ) {
			return;
		}

		// Register Stripe.js library from CDN.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe CDN ignores version; version param is included to keep linter happy.
		wp_enqueue_script(
			'stripe-js',
			'https://js.stripe.com/v3/',
			[],
			SUREDONATION_VER,
			true
		);
	}

	/**
	 * Create Payment Intent via AJAX.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function create_payment_intent() {
		// Throttle abuse on this public endpoint before doing any work.
		if ( ! Helper::check_rate_limit( 'create_payment_intent' ) ) {
			wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait a moment and try again.', 'suredonation' ) ], 429 );
		}

		check_ajax_referer( 'suredonation_donation_form', 'nonce' );

		// Reject bot submissions caught by the honeypot before processing.
		if ( Helper::is_honeypot_spam() ) {
			wp_send_json_error( [ 'message' => __( 'Your submission was flagged as spam. Please try again.', 'suredonation' ) ] );
		}

		// Enforce the required contact-consent here — before creating a Stripe customer,
		// PaymentIntent, pending donation, or sending any email — so donor PII is never
		// persisted when the required consent box is unchecked. complete_donation()
		// validates again at the shared validate_submission() choke point.
		$consent_error = \SureDonation\Inc\Privacy\Privacy_Frontend::validate_consent();
		if ( '' !== $consent_error ) {
			wp_send_json_error(
				[
					'message'     => __( 'Please correct the highlighted fields and try again.', 'suredonation' ),
					'fieldErrors' => [ \SureDonation\Inc\Privacy\Privacy_Frontend::CONSENT_FIELD => $consent_error ],
				]
			);
		}

		$data         = $this->extract_donation_form_data();
		$campaign_id  = $data['campaign_id'];
		$amount       = $data['amount'];
		$base_amount  = $data['base_amount'];
		$cover_fees   = $data['cover_fees'];
		$donor_email  = $data['donor_email'];
		$donor_name   = $data['donor_name'];
		$donor_phone  = $data['donor_phone'];
		$is_anonymous = $data['is_anonymous'];
		$fees_covered = $data['fees_covered'];
		$currency     = $data['currency'];
		$customer_id  = $data['customer_id'];
		$campaign     = $data['campaign'];
		$form_id      = $data['form_id'];

		// Account this form charges to — resolved in extract_donation_form_data so the
		// customer (created there) and the payment intent use the same account.
		$stripe_account_id = $data['stripe_account_id'];

		// Convert amount to Stripe format (cents).
		$amount_in_cents = Payment_Helper::amount_to_stripe_format( $amount, $currency );

		// Convert base amount and fees to cents for metadata.
		$base_amount_cents  = Payment_Helper::amount_to_stripe_format( $base_amount, $currency );
		$fees_covered_cents = Payment_Helper::amount_to_stripe_format( $fees_covered, $currency );

		// Create Payment Intent via middleware (handles platform fees automatically).
		$intent_data = [
			'amount'        => $amount_in_cents,
			'currency'      => strtolower( $currency ),
			'customer'      => $customer_id,
			'receipt_email' => $donor_email,
			'description'   => $campaign
				? sprintf(
					// translators: %s is the campaign title.
					__( 'Donation to %s', 'suredonation' ),
					$campaign->post_title
				)
				: __( 'Donation', 'suredonation' ),
			'metadata'      => [
				'campaign_id'     => $campaign_id,
				'donor_email'     => $donor_email,
				'donor_name'      => $donor_name,
				'original_amount' => $amount_in_cents, // Store original amount for verification.
				'base_amount'     => $base_amount_cents,
				'fees_covered'    => $fees_covered_cents,
				'cover_fees'      => $cover_fees ? 'yes' : 'no',
			],
		];

		// Use middleware for payment intent creation (handles platform fees based on license).
		$payment_intent = Stripe_Helper::create_payment_intent_via_middleware( $intent_data, $stripe_account_id );

		if ( is_wp_error( $payment_intent ) ) {
			wp_send_json_error( [ 'message' => esc_html( $payment_intent->get_error_message() ) ] );
		}

		// Extract payment intent ID - ensure string type.
		$payment_intent_id = isset( $payment_intent['id'] ) && is_string( $payment_intent['id'] ) ? $payment_intent['id'] : '';
		if ( empty( $payment_intent_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid payment intent response.', 'suredonation' ) ] );
		}

		// Security: Store payment intent metadata for webhook verification.
		// This allows the webhook to verify the actual charged amount matches expected.
		Payment_Helper::store_payment_intent_metadata(
			$payment_intent_id,
			[
				'amount_cents' => $amount_in_cents,
				'currency'     => $currency,
				'campaign_id'  => $campaign_id,
				'created_at'   => time(),
			]
		);

		// Create pending donation record with transaction ID and customer ID.
		$donation_id = $this->create_pending_donation(
			[
				'campaign_id'       => $campaign_id,
				'amount'            => $amount,
				'donor_email'       => $donor_email,
				'donor_name'        => $donor_name,
				'transaction_id'    => $payment_intent_id,
				'customer_id'       => $customer_id,
				'fees_covered'      => $fees_covered,
				'form_id'           => $form_id,
				'donor_phone'       => $donor_phone,
				'is_anonymous'      => $is_anonymous,
				'stripe_account_id' => $stripe_account_id,
			]
		);

		if ( is_wp_error( $donation_id ) ) {
			wp_send_json_error( [ 'message' => esc_html( $donation_id->get_error_message() ) ] );
		}

		// Send donation processing email.
		Email_Handler::send_donation_processing(
			$donation_id,
			$campaign_id,
			[
				'id'            => $donation_id,
				'donor_name'    => $donor_name,
				'donor_email'   => $donor_email,
				'amount'        => $amount,
				'fees_covered'  => $fees_covered,
				'currency'      => $currency,
				'donation_type' => 'one-time',
				'gateway'       => 'stripe',
			],
			$form_id
		);

		// Return client secret to frontend.
		wp_send_json_success(
			[
				'clientSecret' => $payment_intent['client_secret'],
				'donationId'   => $donation_id,
			]
		);
	}

	/**
	 * Complete donation via AJAX after successful payment confirmation.
	 *
	 * This method follows the SureForms pattern where after Stripe.confirmPayment()
	 * succeeds on the frontend, the server-side code:
	 * 1. Captures the payment via middleware (since capture_method='manual')
	 * 2. Updates the donation status from 'pending' to 'completed'
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function complete_donation() {
		// Verify nonce.
		check_ajax_referer( 'suredonation_donation_form', 'nonce' );

		// Get payment intent ID and donation ID.
		$payment_intent_id = isset( $_POST['payment_intent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_intent_id'] ) ) : '';
		$donation_id       = isset( $_POST['donation_id'] ) ? absint( $_POST['donation_id'] ) : 0;

		if ( empty( $payment_intent_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Payment intent ID is required', 'suredonation' ) ] );
		}

		if ( empty( $donation_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Donation ID is required', 'suredonation' ) ] );
		}

		// Validate payment intent ID format.
		if ( ! preg_match( '/^pi_[a-zA-Z0-9]+$/', $payment_intent_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid payment intent ID', 'suredonation' ) ] );
		}

		// Verify donation exists and matches the payment intent.
		$donation = Donations::get( $donation_id );
		if ( ! $donation ) {
			wp_send_json_error( [ 'message' => __( 'Donation not found', 'suredonation' ) ] );
		}

		// Verify transaction ID matches.
		if ( $donation['transaction_id'] !== $payment_intent_id ) {
			wp_send_json_error( [ 'message' => __( 'Payment verification failed', 'suredonation' ) ] );
		}

		// Verify donation is in pending state to prevent status rollback on replay.
		if ( 'pending' !== ( $donation['payment_status'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => __( 'Donation is not in pending state', 'suredonation' ) ] );
		}

		// Capture the payment via middleware, using the account that created the intent.
		$capture_account_id = isset( $donation['stripe_account_id'] ) && is_string( $donation['stripe_account_id'] ) ? $donation['stripe_account_id'] : '';
		$capture_result     = Stripe_Helper::capture_payment_intent_via_middleware( $payment_intent_id, $capture_account_id );

		if ( is_wp_error( $capture_result ) ) {
			wp_send_json_error( [ 'message' => esc_html( $capture_result->get_error_message() ) ] );
		}

		// Check if payment was captured successfully.
		$payment_status = isset( $capture_result['status'] ) && is_string( $capture_result['status'] ) ? $capture_result['status'] : '';

		if ( ! in_array( $payment_status, [ 'succeeded', 'requires_capture' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Payment capture failed', 'suredonation' ) ] );
		}

		// Update donation status to completed.
		$update_result = Donations::update(
			$donation_id,
			[
				'payment_status' => 'completed',
			]
		);

		if ( ! $update_result ) {
			// Payment was captured but status update failed - log it but don't fail the request.
			// The webhook will handle this as a backup.
			Donations::add_log(
				$donation_id,
				'warning',
				__( 'Payment captured but donation status update failed', 'suredonation' ),
				[
					'payment_status' => $payment_status,
				]
			);
		} else {
			// Add success log entry.
			Donations::add_log(
				$donation_id,
				'completed',
				__( 'Payment captured and donation completed', 'suredonation' ),
				[
					'payment_status'    => $payment_status,
					'payment_intent_id' => $payment_intent_id,
				]
			);
		}

		// Send donation confirmation emails.
		$campaign_id   = isset( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;
		$form_id       = isset( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
		$donation_data = [
			'id'             => $donation_id,
			'donor_name'     => $donation['donor_name'] ?? '',
			'donor_email'    => $donation['donor_email'] ?? '',
			'amount'         => $donation['amount'] ?? 0,
			'fees_covered'   => $donation['fees_covered'] ?? 0,
			'currency'       => $donation['currency'] ?? 'USD',
			'transaction_id' => $payment_intent_id,
			'gateway'        => 'stripe',
			'donation_type'  => $donation['donation_type'] ?? 'one-time',
		];

		Email_Handler::send_donation_confirmation( $donation_id, $campaign_id, $donation_data, $form_id );

		wp_send_json_success(
			[
				'status'            => $payment_status,
				'payment_intent_id' => $payment_intent_id,
				'donation_id'       => $donation_id,
				'message'           => Helper::render_confirmation_message( $donation_id ),
			]
		);
	}

	/**
	 * Get or create Stripe customer based on email.
	 *
	 * This method checks for existing customers in the following order:
	 * 1. WordPress user meta (for logged-in users)
	 * 2. Donors table (for returning donors)
	 * 3. Creates a new Stripe customer if not found
	 *
	 * Note: Cached customer IDs are verified with Stripe to handle mode switches
	 * (test/live) or deleted customers gracefully.
	 *
	 * @param string $email      Customer email.
	 * @param string $name       Customer name.
	 * @param string $account_id Connected account to resolve/create the customer on; default account when empty.
	 * @return string|\WP_Error Customer ID or error.
	 * @since 0.0.1
	 */
	public static function get_or_create_stripe_customer( $email, $name, $account_id = '' ) {
		// A Stripe customer is scoped to one connected account, so resolve/create
		// it on the account this donation will be charged to.
		if ( empty( $account_id ) ) {
			$account_id = Stripe_Helper::get_default_account_id();
		}

		$is_default           = ( '' !== $account_id && Stripe_Helper::get_default_account_id() === $account_id );
		$user                 = get_user_by( 'email', $email );
		$meta_key             = '_suredonation_stripe_customer_id_' . $account_id; // Per-account cache key.
		$existing_customer_id = '';

		// 1. Per-account cache in user meta (logged-in users).
		if ( $user ) {
			$cached = get_user_meta( $user->ID, $meta_key, true );
			if ( ! empty( $cached ) && is_string( $cached ) ) {
				$existing_customer_id = $cached;
			}
		}

		// 2. Per-account map in the donors table (returning donors), with legacy fallback.
		if ( empty( $existing_customer_id ) ) {
			$existing_customer_id = Donors::get_stripe_customer_id_for_account( $email, $account_id, $is_default );
		}

		// 3. Verify the existing customer still exists on THIS account.
		if ( ! empty( $existing_customer_id ) ) {
			if ( Stripe_Helper::verify_customer_exists( $existing_customer_id, $account_id ) ) {
				if ( $user ) {
					update_user_meta( $user->ID, $meta_key, $existing_customer_id );
				}
				return $existing_customer_id;
			}

			// Not found on this account (deleted, mode switch, or belongs to another account).
			if ( $user ) {
				delete_user_meta( $user->ID, $meta_key );
			}
			Donors::clear_stripe_customer_id_for_account( $email, $account_id, $is_default );
		}

		// 4. Create a new Stripe customer on this account.
		$customer = Stripe_Helper::create_customer(
			[
				'email' => $email,
				'name'  => $name,
			],
			$account_id
		);

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$customer_id = isset( $customer['id'] ) && is_string( $customer['id'] ) ? $customer['id'] : '';

		if ( empty( $customer_id ) ) {
			return new \WP_Error( 'customer_creation_failed', __( 'Failed to create customer', 'suredonation' ) );
		}

		// 5. Store the customer id for this account.
		if ( $user ) {
			update_user_meta( $user->ID, $meta_key, $customer_id );
		}
		Donors::set_stripe_customer_id_for_account( $email, $account_id, $customer_id, $is_default );

		return $customer_id;
	}

	/**
	 * Extract and validate common donation form POST data.
	 *
	 * Calls wp_send_json_error() and exits on validation failure.
	 *
	 * @return array{campaign_id: int, is_standalone: bool, amount: float, base_amount: float, cover_fees: bool, donor_email: string, donor_name: string, donor_phone: string, is_anonymous: bool, form_id: int, block_id: string, fees_covered: float, currency: string, customer_id: string, stripe_account_id: string, campaign: \WP_Post|null} Validated form data.
	 * @since 1.0.0
	 */
	private function extract_donation_form_data() {
		// Nonce is verified in the calling method (create_payment_intent).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$is_standalone = isset( $_POST['is_standalone'] ) && '1' === $_POST['is_standalone'];
		$campaign_id   = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$amount        = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;
		$base_amount   = isset( $_POST['base_amount'] ) ? floatval( wp_unslash( $_POST['base_amount'] ) ) : $amount;
		$cover_fees    = isset( $_POST['cover_fees'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['cover_fees'] ) );
		$donor_email   = isset( $_POST['donor_email'] ) ? sanitize_email( wp_unslash( $_POST['donor_email'] ) ) : '';
		$donor_name    = isset( $_POST['donor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_name'] ) ) : '';
		$form_id       = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		// Derive the donor phone from the validated mapped field, not a separate
		// unvalidated $_POST['donor_phone'] (see Payment_Helper::get_mapped_donor_phone).
		$donor_phone = Payment_Helper::get_mapped_donor_phone( $form_id );
		$block_id    = isset( $_POST['block_id'] ) ? sanitize_text_field( wp_unslash( $_POST['block_id'] ) ) : '';
		// Display-only flag: the donor's real name/email/phone are still stored
		// and only public surfaces mask them.
		$is_anonymous = Payment_Helper::get_submitted_is_anonymous( $form_id );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Standalone forms must not have a campaign.
		if ( $is_standalone ) {
			$campaign_id = 0;
		}

		$fees_covered = 0.0;
		if ( $cover_fees && $base_amount > 0 ) {
			$fees_covered = $amount - $base_amount;
			if ( $fees_covered < 0 ) {
				$fees_covered = 0.0;
			}
		}

		// Validate campaign only if not standalone.
		if ( ! $is_standalone && empty( $campaign_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign', 'suredonation' ) ] );
		}

		if ( empty( $donor_email ) ) {
			wp_send_json_error( [ 'message' => __( 'Email address is required', 'suredonation' ) ] );
		}

		$currency = Payment_Helper::get_currency();

		// Fail closed: server-side validation is the source of truth, so a
		// submission without the form/block context cannot be validated and must
		// be rejected (mirrors the offline handler). Otherwise omitting these
		// params would silently skip amount + field validation.
		if ( empty( $form_id ) || empty( $block_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid form configuration.', 'suredonation' ) ] );
		}

		$donation_amount   = $amount - $fees_covered;
		$validation_result = Payment_Helper::validate_submission( Payment_Helper::get_submitted_fields(), $donation_amount, $currency, $form_id, $block_id, 'stripe' );
		if ( ! $validation_result['valid'] ) {
			wp_send_json_error(
				[
					'message'     => esc_html( $validation_result['message'] ),
					'fieldErrors' => $validation_result['field_errors'],
				]
			);
		}

		if ( ! Stripe_Helper::is_stripe_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Payment gateway not configured', 'suredonation' ) ] );
		}

		// Resolve the connected account this form charges to; create the customer on it.
		$stripe_account_id = Stripe_Helper::resolve_account_for_form( $form_id );

		$customer_id = self::get_or_create_stripe_customer( $donor_email, $donor_name, $stripe_account_id );
		if ( is_wp_error( $customer_id ) ) {
			wp_send_json_error( [ 'message' => esc_html( $customer_id->get_error_message() ) ] );
		}

		// Validate campaign only if not standalone.
		$campaign = null;
		if ( ! $is_standalone ) {
			$campaign = get_post( $campaign_id );
			if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
				wp_send_json_error( [ 'message' => __( 'Invalid campaign', 'suredonation' ) ] );
			}

			if ( 'publish' !== $campaign->post_status ) {
				wp_send_json_error( [ 'message' => __( 'This campaign is not available for donations.', 'suredonation' ) ] );
			}

			$campaign_status = Helper::get_campaign_meta_value( $campaign_id, 'campaign_status', 'active' );
			if ( 'paused' === $campaign_status || 'completed' === $campaign_status ) {
				wp_send_json_error( [ 'message' => __( 'This campaign is not currently accepting donations.', 'suredonation' ) ] );
			}
		}

		// Verify campaign allows fee coverage.
		$allow_fees = ! $is_standalone ? Helper::get_campaign_meta_value( $campaign_id, 'allow_fees_coverage', true ) : true;
		if ( $cover_fees && ! $allow_fees ) {
			$cover_fees   = false;
			$fees_covered = 0.0;
			$amount       = $base_amount;
		}

		// Verify that the fee calculation is legitimate using stored block config.
		if ( $cover_fees && $fees_covered > 0 ) {
			$fee_config = Payment_Helper::get_cover_fees_config( $form_id, 'stripe' );

			if ( ! $fee_config['enabled'] ) {
				$cover_fees   = false;
				$fees_covered = 0.0;
				$amount       = $base_amount;
			}

			if ( $cover_fees ) {
				// Recalculate fee and total server-side from base_amount — do not trust client-supplied amount.
				$expected_fees = Payment_Helper::calculate_fee( $base_amount, $fee_config['fee_percentage'], $fee_config['fee_fixed'] );
				$fees_covered  = $expected_fees;
				$amount        = $base_amount + $fees_covered;
			}
		}

		return compact(
			'campaign_id',
			'is_standalone',
			'amount',
			'base_amount',
			'cover_fees',
			'donor_email',
			'donor_name',
			'donor_phone',
			'is_anonymous',
			'form_id',
			'block_id',
			'fees_covered',
			'currency',
			'customer_id',
			'stripe_account_id',
			'campaign'
		);
	}

	/**
	 * Create pending donation record in database table
	 *
	 * Takes an argument array rather than a positional list — the set had grown
	 * past the point where call sites stayed readable. The shape below is
	 * declared precisely rather than as array<string, mixed> so a missing or
	 * misnamed required key is a static-analysis failure, not a silently
	 * defaulted value: an empty transaction_id would produce a pending donation
	 * that neither complete_donation() nor the webhook can ever match, leaving a
	 * charged donor with a permanently pending record.
	 *
	 * @param array{campaign_id: int, amount: float, donor_email: string, donor_name: string, transaction_id: string, customer_id: string, fees_covered?: float, form_id?: int, donor_phone?: string, is_anonymous?: bool, stripe_account_id?: string} $args Donation arguments.
	 * @return int|\WP_Error Donation ID or error.
	 * @since 0.0.1
	 */
	private function create_pending_donation( array $args ) {
		$campaign_id       = absint( $args['campaign_id'] );
		$amount            = (float) $args['amount'];
		$donor_email       = $args['donor_email'];
		$donor_name        = $args['donor_name'];
		$transaction_id    = $args['transaction_id'];
		$customer_id       = $args['customer_id'];
		$fees_covered      = (float) ( $args['fees_covered'] ?? 0.0 );
		$form_id           = absint( $args['form_id'] ?? 0 );
		$donor_phone       = $args['donor_phone'] ?? '';
		$is_anonymous      = ! empty( $args['is_anonymous'] );
		$stripe_account_id = $args['stripe_account_id'] ?? '';

		// Belt-and-braces for the two values with no safe default. The declared
		// shape makes omitting them a static error, but a runtime empty value
		// would still orphan the donation against the charge, so refuse rather
		// than insert an unmatchable row.
		if ( '' === $transaction_id || '' === $donor_email ) {
			return new \WP_Error( 'donation_failed', __( 'Failed to create donation record', 'suredonation' ) );
		}

		// Get or create donor.
		$donor_id = Donors::get_or_create( $donor_email, $donor_name, $donor_phone );

		// The Stripe customer ID is already persisted against the correct
		// account by get_or_create_stripe_customer() during data extraction.
		// Writing the legacy default-account column here would clobber it with a
		// non-default account's customer, so it is intentionally not done.

		// Create donation in database table.
		$donation_id = Donations::add(
			[
				'campaign_id'       => $campaign_id,
				'donor_id'          => $donor_id ? $donor_id : 0,
				'amount'            => $amount,
				'fees_covered'      => $fees_covered,
				'currency'          => Payment_Helper::get_currency(),
				'gateway'           => 'stripe',
				'payment_status'    => 'pending',
				'payment_mode'      => Payment_Helper::get_payment_mode(),
				'donor_name'        => $donor_name,
				'donor_email'       => $donor_email,
				'donor_phone'       => $donor_phone,
				'is_anonymous'      => $is_anonymous ? 1 : 0,
				'donation_type'     => 'one-time',
				'transaction_id'    => $transaction_id,
				'customer_id'       => $customer_id,
				'stripe_account_id' => $stripe_account_id,
				'form_id'           => $form_id,
				'ip_address'        => Helper::get_client_ip(),
				'user_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'referer_url'       => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			]
		);

		if ( ! $donation_id ) {
			return new \WP_Error( 'donation_failed', __( 'Failed to create donation record', 'suredonation' ) );
		}

		// Persist the submitted field values for the entry record.
		Donations::set_submitted_fields( $donation_id, Payment_Helper::get_submitted_field_data() );

		// Add initial log entry.
		Donations::add_log(
			$donation_id,
			'created',
			__( 'Donation created with pending payment', 'suredonation' ),
			[
				'transaction_id' => $transaction_id,
				'customer_id'    => $customer_id,
			]
		);

		return $donation_id;
	}
}
