<?php
/**
 * PayPal Webhook Management.
 *
 * Handles creation and deletion of PayPal webhooks via middleware.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Payments\PayPal;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal_Webhook class.
 *
 * @since 1.0.0
 */
class PayPal_Webhook {
	/**
	 * Create a webhook for the specified mode via middleware.
	 *
	 * @param string $mode Payment mode ('test' or 'live').
	 * @return array<string, mixed>|\WP_Error Webhook data or error.
	 * @since 1.0.0
	 */
	public static function create_webhook( $mode ) {
		$merchant_id = PayPal_Helper::get_paypal_merchant_id( $mode );
		$webhook_url = PayPal_Helper::get_webhook_url( $mode );

		if ( empty( $merchant_id ) ) {
			return new \WP_Error( 'not_connected', __( 'PayPal is not connected.', 'suredonation' ) );
		}

		/**
		 * Filter the webhook event types to subscribe to.
		 *
		 * Pro adds subscription events (BILLING.SUBSCRIPTION.*, PAYMENT.SALE.*).
		 *
		 * @param array<string> $events Event type names.
		 * @param string        $mode   Payment mode.
		 * @since 1.0.0
		 */
		$event_types = apply_filters(
			'suredonation_paypal_webhook_events',
			[
				'CHECKOUT.ORDER.APPROVED',
				'PAYMENT.CAPTURE.COMPLETED',
				'PAYMENT.CAPTURE.DENIED',
				'PAYMENT.CAPTURE.REFUNDED',
			],
			$mode
		);

		$result = PayPal_Helper::middleware_request(
			'webhooks/create',
			[
				'merchant_id' => $merchant_id,
				'environment' => PayPal_Helper::get_middleware_environment( $mode ),
				'webhook_url' => $webhook_url,
				'event_types' => $event_types,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Store webhook ID.
		$webhook_id = isset( $result['id'] ) && is_string( $result['id'] ) ? $result['id'] : '';

		if ( ! empty( $webhook_id ) ) {
			$settings = PayPal_Helper::get_all_paypal_settings();

			if ( 'live' === $mode ) {
				$settings['webhook_live_id']  = $webhook_id;
				$settings['webhook_live_url'] = $webhook_url;
			} else {
				$settings['webhook_test_id']  = $webhook_id;
				$settings['webhook_test_url'] = $webhook_url;
			}

			PayPal_Helper::update_all_paypal_settings( $settings );
		}

		return [
			'webhook_id'  => $webhook_id,
			'webhook_url' => $webhook_url,
		];
	}

	/**
	 * Delete a webhook for the specified mode via middleware.
	 *
	 * @param string $mode Payment mode ('test' or 'live').
	 * @return true|\WP_Error True on success or error.
	 * @since 1.0.0
	 */
	public static function delete_webhook( $mode ) {
		$webhook_id  = PayPal_Helper::get_webhook_id( $mode );
		$merchant_id = PayPal_Helper::get_paypal_merchant_id( $mode );

		if ( empty( $webhook_id ) ) {
			return new \WP_Error( 'no_webhook', __( 'No webhook found to delete.', 'suredonation' ) );
		}

		$result = PayPal_Helper::middleware_request(
			'webhooks/delete',
			[
				'merchant_id' => $merchant_id,
				'environment' => PayPal_Helper::get_middleware_environment( $mode ),
				'webhook_id'  => $webhook_id,
			]
		);

		// Clear stored webhook data regardless of result.
		$settings = PayPal_Helper::get_all_paypal_settings();

		if ( 'live' === $mode ) {
			$settings['webhook_live_id']     = '';
			$settings['webhook_live_url']    = '';
			$settings['webhook_live_secret'] = '';
		} else {
			$settings['webhook_test_id']     = '';
			$settings['webhook_test_url']    = '';
			$settings['webhook_test_secret'] = '';
		}

		PayPal_Helper::update_all_paypal_settings( $settings );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
