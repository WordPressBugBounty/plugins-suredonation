<?php
/**
 * GiveWP gateway and status translation tables.
 *
 * Centralises the mapping from GiveWP gateway slugs / donation statuses to
 * SureDonation equivalents, plus runtime detection of whether a gateway
 * (and its Pro recurring handler) is currently loaded on this site.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Status_Map class.
 *
 * All methods are pure and stateless; safe to call statically anywhere.
 *
 * @since 1.0.0
 */
class Status_Map {

	/**
	 * Map a GiveWP gateway slug to the SureDonation gateway slug we store on the donation row.
	 *
	 * Slugs that match exactly (stripe / paypal / mollie / razorpay) pass through
	 * unchanged. `manual` is renamed to `offline`. PayPal Commerce, PayPal Pro,
	 * PayPal Adaptive etc. collapse to `paypal`. Stripe sub-method gateways
	 * (stripe_checkout / stripe_ach / stripe_ideal / ...) collapse to `stripe`.
	 * Unknown slugs are preserved as-is so historical records keep their
	 * provenance even when SureDonation cannot transact through that gateway.
	 *
	 * @param  string $givewp_gateway GiveWP gateway slug.
	 * @return string SureDonation gateway slug (may equal the input).
	 * @since  1.0.0
	 */
	public static function map_gateway( $givewp_gateway ) {
		$givewp_gateway = is_string( $givewp_gateway ) ? $givewp_gateway : '';

		$passthrough = [ 'stripe', 'paypal', 'mollie', 'razorpay', 'offline' ];
		if ( in_array( $givewp_gateway, $passthrough, true ) ) {
			return $givewp_gateway;
		}

		$map = [
			'manual'            => 'offline',
			'paypalcommerce'    => 'paypal',
			'paypaladaptive'    => 'paypal',
			'paypal_pro'        => 'paypal',
			'paypal_express'    => 'paypal',
			'paypal_standard'   => 'paypal',
			'stripe_checkout'   => 'stripe',
			'stripe_ach'        => 'stripe',
			'stripe_ideal'      => 'stripe',
			'stripe_sepa'       => 'stripe',
			'stripe_apple_pay'  => 'stripe',
			'stripe_google_pay' => 'stripe',
			'stripe_becs'       => 'stripe',
			'stripe_bancontact' => 'stripe',
		];

		return isset( $map[ $givewp_gateway ] ) ? $map[ $givewp_gateway ] : $givewp_gateway;
	}

	/**
	 * Map a GiveWP donation status (post_status on the give_payment CPT) to
	 * the SureDonation `payment_status` value stored on the donations row.
	 *
	 * @param  string $givewp_status GiveWP donation post_status.
	 * @return string SureDonation payment_status value.
	 * @since  1.0.0
	 */
	public static function map_donation_status( $givewp_status ) {
		$givewp_status = is_string( $givewp_status ) ? $givewp_status : '';

		$map = [
			'publish'          => 'completed',
			'give_complete'    => 'completed',
			'give_pending'     => 'pending',
			'pending'          => 'pending',
			'give_processing'  => 'processing',
			'give_cancelled'   => 'cancelled',
			'give_abandoned'   => 'cancelled',
			'give_failed'      => 'failed',
			'give_revoked'     => 'cancelled',
			'give_preapproval' => 'pending',
			'refunded'         => 'refunded',
			'give_refunded'    => 'refunded',
		];

		return isset( $map[ $givewp_status ] ) ? $map[ $givewp_status ] : 'pending';
	}

	/**
	 * Map a GiveWP subscription status to SureDonation's subscription_status value.
	 *
	 * @param  string $givewp_status GiveWP subscription status.
	 * @return string SureDonation subscription_status value.
	 * @since  1.0.0
	 */
	public static function map_subscription_status( $givewp_status ) {
		$givewp_status = is_string( $givewp_status ) ? $givewp_status : '';

		$map = [
			'active'    => 'active',
			'cancelled' => 'cancelled',
			'expired'   => 'expired',
			'completed' => 'completed',
			'pending'   => 'pending',
			'failing'   => 'past_due',
			'suspended' => 'paused',
		];

		return isset( $map[ $givewp_status ] ) ? $map[ $givewp_status ] : 'pending';
	}

	/**
	 * Check whether a SureDonation gateway is currently loaded on this site.
	 *
	 * Used by the migration tool to display per-gateway "live vs historical"
	 * badges in the data-found panel: records for gateways whose helper class
	 * is loaded will be fully functional after import; others import as
	 * historical-only ledger rows.
	 *
	 * @param  string $sd_gateway SureDonation gateway slug.
	 * @return bool True if the gateway's helper class is loaded (or offline, which is always available).
	 * @since  1.0.0
	 */
	public static function is_gateway_live( $sd_gateway ) {
		if ( 'offline' === $sd_gateway ) {
			return true;
		}

		$detectors = [
			'stripe'   => 'SureDonation\\Inc\\Payments\\Stripe\\Stripe_Helper',
			'paypal'   => 'SureDonation\\Inc\\Payments\\PayPal\\PayPal_Helper',
			'mollie'   => 'SureDonation\\Inc\\Payments\\Mollie\\Mollie_Helper',
			'razorpay' => 'SureDonation\\Inc\\Payments\\Razorpay\\Razorpay_Helper',
		];

		if ( ! isset( $detectors[ $sd_gateway ] ) ) {
			return false;
		}

		return class_exists( $detectors[ $sd_gateway ] );
	}

	/**
	 * Check whether a SureDonation gateway's Pro recurring handler is loaded.
	 *
	 * Imported subscriptions for gateways whose handler is NOT loaded are
	 * stored with `subscription_status = 'imported-historical'` so the donor
	 * dashboard can hide cancel/suspend/activate actions. This mitigates the
	 * default-to-Stripe fallback in
	 * suredonation-pro/inc/recurring/subscription-api.php:362.
	 *
	 * @param  string $sd_gateway SureDonation gateway slug.
	 * @return bool True if the gateway's subscription handler class is loaded.
	 * @since  1.0.0
	 */
	public static function is_subscription_handler_live( $sd_gateway ) {
		$detectors = [
			'stripe'   => 'SureDonationPro\\Inc\\Recurring\\Subscription_Handler',
			'paypal'   => 'SureDonationPro\\Inc\\Payments\\PayPal\\PayPal_Subscription_Handler',
			'mollie'   => 'SureDonationPro\\Inc\\Payments\\Mollie\\Mollie_Subscription_Handler',
			'razorpay' => 'SureDonationPro\\Inc\\Payments\\Razorpay\\Razorpay_Subscription_Handler',
		];

		if ( ! isset( $detectors[ $sd_gateway ] ) ) {
			return false;
		}

		return class_exists( $detectors[ $sd_gateway ] );
	}
}
