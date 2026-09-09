<?php
/**
 * Read-only access to Charitable data.
 *
 * Wraps all queries against Charitable's tables (`campaign` / `donation` /
 * `recurring_donation` post types, `charitable_campaign_donations`,
 * `charitable_donors`, `charitable_donormeta`) so the rest of the importer
 * never touches raw $wpdb against Charitable-owned schemas. Keeps the surface
 * area small if Charitable ever restructures its data model.
 *
 * @package SureDonation
 * @since 1.5.1
 */

namespace SureDonation\Inc\Import\Charitable;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Source class.
 *
 * @since 1.5.1
 */
class Source {
	use Get_Instance;

	/**
	 * The Charitable campaign↔donation join table (unprefixed name).
	 *
	 * @since 1.5.1
	 */
	private const CAMPAIGN_DONATIONS_TABLE = 'charitable_campaign_donations';

	/**
	 * Memoised result of has_charitable_data() — null until first call.
	 *
	 * @var bool|null
	 * @since 1.5.1
	 */
	private $has_data_cache = null;

	/**
	 * Per-request cache of donation post meta, keyed by post ID.
	 *
	 * A multi-campaign donation yields several campaign_donations rows that
	 * share one donation post, so its meta is read once per request.
	 *
	 * @var array<int, array<string, string>>
	 * @since 1.5.1
	 */
	private $donation_meta_cache = [];

