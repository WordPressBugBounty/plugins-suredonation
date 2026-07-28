<?php
/**
 * PayPal Frontend - Frontend payment processing.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Payments\PayPal;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Field_Validation;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal_Frontend class.
 *
 * Handles frontend PayPal payment creation and verification.
 *
 * @since 1.0.0
 */
class PayPal_Frontend {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_suredonation_create_paypal_order', [ $this, 'create_paypal_order' ] );
		add_action( 'wp_ajax_nopriv_suredonation_create_paypal_order', [ $this, 'create_paypal_order' ] );

		add_action( 'wp_ajax_suredonation_verify_paypal_order', [ $this, 'verify_paypal_order' ] );
		add_action( 'wp_ajax_nopriv_suredonation_verify_paypal_order', [ $this, 'verify_paypal_order' ] );
	}

	/**
	 * Create a PayPal order via middleware.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function create_paypal_order() {
		// Throttle abuse on this public endpoint before doing any work.
		if ( ! Helper::check_rate_limit( 'create_paypal_order' ) ) {
			wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait a moment and try again.', 'suredonation' ) ], 429 );
		}

		check_ajax_referer( 'suredonation_donation_form', 'nonce' );

		// Reject bot submissions caught by the honeypot before processing.
		if ( Helper::is_honeypot_spam() ) {
			wp_send_json_error( [ 'message' => __( 'Your submission was flagged as spam. Please try again.', 'suredonation' ) ] );
		}

		$data = $this->extract_and_validate_form_data();

		if ( is_wp_error( $data ) ) {
			$error_data = $data->get_error_data();
			wp_send_json_error(
				[
					'message'     => esc_html( $data->get_error_message() ),
					'fieldErrors' => is_array( $error_data ) && isset( $error_data['fieldErrors'] ) ? $error_data['fieldErrors'] : [],
				]
			);
		}

		$mode        = Payment_Helper::get_payment_mode();
		$merchant_id = PayPal_Helper::get_paypal_merchant_id( $mode );

		if ( empty( $merchant_id ) ) {
			wp_send_json_error( [ 'message' => __( 'PayPal is not configured.', 'suredonation' ) ] );
		}

		/**
		 * Extract validated form data.
		 *
		 * @phpstan-var array{currency: string, amount: float, base_amount: float, fees_covered: float, donor_email: string, donor_name: string, donor_phone: string, campaign_id: int, form_id: int, campaign_title: string} $data
		 */
		$currency       = $data['currency'];
		$amount         = $data['amount'];
		$base_amount    = $data['base_amount'];
		$fees_covered   = $data['fees_covered'];
		$donor_email    = $data['donor_email'];
		$donor_name     = $data['donor_name'];
		$donor_phone    = $data['donor_phone'];
		$campaign_id    = $data['campaign_id'];
		$form_id        = $data['form_id'];
		$campaign_title = $data['campaign_title'];

		// Format amount for PayPal.
		$formatted_amount = PayPal_Helper::format_amount_for_paypal( $amount, $currency );

		/**
		 * Filter the license key sent to middleware for platform fee calculation.
		 * Pro injects its license key for 0% fees.
		 *
		 * @param string $license_key The license key (empty for free).
		 * @since 1.0.0
		 */
		$license_key = apply_filters( 'suredonation_paypal_license_key', '' );

		// Create order via middleware (partner credentials used by middleware, not merchant's).
		$order_data = [
			'amount'      => $formatted_amount,
			'currency'    => strtoupper( $currency ),
			'merchant_id' => $merchant_id,
			'environment' => PayPal_Helper::get_middleware_environment( $mode ),
			'license_key' => $license_key,
			'description' => sprintf(
				/* translators: %s is the campaign title. */
				__( 'Donation to %s', 'suredonation' ),
				$campaign_title
			),
			'bn_code'     => PayPal_Helper::paypal_bn_code(),
		];

		// Add payer info if available.
		if ( ! empty( $donor_email ) ) {
			$order_data['payer'] = [
				'email_address' => $donor_email,
			];
			if ( ! empty( $donor_name ) ) {
				$order_data['payer']['name'] = [
					'given_name' => $donor_name,
				];
			}
		}

		$result = PayPal_Helper::middleware_request( 'orders/create', $order_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => esc_html( $result->get_error_message() ) ] );
		}

		if ( empty( $result['id'] ) ) {
			$raw_message   = $result['message'] ?? null;
			$error_message = is_string( $raw_message ) ? $raw_message : __( 'Failed to create PayPal order.', 'suredonation' );
			wp_send_json_error( [ 'message' => esc_html( $error_message ) ] );
		}

		$order_id = is_string( $result['id'] ) ? $result['id'] : '';

		// Get or create donor.
		$donor_id = 0;
		if ( ! empty( $donor_email ) ) {
			$donor_id = Donors::get_or_create( $donor_email, $donor_name, $donor_phone );
		}

		// Create pending donation record.
		$request_meta = Helper::get_request_meta();
		$donation_id  = Donations::add(
			[
				'campaign_id'    => $campaign_id,
				'donor_id'       => $donor_id ? $donor_id : 0,
				'amount'         => number_format( $base_amount, 2, '.', '' ),
				'fees_covered'   => number_format( $fees_covered, 2, '.', '' ),
				'currency'       => $currency,
				'gateway'        => 'paypal',
				'payment_status' => 'pending',
				'payment_mode'   => $mode,
				'donor_name'     => $donor_name,
				'donor_email'    => $donor_email,
				'donor_phone'    => $donor_phone,
				'donation_type'  => 'one-time',
				'transaction_id' => $order_id,
				'form_id'        => $form_id,
				'ip_address'     => Helper::get_client_ip(),
				'user_agent'     => $request_meta['user_agent'],
				'referer_url'    => $request_meta['referer_url'],
			]
		);

		if ( ! $donation_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to create donation record.', 'suredonation' ) ] );
		}

		// Persist the submitted field values for the entry record.
		Donations::set_submitted_fields( $donation_id, Payment_Helper::get_submitted_field_data() );

		// Store metadata for webhook verification.
		Payment_Helper::store_payment_intent_metadata(
			$order_id,
			[
				'amount'      => $amount,
				'currency'    => $currency,
				'campaign_id' => $campaign_id,
				'created_at'  => time(),
			]
		);

		// Send processing email.
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
				'gateway'       => 'paypal',
			],
			$form_id
		);

		wp_send_json_success(
			[
				'orderID'    => $order_id,
				'donationId' => $donation_id,
			]
		);
	}

	/**
	 * Verify and capture a PayPal order after user approval.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function verify_paypal_order() {
		check_ajax_referer( 'suredonation_donation_form', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$order_id    = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$donation_id = isset( $_POST['donation_id'] ) ? absint( $_POST['donation_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $order_id ) || empty( $donation_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing required parameters.', 'suredonation' ) ] );
		}

		// Verify donation exists and matches the order.
		$donation = Donations::get( $donation_id );
		if ( ! $donation ) {
			wp_send_json_error( [ 'message' => __( 'Donation not found.', 'suredonation' ) ] );
		}

		if ( ( $donation['transaction_id'] ?? '' ) !== $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Payment verification failed.', 'suredonation' ) ] );
		}

		// Prevent status rollback on replay.
		if ( 'pending' !== ( $donation['payment_status'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => __( 'Donation is not in pending state.', 'suredonation' ) ] );
		}

		$mode        = Payment_Helper::get_payment_mode();
		$merchant_id = PayPal_Helper::get_paypal_merchant_id( $mode );

		// Capture order via middleware (partner credentials used by middleware).
		$result = PayPal_Helper::middleware_request(
			'orders/capture',
			[
				'order_id'    => $order_id,
				'merchant_id' => $merchant_id,
				'environment' => PayPal_Helper::get_middleware_environment( $mode ),
				'plugin_name' => 'SureDonation',
			]
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => esc_html( $result->get_error_message() ) ] );
		}

		$capture_status = isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : '';

		if ( 'COMPLETED' !== $capture_status ) {
			$error_message = ! empty( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : __( 'Payment capture failed.', 'suredonation' );
			Donations::add_log( $donation_id, 'error', esc_html( $error_message ), [ 'status' => $capture_status ] );
			wp_send_json_error( [ 'message' => esc_html( $error_message ) ] );
		}

		// Extract capture ID for refund operations.
		$capture_id = $this->extract_capture_id( $result );

		// Verify captured amount matches expected.
		$captured_amount = $this->extract_captured_amount( $result );
		$expected_amount = (float) ( $donation['amount'] ?? 0 ) + (float) ( $donation['fees_covered'] ?? 0 );

		if ( $captured_amount > 0 && abs( $captured_amount - $expected_amount ) > 0.01 ) {
			Donations::update_status( $donation_id, 'suspicious' );
			Donations::add_log(
				$donation_id,
				'security_warning',
				__( 'Captured amount does not match expected amount', 'suredonation' ),
				[
					'captured' => $captured_amount,
					'expected' => $expected_amount,
				]
			);
			wp_send_json_error( [ 'message' => __( 'Payment amount verification failed.', 'suredonation' ) ] );
		}

		// Update donation status.
		$update_data = [ 'payment_status' => 'completed' ];
		if ( ! empty( $capture_id ) ) {
			$update_data['transaction_id'] = $capture_id;
		}

		$update_result = Donations::update( $donation_id, $update_data );

		// Consolidated payment verification log (single entry with all details).
		$log_type    = $update_result ? 'completed' : 'warning';
		$log_message = $update_result
			? __( 'Payment Verification', 'suredonation' )
			: __( 'Payment captured but donation status update failed', 'suredonation' );

		Donations::add_log(
			$donation_id,
			$log_type,
			$log_message,
			[
				'transaction_id'  => $capture_id,
				'order_id'        => $order_id,
				'payment_gateway' => 'PayPal',
				'amount'          => PayPal_Helper::format_amount_for_paypal( $captured_amount, $donation['currency'] ?? 'USD' ) . ' ' . strtoupper( $donation['currency'] ?? 'USD' ),
				'status'          => $capture_status,
				'user_id'         => get_current_user_id(),
				'mode'            => ucfirst( Payment_Helper::get_payment_mode() ),
			]
		);

		// Send confirmation email.
		$campaign_id   = isset( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;
		$form_id       = isset( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
		$donation_data = [
			'id'             => $donation_id,
			'donor_name'     => $donation['donor_name'] ?? '',
			'donor_email'    => $donation['donor_email'] ?? '',
			'amount'         => $donation['amount'] ?? 0,
			'fees_covered'   => $donation['fees_covered'] ?? 0,
			'currency'       => $donation['currency'] ?? 'USD',
			'transaction_id' => ! empty( $capture_id ) ? $capture_id : $order_id,
			'gateway'        => 'paypal',
			'donation_type'  => $donation['donation_type'] ?? 'one-time',
		];

		Email_Handler::send_donation_confirmation( $donation_id, $campaign_id, $donation_data, $form_id );

		wp_send_json_success(
			[
				'status'     => 'completed',
				'donationId' => $donation_id,
				'captureId'  => $capture_id,
				'message'    => Helper::render_confirmation_message( $donation_id ),
			]
		);
	}

	/**
	 * Extract capture ID from PayPal order capture response.
	 *
	 * @param array<string, mixed> $body Capture response body.
	 * @return string Capture ID or empty string.
	 * @since 1.0.0
	 */
	private function extract_capture_id( $body ) {
		$purchase_units = $body['purchase_units'] ?? [];

		if ( is_array( $purchase_units ) && ! empty( $purchase_units[0] ) ) {
			$captures = $purchase_units[0]['payments']['captures'] ?? [];
			if ( is_array( $captures ) && ! empty( $captures[0]['id'] ) && is_string( $captures[0]['id'] ) ) {
				return $captures[0]['id'];
			}
		}

		return '';
	}

	/**
	 * Extract captured amount from PayPal order capture response.
	 *
	 * @param array<string, mixed> $body Capture response body.
	 * @return float Captured amount or 0.
	 * @since 1.0.0
	 */
	private function extract_captured_amount( $body ) {
		$purchase_units = $body['purchase_units'] ?? [];

		if ( is_array( $purchase_units ) && ! empty( $purchase_units[0] ) ) {
			$captures = $purchase_units[0]['payments']['captures'] ?? [];
			if ( is_array( $captures ) && ! empty( $captures[0]['amount']['value'] ) ) {
				return (float) $captures[0]['amount']['value'];
			}
		}

		return 0.0;
	}

	/**
	 * Extract and validate common form data from POST.
	 *
	 * @return array<string, mixed>|\WP_Error Validated form data or error.
	 * @since 1.0.0
	 */
	private function extract_and_validate_form_data() {
		// Nonce verified in calling method.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$amount      = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;
		$base_amount = isset( $_POST['base_amount'] ) ? floatval( wp_unslash( $_POST['base_amount'] ) ) : $amount;
		$cover_fees  = isset( $_POST['cover_fees'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['cover_fees'] ) );
		$donor_email = isset( $_POST['donor_email'] ) ? sanitize_email( wp_unslash( $_POST['donor_email'] ) ) : '';
		$donor_name  = isset( $_POST['donor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_name'] ) ) : '';
		$form_id     = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		// Derive the donor phone from the validated mapped field, not a separate
		// unvalidated $_POST['donor_phone'] (see Payment_Helper::get_mapped_donor_phone).
		$donor_phone = Payment_Helper::get_mapped_donor_phone( $form_id );
		$block_id    = isset( $_POST['block_id'] ) ? sanitize_text_field( wp_unslash( $_POST['block_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$fees_covered = 0.0;
		if ( $cover_fees && $base_amount > 0 ) {
			$fees_covered = $amount - $base_amount;
			if ( $fees_covered < 0 ) {
				$fees_covered = 0.0;
			}
		}

		if ( empty( $donor_email ) ) {
			return new \WP_Error( 'missing_email', __( 'Email address is required.', 'suredonation' ) );
		}

		$currency = Payment_Helper::get_currency();

		// Basic amount validation — must be positive regardless of form context.
		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_amount', __( 'Donation amount must be greater than zero.', 'suredonation' ) );
		}

		// Fail closed: a submission without the form/block context cannot be
		// validated server-side, so reject it rather than silently skipping
		// amount + field validation (mirrors the offline handler).
		if ( empty( $form_id ) || empty( $block_id ) ) {
			return new \WP_Error( 'invalid_form', __( 'Invalid form configuration.', 'suredonation' ) );
		}

		// Validate field values + amount against block configuration.
		$donation_amount   = $amount - $fees_covered;
		$validation_result = Payment_Helper::validate_submission( Payment_Helper::get_submitted_fields(), $donation_amount, $currency, $form_id, $block_id, 'paypal' );
		if ( ! $validation_result['valid'] ) {
			return new \WP_Error( 'invalid_submission', $validation_result['message'], [ 'fieldErrors' => $validation_result['field_errors'] ] );
		}

		if ( ! PayPal_Helper::is_paypal_connected() ) {
			return new \WP_Error( 'not_connected', __( 'PayPal is not configured.', 'suredonation' ) );
		}

		// Determine campaign info.
		$campaign_title = __( 'Donation', 'suredonation' );
		$is_standalone  = empty( $campaign_id );

		if ( ! $is_standalone ) {
			$campaign = get_post( $campaign_id );
			if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
				return new \WP_Error( 'invalid_campaign', __( 'Invalid campaign.', 'suredonation' ) );
			}

			if ( 'publish' !== $campaign->post_status ) {
				return new \WP_Error( 'campaign_unavailable', __( 'This campaign is not available for donations.', 'suredonation' ) );
			}

			$campaign_status = Helper::get_campaign_meta_value( $campaign_id, 'campaign_status', 'active' );
			if ( 'paused' === $campaign_status || 'completed' === $campaign_status ) {
				return new \WP_Error( 'campaign_paused', __( 'This campaign is not currently accepting donations.', 'suredonation' ) );
			}

			$campaign_title = $campaign->post_title;

			// Verify campaign allows fee coverage.
			$allow_fees = Helper::get_campaign_meta_value( $campaign_id, 'allow_fees_coverage', true );
			if ( $cover_fees && ! $allow_fees ) {
				$cover_fees   = false;
				$fees_covered = 0.0;
				$amount       = $base_amount;
			}
		}

		// Recalculate fees server-side.
		if ( $cover_fees && $fees_covered > 0 && $form_id > 0 ) {
			$fee_percentage = null;
			$fee_fixed      = null;

			$block_config = Field_Validation::get_or_migrate_block_config_for_legacy_form( $form_id );
			if ( ! empty( $block_config ) && is_array( $block_config ) ) {
				foreach ( $block_config as $config ) {
					if ( is_array( $config ) && isset( $config['block_name'] ) && 'suredonation/cover-fees' === $config['block_name'] ) {
						$block_fee_mode = $config['fee_mode'] ?? 'all_gateways';

						$gateway_fees = is_array( $config['gateway_fees'] ?? null ) ? $config['gateway_fees'] : [];
						if ( 'per_gateway' === $block_fee_mode && ! empty( $gateway_fees['paypal'] ) ) {
							$gw = is_array( $gateway_fees['paypal'] ) ? $gateway_fees['paypal'] : [];
							if ( empty( $gw['enabled'] ) ) {
								$cover_fees   = false;
								$fees_covered = 0.0;
								$amount       = $base_amount;
							} else {
								$fee_percentage = (float) ( $gw['fee_percentage'] ?? 0 );
								$fee_fixed      = (float) ( $gw['fee_fixed'] ?? 0 );
							}
						} else {
							$fee_percentage = is_numeric( $config['fee_percentage'] ?? null ) ? (float) $config['fee_percentage'] : null;
							$fee_fixed      = is_numeric( $config['fee_fixed'] ?? null ) ? (float) $config['fee_fixed'] : null;
						}
						break;
					}
				}
			}

			// Fall back to global gateway-specific settings.
			if ( $cover_fees && ( null === $fee_percentage || null === $fee_fixed ) ) {
				$rates          = Payment_Helper::get_fee_rates_for_gateway( 'paypal' );
				$fee_percentage = null === $fee_percentage ? (float) $rates['fee_percentage'] : $fee_percentage;
				$fee_fixed      = null === $fee_fixed ? (float) $rates['fee_fixed'] : $fee_fixed;
			}

			if ( $cover_fees ) {
				$expected_fees = Payment_Helper::calculate_fee( $base_amount, $fee_percentage, $fee_fixed );
				$fees_covered  = $expected_fees;
				$amount        = $base_amount + $fees_covered;
			}
		}

		return [
			'campaign_id'    => $campaign_id,
			'amount'         => $amount,
			'base_amount'    => $base_amount,
			'cover_fees'     => $cover_fees,
			'donor_email'    => $donor_email,
			'donor_name'     => $donor_name,
			'donor_phone'    => $donor_phone,
			'form_id'        => $form_id,
			'block_id'       => $block_id,
			'fees_covered'   => $fees_covered,
			'currency'       => $currency,
			'campaign_title' => $campaign_title,
		];
	}
}
