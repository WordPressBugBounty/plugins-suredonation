<?php
/**
 * PayPal API Payments Class.
 *
 * Handles PayPal API operations (refunds, order lookups) via middleware.
 * All calls use partner credentials through the middleware — no direct PayPal API calls.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Payments\PayPal;

use SureDonation\Inc\Payments\Payment_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal API Payments.
 *
 * @since 1.0.0
 */
class PayPal_Api_Payments {
	/**
	 * Refund a captured payment via middleware.
	 *
	 * @param string      $capture_id The capture ID to refund.
	 * @param float|null  $amount     Amount to refund (null for full refund).
	 * @param string|null $currency   Currency code for partial refund.
	 * @param string|null $mode       Payment mode ('test' or 'live').
	 * @since 1.0.0
	 * @return array<string, mixed>|\WP_Error Refund data or error.
	 */
	public static function refund_capture( $capture_id, $amount = null, $currency = null, $mode = null ) {
		if ( null === $mode ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$merchant_id = PayPal_Helper::get_paypal_merchant_id( $mode );

		$body = [
			'capture_id'  => $capture_id,
			'merchant_id' => $merchant_id,
			'environment' => PayPal_Helper::get_middleware_environment( $mode ),
		];

		// Partial refund — include amount.
		if ( null !== $amount && null !== $currency ) {
			$body['amount']   = PayPal_Helper::format_amount_for_paypal( $amount, $currency );
			$body['currency'] = strtoupper( $currency );
		}

		return PayPal_Helper::middleware_request( 'captures/refund', $body );
	}
}
