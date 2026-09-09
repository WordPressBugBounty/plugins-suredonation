<?php
/**
 * Charitable gateway and status translation tables.
 *
 * Centralises the mapping from Charitable gateway slugs / donation statuses to
 * SureDonation equivalents, plus runtime detection of whether a gateway
 * (and its Pro recurring handler) is currently loaded on this site.
 *
 * @package SureDonation
 * @since 1.5.1
 */

namespace SureDonation\Inc\Import\Charitable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Status_Map class.
 *
 * All methods are pure and stateless; safe to call statically anywhere.
 *
 * @since 1.5.1
 */
class Status_Map {
	/**
	 * Map a Charitable gateway slug to the SureDonation gateway slug we store
	 * on the donation row.
	 *
	 * `offline`, `paypal` and `stripe` pass through unchanged. PayPal Commerce
	 * collapses to `paypal`; Square variants collapse to `square`. Unknown
	 * slugs are preserved as-is so historical records keep their provenance
	 * even when SureDonation cannot transact through that gateway (same
	 * treatment as the GiveWP importer).
	 *
	 * @param  string $charitable_gateway Charitable gateway slug.
	 * @return string SureDonation gateway slug (may equal the input).
	 * @since  1.5.1
	 */
	public static function map_gateway( $charitable_gateway ) {
		$charitable_gateway = is_string( $charitable_gateway ) ? $charitable_gateway : '';

		$passthrough = [ 'offline', 'paypal', 'stripe' ];
		if ( in_array( $charitable_gateway, $passthrough, true ) ) {
			return $charitable_gateway;
		}

		$map = [
			'paypal_commerce' => 'paypal',
			'square_core'     => 'square',
			'square'          => 'square',
		];

		return $map[ $charitable_gateway ] ?? $charitable_gateway;
	}

	/**
	 * Map a Charitable donation status (post_status on the donation CPT) to
	 * the SureDonation `payment_status` value stored on the donations row.
	 *
	 * Charitable registers charitable-* post statuses; `charitable-completed`
	 * is labelled "Paid" and is the only revenue-bearing status by default.
	 * `charitable-preapproved` (authorized, not captured) maps to pending.
	 *
	 * @param  string $charitable_status Charitable donation post_status.
	 * @return string SureDonation payment_status value.
	 * @since  1.5.1
	 */
	public static function map_donation_status( $charitable_status ) {
		$charitable_status = is_string( $charitable_status ) ? $charitable_status : '';

		$map = [
			'charitable-completed'   => 'completed',
			'charitable-pending'     => 'pending',
			'charitable-preapproved' => 'pending',
			'charitable-refunded'    => 'refunded',
			'charitable-failed'      => 'failed',
			'charitable-cancelled'   => 'cancelled',
		];

		return $map[ $charitable_status ] ?? 'pending';
	}

	/**
	 * Map a Charitable Recurring plan status (post_status on the
	 * recurring_donation CPT) to SureDonation's subscription_status value.
	 *
	 * @param  string $charitable_status Charitable recurring donation post_status.
	 * @return string SureDonation subscription_status value.
	 * @since  1.5.1
	 */
	public static function map_subscription_status( $charitable_status ) {
		$charitable_status = is_string( $charitable_status ) ? $charitable_status : '';

		$map = [
			'charitable-active'    => 'active',
			'charitable-cancelled' => 'cancelled',
			'charitable-expired'   => 'expired',
			'charitable-completed' => 'completed',
			'charitable-failed'    => 'past_due',
			'charitable-pending'   => 'pending',
		];

		return $map[ $charitable_status ] ?? 'pending';
	}

	/**
	 * Map a Charitable CSV-export donation status LABEL to the SureDonation
	 * payment_status value.
	 *
	 * Charitable's donation export writes human labels ("Paid", "Pre Approved"),
	 * not status slugs; raw charitable-* slugs are also accepted and delegated
	 * to map_donation_status().
	 *
	 * @param  string $label Status label (or slug) from a CSV cell.
	 * @return string SureDonation payment_status value.
	 * @since  1.5.1
	 */
	public static function map_status_label( $label ) {
		$label = is_string( $label ) ? strtolower( trim( $label ) ) : '';

		if ( 0 === strpos( $label, 'charitable-' ) ) {
			return self::map_donation_status( $label );
		}

		$map = [
			'paid'         => 'completed',
			'pending'      => 'pending',
			'pre approved' => 'pending',
			'refunded'     => 'refunded',
			'failed'       => 'failed',
			'canceled'     => 'cancelled',
			'cancelled'    => 'cancelled',
		];

		return $map[ $label ] ?? 'pending';
	}

	/**
	 * Map a Charitable CSV-export gateway LABEL to the SureDonation gateway slug.
	 *
	 * @param  string $label Gateway label (or slug) from a CSV cell.
	 * @return string SureDonation gateway slug.
	 * @since  1.5.1
	 */
	public static function map_gateway_label( $label ) {
		$label = is_string( $label ) ? strtolower( trim( $label ) ) : '';

		$map = [
			'offline'         => 'offline',
			'offline payment' => 'offline',
			'paypal'          => 'paypal',
			'paypal commerce' => 'paypal',
			'stripe'          => 'stripe',
			'square'          => 'square',
		];

		if ( isset( $map[ $label ] ) ) {
			return $map[ $label ];
		}

		return self::map_gateway( sanitize_title( $label ) );
	}

	/**
	 * Check whether a SureDonation gateway is currently loaded on this site.
	 *
	 * Used by the migration tool to display per-gateway "live vs historical"
	 * badges in the data-found panel: records for gateways whose helper class
	 * is loaded will be fully functional after import; others import as
	 * historical-only ledger rows. (Square has no SureDonation gateway, so
	 * square records always import as historical.)
	 *
	 * @param  string $sd_gateway SureDonation gateway slug.
	 * @return bool True if the gateway's helper class is loaded (or offline, which is always available).
	 * @since  1.5.1
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
	 * Imported subscriptions for gateways whose handler is NOT loaded carry
	 * `gateway_live = false` in their donation_data so the donor dashboard can
	 * hide cancel/suspend/activate actions. This mitigates the
	 * default-to-Stripe fallback in
	 * suredonation-pro/inc/recurring/subscription-api.php:362.
	 *
	 * @param  string $sd_gateway SureDonation gateway slug.
	 * @return bool True if the gateway's subscription handler class is loaded.
	 * @since  1.5.1
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
