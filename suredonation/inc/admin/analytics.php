<?php
/**
 * Analytics class helps to connect BSF Analytics.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Offline\Offline_Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\PayPal\PayPal_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use SureDonation\Inc\Privacy\Privacy_Settings;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics class.
 *
 * @since 1.0.0
 */
class Analytics {
	use Get_Instance;

	/**
	 * BSF_Analytics_Events instance for one-time event tracking.
	 *
	 * @var \BSF_Analytics_Events|null
	 * @since 1.0.0
	 */
	private static $events = null;

	/**
	 * Request-cached donation aggregates.
	 *
	 * @var array<string, int>|null
	 * @since 1.0.0
	 */
	private static $donation_aggregates = null;

	/**
	 * Class constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		/*
		 * Entity registration is deferred to init priority 0 so that add-on
		 * plugins (e.g. SureDonation Pro) registering filters such as
		 * suredonation_deactivation_survey_data on plugins_loaded are in
		 * place before the deactivation survey data is filtered, and the
		 * entity is still set before the BSF Analytics loader consumes it on
		 * init priority 10.
		 */
		add_action( 'init', [ $this, 'register_entity' ], 0 );

		add_filter( 'bsf_core_stats', [ $this, 'add_suredonation_analytics_data' ] );

		// Keep analytics sends (and their stat queries) off the frontend.
		add_filter( 'suredonation_tracking_enabled', [ $this, 'restrict_tracking_to_admin' ] );

		// Event tracking hooks. Registered outside is_admin() on purpose —
		// onboarding completion and campaign publishes fire during REST requests.
		add_action( 'suredonation_onboarding_user_details_saved', [ $this, 'track_onboarding_completed' ] );
		add_action( 'transition_post_status', [ $this, 'track_first_campaign_published' ], 10, 3 );
		add_action( 'current_screen', [ $this, 'track_first_campaign_editor_opened' ] );
		add_action( 'suredonation_privacy_data_exported', [ $this, 'track_privacy_data_exported' ] );
		add_action( 'suredonation_privacy_data_erased', [ $this, 'track_privacy_data_erased' ] );

