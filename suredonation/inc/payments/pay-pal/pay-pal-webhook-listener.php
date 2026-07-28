<?php
/**
 * PayPal Webhook Listener.
 *
 * Handles incoming PayPal webhook events.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Payments\PayPal;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Traits\Get_Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal_Webhook_Listener class.
 *
 * @since 1.0.0
 */
class PayPal_Webhook_Listener {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register webhook REST endpoints.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			'suredonation',
			'/paypal_webhook_test',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook_test' ],
				'permission_callback' => '__return_true', // PayPal signature handles security.
			]
		);

		register_rest_route(
			'suredonation',
			'/paypal_webhook_live',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook_live' ],
				'permission_callback' => '__return_true', // PayPal signature handles security.
			]
		);
	}

	/**
	 * Handle test mode webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function handle_webhook_test( $request ) {
		return $this->handle_webhook( $request, 'test' );
	}

	/**
	 * Handle live mode webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	public function handle_webhook_live( $request ) {
		return $this->handle_webhook( $request, 'live' );
	}

	/**
	 * Handle webhook event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $mode    Payment mode.
	 * @return WP_REST_Response Response.
	 * @since 1.0.0
	 */
	private function handle_webhook( $request, $mode ) {
		$payload = $request->get_body();
		$event   = json_decode( $payload, true );

		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		// Verify webhook signature.
		$verified = $this->validate_webhook_signature( $request, $mode );
		if ( is_wp_error( $verified ) ) {
			return new WP_REST_Response( [ 'error' => 'Signature verification failed' ], 400 );
		}

		// Process event.
		$event_type = isset( $event['event_type'] ) && is_string( $event['event_type'] ) ? $event['event_type'] : '';
		$resource   = isset( $event['resource'] ) && is_array( $event['resource'] ) ? $event['resource'] : [];

		$result = $this->process_event( $event_type, $resource, $mode );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => 'Processing failed' ], 500 );
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Validate webhook signature via PayPal API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $mode    Payment mode.
	 * @return true|\WP_Error True on success, error on failure.
	 * @since 1.0.0
	 */
	private function validate_webhook_signature( $request, $mode ) {
		$webhook_id = PayPal_Helper::get_webhook_id( $mode );

		if ( empty( $webhook_id ) ) {
			return new \WP_Error( 'no_webhook_id', __( 'Webhook ID not configured.', 'suredonation' ) );
		}

		$headers = $request->get_headers();

		// Validate cert_url points to a PayPal domain to prevent SSRF.
		$cert_url = $headers['paypal_cert_url'][0] ?? '';
		if ( ! empty( $cert_url ) ) {
			$parsed_host = wp_parse_url( $cert_url, PHP_URL_HOST );
			if ( ! is_string( $parsed_host ) || ! preg_match( '/\.paypal\.com$/i', $parsed_host ) ) {
				return new \WP_Error( 'invalid_cert_url', __( 'Invalid PayPal certificate URL.', 'suredonation' ) );
			}
		}

		$mode_environment = PayPal_Helper::get_middleware_environment( $mode );

		// GIT-33: /webhooks/verify-signature is an open middleware endpoint
		// (doesn't act on a merchant's behalf) — skip HMAC signing.
		$result = PayPal_Helper::middleware_request(
			'webhooks/verify-signature',
			[
				'environment'       => $mode_environment,
				'webhook_id'        => $webhook_id,
				'auth_algo'         => $headers['paypal_auth_algo'][0] ?? '',
				'cert_url'          => $headers['paypal_cert_url'][0] ?? '',
				'transmission_id'   => $headers['paypal_transmission_id'][0] ?? '',
				'transmission_sig'  => $headers['paypal_transmission_sig'][0] ?? '',
				'transmission_time' => $headers['paypal_transmission_time'][0] ?? '',
				'webhook_event'     => json_decode( $request->get_body(), true ),
			],
			false
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'verification_failed', __( 'Webhook signature verification failed.', 'suredonation' ) );
		}

		$verification_status = $result['verification_status'] ?? '';

		if ( 'SUCCESS' !== $verification_status ) {
			return new \WP_Error( 'verification_failed', __( 'Webhook signature is invalid.', 'suredonation' ) );
		}

		return true;
	}

	/**
	 * Process a webhook event.
	 *
	 * @param string               $event_type     Event type.
	 * @param array<string, mixed> $event_resource Event resource data.
	 * @param string               $mode           Payment mode.
	 * @return true|\WP_Error True on success.
	 * @since 1.0.0
	 */
	private function process_event( $event_type, $event_resource, $mode ) {
		switch ( $event_type ) {
			case 'PAYMENT.CAPTURE.COMPLETED':
				return $this->handle_capture_completed( $event_resource );

			case 'PAYMENT.CAPTURE.DENIED':
				return $this->handle_capture_denied( $event_resource );

			case 'PAYMENT.CAPTURE.REFUNDED':
				return $this->handle_capture_refunded( $event_resource );

			default:
				/**
				 * Allow extensions to handle additional webhook events.
				 *
				 * Pro uses this for subscription events (BILLING.SUBSCRIPTION.*, PAYMENT.SALE.*).
				 *
				 * @param bool|\WP_Error|null   $result         null means unhandled.
				 * @param string                $event_type     PayPal event type.
				 * @param array<string, mixed>  $event_resource Event resource data.
				 * @param string                $mode           Payment mode.
				 * @since 1.0.0
				 */
				$result = apply_filters( 'suredonation_paypal_webhook_handle_event', null, $event_type, $event_resource, $mode );

				// Only accept null (unhandled), true (success), or WP_Error (failure).
				if ( null !== $result ) {
					if ( true === $result || is_wp_error( $result ) ) {
						return $result;
					}
					return new \WP_Error( 'webhook_filter_invalid', __( 'Webhook filter returned unexpected value', 'suredonation' ) );
				}

				return true;
		}
	}

	/**
	 * Handle PAYMENT.CAPTURE.COMPLETED event.
	 *
	 * Acts as a backup confirmation for payments that were already captured via the frontend flow.
	 *
	 * @param array<string, mixed> $event_resource Capture resource data.
	 * @return true|\WP_Error
	 * @since 1.0.0
	 */
	private function handle_capture_completed( $event_resource ) {
		$capture_id = isset( $event_resource['id'] ) && is_string( $event_resource['id'] ) ? $event_resource['id'] : '';

		if ( empty( $capture_id ) ) {
			return new \WP_Error( 'missing_capture_id', 'Capture ID not found in event data.' );
		}

		// Find donation by capture ID (transaction_id).
		$donation = Donations::get_by_transaction_id( $capture_id );
		if ( ! $donation ) {
			// May not exist yet if webhook arrives before frontend completes.
			return true;
		}

		$donation_id = isset( $donation['id'] ) && is_numeric( $donation['id'] ) ? absint( $donation['id'] ) : 0;

		// Idempotency — only update if still pending (avoid overwriting completed status).
		if ( 'pending' === ( $donation['payment_status'] ?? '' ) ) {
			// Verify captured amount matches expected (amount + fees).
			$amount_data     = is_array( $event_resource['amount'] ?? null ) ? $event_resource['amount'] : [];
			$captured_amount = isset( $amount_data['value'] ) ? (float) $amount_data['value'] : 0;
			$raw_amount      = $donation['amount'] ?? 0;
			$raw_fees        = $donation['fees_covered'] ?? 0;
			$donation_amount = is_numeric( $raw_amount ) ? (float) $raw_amount : 0.0;
			$donation_fees   = is_numeric( $raw_fees ) ? (float) $raw_fees : 0.0;
			$expected_amount = $donation_amount + $donation_fees;

			if ( $captured_amount > 0 && $expected_amount > 0 && abs( $captured_amount - $expected_amount ) > 0.01 ) {
				Donations::update_status( $donation_id, 'suspicious' );
				Donations::add_log(
					$donation_id,
					'security_warning',
					__( 'Captured amount does not match expected amount — marked suspicious', 'suredonation' ),
					[
						'captured' => $captured_amount,
						'expected' => $expected_amount,
					]
				);
				return true;
			}

			Donations::update_status( $donation_id, 'completed' );
			Donations::add_log(
				$donation_id,
				'completed',
				__( 'Payment confirmed via PayPal webhook', 'suredonation' ),
				[ 'capture_id' => $capture_id ]
			);

			// Send confirmation email only for verified amounts.
			$campaign_id = isset( $donation['campaign_id'] ) && is_numeric( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;
			$form_id     = isset( $donation['form_id'] ) && is_numeric( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;

			Email_Handler::send_donation_confirmation(
				$donation_id,
				$campaign_id,
				[
					'id'            => $donation_id,
					'donor_name'    => $donation['donor_name'] ?? '',
					'donor_email'   => $donation['donor_email'] ?? '',
					'amount'        => $donation['amount'] ?? 0,
					'fees_covered'  => $donation['fees_covered'] ?? 0,
					'currency'      => $donation['currency'] ?? 'USD',
					'gateway'       => 'paypal',
					'donation_type' => $donation['donation_type'] ?? 'one-time',
				],
				$form_id
			);
		}

		return true;
	}

	/**
	 * Handle PAYMENT.CAPTURE.DENIED event.
	 *
	 * @param array<string, mixed> $event_resource Capture resource data.
	 * @return true
	 * @since 1.0.0
	 */
	private function handle_capture_denied( $event_resource ) {
		$capture_id = isset( $event_resource['id'] ) && is_string( $event_resource['id'] ) ? $event_resource['id'] : '';

		if ( empty( $capture_id ) ) {
			return true;
		}

		$donation = Donations::get_by_transaction_id( $capture_id );
		if ( ! $donation ) {
			return true;
		}

		$donation_id = isset( $donation['id'] ) && is_numeric( $donation['id'] ) ? absint( $donation['id'] ) : 0;

		Donations::update_status( $donation_id, 'failed' );
		Donations::add_log(
			$donation_id,
			'failed',
			__( 'PayPal payment capture denied', 'suredonation' ),
			[ 'capture_id' => $capture_id ]
		);

		return true;
	}

	/**
	 * Handle PAYMENT.CAPTURE.REFUNDED event.
	 *
	 * @param array<string, mixed> $event_resource Refund resource data.
	 * @return true
	 * @since 1.0.0
	 */
	private function handle_capture_refunded( $event_resource ) {
		// The refund resource links back to the capture.
		$links      = $event_resource['links'] ?? [];
		$capture_id = '';

		if ( is_array( $links ) ) {
			foreach ( $links as $link ) {
				if ( isset( $link['rel'] ) && 'up' === $link['rel'] && ! empty( $link['href'] ) ) {
					// Extract capture ID from URL.
					$parts      = explode( '/', rtrim( $link['href'], '/' ) );
					$capture_id = end( $parts );
					break;
				}
			}
		}

		if ( empty( $capture_id ) ) {
			return true;
		}

		$donation = Donations::get_by_transaction_id( $capture_id );
		if ( ! $donation ) {
			return true;
		}

		$donation_id = isset( $donation['id'] ) && is_numeric( $donation['id'] ) ? absint( $donation['id'] ) : 0;
		$refund_id   = isset( $event_resource['id'] ) && is_string( $event_resource['id'] ) ? $event_resource['id'] : '';

		// Deduplicate: an admin-initiated refund already applied this refund via
		// the API path (recording the same PayPal refund id), and PayPal can
		// deliver the webhook more than once. Without this guard refunded_amount
		// is inflated and — new with the OttoKit integration — the
		// `suredonation_donation_refunded` automation fires a second time. Mirror
		// the Stripe webhook's transient lock + recorded-refund check.
		if ( '' !== $refund_id ) {
			$lock_key = 'suredonation_refund_lock_' . $refund_id;
			if ( get_transient( $lock_key ) || Donations::check_refund_exists( $donation_id, $refund_id ) ) {
				return true;
			}
			set_transient( $lock_key, true, MINUTE_IN_SECONDS );
		}

		$amount_data     = is_array( $event_resource['amount'] ?? null ) ? $event_resource['amount'] : [];
		$refund_amount   = isset( $amount_data['value'] ) && is_numeric( $amount_data['value'] ) ? (float) $amount_data['value'] : 0;
		$donation_amount = isset( $donation['amount'] ) && is_numeric( $donation['amount'] ) ? (float) $donation['amount'] : 0;
		$fees_covered    = isset( $donation['fees_covered'] ) && is_numeric( $donation['fees_covered'] ) ? (float) $donation['fees_covered'] : 0;
		$total_amount    = $donation_amount + $fees_covered;
		$prev_refunded   = isset( $donation['refunded_amount'] ) && is_numeric( $donation['refunded_amount'] ) ? (float) $donation['refunded_amount'] : 0;
		$total_refunded  = $prev_refunded + $refund_amount;

		$new_status = $total_refunded >= $total_amount ? 'refunded' : 'partially_refunded';

		Donations::update(
			$donation_id,
			[
				'payment_status'  => $new_status,
				'refunded_amount' => number_format( $total_refunded, 2, '.', '' ),
			]
		);

		// Record the refund id so repeat webhook deliveries are recognised by
		// check_refund_exists() above.
		if ( '' !== $refund_id ) {
			Donations::add_refund_to_donation_data(
				$donation_id,
				[
					'refund_id'      => $refund_id,
					'refund_amount'  => $refund_amount,
					'total_refunded' => $total_refunded,
					'gateway'        => 'paypal',
					'status'         => $new_status,
				]
			);
		}

		Donations::add_log(
			$donation_id,
			$new_status,
			__( 'Refund received via PayPal webhook', 'suredonation' ),
			[
				'refund_amount'  => $refund_amount,
				'total_refunded' => $total_refunded,
				'refund_id'      => $refund_id,
			]
		);

		return true;
	}
}
