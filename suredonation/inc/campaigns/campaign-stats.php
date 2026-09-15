<?php
/**
 * Campaign Statistics Helper
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Campaigns;

use SureDonation\Inc\Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign_Stats class.
 *
 * @since 0.0.1
 */
class Campaign_Stats {
	/**
	 * Rows fetched into the cached recent-donations / top-donors transients.
	 *
	 * A fixed window (rather than the caller's limit) keeps one cache entry
	 * per campaign regardless of the block's display limit; callers slice
	 * down to what they need. Matches the largest fetch any block performs.
	 */
	private const LIST_CACHE_SIZE = 100;

	/**
	 * Get campaign statistics.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string, mixed> Campaign statistics.
	 * @since 0.0.1
	 */
	public static function get_stats( $campaign_id ) {
		global $wpdb;

		$donations_table = $wpdb->prefix . 'suredonation_donations';

		// Get total raised amount (completed and partially refunded donations, minus refunds).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_raised = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount - refunded_amount), 0)
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')",
				$donations_table,
				$campaign_id
			)
		);

		// Get total donation count (completed and partially refunded).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$donation_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')",
				$donations_table,
				$campaign_id
			)
		);

		// Get unique donor count (completed and partially refunded).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$donor_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT donor_email)
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')
				AND donor_email IS NOT NULL
				AND donor_email != ''",
				$donations_table,
				$campaign_id
			)
		);

		// Get goal type and amount from consolidated meta.
		$campaign_meta = Helper::get_campaign_meta( $campaign_id );
		$goal_type     = $campaign_meta['goal_type'];
		$goal_amount   = floatval( Helper::get_string_value( $campaign_meta['goal_amount'] ) );

		// Calculate progress percentage based on goal type.
		$progress_percentage = 0;
		if ( $goal_amount > 0 ) {
			if ( 'donation_count' === $goal_type ) {
				$progress_percentage = intval( $donation_count ) / $goal_amount * 100;
			} else {
				$progress_percentage = floatval( $total_raised ) / $goal_amount * 100;
			}
			$progress_percentage = min( $progress_percentage, 100 ); // Cap at 100%.
		}

		// Get average donation amount.
		$average_donation = 0;
		if ( $donation_count > 0 ) {
			$average_donation = floatval( $total_raised ) / intval( $donation_count );
		}

		// Get largest single donation (net of refunds).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$largest_donation = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(MAX(amount - refunded_amount), 0)
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')",
				$donations_table,
				$campaign_id
			)
		);

		// Get campaign status from consolidated meta.
		$campaign_status = $campaign_meta['campaign_status'];

		return [
			'total_raised'        => floatval( $total_raised ),
			'goal_amount'         => $goal_amount,
			'donation_count'      => intval( $donation_count ),
			'donor_count'         => intval( $donor_count ),
			'progress_percentage' => round( $progress_percentage, 2 ),
			'average_donation'    => round( $average_donation, 2 ),
			'largest_donation'    => floatval( $largest_donation ),
			'campaign_status'     => $campaign_status,
			'goal_type'           => $goal_type,
			'is_goal_reached'     => ( $goal_amount > 0 && ( 'donation_count' === $goal_type ? intval( $donation_count ) >= $goal_amount : floatval( $total_raised ) >= $goal_amount ) ),
		];
	}

	/**
	 * Get recent donations for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $limit Number of donations to retrieve.
	 * @return array<int, array<string, mixed>>|null Recent donations.
	 * @since 0.0.1
	 */
	public static function get_recent_donations( $campaign_id, $limit = 10 ) {
		global $wpdb;

		$donations_table = $wpdb->prefix . 'suredonation_donations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, (amount - refunded_amount) as amount, donor_name, donor_email, is_anonymous, created_at, payment_status
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')
				ORDER BY created_at DESC
				LIMIT %d",
				$donations_table,
				$campaign_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get top donors for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $limit Number of donors to retrieve.
	 * @return array<int, array<string, mixed>>|null Top donors.
	 * @since 0.0.1
	 */
	public static function get_top_donors( $campaign_id, $limit = 10 ) {
		global $wpdb;

		$donations_table = $wpdb->prefix . 'suredonation_donations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					donor_email,
					donor_name,
					SUM(amount - refunded_amount) as total_donated,
					COUNT(*) as donation_count,
					MAX(created_at) as last_donation_date
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')
				AND is_anonymous = 0
				AND donor_email IS NOT NULL
				AND donor_email != ''
				GROUP BY donor_email, donor_name
				ORDER BY total_donated DESC
				LIMIT %d",
				$donations_table,
				$campaign_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get the approved donor comments for a campaign.
	 *
	 * Only completed (or partially refunded) donations qualify, matching the
	 * recent-donations list — a comment on a pending or failed payment is not a
	 * donation the campaign received. `rejected` and `pending` comments are
	 * excluded here rather than filtered in PHP so a moderated comment never
	 * reaches the render path or the cache.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $limit Number of comments to retrieve.
	 * @return array<int, array<string, mixed>>|null Donor comments.
	 * @since 1.6.0
	 */
	public static function get_donor_comments( $campaign_id, $limit = 10 ) {
		global $wpdb;

		$donations_table = $wpdb->prefix . 'suredonation_donations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, (amount - refunded_amount) as amount, donor_name, donor_email, is_anonymous, donor_comment, created_at
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')
				AND donor_comment_status = 'approved'
				AND donor_comment IS NOT NULL
				AND donor_comment != ''
				ORDER BY created_at DESC
				LIMIT %d",
				$donations_table,
				$campaign_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get donation timeline (grouped by date).
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $days Number of days to include.
	 * @return array<int, array<string, mixed>>|null Donation timeline.
	 * @since 0.0.1
	 */
	public static function get_donation_timeline( $campaign_id, $days = 30 ) {
		global $wpdb;

		$donations_table = $wpdb->prefix . 'suredonation_donations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(created_at) as date,
					COUNT(*) as donation_count,
					SUM(amount - refunded_amount) as total_amount
				FROM %i
				WHERE campaign_id = %d
				AND payment_status IN ('completed', 'partially_refunded')
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY DATE(created_at)
				ORDER BY date ASC",
				$donations_table,
				$campaign_id,
				$days
			),
			ARRAY_A
		);
	}

	/**
	 * Get cached recent donations for a campaign.
	 *
	 * Caches a fixed window per campaign (see LIST_CACHE_SIZE) and slices to
	 * the requested limit, so the public campaign-donations block doesn't hit
	 * the database on every page view.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $limit Number of donations to return.
	 * @param int $cache_duration Cache duration in seconds (default: 5 minutes).
	 * @return array<int, array<string, mixed>> Recent donations.
	 * @since 1.0.0
	 */
	public static function get_cached_recent_donations( $campaign_id, $limit = 10, $cache_duration = 300 ) {
		$cache_key = 'suredonation_recent_donations_' . $campaign_id;
		$donations = get_transient( $cache_key );

		if ( false === $donations || ! is_array( $donations ) ) {
			$donations = self::get_recent_donations( $campaign_id, self::LIST_CACHE_SIZE );
			$donations = is_array( $donations ) ? $donations : [];
			set_transient( $cache_key, $donations, $cache_duration );
		}

		return array_slice( $donations, 0, max( 0, (int) $limit ) );
	}

	/**
	 * Get cached top donors for a campaign.
	 *
	 * Caches a fixed window per campaign (see LIST_CACHE_SIZE) and slices to
	 * the requested limit, so the public campaign-donors block doesn't hit
	 * the database on every page view.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $limit Number of donors to return.
	 * @param int $cache_duration Cache duration in seconds (default: 5 minutes).
	 * @return array<int, array<string, mixed>> Top donors.
	 * @since 1.0.0
	 */
	public static function get_cached_top_donors( $campaign_id, $limit = 10, $cache_duration = 300 ) {
		$cache_key = 'suredonation_top_donors_' . $campaign_id;
		$donors    = get_transient( $cache_key );

		if ( false === $donors || ! is_array( $donors ) ) {
			$donors = self::get_top_donors( $campaign_id, self::LIST_CACHE_SIZE );
			$donors = is_array( $donors ) ? $donors : [];
			set_transient( $cache_key, $donors, $cache_duration );
		}

		return array_slice( $donors, 0, max( 0, (int) $limit ) );
	}

	/**
	 * Get cached donor comments for a campaign.
	 *
	 * Caches a fixed window per campaign (see LIST_CACHE_SIZE) and slices to the
	 * requested limit, so the public donor-comments block doesn't hit the
	 * database on every page view.
	 *
	 * `$exclude_anonymous` is applied to the whole cached window before the
	 * slice, which is why it belongs here rather than in the caller. Filtering
	 * after a slice means a run of anonymous comments at the top can consume the
	 * entire slice and render an empty list while non-anonymous approved comments
	 * sit just past it. The cached window is materialised in full either way, so
	 * filtering it costs nothing extra. The transient is deliberately keyed per
	 * campaign only — it always holds the unfiltered window, so both callers
	 * share one entry.
	 *
	 * The ceiling is LIST_CACHE_SIZE: more than that many consecutive anonymous
	 * comments at the top of a campaign still yields an empty list. Far better
	 * than the caller's old `min( limit * 5, 100 )` over-fetch, which hit the
	 * same wall at 25 for the default limit, but not unbounded — stated here so
	 * the next reader does not have to re-derive it from the slice.
	 *
	 * @param int  $campaign_id Campaign post ID.
	 * @param int  $limit Number of comments to return.
	 * @param int  $cache_duration Cache duration in seconds (default: 5 minutes).
	 * @param bool $exclude_anonymous Drop comments left on anonymous donations.
	 * @return array<int, array<string, mixed>> Donor comments.
	 * @since 1.6.0
	 */
	public static function get_cached_donor_comments( $campaign_id, $limit = 10, $cache_duration = 300, $exclude_anonymous = false ) {
		$cache_key = 'suredonation_donor_comments_' . $campaign_id;
		$comments  = get_transient( $cache_key );

		if ( false === $comments || ! is_array( $comments ) ) {
			$comments = self::get_donor_comments( $campaign_id, self::LIST_CACHE_SIZE );
			$comments = is_array( $comments ) ? $comments : [];
			set_transient( $cache_key, $comments, $cache_duration );
		}

		if ( $exclude_anonymous ) {
			$comments = array_values(
				array_filter(
					$comments,
					static function ( $comment ) {
						return empty( $comment['is_anonymous'] );
					}
				)
			);
		}

		return array_slice( $comments, 0, max( 0, (int) $limit ) );
	}

	/**
	 * Clear campaign stats cache.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 * @since 0.0.1
	 */
	public static function clear_cache( $campaign_id ) {
		delete_transient( 'suredonation_stats_' . $campaign_id );
		delete_transient( 'suredonation_recent_donations_' . $campaign_id );
		delete_transient( 'suredonation_top_donors_' . $campaign_id );
		delete_transient( 'suredonation_donor_comments_' . $campaign_id );
	}

	/**
	 * Get cached campaign statistics.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $cache_duration Cache duration in seconds (default: 5 minutes).
	 * @return array<string, mixed> Campaign statistics.
	 * @since 0.0.1
	 */
	public static function get_cached_stats( $campaign_id, $cache_duration = 300 ) {
		$cache_key = 'suredonation_stats_' . $campaign_id;
		$stats     = get_transient( $cache_key );

		if ( false === $stats || ! is_array( $stats ) ) {
			$stats = self::get_stats( $campaign_id );
			set_transient( $cache_key, $stats, $cache_duration );
		}

		return $stats;
	}
}
