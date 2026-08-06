<?php
/**
 * Stripe Webhook Handler
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
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stripe_Webhook class
 * Handles Stripe webhook events
 *
 * @since 0.0.1
 */
class Stripe_Webhook {
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register webhook endpoints.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_routes() {
		// Real Stripe deliveries never send `mode` (it falls back to the route
		// default), so this only rejects a caller-supplied value that is not an
		// allowed mode — preventing it from reaching the interpolated transient
		// key in log_webhook_event() before the signature is verified.
		$validate_mode = static function ( $value ) {
			return in_array( $value, [ 'test', 'live' ], true );
		};

		// Test mode webhook.
		register_rest_route(
			'suredonation',
			'/webhook_test',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => '__return_true', // Stripe signature validation handles security.
				'args'                => [
					'mode' => [
						'default'           => 'test',
						'type'              => 'string',
						'enum'              => [ 'test', 'live' ],
						'validate_callback' => $validate_mode,
					],
				],
			]
		);

		// Live mode webhook.
		register_rest_route(
			'suredonation',
			'/webhook_live',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => '__return_true', // Stripe signature validation handles security.
				'args'                => [
					'mode' => [
						'default'           => 'live',
						'type'              => 'string',
						'enum'              => [ 'test', 'live' ],
						'validate_callback' => $validate_mode,
					],
				],
			]
		);
	}

	/**
	 * Handle webhook event
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function handle_webhook( $request ) {
		$mode = $request->get_param( 'mode' );

		// Validate source IP if IP whitelisting is enabled.
		if ( ! $this->validate_source_ip() ) {
			return new WP_REST_Response( [ 'error' => 'Access denied' ], 403 );
		}

		// Get raw body.
		$payload = $request->get_body();

		// Get Stripe signature.
		$sig_header = $request->get_header( 'stripe_signature' );

		if ( empty( $sig_header ) ) {
			$this->log_webhook_event( $mode, 'error', 'Missing Stripe signature header' );
			return new WP_REST_Response( [ 'error' => 'Missing signature' ], 400 );
		}

		// Verify signature (tries every connected account's secret for this mode).
		$account_id = '';
		$event      = $this->verify_webhook_signature( $payload, $sig_header, $mode, $account_id );

		if ( is_wp_error( $event ) ) {
			$this->log_webhook_event( $mode, 'error', $event->get_error_message() );
			return new WP_REST_Response( [ 'error' => 'Webhook signature verification failed' ], 400 );
		}

		// Log webhook began.
		$this->log_webhook_event( $mode, 'began', 'Webhook processing started', $event );

		// Process event.
		$result = $this->process_event( $event, $mode, $account_id );

		if ( is_wp_error( $result ) ) {
			$this->log_webhook_event( $mode, 'failure', $result->get_error_message(), $event );
			return new WP_REST_Response( [ 'error' => 'Webhook processing failed' ], 500 );
		}

		// Log success.
		$this->log_webhook_event( $mode, 'success', 'Webhook processed successfully', $event );

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Verify the Stripe webhook signature locally.
	 *
	 * Validates the Stripe-Signature header against the configured webhook
	 * secret using HMAC-SHA256 (Stripe's documented signing scheme), so the
	 * webhook secret never leaves the server. On success the verified event
	 * payload is decoded and returned.
	 *
	 * @param string $payload    Raw webhook request body.
	 * @param string $sig_header Signature header value.
	 * @param string $mode       Payment mode ('test' or 'live') the endpoint received. Both are tried when empty.
	 * @param string $account_id Out: the connected account whose secret verified the signature.
	 * @return array<string, mixed>|\WP_Error Decoded event data on success, WP_Error on failure.
	 * @since 0.0.1
	 */
	private function verify_webhook_signature( $payload, $sig_header, $mode = '', &$account_id = '' ) {

		// Each connected account has its own webhook endpoint (and signing secret)
		// pointing at this shared URL, so try every account's secret for the mode.
		$modes   = in_array( $mode, [ 'test', 'live' ], true ) ? [ $mode ] : [ 'test', 'live' ];
		$secrets = [];

		foreach ( Stripe_Helper::get_all_accounts() as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			foreach ( $modes as $candidate_mode ) {
				$secret = $account[ $candidate_mode . '_webhook_secret' ] ?? '';
				if ( is_string( $secret ) && '' !== $secret ) {
					// Keep the owning account with each secret: whichever secret
					// verifies identifies the account the event belongs to, which
					// downstream handlers need to call the Stripe API back.
					$secrets[] = [
						'account_id' => is_string( $account['account_id'] ?? null ) ? $account['account_id'] : '',
						'secret'     => $secret,
					];
				}
			}
		}

		if ( empty( $secrets ) ) {
			return new \WP_Error( 'no_webhook_secret', __( 'Webhook secret not configured', 'suredonation' ) );
		}

		$verified = false;
		foreach ( $secrets as $candidate ) {
			if ( $this->verify_signature_locally( $payload, $sig_header, $candidate['secret'] ) ) {
				$verified   = true;
				$account_id = $candidate['account_id'];
				break;
			}
		}

		if ( ! $verified ) {
			return new \WP_Error( 'signature_verification_failed', __( 'Webhook signature verification failed', 'suredonation' ) );
		}

		// Signature verified — decode the event payload.
		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Invalid webhook payload', 'suredonation' ) );
		}

		/** Verified event data. @var array<string, mixed> $event */
		return $event;
	}

	/**
	 * Verify a Stripe webhook signature locally using HMAC-SHA256.
	 *
	 * Implements Stripe's signature scheme without any external dependency or
	 * SDK: parse the timestamp (t) and signature (v1) from the Stripe-Signature
	 * header, recompute HMAC-SHA256 over "{timestamp}.{payload}" with the
	 * webhook secret, and compare in constant time. Requests older than the
	 * tolerance window are rejected to mitigate replay attacks.
	 *
	 * @param string $payload    Raw webhook request body.
	 * @param string $sig_header Stripe-Signature header value.
	 * @param string $secret     Webhook signing secret (whsec_...).
	 * @param int    $tolerance  Maximum accepted age of the signature, in seconds.
	 * @return bool True if the signature is valid and within tolerance.
	 * @since 1.1.1
	 */
	private function verify_signature_locally( $payload, $sig_header, $secret, $tolerance = 300 ) {
		if ( ! is_string( $payload ) || ! is_string( $sig_header ) || '' === $sig_header ) {
			return false;
		}

		// Parse the Stripe-Signature header (format: t=timestamp,v1=signature,...).
		$timestamp = '';
		$signature = '';

		foreach ( explode( ',', $sig_header ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signature = $pair[1];
			}
		}

		if ( '' === $timestamp || '' === $signature ) {
			return false;
		}

		// Replay protection: reject signatures older than the tolerance window.
		if ( absint( $timestamp ) < time() - $tolerance ) {
			return false;
		}

		// Recompute the expected signature and compare in constant time.
		$expected_signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		return hash_equals( $expected_signature, $signature );
	}

	/**
	 * Process webhook event.
	 *
	 * @param array<string, mixed> $event      Event data.
	 * @param string               $mode       Payment mode.
	 * @param string               $account_id Connected account whose secret verified the event.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 * @since 0.0.1
	 */
	private function process_event( $event, $mode, $account_id = '' ) {
		$event_type = isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : '';

		// Extract event data safely.
		$event_data = [];
		if ( isset( $event['data'] ) && is_array( $event['data'] ) && isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ) {
			$event_data = $event['data']['object'];
		}

		/** Event object data. @var array<string, mixed> $event_data */

		switch ( $event_type ) {
			case 'payment_intent.succeeded':
				return $this->handle_payment_succeeded( $event_data, $mode );

			case 'payment_intent.payment_failed':
				return $this->handle_payment_failed( $event_data, $mode );

			case 'charge.refund.updated':
				// This event sends a Refund object (not a Charge object).
				// Matches SureForms pattern for handling refunds from Stripe dashboard.
				return $this->handle_refund_updated( $event_data, $mode );

			case 'payment_intent.canceled':
				return $this->handle_payment_canceled( $event_data, $mode );

			default:
				/**
				 * Allow extensions to handle additional webhook events.
				 *
				 * Pro plugin uses this to handle subscription events
				 * (customer.subscription.created, invoice.payment_succeeded, etc.)
				 *
				 * Return contract:
				 *   - null              : Filter did not handle the event.
				 *   - true              : Handled successfully.
				 *   - WP_Error with code 'permanent_failure' or 'invalid_event'
				 *                       : Handled but unrecoverable — webhook is acked
				 *                         to Stripe so retries stop. Use this for
				 *                         malformed event data the callback will
				 *                         never be able to process.
				 *   - Any other WP_Error: Transient failure — returned as-is so
				 *                         Stripe retries on its standard schedule.
				 *
				 * @param mixed                 $result     Initially null; callbacks may return true, WP_Error, or null.
				 * @param string                $event_type The Stripe event type.
				 * @param array<string, mixed>  $event_data The event object data.
				 * @param string                $mode       Payment mode (live/test).
				 * @param string                $account_id Connected Stripe account whose signing secret verified
				 *                                          this event. Callbacks that call the Stripe API back
				 *                                          must pass it as `stripe_api_request()`'s
				 *                                          `account_id` extra arg, or they will hit the default
				 *                                          account instead of the one that owns the event.
				 * @since 1.0.0
				 */
				$result = apply_filters( 'suredonation_webhook_handle_event', null, $event_type, $event_data, $mode, $account_id );

				// Only accept null (unhandled), true (success), or WP_Error (failure).
				// Reject other return types (e.g. false from buggy callbacks) to ensure Stripe retries.
				if ( null !== $result ) {
					if ( true === $result ) {
						return true;
					}

					if ( is_wp_error( $result ) ) {
						// Drop permanent-failure errors so Stripe stops retrying events
						// the pro callback will never be able to process (e.g. malformed
						// event data). Transient errors fall through and are returned
						// as-is so Stripe retries on its standard schedule.
						if ( in_array( $result->get_error_code(), [ 'permanent_failure', 'invalid_event' ], true ) ) {
							return true;
						}

						return $result;
					}

					return new \WP_Error( 'webhook_filter_invalid', __( 'Webhook filter returned unexpected value', 'suredonation' ) );
				}

				// Event not handled, but not an error.
				return true;
		}
	}

	/**
	 * Handle payment succeeded event.
	 *
	 * @param array<string, mixed> $data Payment intent data.
	 * @param string               $mode Payment mode.
	 * @return bool|\WP_Error True on success.
	 * @since 0.0.1
	 */
	private function handle_payment_succeeded( $data, $mode ) {
		$payment_intent_id = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';

		if ( empty( $payment_intent_id ) ) {
			return new \WP_Error( 'missing_payment_intent_id', 'Payment intent ID not found in event data' );
		}

		// Security: Verify payment amount matches expected amount.
		// This detects payment amount manipulation attacks.
		$actual_amount = isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? (int) $data['amount'] : 0;
		$currency      = isset( $data['currency'] ) && is_string( $data['currency'] ) ? $data['currency'] : 'usd';

		$amount_verification = Payment_Helper::verify_payment_intent_amount(
			$payment_intent_id,
			$actual_amount,
			$currency
		);

		if ( is_wp_error( $amount_verification ) ) {
			// Find donation and mark as suspicious for manual review.
			$donation = Donations::get_by_transaction_id( $payment_intent_id );
			if ( $donation && isset( $donation['id'] ) && is_numeric( $donation['id'] ) ) {
				$donation_id = absint( $donation['id'] );
				$error_data  = $amount_verification->get_error_data();
				Donations::update_status( $donation_id, 'suspicious' );
				Donations::add_log(
					$donation_id,
					'security_warning',
					$amount_verification->get_error_message(),
					[
						'payment_intent_id' => $payment_intent_id,
						'expected_amount'   => is_array( $error_data ) && isset( $error_data['expected'] ) ? $error_data['expected'] : 'unknown',
						'actual_amount'     => $actual_amount,
						'mode'              => $mode,
					]
				);
			}

			return new \WP_Error(
				'amount_mismatch',
				$amount_verification->get_error_message()
			);
		}

		// Find donation by transaction ID.
		$donation = Donations::get_by_transaction_id( $payment_intent_id );

		if ( ! $donation || ! isset( $donation['id'] ) || ! is_numeric( $donation['id'] ) ) {
			return new \WP_Error( 'donation_not_found', 'Donation record not found for transaction: ' . $payment_intent_id );
		}

		$donation_id = absint( $donation['id'] );

		// Update donation status to completed.
		$updated = Donations::update_status( $donation_id, 'completed' );

		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', 'Failed to update donation record' );
		}

		// Get fees covered from donation record.
		$fees_covered = isset( $donation['fees_covered'] ) && is_numeric( $donation['fees_covered'] ) ? (float) $donation['fees_covered'] : 0.0;

		// Add log entry.
		Donations::add_log(
			$donation_id,
			'payment_succeeded',
			__( 'Payment completed successfully via webhook', 'suredonation' ),
			[
				'payment_intent_id' => $payment_intent_id,
				'amount_verified'   => true,
				'fees_covered'      => $fees_covered,
				'mode'              => $mode,
			]
		);

		// Update donor statistics.
		if ( ! empty( $donation['donor_id'] ) ) {
			$donor_id_val = $donation['donor_id'];
			$amount_val   = $donation['amount'] ?? 0;
			$donor_id     = is_numeric( $donor_id_val ) ? (int) $donor_id_val : 0;
			$amount       = is_numeric( $amount_val ) ? (float) $amount_val : 0.0;
			if ( $donor_id > 0 ) {
				Donors::record_donation( $donor_id, $amount );
			}
		}

		// Send confirmation emails if not already sent by complete_donation().
		// Skip for recurring/renewal donations — pro plugin handles subscription emails.
		$was_pending     = 'pending' === ( $donation['payment_status'] ?? '' );
		$is_subscription = in_array( $donation['donation_type'] ?? '', [ 'recurring', 'renewal' ], true );
		if ( $was_pending && ! $is_subscription ) {
			$campaign_id   = isset( $donation['campaign_id'] ) && is_numeric( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;
			$form_id       = isset( $donation['form_id'] ) && is_numeric( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
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
		}

		return true;
	}

	/**
	 * Handle payment failed event.
	 *
	 * @param array<string, mixed> $data Payment intent data.
	 * @param string               $mode Payment mode.
	 * @return bool|\WP_Error True on success.
	 * @since 0.0.1
	 */
	private function handle_payment_failed( $data, $mode ) {
		$payment_intent_id = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';

		if ( empty( $payment_intent_id ) ) {
			return new \WP_Error( 'missing_payment_intent_id', 'Payment intent ID not found in event data' );
		}

		// Find donation by transaction ID.
		$donation = Donations::get_by_transaction_id( $payment_intent_id );

		if ( ! $donation || ! isset( $donation['id'] ) || ! is_numeric( $donation['id'] ) ) {
			return new \WP_Error( 'donation_not_found', 'Donation record not found' );
		}

		$donation_id = absint( $donation['id'] );

		// Update donation status to failed.
		Donations::update_status( $donation_id, 'failed' );

		// Get error message safely.
		$error_message = '';
		if ( isset( $data['last_payment_error'] ) && is_array( $data['last_payment_error'] ) && isset( $data['last_payment_error']['message'] ) ) {
			$error_message = is_string( $data['last_payment_error']['message'] ) ? $data['last_payment_error']['message'] : '';
		}

		// Add log entry.
		Donations::add_log(
			$donation_id,
			'payment_failed',
			__( 'Payment failed via webhook', 'suredonation' ),
			[
				'payment_intent_id' => $payment_intent_id,
				'mode'              => $mode,
				'error'             => $error_message,
			]
		);

		// Send donation failed emails.
		$campaign_id   = isset( $donation['campaign_id'] ) && is_numeric( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;
		$form_id       = isset( $donation['form_id'] ) && is_numeric( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
		$donation_data = [
			'id'            => $donation_id,
			'donor_name'    => $donation['donor_name'] ?? '',
			'donor_email'   => $donation['donor_email'] ?? '',
			'amount'        => $donation['amount'] ?? 0,
			'currency'      => $donation['currency'] ?? 'USD',
			'donation_type' => $donation['donation_type'] ?? 'one-time',
			'gateway'       => 'stripe',
		];

		Email_Handler::send_donation_failed( $donation_id, $campaign_id, $donation_data, $form_id );

		return true;
	}

	/**
	 * Handle charge.refund.updated event.
	 *
	 * This event is triggered when a refund status changes, including when created from Stripe dashboard.
	 * The event data contains a Refund object (not a Charge object).
	 * This matches the SureForms pattern for handling refunds.
	 *
	 * @param array<string, mixed> $refund Refund object data from webhook.
	 * @param string               $mode   Payment mode.
	 * @return bool|\WP_Error True on success.
	 * @since 0.0.1
	 */
	private function handle_refund_updated( $refund, $mode ) {
		$refund_id = isset( $refund['id'] ) && is_string( $refund['id'] ) ? $refund['id'] : '';

		// Extract payment identifiers from refund object.
		$payment_intent_id = isset( $refund['payment_intent'] ) && is_string( $refund['payment_intent'] ) ? $refund['payment_intent'] : '';
		$charge_id         = isset( $refund['charge'] ) && is_string( $refund['charge'] ) ? $refund['charge'] : '';

		$donation = null;

		// Method 1: Try to find donation by payment_intent.
		if ( ! empty( $payment_intent_id ) ) {
			$donation = Donations::get_by_transaction_id( $payment_intent_id );
		}

		// Method 2: Try to find by charge ID as fallback.
		if ( ! $donation && ! empty( $charge_id ) ) {
			$donation = Donations::get_by_transaction_id( $charge_id );
		}

		if ( ! $donation || ! isset( $donation['id'] ) || ! is_numeric( $donation['id'] ) ) {
			return new \WP_Error( 'donation_not_found', 'Donation record not found for refund' );
		}

		$donation_id = absint( $donation['id'] );

		// Extract refund details from the Refund object.
		$refund_amount_cents = isset( $refund['amount'] ) && is_numeric( $refund['amount'] ) ? (int) $refund['amount'] : 0;
		$currency            = isset( $refund['currency'] ) && is_string( $refund['currency'] ) ? strtolower( $refund['currency'] ) : 'usd';
		$refund_status       = isset( $refund['status'] ) && is_string( $refund['status'] ) ? $refund['status'] : 'unknown';

		// Handle refund cancellation.
		if ( 'canceled' === $refund_status ) {
			return $this->process_refund_cancellation( $donation, $refund, $currency, $mode );
		}

		// Only process succeeded refunds.
		if ( 'succeeded' !== $refund_status ) {
			return true; // Not an error, just skip.
		}

		// Check if this refund was already processed (duplicate prevention with lock).
		$lock_key = 'suredonation_refund_lock_' . $refund_id;
		if ( get_transient( $lock_key ) || Donations::check_refund_exists( $donation_id, $refund_id ) ) {
			return true; // Already processed or being processed.
		}
		set_transient( $lock_key, true, 60 );

		// Get current donation data.
		$original_amount   = isset( $donation['amount'] ) && is_numeric( $donation['amount'] ) ? (float) $donation['amount'] : 0;
		$existing_refunded = isset( $donation['refunded_amount'] ) && is_numeric( $donation['refunded_amount'] ) ? (float) $donation['refunded_amount'] : 0;
		$new_refund_amount = Payment_Helper::amount_from_stripe_format( $refund_amount_cents, $currency );
		$total_refunded    = $existing_refunded + $new_refund_amount;

		// Prevent over-refunding.
		if ( $total_refunded > $original_amount ) {
			return new \WP_Error( 'over_refund', 'Refund would exceed original donation amount' );
		}

		// Determine new payment status.
		$payment_status = 'completed';
		if ( $total_refunded >= $original_amount ) {
			$payment_status = 'refunded';
		} elseif ( $total_refunded > 0 ) {
			$payment_status = 'partially_refunded';
		}

		// Store refund in donation_data for audit trail and duplicate prevention.
		$refund_data = [
			'refund_id'      => $refund_id,
			'amount'         => absint( $refund_amount_cents ),
			'currency'       => strtoupper( $currency ),
			'status'         => $refund_status,
			'created'        => time(),
			'reason'         => isset( $refund['reason'] ) && is_string( $refund['reason'] ) ? $refund['reason'] : 'requested_by_customer',
			'description'    => isset( $refund['description'] ) && is_string( $refund['description'] ) ? $refund['description'] : '',
			'receipt_number' => isset( $refund['receipt_number'] ) && is_string( $refund['receipt_number'] ) ? $refund['receipt_number'] : '',
			'refunded_by'    => 'stripe_dashboard',
			'refunded_at'    => gmdate( 'Y-m-d H:i:s' ),
		];
		Donations::add_refund_to_donation_data( $donation_id, $refund_data );

		// Update donation with new refunded amount and status.
		Donations::update(
			$donation_id,
			[
				'payment_status'  => $payment_status,
				'refunded_amount' => $total_refunded,
			]
		);

		// Send refund processed emails.
		$campaign_id   = isset( $donation['campaign_id'] ) && is_numeric( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;
		$form_id       = isset( $donation['form_id'] ) && is_numeric( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
		$donation_data = [
			'id'            => $donation_id,
			'donor_name'    => $donation['donor_name'] ?? '',
			'donor_email'   => $donation['donor_email'] ?? '',
			'amount'        => $donation['amount'] ?? 0,
			'currency'      => strtoupper( $currency ),
			'refund_amount' => $new_refund_amount,
			'donation_type' => $donation['donation_type'] ?? 'one-time',
			'gateway'       => 'stripe',
		];

		Email_Handler::send_refund_processed( $donation_id, $campaign_id, $donation_data, $form_id );

		// Determine refund type for log message.
		$refund_type = $total_refunded >= $original_amount
			? __( 'Full', 'suredonation' )
			: __( 'Partial', 'suredonation' );

		// Add log entry.
		Donations::add_log(
			$donation_id,
			'refund',
			sprintf(
				/* translators: %s: Refund type (Full/Partial) */
				__( '%s refund processed via webhook', 'suredonation' ),
				$refund_type
			),
			[
				'refund_id'         => $refund_id,
				'refund_amount'     => $new_refund_amount,
				'total_refunded'    => $total_refunded,
				'original_amount'   => $original_amount,
				'payment_status'    => $payment_status,
				'currency'          => strtoupper( $currency ),
				'mode'              => $mode,
				'payment_intent_id' => $payment_intent_id,
			]
		);

		return true;
	}

	/**
	 * Process refund cancellation - reverses a previously processed refund.
	 *
	 * @param array<string, mixed> $donation Donation record.
	 * @param array<string, mixed> $refund   Refund object from Stripe webhook.
	 * @param string               $currency Currency code.
	 * @param string               $mode     Payment mode.
	 * @return bool|\WP_Error True on success.
	 * @since 0.0.1
	 */
	private function process_refund_cancellation( $donation, $refund, $currency, $mode ) {
		$refund_id   = isset( $refund['id'] ) && is_string( $refund['id'] ) ? $refund['id'] : '';
		$donation_id = isset( $donation['id'] ) && is_numeric( $donation['id'] ) ? absint( $donation['id'] ) : 0;

		if ( ! $donation_id ) {
			return new \WP_Error( 'invalid_donation', 'Invalid donation record for refund cancellation' );
		}

		// Use shared method to remove refund and get the refund data.
		$remove_result = Donations::remove_refund_from_donation_data( $donation_id, $refund_id );

		if ( ! $remove_result['removed'] ) {
			return true; // Not an error, just skip - refund may not have been tracked.
		}

		$existing_refund              = $remove_result['refund_data'];
		$canceled_refund_amount_cents = isset( $existing_refund['amount'] ) && is_numeric( $existing_refund['amount'] ) ? (int) $existing_refund['amount'] : 0;
		$refund_currency              = isset( $existing_refund['currency'] ) && is_string( $existing_refund['currency'] ) ? strtolower( $existing_refund['currency'] ) : $currency;

		if ( $canceled_refund_amount_cents <= 0 ) {
			return new \WP_Error( 'invalid_amount', 'Invalid refund cancellation amount' );
		}

		// Convert from Stripe format (cents) to decimal format.
		$canceled_refund_amount = Payment_Helper::amount_from_stripe_format( $canceled_refund_amount_cents, $refund_currency );

		// Calculate new refunded amount after cancellation.
		$current_refunded    = isset( $donation['refunded_amount'] ) && is_numeric( $donation['refunded_amount'] ) ? (float) $donation['refunded_amount'] : 0;
		$new_refunded_amount = max( 0, $current_refunded - $canceled_refund_amount );

		// Recalculate payment status.
		$original_amount = isset( $donation['amount'] ) && is_numeric( $donation['amount'] ) ? (float) $donation['amount'] : 0;
		$payment_status  = 'completed';

		if ( $new_refunded_amount >= $original_amount ) {
			$payment_status = 'refunded';
		} elseif ( $new_refunded_amount > 0 ) {
			$payment_status = 'partially_refunded';
		}

		// Extract failure reason from refund object.
		$failure_reason = isset( $refund['failure_reason'] ) && is_string( $refund['failure_reason'] ) ? $refund['failure_reason'] : 'unknown';

		// Update donation record with new status and refunded amount.
		Donations::update(
			$donation_id,
			[
				'payment_status'  => $payment_status,
				'refunded_amount' => $new_refunded_amount,
			]
		);

		// Add log entry.
		Donations::add_log(
			$donation_id,
			'refund_canceled',
			__( 'Refund canceled via webhook', 'suredonation' ),
			[
				'refund_id'           => $refund_id,
				'canceled_amount'     => $canceled_refund_amount,
				'remaining_refunded'  => $new_refunded_amount,
				'original_amount'     => $original_amount,
				'payment_status'      => $payment_status,
				'cancellation_reason' => $failure_reason,
				'currency'            => strtoupper( $refund_currency ),
				'mode'                => $mode,
			]
		);

		return true;
	}

	/**
	 * Handle payment canceled event.
	 *
	 * @param array<string, mixed> $data Payment intent data.
	 * @param string               $mode Payment mode.
	 * @return bool|\WP_Error True on success.
	 * @since 0.0.1
	 */
	private function handle_payment_canceled( $data, $mode ) {
		$payment_intent_id = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';

		if ( empty( $payment_intent_id ) ) {
			return new \WP_Error( 'missing_payment_intent_id', 'Payment intent ID not found' );
		}

		// Find donation by transaction ID.
		$donation = Donations::get_by_transaction_id( $payment_intent_id );

		if ( ! $donation || ! isset( $donation['id'] ) || ! is_numeric( $donation['id'] ) ) {
			return new \WP_Error( 'donation_not_found', 'Donation record not found' );
		}

		$donation_id = absint( $donation['id'] );

		// Update donation status to cancelled.
		Donations::update_status( $donation_id, 'cancelled' );

		// Add log entry.
		Donations::add_log(
			$donation_id,
			'payment_canceled',
			__( 'Payment canceled via webhook', 'suredonation' ),
			[
				'payment_intent_id' => $payment_intent_id,
				'mode'              => $mode,
			]
		);

		return true;
	}

	/**
	 * Log webhook event.
	 *
	 * @param string               $mode    Payment mode.
	 * @param string               $type    Event type (began, success, failure, error).
	 * @param string               $message Log message.
	 * @param array<string, mixed> $data    Additional data.
	 * @return void
	 * @since 0.0.1
	 */
	private function log_webhook_event( $mode, $type, $message, $data = [] ) {
		$transient_key = "suredonation_webhook_{$mode}_status";
		$status        = get_transient( $transient_key );

		if ( ! is_array( $status ) ) {
			$status = [];
		}

		$timestamp = time();

		switch ( $type ) {
			case 'began':
				$status['began_at'] = $timestamp;
				break;

			case 'success':
				$status['last_success_at'] = $timestamp;
				break;

			case 'failure':
			case 'error':
				$status['last_failure_at'] = $timestamp;
				$status['last_error']      = $message;
				break;
		}

		// Store event type if available.
		if ( ! empty( $data['type'] ) ) {
			$status['last_event_type'] = $data['type'];
		}

		set_transient( $transient_key, $status, DAY_IN_SECONDS );
	}

	/**
	 * Validate the source IP address of the webhook request.
	 *
	 * @return bool True if the IP is valid or IP checking is disabled.
	 * @since 0.0.1
	 */
	private function validate_source_ip() {
		// Check if IP validation is enabled in settings.
		$stripe_settings      = Payment_Helper::get_gateway_settings( 'stripe' );
		$enable_ip_validation = $stripe_settings['enable_webhook_ip_validation'] ?? false;

		if ( ! $enable_ip_validation ) {
			return true; // IP validation disabled.
		}

		$client_ip = $this->get_client_ip();
		if ( empty( $client_ip ) ) {
			return false; // Could not determine client IP.
		}

		// Get Stripe's current webhook IP ranges.
		$allowed_ips = $this->get_stripe_webhook_ips();

		foreach ( $allowed_ips as $allowed_range ) {
			if ( $this->ip_in_range( $client_ip, $allowed_range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get client IP address safely.
	 *
	 * Only uses REMOTE_ADDR for security-critical IP validation.
	 * Forwarded headers (X-Forwarded-For, CF-Connecting-IP, etc.) are
	 * spoofable by attackers and must not be trusted for IP whitelisting.
	 *
	 * @return string Client IP address or empty string if not found.
	 * @since 0.0.1
	 */
	private function get_client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		// Validate IP format.
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '';
	}

	/**
	 * Get Stripe webhook IP addresses.
	 *
	 * These are Stripe's documented webhook IP ranges.
	 * Updated as of 2024 - should be periodically reviewed.
	 *
	 * @return array<string> Array of IP ranges in CIDR notation.
	 * @since 0.0.1
	 */
	private function get_stripe_webhook_ips() {
		// Allow filtering for custom IP ranges or updates.
		return apply_filters(
			'suredonation_stripe_webhook_ips',
			[
				'3.18.12.0/26',
				'3.130.192.0/25',
				'13.235.14.237/32',
				'13.235.122.149/32',
				'18.211.135.69/32',
				'35.154.171.200/32',
				'52.15.183.38/32',
				'54.88.130.119/32',
				'54.88.130.237/32',
				'54.187.174.169/32',
				'54.187.205.235/32',
				'54.187.216.72/32',
			]
		);
	}

	/**
	 * Check if an IP address is within a given CIDR range.
	 *
	 * @param string $ip    The IP address to check.
	 * @param string $range The CIDR range (e.g., '192.168.1.0/24').
	 * @return bool True if IP is in range.
	 * @since 0.0.1
	 */
	private function ip_in_range( $ip, $range ) {
		if ( strpos( $range, '/' ) === false ) {
			// Single IP address.
			return $ip === $range;
		}

		[ $subnet, $mask ] = explode( '/', $range );

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		$mask_long   = -1 << 32 - (int) $mask;

		return ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long );
	}
}
