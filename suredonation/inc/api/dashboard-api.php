<?php
/**
 * Dashboard REST API endpoints.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Payments\Payment_Helper;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard API class.
 *
 * @since 0.0.1
 */
class Dashboard_API {
	/**
	 * Get dashboard endpoints.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 0.0.1
	 */
	public function get_endpoints() {
		return [
			// Get dashboard statistics.
			'/dashboard/stats'            => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],

			// Get recent donations for dashboard.
			'/dashboard/recent-donations' => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_recent_donations' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'limit' => [
						'default'           => 5,
						'sanitize_callback' => 'absint',
					],
				],
			],

			// Get top campaigns for dashboard.
			'/dashboard/top-campaigns'    => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_top_campaigns' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'limit' => [
						'default'           => 5,
						'sanitize_callback' => 'absint',
					],
				],
			],

			// Get top donors for dashboard.
			'/dashboard/top-donors'       => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_top_donors' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'limit' => [
						'default'           => 5,
						'sanitize_callback' => 'absint',
					],
				],
			],

			// Get donation trends for chart.
			'/dashboard/trends'           => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_trends' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'after'  => [
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $param ) {
							return empty( $param ) || false !== strtotime( $param );
						},
					],
					'before' => [
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $param ) {
							return empty( $param ) || false !== strtotime( $param );
						},
					],
					'group'  => [
						'default' => 'day',
						'enum'    => [ 'day', 'week', 'month' ],
					],
				],
			],
		];
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_stats( $request ) {
		unset( $request ); // Unused parameter.

		$stats    = Donations::get_dashboard_stats();
		$currency = Payment_Helper::get_currency();

		// Count active (published) campaigns.
		$active_campaigns = wp_count_posts( 'suredonation_cmpgn' );
		$active_count     = isset( $active_campaigns->publish ) ? (int) $active_campaigns->publish : 0;

		return new WP_REST_Response(
			[
				'success' => true,
				'stats'   => [
					'total_donations'  => (int) $stats['total_donations'],
					'total_raised'     => (float) $stats['total_raised'],
					'unique_donors'    => (int) $stats['unique_donors'],
					'average_donation' => (float) $stats['average_donation'],
					'largest_donation' => (float) $stats['largest_donation'],
					'active_campaigns' => $active_count,
					'currency'         => $currency,
				],
			],
			200
		);
	}

	/**
	 * Get recent donations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_recent_donations( $request ) {
		$limit     = $request->get_param( 'limit' );
		$donations = Donations::get_recent_donations_global( $limit );
		$currency  = Payment_Helper::get_currency();

		$formatted = [];
		foreach ( $donations as $donation ) {
			$campaign_id_val = $donation['campaign_id'] ?? 0;
			$campaign_id     = is_numeric( $campaign_id_val ) ? (int) $campaign_id_val : 0;
			$id_val          = $donation['id'] ?? 0;
			$amount_val      = $donation['amount'] ?? 0;

			$formatted[] = [
				'id'             => is_numeric( $id_val ) ? (int) $id_val : 0,
				'donor_name'     => $donation['donor_name'] ?? '',
				'donor_email'    => $donation['donor_email'] ?? '',
				'amount'         => is_numeric( $amount_val ) ? (float) $amount_val : 0.0,
				'currency'       => $donation['currency'] ?? $currency,
				'campaign_id'    => $campaign_id,
				'campaign_title' => $campaign_id ? get_the_title( $campaign_id ) : '',
				'is_anonymous'   => ! empty( $donation['is_anonymous'] ),
				'donation_type'  => $donation['donation_type'] ?? 'one-time',
				'gateway'        => $donation['gateway'] ?? '',
				'payment_status' => $donation['payment_status'] ?? 'pending',
				'created_at'     => $donation['created_at'] ?? '',
			];
		}

		return new WP_REST_Response(
			[
				'success'   => true,
				'donations' => $formatted,
			],
			200
		);
	}

	/**
	 * Get top campaigns.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_top_campaigns( $request ) {
		$limit     = $request->get_param( 'limit' );
		$campaigns = Donations::get_top_campaigns( $limit );
		$currency  = Payment_Helper::get_currency();

		$formatted = [];
		foreach ( $campaigns as $campaign ) {
			$campaign_id = isset( $campaign['campaign_id'] ) ? (int) $campaign['campaign_id'] : 0;
			$post        = get_post( $campaign_id );

			if ( ! $post || SUREDONATION_POST_TYPE !== $post->post_type ) {
				continue;
			}

			// Get goal amount from post meta.
			$goal_amount       = get_post_meta( $campaign_id, '_suredonation_goal_amount', true );
			$goal_amount_float = is_numeric( $goal_amount ) ? (float) $goal_amount : null;

			$formatted[] = [
				'id'             => $campaign_id,
				'title'          => $post->post_title,
				'total_raised'   => (float) $campaign['total_raised'],
				'donation_count' => (int) $campaign['donation_count'],
				'unique_donors'  => (int) $campaign['unique_donors'],
				'goal_amount'    => $goal_amount_float,
				'currency'       => $currency,
			];
		}

		return new WP_REST_Response(
			[
				'success'   => true,
				'campaigns' => $formatted,
			],
			200
		);
	}

	/**
	 * Get top donors.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_top_donors( $request ) {
		$limit    = $request->get_param( 'limit' );
		$donors   = Donors::get_top_donors( $limit );
		$currency = Payment_Helper::get_currency();

		$formatted = [];
		foreach ( $donors as $donor ) {
			$id_val      = $donor['id'] ?? 0;
			$donated_val = $donor['total_donated'] ?? 0;
			$count_val   = $donor['donation_count'] ?? 0;

			$formatted[] = [
				'id'             => is_numeric( $id_val ) ? (int) $id_val : 0,
				'name'           => $donor['name'] ?? '',
				'email'          => $donor['email'] ?? '',
				'total_donated'  => is_numeric( $donated_val ) ? (float) $donated_val : 0.0,
				'donation_count' => is_numeric( $count_val ) ? (int) $count_val : 0,
				'currency'       => $currency,
			];
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'donors'  => $formatted,
			],
			200
		);
	}

	/**
	 * Get donation trends for chart.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 0.0.1
	 */
	public function get_trends( $request ) {
		$after    = $request->get_param( 'after' );
		$before   = $request->get_param( 'before' );
		$group    = $request->get_param( 'group' );
		$trends   = Donations::get_donation_trends( $after, $before, $group );
		$currency = Payment_Helper::get_currency();

		$formatted = [];
		foreach ( $trends as $trend ) {
			$formatted[] = [
				'period'         => $trend['period'] ?? '',
				'donation_count' => isset( $trend['donation_count'] ) ? (int) $trend['donation_count'] : 0,
				'total_amount'   => isset( $trend['total_amount'] ) ? (float) $trend['total_amount'] : 0,
			];
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'trends'   => $formatted,
				'currency' => $currency,
			],
			200
		);
	}

	/**
	 * Check if user has permission to view dashboard.
	 *
	 * @return bool True if user has permission.
	 * @since 0.0.1
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}
}
