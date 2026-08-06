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

		$event_type = isset( $event['event_type'] ) && is_string( $event['event_type'] ) ? $event['event_type'] : '';
		$resource   = isset( $event['resource'] ) && is_array( $event['resource'] ) ? $event['resource'] : [];
		$event_id   = isset( $event['id'] ) && is_string( $event['id'] ) ? $event['id'] : '';

		// Verify webhook signature.
		$verified = $this->validate_webhook_signature( $request, $mode );
		if ( is_wp_error( $verified ) ) {
			// A rejected delivery used to vanish without a trace, which is the
			// worst possible failure mode: the donor has been charged and
			// nothing anywhere records why nothing happened. Record the reason
			// against the donation before responding.
			$this->log_rejected_webhook( $verified, $event_type, $resource, $event_id, $mode );

			$response = [ 'error' => 'Signature verification failed' ];

			// This route is unauthenticated, so nothing beyond a fixed string is
			// returned by default: the error code alone would tell an anonymous
			// prober whether PayPal is configured for this mode, and the reason
			// can carry the middleware's own transport errors. Both are available
			// on the donation's activity log, and echoed here only while
			// debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$response['code']    = $verified->get_error_code();
				$response['message'] = $verified->get_error_message();
			}

			return new WP_REST_Response( $response, 400 );
		}

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

		$signature_headers = [
			'auth_algo'         => $headers['paypal_auth_algo'][0] ?? '',
			'cert_url'          => $headers['paypal_cert_url'][0] ?? '',
			'transmission_id'   => $headers['paypal_transmission_id'][0] ?? '',
			'transmission_sig'  => $headers['paypal_transmission_sig'][0] ?? '',
			'transmission_time' => $headers['paypal_transmission_time'][0] ?? '',
		];

		// The middleware rejects the call with a generic 400 if any of these is
		// empty, which is indistinguishable from a genuinely bad signature. Some
		// proxies strip or rename custom headers, so name the missing ones here
		// instead of letting them surface as an opaque verification failure.
		$missing = [];
		foreach ( $signature_headers as $header_key => $header_value ) {
			if ( '' === $header_value ) {
				$missing[] = $header_key;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'missing_signature_headers',
				sprintf(
					/* translators: %s: comma-separated list of missing PayPal header names. */
					__( 'PayPal signature headers missing from the request: %s.', 'suredonation' ),
					implode( ', ', $missing )
				),
				[ 'missing_headers' => $missing ]
			);
		}

		// GIT-33: /webhooks/verify-signature is an open middleware endpoint
		// (doesn't act on a merchant's behalf) — skip HMAC signing.
		$result = PayPal_Helper::middleware_request(
			'webhooks/verify-signature',
			array_merge(
				[
					'environment'   => $mode_environment,
					'webhook_id'    => $webhook_id,
					'webhook_event' => json_decode( $request->get_body(), true ),
				],
				$signature_headers
			),
			false
		);

		// Propagate the middleware's own code and message rather than flattening
		// them: the create path made the same change and it turned a useless
		// "Failed to create webhook." into an actionable
		// WEBHOOK_URL_ALREADY_EXISTS. A dropped payment webhook deserves at
		// least as much.
		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'verification_request_failed',
				sprintf(
					/* translators: 1: middleware error code, 2: middleware error message. */
					__( 'Webhook signature verification request failed (%1$s): %2$s', 'suredonation' ),
					$result->get_error_code(),
					$result->get_error_message()
				),
				$result->get_error_data()
			);
		}

		$verification_status = isset( $result['verification_status'] ) && is_string( $result['verification_status'] ) ? $result['verification_status'] : '';

		if ( 'SUCCESS' !== $verification_status ) {
			// PayPal answers a malformed verify call with an error body instead
			// of a verification_status, and the middleware passes that through
			// as a success, so report whatever actually came back.
			return new \WP_Error(
				'verification_status_not_success',
				sprintf(
					/* translators: %s: verification status reported by PayPal. */
					__( 'PayPal reported webhook verification status: %s', 'suredonation' ),
					'' !== $verification_status ? $verification_status : __( 'none returned', 'suredonation' )
				),
				[
					'verification_status' => $verification_status,
					'response'            => $result,
					'webhook_id'          => $webhook_id,
					'environment'         => $mode_environment,
				]
			);
		}

		return true;
	}

	/**
	 * Maximum rejection entries kept on a single donation's activity log.
	 *
	 * PayPal retries a delivery a handful of times, so a genuine problem needs
	 * only a few entries to be legible. The cap is what stops this route — which
	 * is unauthenticated by necessity — from being used to grow the `log` column
	 * without bound.
	 *
	 * @since 1.4.0
	 */
	private const MAX_REJECTION_LOGS = 5;

	/**
	 * Maximum stored length of a value taken from the webhook payload.
	 *
	 * @since 1.4.0
	 */
	private const MAX_LOGGED_VALUE_LENGTH = 200;

	/**
	 * Record a webhook that was rejected before it could be processed.
	 *
	 * Attaches the reason to the donation the event refers to, so it shows up in
	 * that donation's activity log. When no donation matches there is nothing to
	 * record against and the rejection is not logged: the payload is unverified
	 * at this point, so writing it anywhere an anonymous caller can reach would
	 * hand them an unbounded log.
	 *
	 * @param \WP_Error            $error          Rejection reason.
	 * @param string               $event_type     Event type from the payload.
	 * @param array<string, mixed> $event_resource Event resource from the payload.
	 * @param string               $event_id       PayPal event id from the payload.
	 * @param string               $mode           Payment mode.
	 * @return void
	 * @since 1.4.0
	 */
	private function log_rejected_webhook( $error, $event_type, $event_resource, $event_id, $mode ) {
		$donation = $this->find_donation_for_event( $event_type, $event_resource, $mode );

		if ( ! is_array( $donation ) || ! isset( $donation['id'] ) || ! is_numeric( $donation['id'] ) ) {
			return;
		}

		$donation_id = absint( $donation['id'] );
		$code        = $error->get_error_code();

		// One entry per donation per reason per minute. A repeated delivery of
		// the same broken event is the common case and does not need recording
		// twice; PayPal's own retries collapse into one line.
		$lock_key = 'suredonation_paypal_reject_' . md5( $donation_id . '|' . $code );
		if ( get_transient( $lock_key ) ) {
			return;
		}

		if ( $this->rejection_log_count( $donation ) >= self::MAX_REJECTION_LOGS ) {
			return;
		}

		set_transient( $lock_key, true, MINUTE_IN_SECONDS );

		// The activity log renders each data value as text, so a nested payload
		// would show up as "[object Object]" — exactly the reason that got lost
		// before. Flatten it to JSON so it stays readable, and keep it bounded:
		// the middleware response it can carry is not size-limited.
		$detail = $error->get_error_data();
		if ( null !== $detail && ! is_string( $detail ) ) {
			$encoded = wp_json_encode( $detail );
			$detail  = is_string( $encoded ) ? $encoded : '';
		}

		Donations::add_log(
			$donation_id,
			'webhook_rejected',
			__( 'PayPal webhook rejected before processing', 'suredonation' ),
			[
				// event_type and event_id come from an unverified request body,
				// so they are sanitised and capped before being stored.
				'event_type' => $this->clean_logged_value( $event_type ),
				'event_id'   => $this->clean_logged_value( $event_id ),
				'mode'       => $mode,
				'code'       => $code,
				// Capped like the rest: middleware_request() now appends the
				// gateway's own error text to this message, which is unbounded
				// and lands in the donations log column.
				'reason'     => $this->clean_logged_value( $error->get_error_message() ),
				'detail'     => $this->clean_logged_value( $detail ),
			]
		);
	}

	/**
	 * Read the originating capture id off a refund resource.
	 *
	 * A refund's own `resource.id` is the refund id, so the capture it reverses
	 * has to be taken from the `up` link.
	 *
	 * @param array<string, mixed> $event_resource Refund resource data.
	 * @return string Capture id, or an empty string when the link is absent.
	 * @since 1.4.0
	 */
	private static function extract_capture_id_from_refund( $event_resource ) {
		$links = $event_resource['links'] ?? [];

		if ( ! is_array( $links ) ) {
			return '';
		}

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || ! isset( $link['rel'] ) || 'up' !== $link['rel'] ) {
				continue;
			}

			// Typed rather than merely non-empty. This runs before the signature
			// is verified — find_donation_for_event() is reached from
			// log_rejected_webhook() — so the body is unauthenticated input, and
			// `! empty()` is satisfied by an array, which rtrim() then fatals on.
			// Every sibling branch below already checks the type.
			if ( empty( $link['href'] ) || ! is_string( $link['href'] ) ) {
				continue;
			}

			// Extract capture ID from URL.
			$parts = explode( '/', rtrim( $link['href'], '/' ) );
			return (string) end( $parts );
		}

		return '';
	}

	/**
	 * Count the rejection entries already recorded on a donation.
	 *
	 * Uses the row that was just fetched rather than re-reading it, so the cap
	 * costs no extra query.
	 *
	 * @param array<string, mixed> $donation Donation row.
	 * @return int Number of existing rejection entries.
	 * @since 1.4.0
	 */
	private function rejection_log_count( $donation ) {
		$log = $donation['log'] ?? [];

		if ( is_string( $log ) && '' !== $log ) {
			$log = json_decode( $log, true );
		}

		if ( ! is_array( $log ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $log as $entry ) {
			if ( is_array( $entry ) && 'webhook_rejected' === ( $entry['action'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Sanitise and cap a value pulled from an unverified webhook payload.
	 *
	 * @param mixed $value Raw value.
	 * @return string Value safe to store.
	 * @since 1.4.0
	 */
	private function clean_logged_value( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$value = sanitize_text_field( $value );

		return mb_substr( $value, 0, self::MAX_LOGGED_VALUE_LENGTH );
	}

	/**
	 * Resolve the donation a webhook event refers to.
	 *
	 * Subscription and sale events carry a subscription id; other events are
	 * matched on the id stored as the donation's transaction id. Refund events
	 * are the exception — their `resource.id` is the refund's own id, so the
	 * originating capture has to be read off the `up` link.
	 *
	 * The resolved row is confirmed to be a PayPal donation in this payment mode
	 * before it is returned, because the ids arrive unverified and a bare id
	 * lookup spans every gateway and both modes.
	 *
	 * @param string               $event_type     Event type.
	 * @param array<string, mixed> $event_resource Event resource data.
	 * @param string               $mode           Payment mode.
	 * @return array<string, mixed>|null Donation row, or null when none matches.
	 * @since 1.4.0
	 */
	private function find_donation_for_event( $event_type, $event_resource, $mode ) {
		$donation = null;

		if ( str_starts_with( $event_type, 'BILLING.SUBSCRIPTION.' ) ) {
			// The resource *is* the subscription.
			$subscription_id = isset( $event_resource['id'] ) && is_string( $event_resource['id'] ) ? $event_resource['id'] : '';
			$donation        = Donations::get_by_subscription_id( $subscription_id );
		} elseif ( 'PAYMENT.SALE.REFUNDED' === $event_type ) {
			// A refunded renewal names the sale it reverses, not the agreement.
			$sale_id  = isset( $event_resource['sale_id'] ) && is_string( $event_resource['sale_id'] ) ? $event_resource['sale_id'] : '';
			$donation = Donations::get_by_transaction_id( $sale_id );
		} elseif ( str_starts_with( $event_type, 'PAYMENT.SALE.' ) ) {
			// A renewal charge, linked by billing agreement id.
			$agreement_id = isset( $event_resource['billing_agreement_id'] ) && is_string( $event_resource['billing_agreement_id'] ) ? $event_resource['billing_agreement_id'] : '';
			$donation     = Donations::get_by_subscription_id( $agreement_id );
		} elseif ( 'PAYMENT.CAPTURE.REFUNDED' === $event_type ) {
			$donation = Donations::get_by_transaction_id( self::extract_capture_id_from_refund( $event_resource ) );
		} else {
			// Capture and order events carry the id stored as transaction_id.
			$transaction_id = isset( $event_resource['id'] ) && is_string( $event_resource['id'] ) ? $event_resource['id'] : '';
			$donation       = Donations::get_by_transaction_id( $transaction_id );
		}

		if ( ! is_array( $donation ) ) {
			return null;
		}

		// Only reject on a positive mismatch: rows written before these columns
		// were populated leave them empty, and those should still be annotated.
		$gateway = is_string( $donation['gateway'] ?? null ) ? $donation['gateway'] : '';
		if ( '' !== $gateway && 'paypal' !== $gateway ) {
			return null;
		}

		$donation_mode = is_string( $donation['payment_mode'] ?? null ) ? $donation['payment_mode'] : '';
		if ( '' !== $donation_mode && $mode !== $donation_mode ) {
			return null;
		}

		return $donation;
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
		$capture_id = self::extract_capture_id_from_refund( $event_resource );

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
