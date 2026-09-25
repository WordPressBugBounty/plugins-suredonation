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
			$error = new \WP_Error( 'not_connected', __( 'PayPal is not connected.', 'suredonation' ) );
			PayPal_Helper::set_webhook_error( $mode, $error->get_error_message() );

			return $error;
		}

		// This route reaches the middleware before register_routing() does, and
		// the middleware records the endpoint on that first call -- so guarding
		// only the later call would let a restored copy take the live site's
		// stream anyway. Same check, applied before anything leaves the site.
		if ( ! self::host_matches_binding( $mode ) ) {
			$error = new \WP_Error(
				'relay_host_mismatch',
				__( 'This site\'s address does not match the site PayPal is connected to. If this is a copy of a live site, connecting here will stop PayPal events reaching the live site. Disconnect and reconnect PayPal if you intend to move the connection to this site.', 'suredonation' )
			);
			PayPal_Helper::set_webhook_error( $mode, $error->get_error_message() );

			return $error;
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
				'BILLING.SUBSCRIPTION.CREATED',
				'BILLING.SUBSCRIPTION.ACTIVATED',
				'BILLING.SUBSCRIPTION.UPDATED',
				'BILLING.SUBSCRIPTION.CANCELLED',
				'BILLING.SUBSCRIPTION.SUSPENDED',
				'BILLING.SUBSCRIPTION.EXPIRED',
				'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
				'PAYMENT.SALE.COMPLETED',
				'PAYMENT.SALE.REFUNDED',
				'PAYMENT.SALE.REVERSED',
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
			// Recorded rather than only returned. Onboarding calls this and
			// discards the result, so a merchant could connect "successfully"
			// with no webhook and no one — merchant or support — would know it
			// had even been attempted.
			PayPal_Helper::set_webhook_error( $mode, $result->get_error_message() );

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

		// A webhook now exists, so any recorded failure is stale.
		PayPal_Helper::set_webhook_error( $mode, '' );

		return [
			'webhook_id'  => $webhook_id,
			'webhook_url' => $webhook_url,
		];
	}

	/**
	 * Register this site for relayed webhook delivery.
	 *
	 * Creates nothing at PayPal. The middleware owns one webhook per partner
	 * app and forwards to the owning site, which removes both the 10-per-app
	 * slot cap and the broadcast delivery that sent every merchant's events to
	 * every registered endpoint.
	 *
	 * @param string $mode   Payment mode ('test' or 'live').
	 * @param int    $before  Claim cursor from a previous run; 0 for the first page.
	 * @return array<string, mixed>|\WP_Error Registration result or error.
	 * @since 1.6.1
	 */
	public static function register_routing( $mode, $before = 0 ) {
		$merchant_id = PayPal_Helper::get_paypal_merchant_id( $mode );
		if ( empty( $merchant_id ) ) {
			return new \WP_Error( 'not_connected', __( 'PayPal is not connected.', 'suredonation' ) );
		}

		// Checked here, not only at the cron caller. A restored copy of a live
		// site carries the tracking id, hmac secret and merchant id, so it can
		// sign as the original -- and the middleware holds ONE endpoint per
		// merchant, so registering from the copy silently takes the live site's
		// event stream. The live site never re-registers, because it still
		// believes it is registered. Every caller must pass this, including the
		// Create button and the connect callback.
		if ( ! self::host_matches_binding( $mode ) ) {
			return new \WP_Error(
				'relay_host_mismatch',
				__( 'This site\'s address does not match the site PayPal is connected to. If this is a copy of a live site, connecting here will stop PayPal events reaching the live site. Disconnect and reconnect PayPal if you intend to move the connection to this site.', 'suredonation' )
			);
		}

		$claims = self::collect_subscription_ids( $mode, 10, $before );

		$result = PayPal_Helper::middleware_request(
			'webhooks/register',
			[
				'environment'      => PayPal_Helper::get_middleware_environment( $mode ),
				'webhook_url'      => PayPal_Helper::get_webhook_url( $mode ),
				// Subscriptions created before the relay are absent from the
				// middleware's routing map and cannot be recovered from its side:
				// it never stored them, and the partner credentials are not
				// granted Transaction Search. This site does hold them, so it
				// claims its own -- and the middleware verifies every claim
				// against PayPal before writing it.
				'subscription_ids' => $claims['ids'],
			],
			// Sign as $mode, not as the site's active mode. This runs for both
			// modes on an admin load, so the inactive one would otherwise be
			// signed with the active mode's tracking id and rejected -- leaving
			// it silently unroutable, which is the failure this call prevents.
			true,
			$mode
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = is_array( $result ) ? $result : [];

		// Recorded here, not at the call sites. The guard in relay_signature_for()
		// skips on an empty value, so a path that registers without writing it
		// leaves the guard inert -- which is what happened when only the cron
		// route recorded it: already-connected sites never re-register, and a
		// DISABLE_WP_CRON site registers inline at connect time. Both are exactly
		// the populations the guard exists to protect.
		self::record_relay_host( $mode );

		// Hand the caller the cursor so the next run continues the drain rather
		// than re-sending the same page.
		$result['claim_cursor'] = $claims['cursor'];
		$result['claim_count']  = count( $claims['ids'] );

		return $result;
	}

	/**
	 * Whether this site is the one the relay binding was recorded against.
	 *
	 * True when nothing is recorded yet, so a first registration is unaffected.
	 *
	 * @param string $mode Payment mode ('test' or 'live').
	 * @return bool
	 * @since 1.6.1
	 */
	public static function host_matches_binding( $mode ) {
		// get_option() returns mixed, and ! empty() does not narrow it.
		$bound_host = get_option( 'suredonation_paypal_relay_host_' . $mode );
		$bound_host = is_string( $bound_host ) ? $bound_host : '';

		if ( '' === $bound_host ) {
			return true;
		}

		return PayPal_Helper::current_host() === PayPal_Helper::normalise_host( $bound_host );
	}

	/**
	 * Remember the host a relay registration was made from.
	 *
	 * The binding travels with the database, so this is what lets a later
	 * registration tell "this site moved" from "this database was copied
	 * somewhere else". Written on every successful registration rather than at
	 * one call site, so no route can register without it.
	 *
	 * @param string $mode Payment mode ('test' or 'live').
	 * @return void
	 * @since 1.6.1
	 */
	public static function record_relay_host( $mode ) {
		update_option( 'suredonation_paypal_relay_host_' . $mode, PayPal_Helper::current_host(), false );
	}

	/**
	 * This site's own PayPal subscription ids for the given mode.
	 *
	 * Bounded: the middleware verifies each claim with its own PayPal call and
	 * caps a single request, reporting the remainder as `skipped` for the next
	 * registration to pick up.
	 *
	 * GROUP BY, not DISTINCT -- renewals repeat the parent's subscription_id,
	 * so without collapsing them this would return the set of charges rather
	 * than the set of subscriptions.
	 *
	 * Ordered by MAX(id), not by subscription_id: PayPal's I- ids are not
	 * chronological, so ordering by them would make *which* subscriptions get
	 * claimed when the limit bites a matter of lexical chance. (DISTINCT cannot
	 * be used here -- MySQL rejects ORDER BY on a column outside the select
	 * list when DISTINCT is applied, which GROUP BY allows via an aggregate.)
	 *
	 * $before returns the page older than a previous call's cursor. Without it
	 * every call returned the same newest ten forever, so a site with more than
	 * ten pre-relay subscriptions could never claim the rest and their renewals
	 * stayed unrouted -- the exact failure the claim mechanism exists to
	 * prevent. Newer subscriptions need no page of their own: the middleware
	 * maps those when it creates them.
	 *
	 * The id shape is filtered in SQL rather than afterwards, so a mislabelled
	 * row cannot consume a slot in the page and stall the drain.
	 *
	 * @param string $mode   Payment mode ('test' or 'live').
	 * @param int    $limit  Maximum ids to return.
	 * @param int    $before Only rows older than this cursor; 0 for the newest page.
	 * @return array{ids: array<int, string>, cursor: int} Ids and the cursor for the next page.
	 * @since 1.6.1
	 */
	public static function collect_subscription_ids( $mode, $limit = 10, $before = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'suredonation_donations';

		$before = absint( $before );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP_Query equivalent; runs at most once per registration page, so caching would be pure overhead.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT subscription_id, MAX(id) AS newest FROM %i
				 WHERE gateway = %s
				   AND payment_mode = %s
				   AND subscription_id != %s
				   AND subscription_id LIKE %s
				 GROUP BY subscription_id
				 HAVING ( %d = 0 OR newest < %d )
				 ORDER BY newest DESC
				 LIMIT %d',
				$table,
				'paypal',
				'live' === $mode ? 'live' : 'test',
				'',
				'I-%',
				$before,
				$before,
				absint( $limit )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return [
				'ids'    => [],
				'cursor' => 0,
			];
		}

		$ids    = [];
		$cursor = 0;

		foreach ( $rows as $row ) {
			$id = isset( $row['subscription_id'] ) ? (string) $row['subscription_id'] : '';

			// Belt and braces: LIKE 'I-%' is not the full shape.
			if ( 1 !== preg_match( '/^I-[A-Z0-9]{6,32}$/', $id ) ) {
				continue;
			}

			$ids[]  = $id;
			$newest = isset( $row['newest'] ) ? absint( $row['newest'] ) : 0;

			// Rows arrive newest-first, so the last one is the oldest in the
			// page and therefore where the next page starts.
			if ( $newest > 0 ) {
				$cursor = $newest;
			}
		}

		return [
			'ids'    => $ids,
			'cursor' => $cursor,
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
			$settings['webhook_live_id']  = '';
			$settings['webhook_live_url'] = '';
			// Legacy: nothing writes this any more, but an old install may hold a
			// value and it must not outlive the webhook it belonged to.
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