		// Detect state-based events (daily throttle; dedup prevents repeat
		// tracking). Admin-only so the detection never runs on the frontend.
		if ( is_admin() ) {
			$this->detect_state_events();
		}
	}

	/**
	 * Register the SureDonation entity with the BSF Analytics loader.
	 *
	 * Runs on init priority 0 — after add-on plugins have registered their
	 * filters on plugins_loaded, and before the loader's own init callback
	 * loads the analytics library.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_entity() {
		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			require_once SUREDONATION_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-loader.php';
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			require_once SUREDONATION_DIR . 'inc/lib/astra-notices/class-bsf-admin-notices.php';
		}

		/**
		 * The loader's get_instance() carries no return type.
		 *
		 * @var \BSF_Analytics_Loader $suredonation_bsf_analytics
		 */
		$suredonation_bsf_analytics = \BSF_Analytics_Loader::get_instance();

		$suredonation_bsf_analytics->set_entity(
			[
				'suredonation' => [
					'product_name'        => 'SureDonation',
					'path'                => SUREDONATION_DIR . 'inc/lib/bsf-analytics',
					'author'              => 'SureDonation',
					'time_to_display'     => '+24 hours',
					'deactivation_survey' => apply_filters(
						'suredonation_deactivation_survey_data',
						[
							[
								'id'                => 'deactivation-survey-suredonation',
								'popup_logo'        => SUREDONATION_URL . 'images/suredonation-icon.svg',
								'plugin_slug'       => 'suredonation',
								'popup_title'       => __( 'Quick Feedback', 'suredonation' ),
								'support_url'       => 'https://suredonation.com/support/',
								'popup_description' => __( 'If you have a moment, please share why you are deactivating SureDonation:', 'suredonation' ),
								'show_on_screens'   => [ 'plugins' ],
								'plugin_version'    => SUREDONATION_VER,
							],
						]
					),
					'hide_optin_checkbox' => true,
				],
			]
		);
	}

	/**
	 * Get the shared BSF_Analytics_Events instance.
	 *
	 * Uses SureDonation's Helper option methods so the event data stays
	 * inside the consolidated suredonation_options row.
	 *
	 * @return \BSF_Analytics_Events|null Events instance, or null when the library is unavailable.
	 * @since 1.0.0
	 */
	public static function events() {
		if ( null === self::$events ) {
			if ( ! class_exists( 'BSF_Analytics_Events' ) ) {
				$events_file = SUREDONATION_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-events.php';
				if ( file_exists( $events_file ) ) {
					require_once $events_file;
				}
			}

			if ( ! class_exists( 'BSF_Analytics_Events' ) ) {
				return null;
			}

			self::$events = new \BSF_Analytics_Events(
				'suredonation',
				[
					'get'    => [ Helper::class, 'get_suredonation_option' ],
					'update' => [ Helper::class, 'update_suredonation_option' ],
				]
			);
		}

		return self::$events;
	}

	/**
	 * Callback function to add SureDonation specific analytics data.
	 *
	 * @param array<string, mixed> $stats_data Existing stats data.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function add_suredonation_analytics_data( $stats_data ) {
		$aggregates      = $this->get_donation_aggregates();
		$campaign_counts = wp_count_posts( 'suredonation_cmpgn' );
		$form_counts     = wp_count_posts( 'suredonation_form' );

		$bsf_internal_referrer = get_option( 'bsf_product_referers', [] );
		$internal_referer      = is_array( $bsf_internal_referrer ) && ! empty( $bsf_internal_referrer['suredonation'] )
			? sanitize_text_field( (string) $bsf_internal_referrer['suredonation'] )
			: 'self';

		$privacy_settings = Privacy_Settings::get_settings();

		$plugin_data = [
			'free_version'           => SUREDONATION_VER,
			'numeric_values'         => [
				'total_campaigns'                 => absint( $campaign_counts->publish ?? 0 ),
				'total_donation_forms'            => absint( $form_counts->publish ?? 0 ),
				'total_donations'                 => $aggregates['total'],
				'completed_donations'             => $aggregates['completed'],
				'recurring_donations'             => $aggregates['recurring'],
				'total_donors'                    => $this->get_total_donors(),
				'posts_with_social_sharing_block' => $this->get_social_sharing_block_count(),
			],
			'boolean_values'         => [
				'stripe_enabled'               => Stripe_Helper::is_stripe_connected(),
				'paypal_enabled'               => PayPal_Helper::is_paypal_connected(),
				'offline_enabled'              => Offline_Helper::is_offline_enabled(),
				// True only when the OttoKit plugin is active AND authenticated,
				// so this implies the plugin is active.
				'ottokit_connected'            => Helper::is_suretriggers_ready(),
				'contact_consent_enabled'      => ! empty( $privacy_settings['contact_consent_field'] ),
				'privacy_policy_field_enabled' => ! empty( $privacy_settings['privacy_policy_field'] ),
				'terms_field_enabled'          => ! empty( $privacy_settings['terms_conditions_field'] ),
			],
			'data_retention_period'  => isset( $privacy_settings['minimum_data_retention_period'] ) ? Helper::get_string_value( $privacy_settings['minimum_data_retention_period'] ) : 'none',
			'block_usage'            => $this->get_block_usage(),
			'elementor_widget_usage' => $this->get_elementor_widget_usage(),
			'bricks_element_usage'   => $this->get_bricks_element_usage(),
			'internal_referer'       => $internal_referer,
		];

		// Add KPI tracking data.
		$kpi_data = $this->get_kpi_tracking_data();
		if ( ! empty( $kpi_data ) ) {
			$plugin_data['kpi_records'] = $kpi_data;
		}

		// Flush pending events into payload (only if any exist).
		$events = self::events();
		if ( null !== $events ) {
			$pending_events = $events->flush_pending();
			if ( ! empty( $pending_events ) ) {
				$plugin_data['events_record'] = $pending_events;
			}
		}

		if ( ! isset( $stats_data['plugin_data'] ) || ! is_array( $stats_data['plugin_data'] ) ) {
			$stats_data['plugin_data'] = [];
		}

		$stats_data['plugin_data']['suredonation'] = $plugin_data;

		return $stats_data;
	}

	/**
	 * Keep analytics sends off the frontend.
	 *
	 * Filter callback for `suredonation_tracking_enabled`. The library
	 * evaluates this on every request via `is_tracking_enabled()`; gating on
	 * is_admin() means the stats queries never run on frontend page loads.
	 * Deliberately NOT narrowed further (e.g. to plugin screens): the library
	 * also consults this filter from `register_usage_tracking_setting()` on
	 * admin_init, where returning false aborts settings registration for all
	 * registered BSF products.
	 *
	 * @param bool $is_enabled Whether tracking is enabled (opt-in state).
	 * @return bool
	 * @since 1.0.0
	 */
	public function restrict_tracking_to_admin( $is_enabled ) {
		return $is_enabled && is_admin();
	}

	/**
	 * Track onboarding completion when lead-capture details are saved.
	 *
	 * The payload contains PII (name/email) — only the opt-in flag is
	 * forwarded to analytics.
	 *
	 * @param array<string, mixed> $payload Sanitized onboarding payload.
	 * @return void
	 * @since 1.0.0
	 */
	public function track_onboarding_completed( $payload ) {
		$events = self::events();
		if ( null === $events ) {
			return;
		}

		$payload = is_array( $payload ) ? $payload : [];

		$events->track(
			'onboarding_completed',
			'',
			[
				'opted_in' => ! empty( $payload['opted_in'] ) ? 'yes' : 'no',
			]
		);
	}

	/**
	 * Track first personal-data export that included SureDonation data
	 * (adoption event — deduped, sent once).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function track_privacy_data_exported() {
		$events = self::events();
		if ( null === $events ) {
			return;
		}

		$events->track( 'privacy_data_export_used' );
	}

	/**
	 * Track first personal-data erasure processed for SureDonation data
	 * (adoption event — deduped, sent once).
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $outcome Erasure outcome flags.
	 * @return void
	 */
	public function track_privacy_data_erased( $outcome ) {
		$events = self::events();
		if ( null === $events ) {
			return;
		}

		$outcome = is_array( $outcome ) ? $outcome : [];

		$events->track(
			'privacy_data_erasure_used',
			'',
			[
				'items_removed'  => ! empty( $outcome['items_removed'] ) ? 'yes' : 'no',
				'items_retained' => ! empty( $outcome['items_retained'] ) ? 'yes' : 'no',
				'erase_failed'   => ! empty( $outcome['erase_failed'] ) ? 'yes' : 'no',
			]
		);
	}

	/**
	 * Track first time a campaign is published (activation event).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 * @since 1.0.0
	 */
	public function track_first_campaign_published( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || 'suredonation_cmpgn' !== $post->post_type ) {
			return;
		}

		$events = self::events();
		if ( null === $events ) {
			return;
		}

		$meta        = Helper::get_campaign_meta( $post->ID );
		$goal_amount = isset( $meta['goal_amount'] ) && is_numeric( $meta['goal_amount'] ) ? (float) $meta['goal_amount'] : 0.0;
		$goal_type   = isset( $meta['goal_type'] ) && is_scalar( $meta['goal_type'] ) ? sanitize_text_field( (string) $meta['goal_type'] ) : '';

		$events->track(
			'first_campaign_published',
			(string) $post->ID,
			[
				'goal_type' => $goal_type,
				'has_goal'  => $goal_amount > 0 ? '1' : '0',
			]
		);
	}

	/**
	 * Track first time a user opens the campaign editor.
	 *
	 * @param \WP_Screen $screen Current screen object.
	 * @return void
	 * @since 1.0.0
	 */
	public function track_first_campaign_editor_opened( $screen ) {
		if ( ! $screen instanceof \WP_Screen || 'suredonation_cmpgn' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$events = self::events();
		if ( null === $events ) {
			return;
		}

		$events->track( 'first_campaign_editor_opened' );
	}

	/**
	 * Get donation aggregates in a single request-cached query.
	 *
	 * Feeds both the stats numeric values and the state-event detection so
	 * the donations table is only ever hit once per request.
	 *
	 * @return array<string, int> Aggregate counts.
	 * @since 1.0.0
	 */
	private function get_donation_aggregates() {
		if ( null !== self::$donation_aggregates ) {
			return self::$donation_aggregates;
		}

		global $wpdb;

		$defaults = [
			'total'                  => 0,
			'completed'              => 0,
			'recurring'              => 0,
			'anonymous_completed'    => 0,
			'fees_covered_completed' => 0,
			'refunded'               => 0,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single aggregate query on a custom table, request-cached in a static.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					COALESCE(SUM(payment_status = 'completed'),0) AS completed,
					COALESCE(SUM(subscription_id IS NOT NULL AND subscription_id <> ''),0) AS recurring,
					COALESCE(SUM(is_anonymous = 1 AND payment_status = 'completed'),0) AS anonymous_completed,
					COALESCE(SUM(fees_covered > 0 AND payment_status = 'completed'),0) AS fees_covered_completed,
					COALESCE(SUM(payment_status IN ('refunded','partially_refunded')),0) AS refunded
				FROM %i",
				$wpdb->prefix . 'suredonation_donations'
			),
			ARRAY_A
		);

		self::$donation_aggregates = is_array( $row )
			? array_map( 'absint', array_merge( $defaults, $row ) )
			: $defaults;

		return self::$donation_aggregates;
	}

	/**
	 * Get the total number of donors.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private function get_total_donors() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single COUNT query on a custom table, runs only at analytics send time.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$wpdb->prefix . 'suredonation_donors'
			)
		);

		return absint( $count );
	}

	/**
	 * How many published posts use each SureDonation campaign/donation block.
	 *
	 * A privacy-preserving usage count (no content leaves the site) so we can see
	 * which blocks are actually adopted. One conditional-SUM query (a single table
	 * scan), run only at analytics send time. Elementor/Bricks placements are not
	 * counted here (they store their config outside the block grammar) — see
	 * get_elementor_widget_usage().
	 *
	 * @return array<string, int> Block key => number of published posts using it.
	 * @since 1.2.0
	 */
	private function get_block_usage() {
		global $wpdb;

		$blocks = [
			'campaign_goal'           => 'suredonation/campaign-goal',
			'campaign_stats'          => 'suredonation/campaign-stats',
			'campaign_donations'      => 'suredonation/campaign-donations',
			'campaign_donors'         => 'suredonation/campaign-donors',
			'campaign_donate_button'  => 'suredonation/campaign-donate-button',
			'campaign_social_sharing' => 'suredonation/campaign-social-sharing',
			'donation_form'           => 'suredonation/donation-form',
		];

		// prepare() fills placeholders in SQL order: the SELECT-list %s LIKEs
		// first, then the FROM %i, then the status %s.
		$selects = [];
		$values  = [];
		foreach ( array_keys( $blocks ) as $key ) {
			$selects[] = "SUM(post_content LIKE %s) AS {$key}";
			// The space after the block name is the delimiter the serializer always
			// emits (before attrs JSON, "-->" or "/-->"), so a future
			// "campaign-goal-x" block can't prefix-match campaign-goal.
			$values[] = '%' . $wpdb->esc_like( '<!-- wp:' . $blocks[ $key ] . ' ' ) . '%';
		}
		$values[] = $wpdb->posts;
		$values[] = 'publish';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Single aggregate scan at analytics send time; SELECT list is built from hardcoded keys and %s placeholders only.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . implode( ', ', $selects ) . ' FROM %i WHERE post_status = %s', $values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built alongside $values; prepare() accepts the array form.
			ARRAY_A
		);

		$usage = [];
		foreach ( array_keys( $blocks ) as $key ) {
			$usage[ $key ] = absint( is_array( $row ) ? ( $row[ $key ] ?? 0 ) : 0 );
		}

		return $usage;
	}

	/**
	 * How many published posts use each SureDonation Elementor widget.
	 *
	 * The Elementor counterpart of get_block_usage(): widgets live in the
	 * _elementor_data postmeta (JSON with a quoted "widgetType"), not in the
	 * block grammar. Same privacy-preserving single-scan shape, run only at
	 * analytics send time.
	 *
	 * @return array<string, int> Widget key => number of published posts using it.
	 * @since 1.2.0
	 */
	private function get_elementor_widget_usage() {
		global $wpdb;

		$widgets = [
			'campaign_goal'           => 'suredonation-campaign-goal',
			'campaign_stats'          => 'suredonation-campaign-stats',
			'campaign_donations'      => 'suredonation-campaign-donations',
			'campaign_donors'         => 'suredonation-campaign-donors',
			'campaign_donate_button'  => 'suredonation-campaign-donate-button',
			'campaign_social_sharing' => 'suredonation-campaign-social-sharing',
			'donation_form'           => 'suredonation-donation-form',
		];

		// prepare() fills placeholders in SQL order: the SELECT-list %s LIKEs
		// first, then the two FROM/JOIN %i tables, then meta_key and status.
		$selects = [];
		$values  = [];
		foreach ( array_keys( $widgets ) as $key ) {
			$selects[] = "SUM(pm.meta_value LIKE %s) AS {$key}";
			// Quoted as stored in the _elementor_data JSON ("widgetType":"…"),
			// which bounds the match on both sides.
			$values[] = '%' . $wpdb->esc_like( '"' . $widgets[ $key ] . '"' ) . '%';
		}
		$values[] = $wpdb->postmeta;
		$values[] = $wpdb->posts;
		$values[] = '_elementor_data';
		$values[] = 'publish';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Single aggregate scan at analytics send time; SELECT list is built from hardcoded keys and %s placeholders only.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . implode( ', ', $selects ) . ' FROM %i AS pm INNER JOIN %i AS p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = %s', $values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built alongside $values; prepare() accepts the array form.
			ARRAY_A
		);

		$usage = [];
		foreach ( array_keys( $widgets ) as $key ) {
			$usage[ $key ] = absint( is_array( $row ) ? ( $row[ $key ] ?? 0 ) : 0 );
		}

		return $usage;
	}

	/**
	 * How many published posts use each SureDonation Bricks element.
	 *
	 * A privacy-preserving usage count (no content leaves the site) so we can see
	 * which Bricks elements are actually adopted — the Bricks counterpart of the
	 * Gutenberg block_usage stat. Bricks stores builder data as serialized element
	 * arrays in postmeta, so each element name is matched inside its quotes.
	 * One conditional-SUM query (a single scan), run only at analytics send time.
	 *
	 * @return array<string, int> Element key => number of published posts using it.
	 * @since 1.2.0
	 */
	private function get_bricks_element_usage() {
		global $wpdb;

		$elements = [
			'campaign_goal'           => 'suredonation-campaign-goal',
			'campaign_stats'          => 'suredonation-campaign-stats',
			'campaign_donations'      => 'suredonation-campaign-donations',
			'campaign_donors'         => 'suredonation-campaign-donors',
			'campaign_donate_button'  => 'suredonation-campaign-donate-button',
			'campaign_social_sharing' => 'suredonation-campaign-social-sharing',
			'donation_form'           => 'suredonation-donation-form',
		];

		// prepare() fills placeholders in SQL order: the SELECT-list %s LIKEs
		// first, then the two FROM/JOIN %i tables, then meta keys and status.
		$selects = [];
		$values  = [];
		foreach ( array_keys( $elements ) as $key ) {
			$selects[] = "SUM(pm.meta_value LIKE %s) AS {$key}";
			// Quoted as stored in the serialized Bricks element data, which
			// bounds the match on both sides.
			$values[] = '%' . $wpdb->esc_like( '"' . $elements[ $key ] . '"' ) . '%';
		}
		$values[] = $wpdb->postmeta;
		$values[] = $wpdb->posts;
		$values[] = '_bricks_page_content_2';
		$values[] = '_bricks_page_header_2';
		$values[] = '_bricks_page_footer_2';
		$values[] = 'publish';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Single aggregate scan at analytics send time; SELECT list is built from hardcoded keys and %s placeholders only.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . implode( ', ', $selects ) . ' FROM %i AS pm INNER JOIN %i AS p ON p.ID = pm.post_id WHERE pm.meta_key IN ( %s, %s, %s ) AND p.post_status = %s', $values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are built alongside $values; prepare() accepts the array form.
			ARRAY_A
		);

		$usage = [];
		foreach ( array_keys( $elements ) as $key ) {
			$usage[ $key ] = absint( is_array( $row ) ? ( $row[ $key ] ?? 0 ) : 0 );
		}

		return $usage;
	}

	/**
	 * How many published posts use the Campaign Social Sharing block.
	 *
	 * A privacy-preserving adoption count (no content leaves the site), run only
	 * at analytics send time. The trailing space is the delimiter the block
	 * serializer always emits after the block name, so a future
	 * "campaign-social-sharing-x" block can't prefix-match.
	 *
	 * @return int Number of published posts containing the block.
	 * @since 1.2.0
	 */
	private function get_social_sharing_block_count() {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( '<!-- wp:suredonation/campaign-social-sharing ' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single aggregate COUNT run only at analytics send time.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(ID) FROM %i WHERE post_status = %s AND post_content LIKE %s',
				$wpdb->posts,
				'publish',
				$like
			)
		);

		return absint( $count );
	}

	/**
	 * Get KPI tracking data for the last 2 full days (excluding today).
	 *
	 * Single grouped query; raw revenue never enters the payload — only
	 * the donation count and a coarse revenue tier per day.
	 *
	 * Date boundaries use GMT because `created_at` is written with
	 * current_time( 'mysql', true ).
	 *
	 * @return array<string, array<string, array<string, mixed>>> KPI data keyed by Y-m-d date.
	 * @since 1.0.0
	 */
	private function get_kpi_tracking_data() {
		global $wpdb;

		$start = gmdate( 'Y-m-d', strtotime( '-2 days' ) ) . ' 00:00:00';
		$end   = gmdate( 'Y-m-d' ) . ' 00:00:00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single grouped query on a custom table, runs only at analytics send time.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS donations, COALESCE(SUM(amount),0) AS revenue
				FROM %i
				WHERE payment_status = 'completed' AND created_at >= %s AND created_at < %s
				GROUP BY day",
				$wpdb->prefix . 'suredonation_donations',
				$start,
				$end
			),
			ARRAY_A
		);

		$kpi_data = [];

		// Seed both days so dates with zero donations are still reported.
		for ( $i = 2; $i >= 1; $i-- ) {
			$day = gmdate( 'Y-m-d', strtotime( '-' . $i . ' days' ) );

			$kpi_data[ $day ] = [
				'numeric_values' => [
					'donations' => 0,
				],
				'string_values'  => [
					'donation_revenue_tier' => '0',
				],
			];
		}

		$rows = is_array( $rows ) ? $rows : [];

		foreach ( $rows as $row ) {
			if ( empty( $row['day'] ) || ! isset( $kpi_data[ $row['day'] ] ) ) {
				continue;
			}

			$kpi_data[ $row['day'] ] = [
				'numeric_values' => [
					'donations' => absint( $row['donations'] ?? 0 ),
				],
				'string_values'  => [
					'donation_revenue_tier' => $this->get_revenue_tier( (float) ( $row['revenue'] ?? 0 ) ),
				],
			];
		}

		return $kpi_data;
	}

	/**
	 * Map a raw daily revenue amount to a coarse reporting tier.
	 *
	 * @param float $revenue Daily revenue.
	 * @return string Revenue tier label.
	 * @since 1.0.0
	 */
	private function get_revenue_tier( float $revenue ): string {
		if ( $revenue <= 0 ) {
			return '0';
		}
		if ( $revenue < 100 ) {
			return '1-100';
		}
		if ( $revenue < 500 ) {
			return '100-500';
		}
		if ( $revenue < 1000 ) {
			return '500-1000';
		}
		if ( $revenue < 5000 ) {
			return '1000-5000';
		}
		return '5000+';
	}

	/**
	 * Detect state-based events that can't use direct hooks.
	 *
	 * Throttled by a daily transient; uses the request-cached donation
	 * aggregates plus option reads only — no extra queries. The events
	 * tracker dedups, so repeated calls are safe.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function detect_state_events() {
		if ( get_transient( 'suredonation_state_events_checked' ) ) {
			return;
		}

		$events = self::events();
		if ( null === $events ) {
			return; // Tracker unavailable — retry on next admin load.
		}

		// Set only after the tracker is confirmed available.
		set_transient( 'suredonation_state_events_checked', true, DAY_IN_SECONDS );

		$aggregates = $this->get_donation_aggregates();
		$mode       = Payment_Helper::get_payment_mode();

		// plugin_activated: dedup ensures this fires only once.
		$bsf_referrers = get_option( 'bsf_product_referers', [] );
		$source        = is_array( $bsf_referrers ) && ! empty( $bsf_referrers['suredonation'] )
			? sanitize_text_field( (string) $bsf_referrers['suredonation'] )
			: 'self';
		$events->track( 'plugin_activated', SUREDONATION_VER, [ 'source' => $source ] );

		// plugin_updated: re-track on every version change.
		$tracked_version = get_option( 'suredonation_tracked_version', '' );
		if ( SUREDONATION_VER !== $tracked_version ) {
			if ( ! empty( $tracked_version ) && is_string( $tracked_version ) ) {
				$events->flush_pushed( [ 'plugin_updated' ] );
				$events->track( 'plugin_updated', SUREDONATION_VER, [ 'from_version' => $tracked_version ] );
			}
			update_option( 'suredonation_tracked_version', SUREDONATION_VER, false );
		}

		// stripe_connected: detect connection state.
		if ( Stripe_Helper::is_stripe_connected() ) {
			$events->track( 'stripe_connected', $mode );
		}

		// paypal_connected: detect connection state.
		if ( PayPal_Helper::is_paypal_connected() ) {
			$events->track( 'paypal_connected', $mode );
		}

		// payment_mode_live: site switched to live payments.
		if ( 'live' === $mode ) {
			$events->track( 'payment_mode_live' );
		}

		// first_donation_received: time-to-value milestone.
		if ( $aggregates['completed'] > 0 ) {
			$install_time_raw   = get_site_option( 'suredonation_usage_installed_time', 0 );
			$install_time       = is_numeric( $install_time_raw ) ? (int) $install_time_raw : 0;
			$days_since_install = $install_time > 0 ? (int) floor( ( time() - $install_time ) / DAY_IN_SECONDS ) : 0;

			$events->track(
				'first_donation_received',
				Payment_Helper::get_currency(),
				[
					'days_since_install' => (string) $days_since_install,
					'payment_mode'       => $mode,
				]
			);
		}

		// anonymous_donation_submitted: at least one completed anonymous donation.
		if ( $aggregates['anonymous_completed'] > 0 ) {
			$events->track( 'anonymous_donation_submitted' );
		}

		// cover_fees_used: at least one completed donation covered fees.
		if ( $aggregates['fees_covered_completed'] > 0 ) {
			$events->track( 'cover_fees_used' );
		}

		// first_refund_processed: at least one (partially) refunded donation.
		if ( $aggregates['refunded'] > 0 ) {
			$events->track( 'first_refund_processed' );
		}

		// webhook_configured: a Stripe webhook secret is stored for the current mode.
		if ( '' !== Stripe_Helper::get_webhook_secret() ) {
			$events->track( 'webhook_configured', $mode );
		}
	}
}