	/**
	 * Whether this site holds Charitable data we can migrate.
	 *
	 * We deliberately don't gate on the Charitable plugin being active —
	 * every read in this class is raw $wpdb against Charitable's tables
	 * (wp_charitable_campaign_donations, wp_charitable_donors, wp_posts,
	 * wp_postmeta), so admins can migrate even after deactivating
	 * Charitable. The canonical signal is "does the
	 * charitable_campaign_donations table exist?" — it is created on
	 * activation and survives deactivation.
	 *
	 * Memoised for the request to keep the pre-flight count call cheap.
	 *
	 * @return bool
	 * @since  1.5.1
	 */
	public function has_charitable_data() {
		if ( null !== $this->has_data_cache ) {
			return $this->has_data_cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::CAMPAIGN_DONATIONS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot existence check for migration pre-flight.
		$exists               = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$this->has_data_cache = ! empty( $exists );

		return $this->has_data_cache;
	}

	/**
	 * Get the running Charitable version, or null if the plugin isn't loaded.
	 * Migration itself doesn't depend on this — it's surfaced in the
	 * pre-flight response for support/debugging context.
	 *
	 * @return string|null
	 * @since  1.5.1
	 */
	public function get_charitable_version() {
		return defined( 'CHARITABLE_VERSION' ) ? CHARITABLE_VERSION : null;
	}

	/**
	 * Count Charitable campaigns eligible for migration.
	 *
	 * @return int
	 * @since  1.5.1
	 */
	public function get_campaign_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status IN (%s, %s, %s)',
				$wpdb->posts,
				'campaign',
				'publish',
				'draft',
				'private'
			)
		);

		return absint( $count );
	}

	/**
	 * Count importable donation rows.
	 *
	 * The unit is one charitable_campaign_donations row (a donation may span
	 * multiple campaigns — one row each — and per-campaign amounts live only
	 * on those rows). The join on post_type = 'donation' both drops rows whose
	 * donation post was deleted AND excludes recurring_donation plan rows the
	 * Recurring add-on writes into the same table.
	 *
	 * @return int
	 * @since  1.5.1
	 */
	public function get_donation_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i cd INNER JOIN %i p ON p.ID = cd.donation_id WHERE p.post_type = %s AND p.post_status NOT IN (%s, %s)',
				$wpdb->prefix . self::CAMPAIGN_DONATIONS_TABLE,
				$wpdb->posts,
				'donation',
				'trash',
				'auto-draft'
			)
		);

		return absint( $count );
	}

	/**
	 * Count Charitable Recurring plans (recurring_donation posts).
	 *
	 * Zero when the Recurring add-on was never used.
	 *
	 * @return int
	 * @since  1.5.1
	 */
	public function get_subscription_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status != %s',
				$wpdb->posts,
				'recurring_donation',
				'trash'
			)
		);

		return absint( $count );
	}

	/**
	 * Count donors with no linked donation (standalone donors).
	 *
	 * GDPR-erased donors (data_erased set) are excluded — their PII has been
	 * blanked and an email-less donor cannot be imported.
	 *
	 * @return int
	 * @since  1.5.1
	 */
	public function get_standalone_donor_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i d WHERE NOT EXISTS ( SELECT 1 FROM %i cd WHERE cd.donor_id = d.donor_id ) AND ( d.data_erased IS NULL OR d.data_erased = %s )',
				$wpdb->prefix . 'charitable_donors',
				$wpdb->prefix . self::CAMPAIGN_DONATIONS_TABLE,
				'0000-00-00 00:00:00'
			)
		);

		return absint( $count );
	}

	/**
	 * Fetch a batch of Charitable campaign posts, scoped to a selection.
	 *
	 * @param  int        $offset       Row offset.
	 * @param  int        $limit        Batch size.
	 * @param  array<int> $campaign_ids Charitable campaign post IDs to include (required).
	 * @return array<int, object> Raw post rows.
	 * @since  1.5.1
	 */
	public function get_campaigns_batch( $offset, $limit, $campaign_ids ) {
		$campaign_ids = array_filter( array_map( 'absint', (array) $campaign_ids ) );
		if ( empty( $campaign_ids ) ) {
			return [];
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated %d markers; values are passed to prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE post_type = %s AND post_status IN (%s, %s, %s) AND ID IN ( {$placeholders} ) ORDER BY ID ASC LIMIT %d OFFSET %d",
				array_merge(
					[ $wpdb->posts, 'campaign', 'publish', 'draft', 'private' ],
					$campaign_ids,
					[ absint( $limit ), absint( $offset ) ]
				)
			)
		);

		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Fetch a batch of donation rows (campaign_donations ⋈ donation posts),
	 * scoped to the selected campaigns.
	 *
	 * @param  int        $offset       Row offset.
	 * @param  int        $limit        Batch size.
	 * @param  array<int> $campaign_ids Charitable campaign post IDs to include (required).
	 * @return array<int, object> Rows with cd columns + post status/date fields.
	 * @since  1.5.1
	 */
	public function get_donation_rows_batch( $offset, $limit, $campaign_ids ) {
		$campaign_ids = array_filter( array_map( 'absint', (array) $campaign_ids ) );
		if ( empty( $campaign_ids ) ) {
			return [];
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated %d markers; values are passed to prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cd.campaign_donation_id, cd.donation_id, cd.donor_id, cd.campaign_id, cd.campaign_name, cd.amount, p.post_status, p.post_date, p.post_date_gmt, p.post_parent FROM %i cd INNER JOIN %i p ON p.ID = cd.donation_id WHERE cd.campaign_id IN ( {$placeholders} ) AND p.post_type = %s AND p.post_status NOT IN (%s, %s) ORDER BY cd.campaign_donation_id ASC LIMIT %d OFFSET %d",
				array_merge(
					[ $wpdb->prefix . self::CAMPAIGN_DONATIONS_TABLE, $wpdb->posts ],
					$campaign_ids,
					[ 'donation', 'trash', 'auto-draft', absint( $limit ), absint( $offset ) ]
				)
			)
		);

		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Fetch a batch of Charitable Recurring plan posts scoped to the selected
	 * campaigns.
	 *
	 * A plan is in scope when its own campaign_donations row targets a selected
	 * campaign OR any of its child (renewal) donations does — the Recurring
	 * add-on's schema is reconstructed from the free plugin's stubs, so we
	 * cannot rely on the plan itself always having a join row.
	 *
	 * @param  int        $offset       Row offset.
	 * @param  int        $limit        Batch size.
	 * @param  array<int> $campaign_ids Charitable campaign post IDs to include (required).
	 * @return array<int, object> Raw recurring_donation post rows.
	 * @since  1.5.1
	 */
	public function get_recurring_plans_batch( $offset, $limit, $campaign_ids ) {
		$campaign_ids = array_filter( array_map( 'absint', (array) $campaign_ids ) );
		if ( empty( $campaign_ids ) ) {
			return [];
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );
		$cd_table     = $wpdb->prefix . self::CAMPAIGN_DONATIONS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated %d markers; values are passed to prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT pl.* FROM %i pl WHERE pl.post_type = %s AND pl.post_status != %s AND ( EXISTS ( SELECT 1 FROM %i cd WHERE cd.donation_id = pl.ID AND cd.campaign_id IN ( {$placeholders} ) ) OR EXISTS ( SELECT 1 FROM %i ch INNER JOIN %i cd2 ON cd2.donation_id = ch.ID WHERE ch.post_parent = pl.ID AND ch.post_type = %s AND cd2.campaign_id IN ( {$placeholders} ) ) ) ORDER BY pl.ID ASC LIMIT %d OFFSET %d",
				array_merge(
					[ $wpdb->posts, 'recurring_donation', 'trash', $cd_table ],
					$campaign_ids,
					[ $wpdb->posts, $cd_table, 'donation' ],
					$campaign_ids,
					[ absint( $limit ), absint( $offset ) ]
				)
			)
		);

		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Flat meta map for a donation (or recurring plan) post.
	 *
	 * Cached per request — a multi-campaign donation is visited once per
	 * campaign_donations row but its meta only read once.
	 *
	 * @param  int $post_id Donation/plan post ID.
	 * @return array<string, string>
	 * @since  1.5.1
	 */
	public function get_donation_meta( $post_id ) {
		$post_id = absint( $post_id );
		if ( isset( $this->donation_meta_cache[ $post_id ] ) ) {
			return $this->donation_meta_cache[ $post_id ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only import source access.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE post_id = %d', $wpdb->postmeta, $post_id )
		);

		$meta = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$meta[ (string) $row->meta_key ] = (string) $row->meta_value;
		}

		// Keep the cache bounded for very large imports.
		if ( count( $this->donation_meta_cache ) > 200 ) {
			$this->donation_meta_cache = [];
		}
		$this->donation_meta_cache[ $post_id ] = $meta;

		return $meta;
	}

	/**
	 * Flat map of a Charitable campaign's migration-relevant meta.
	 *
	 * @param  int $campaign_id Charitable campaign post ID.
	 * @return array<string, string>
	 * @since  1.5.1
	 */
	public function get_campaign_meta( $campaign_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only import source access.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND ( meta_key LIKE %s OR meta_key = %s )',
				$wpdb->postmeta,
				absint( $campaign_id ),
				$wpdb->esc_like( '_campaign_' ) . '%',
				'campaign_settings_v2'
			)
		);

		$meta = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$meta[ (string) $row->meta_key ] = (string) $row->meta_value;
		}

		return $meta;
	}

	/**
	 * Fetch a charitable_donors row by donor_id.
	 *
	 * @param  int $donor_id Charitable donor ID.
	 * @return array<string, mixed>|null
	 * @since  1.5.1
	 */
	public function get_donor( $donor_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only import source access.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE donor_id = %d',
				$wpdb->prefix . 'charitable_donors',
				absint( $donor_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Flat meta map from charitable_donormeta for a donor.
	 *
	 * The table may not exist on very old installs — guarded.
	 *
	 * @param  int $donor_id Charitable donor ID.
	 * @return array<string, string>
	 * @since  1.5.1
	 */
	public function get_donor_meta( $donor_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'charitable_donormeta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot existence check.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( empty( $exists ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only import source access.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE donor_id = %d', $table, absint( $donor_id ) )
		);

		$meta = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$meta[ (string) $row->meta_key ] = (string) $row->meta_value;
		}

		return $meta;
	}

	/**
	 * Group donations by their Charitable gateway slug for the pre-flight
	 * gateway breakdown panel.
	 *
	 * @return array<int, array{slug: string, count: int}>
	 * @since  1.5.1
	 */
	public function get_gateway_breakdown() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pm.meta_value AS slug, COUNT(*) AS total FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN (%s, %s) GROUP BY pm.meta_value ORDER BY total DESC',
				$wpdb->postmeta,
				$wpdb->posts,
				'donation_gateway',
				'donation',
				'trash',
				'auto-draft'
			)
		);

		$breakdown = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$breakdown[] = [
				'slug'  => (string) $row->slug,
				'count' => (int) $row->total,
			];
		}

		return $breakdown;
	}

	/**
	 * Per-campaign aggregate preview for the selection UI.
	 *
	 * The `form_id` key deliberately carries the Charitable campaign post ID —
	 * the shared migration UI's selection model, the `campaign_ids` option and
	 * estimate_total_items() all key on `form_id` (a GiveWP-era name kept so
	 * the React components work unchanged for every source).
	 *
	 * @return array{campaigns: array<int, array<string, mixed>>, standalone_donors: int}
	 * @since  1.5.1
	 */
	public function get_campaigns_preview() {
		global $wpdb;
		$cd_table = $wpdb->prefix . self::CAMPAIGN_DONATIONS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.ID AS form_id, c.post_title AS title, COUNT(p.ID) AS donations, COUNT(DISTINCT CASE WHEN p.ID IS NOT NULL THEN cd.donor_id END) AS donors, COALESCE(SUM(CASE WHEN p.ID IS NOT NULL THEN cd.amount ELSE 0 END), 0) AS total_amount FROM %i c LEFT JOIN %i cd ON cd.campaign_id = c.ID LEFT JOIN %i p ON p.ID = cd.donation_id AND p.post_type = %s AND p.post_status NOT IN (%s, %s) WHERE c.post_type = %s AND c.post_status IN (%s, %s, %s) GROUP BY c.ID, c.post_title ORDER BY donations DESC, c.post_title ASC',
				$wpdb->posts,
				$cd_table,
				$wpdb->posts,
				'donation',
				'trash',
				'auto-draft',
				'campaign',
				'publish',
				'draft',
				'private'
			)
		);

		$campaigns = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$campaigns[ (int) $row->form_id ] = [
				'form_id'       => (int) $row->form_id,
				'title'         => (string) $row->title,
				'donations'     => (int) $row->donations,
				'donors'        => (int) $row->donors,
				'subscriptions' => 0,
				'total_amount'  => (float) $row->total_amount,
			];
		}

		// Merge per-campaign recurring-plan counts (plans join through their
		// own campaign_donations row).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$sub_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cd.campaign_id, COUNT(DISTINCT pl.ID) AS subscriptions FROM %i pl INNER JOIN %i cd ON cd.donation_id = pl.ID WHERE pl.post_type = %s AND pl.post_status != %s GROUP BY cd.campaign_id',
				$wpdb->posts,
				$cd_table,
				'recurring_donation',
				'trash'
			)
		);

		foreach ( is_array( $sub_rows ) ? $sub_rows : [] as $row ) {
			$campaign_id = (int) $row->campaign_id;
			if ( isset( $campaigns[ $campaign_id ] ) ) {
				$campaigns[ $campaign_id ]['subscriptions'] = (int) $row->subscriptions;
			}
		}

		return [
			'campaigns'         => array_values( $campaigns ),
			'standalone_donors' => $this->get_standalone_donor_count(),
		];
	}

	/**
	 * Extract the serialized donor snapshot Charitable stores on each
	 * donation ('donor' postmeta), hardened against object injection.
	 *
	 * @param  array<string, string> $meta Donation meta map.
	 * @return array{first_name: string, last_name: string, email: string, address: string, address_2: string, city: string, state: string, postcode: string, country: string, phone: string}
	 * @since  1.5.1
	 */
	public function extract_donor_snapshot( $meta ) {
		$defaults = [
			'first_name' => '',
			'last_name'  => '',
			'email'      => '',
			'address'    => '',
			'address_2'  => '',
			'city'       => '',
			'state'      => '',
			'postcode'   => '',
			'country'    => '',
			'phone'      => '',
		];

		if ( empty( $meta['donor'] ) ) {
			return $defaults;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- hardened native unserialize with class instantiation disabled.
		$snapshot = @unserialize( $meta['donor'], [ 'allowed_classes' => false ] );
		if ( ! is_array( $snapshot ) ) {
			return $defaults;
		}

		$clean = $defaults;
		foreach ( array_keys( $defaults ) as $key ) {
			if ( isset( $snapshot[ $key ] ) && is_scalar( $snapshot[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $snapshot[ $key ] );
			}
		}

		return $clean;
	}
}
