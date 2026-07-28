<?php
/**
 * Read-only access to GiveWP data.
 *
 * Wraps all queries against GiveWP's tables (`give_forms`, `give_payment`
 * post types plus their meta) so the rest of the importer never touches
 * raw $wpdb against GiveWP-owned schemas. Keeps the surface area small if
 * GiveWP ever restructures its data model.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Source class.
 *
 * @since 1.0.0
 */
class Source {
	use Get_Instance;

	/**
	 * Memoised result of has_givewp_data() — null until first call.
	 *
	 * @var bool|null
	 * @since 1.0.0
	 */
	private $has_data_cache = null;

	/**
	 * Whether this site holds GiveWP data we can migrate.
	 *
	 * We deliberately don't gate on the GiveWP plugin being active —
	 * every read in this class is raw $wpdb against GiveWP's tables
	 * (wp_give_donors, wp_give_subscriptions, wp_postmeta, wp_posts),
	 * so admins can migrate even after deactivating GiveWP. The
	 * canonical signal is "does the give_donors table exist?" — that
	 * table is created on activation and survives deactivation /
	 * uninstall unless the admin opts in to data deletion.
	 *
	 * Memoised for the request to keep the pre-flight count call
	 * cheap (one SHOW TABLES per page load instead of one per phase).
	 *
	 * @return bool
	 * @since  1.0.0
	 */
	public function has_givewp_data() {
		if ( null !== $this->has_data_cache ) {
			return $this->has_data_cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'give_donors';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot existence check for migration pre-flight.
		$exists                = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->has_data_cache = ! empty( $exists );

		return $this->has_data_cache;
	}

	/**
	 * Get the running GiveWP version, or null if the plugin isn't
	 * loaded. Migration itself doesn't depend on this — it's only
	 * surfaced as a "GiveWP X.Y" badge in the admin UI when available.
	 *
	 * @return string|null
	 * @since  1.0.0
	 */
	public function get_givewp_version() {
		return defined( 'GIVE_VERSION' ) ? (string) constant( 'GIVE_VERSION' ) : null;
	}

	/**
	 * Resolve which table + FK column to read donation meta from.
	 *
	 * Modern GiveWP installs store donation meta in `wp_give_donationmeta`
	 * (FK `donation_id` → `wp_posts.ID`). Older installs only have meta
	 * in `wp_postmeta` (FK `post_id`). GiveWP's `get_post_metadata`
	 * filter transparently bridges the two at runtime — but only while
	 * the plugin is active, and we run raw SQL that bypasses the filter
	 * regardless. Detect the custom table once per call and let raw
	 * queries target the right source directly.
	 *
	 * @return array{0:string,1:string} [table name, FK column name].
	 * @since  1.0.0
	 */
	public function resolve_donation_meta_source() {
		global $wpdb;

		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$custom = $wpdb->prefix . 'give_donationmeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $custom ) );

		$cache = $exists
			? [ $custom, 'donation_id' ]
			: [ $wpdb->postmeta, 'post_id' ];

		return $cache;
	}

	/**
	 * Get total count of GiveWP forms (campaigns).
	 *
	 * @return int
	 * @since  1.0.0
	 */
	public function get_form_count() {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft', 'private')",
				'give_forms'
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get total count of GiveWP donations (payments).
	 *
	 * @return int
	 * @since  1.0.0
	 */
	public function get_payment_count() {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot read for migration pre-flight.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
				'give_payment',
				'trash'
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get total count of GiveWP subscriptions.
	 *
	 * Subscriptions live in a custom give_subscriptions table created by the
	 * give-recurring add-on. Returns 0 if the table doesn't exist.
	 *
	 * @return int
	 * @since  1.0.0
	 */
	public function get_subscription_count() {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return 0;
		}

		$table = $wpdb->prefix . 'give_subscriptions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table name is a constant string.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get total count of standalone GiveWP donors (donors without donations).
	 *
	 * @return int
	 * @since  1.0.0
	 */
	public function get_standalone_donor_count() {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return 0;
		}

		$donors_table = $wpdb->prefix . 'give_donors';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $donors_table ) );
		if ( ! $exists ) {
			return 0;
		}

		[ $meta_table, $meta_fk ] = $this->resolve_donation_meta_source();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i d WHERE NOT EXISTS (
					SELECT 1 FROM {$wpdb->posts} p
					WHERE p.post_type = %s
					AND p.post_status != %s
					AND p.ID IN (
						SELECT {$meta_fk} FROM {$meta_table}
						WHERE meta_key = %s AND meta_value = d.id
					)
				)",
				$donors_table,
				'give_payment',
				'trash',
				'_give_payment_donor_id'
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get a batch of GiveWP forms (campaigns).
	 *
	 * When `$form_ids` is a non-empty array, restricts the batch to those
	 * IDs (used by the two-step UI to migrate only campaigns the admin
	 * picked). An empty array returns nothing — callers should always
	 * pass an explicit selection now that the preview UI is the only
	 * entry point.
	 *
	 * @param  int   $offset   Offset.
	 * @param  int   $limit    Limit.
	 * @param  int[] $form_ids Selected give_forms IDs.
	 * @return array<int,object> Array of post-row objects.
	 * @since  1.0.0
	 */
	public function get_forms_batch( $offset, $limit, $form_ids = [] ) {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return [];
		}

		$form_ids = is_array( $form_ids )
			? array_values( array_unique( array_filter( array_map( 'absint', $form_ids ) ) ) )
			: [];
		if ( empty( $form_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status IN ('publish', 'draft', 'private')
					AND ID IN ({$placeholders})
					ORDER BY ID ASC
					LIMIT %d OFFSET %d",
				array_merge(
					[ 'give_forms' ],
					array_map( 'intval', $form_ids ),
					[ absint( $limit ), absint( $offset ) ]
				)
			)
		);

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Get a batch of GiveWP donations (payments) restricted to the given form IDs.
	 *
	 * Joins postmeta on `_give_payment_form_id` to keep only payments
	 * recorded against one of the selected GiveWP forms. An empty
	 * `$form_ids` returns nothing — same contract as `get_forms_batch()`.
	 *
	 * @param  int   $offset   Offset.
	 * @param  int   $limit    Limit.
	 * @param  int[] $form_ids Selected give_forms IDs.
	 * @return array<int,object> Array of post-row objects.
	 * @since  1.0.0
	 */
	public function get_payments_batch( $offset, $limit, $form_ids = [] ) {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return [];
		}

		$form_ids = is_array( $form_ids )
			? array_values( array_unique( array_filter( array_map( 'absint', $form_ids ) ) ) )
			: [];
		if ( empty( $form_ids ) ) {
			return [];
		}

		// give_payment posts don't carry the form_id on the row itself —
		// it lives in donation meta (`_give_payment_form_id`). Read
		// directly from `give_donationmeta` when present so the filter
		// also works while GiveWP is deactivated.
		$placeholders             = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
		[ $meta_table, $meta_fk ] = $this->resolve_donation_meta_source();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.* FROM {$wpdb->posts} p
					INNER JOIN {$meta_table} pm
						ON pm.{$meta_fk} = p.ID
						AND pm.meta_key = %s
						AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders})
					WHERE p.post_type = %s
					AND p.post_status != %s
					ORDER BY p.ID ASC
					LIMIT %d OFFSET %d",
				array_merge(
					[ '_give_payment_form_id' ],
					array_map( 'intval', $form_ids ),
					[ 'give_payment', 'trash', absint( $limit ), absint( $offset ) ]
				)
			)
		);

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Get all meta for a given GiveWP payment as a flat assoc array.
	 *
	 * Reads `give_donationmeta` when GiveWP's custom-table store is
	 * present (the canonical location since GiveWP 2.x switched to it)
	 * and falls back to wp_postmeta otherwise. Going through raw SQL
	 * keeps this working when GiveWP is deactivated — `get_post_meta`
	 * relies on GiveWP's runtime filter to redirect post meta lookups
	 * into the custom table, and that filter isn't registered when the
	 * plugin is off.
	 *
	 * @param  int $payment_id Payment post ID.
	 * @return array<string,string>
	 * @since  1.0.0
	 */
	public function get_payment_meta( $payment_id ) {
		global $wpdb;

		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return [];
		}

		[ $meta_table, $meta_fk ] = $this->resolve_donation_meta_source();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$meta_table} WHERE {$meta_fk} = %d",
				$payment_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$flat = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['meta_key'] ) ) {
				continue;
			}
			$flat[ (string) $row['meta_key'] ] = isset( $row['meta_value'] )
				? (string) $row['meta_value']
				: '';
		}

		return $flat;
	}

	/**
	 * Get the donation count grouped by GiveWP gateway slug.
	 *
	 * Used by the data-found panel to show per-gateway breakdown with
	 * live/historical badges.
	 *
	 * @return array<int,array{slug:string,count:int}>
	 * @since  1.0.0
	 */
	public function get_gateway_breakdown() {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return [];
		}

		[ $meta_table, $meta_fk ] = $this->resolve_donation_meta_source();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS slug, COUNT(*) AS total
				FROM {$meta_table} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.{$meta_fk}
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status != %s
				GROUP BY pm.meta_value
				ORDER BY total DESC",
				'_give_payment_gateway',
				'give_payment',
				'trash'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}
			$out[] = [
				'slug'  => $slug,
				'count' => isset( $row['total'] ) && is_numeric( $row['total'] ) ? (int) $row['total'] : 0,
			];
		}

		return $out;
	}

	/**
	 * Get a batch of GiveWP subscriptions restricted to the given form IDs.
	 *
	 * `give_subscriptions.product_id` holds the give_forms post ID this
	 * subscription was created against — same selection unit the rest of
	 * the migration uses. Empty `$form_ids` returns nothing.
	 *
	 * @param  int   $offset   Offset.
	 * @param  int   $limit    Limit.
	 * @param  int[] $form_ids Selected give_forms IDs.
	 * @return array<int,object>
	 * @since  1.0.0
	 */
	public function get_subscriptions_batch( $offset, $limit, $form_ids = [] ) {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return [];
		}

		$form_ids = is_array( $form_ids )
			? array_values( array_unique( array_filter( array_map( 'absint', $form_ids ) ) ) )
			: [];
		if ( empty( $form_ids ) ) {
			return [];
		}

		$table = $wpdb->prefix . 'give_subscriptions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE product_id IN ({$placeholders}) ORDER BY id ASC LIMIT %d OFFSET %d",
				array_merge(
					[ $table ],
					array_map( 'intval', $form_ids ),
					[ absint( $limit ), absint( $offset ) ]
				)
			)
		);

		return is_array( $results ) ? $results : [];
	}


	/**
	 * Get the GiveWP 4.x campaign row joined to a given form ID.
	 *
	 * GiveWP 4 stores rich campaign data (goal, descriptions, dates, colors)
	 * in a custom `give_campaigns` table that points at the legacy give_forms
	 * post via `form_id`. Older versions don't have this table — the lookup
	 * is best-effort and the caller falls back to post meta.
	 *
	 * @param  int $form_id GiveWP form (post) ID.
	 * @return array<string,mixed>|null
	 * @since  1.0.0
	 */
	public function get_campaign_for_form( $form_id ) {
		global $wpdb;

		$form_id = absint( $form_id );
		if ( ! $form_id || ! $this->has_givewp_data() ) {
			return null;
		}

		$table = $wpdb->prefix . 'give_campaigns';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE form_id = %d LIMIT 1',
				$table,
				$form_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Resolve the donation amount for a GiveWP payment from any of the
	 * known meta keys / serialised payment-meta payloads.
	 *
	 * GiveWP has stored donation amounts under several keys across major
	 * versions (_give_payment_total, _give_payment_amount, and inside the
	 * serialised _give_payment_meta blob's `price` field). Try them in
	 * order, return 0.0 if none yield a positive number.
	 *
	 * @param  array<string,mixed> $meta Flat payment meta as returned by get_payment_meta().
	 * @return float
	 * @since  1.0.0
	 */
	public function extract_donation_amount( $meta ) {
		if ( ! is_array( $meta ) ) {
			return 0.0;
		}

		$candidates = [ '_give_payment_total', '_give_payment_amount', '_give_cs_base_amount' ];
		foreach ( $candidates as $key ) {
			if ( isset( $meta[ $key ] ) && is_numeric( $meta[ $key ] ) ) {
				$value = (float) $meta[ $key ];
				if ( $value > 0 ) {
					return $value;
				}
			}
		}

		// Fall back to the legacy serialised _give_payment_meta blob.
		if ( isset( $meta['_give_payment_meta'] ) && is_string( $meta['_give_payment_meta'] ) && is_serialized( $meta['_give_payment_meta'] ) ) {
			// allowed_classes=false blocks PHP object injection (CWE-502) on the GiveWP-controlled payload; @ suppresses notices on malformed-but-is_serialized() input (result is validated below).
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- hardened native unserialize with class instantiation disabled.
			$unserialised = @unserialize( $meta['_give_payment_meta'], [ 'allowed_classes' => false ] );
			if ( is_array( $unserialised ) && isset( $unserialised['price'] ) && is_numeric( $unserialised['price'] ) ) {
				return (float) $unserialised['price'];
			}
		}

		return 0.0;
	}

	/**
	 * Get a single GiveWP donor row by donor ID.
	 *
	 * @param  int $give_donor_id GiveWP donor ID.
	 * @return array<string,mixed>|null
	 * @since  1.0.0
	 */
	public function get_donor( $give_donor_id ) {
		global $wpdb;

		$give_donor_id = absint( $give_donor_id );
		if ( ! $give_donor_id || ! $this->has_givewp_data() ) {
			return null;
		}

		$table = $wpdb->prefix . 'give_donors';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $give_donor_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get all give_donormeta rows for a donor as a flat assoc array.
	 *
	 * GiveWP stores donor-level fields the payment-meta path can't see
	 * (structured billing address, company, add-on opt-in flags, Stripe
	 * customer id, avatar URL, etc.) on this side. Mappers use this
	 * helper to enrich the donor row beyond what the payment meta
	 * surfaces.
	 *
	 * @param  int $give_donor_id GiveWP donor ID.
	 * @return array<string,string>
	 * @since  1.0.0
	 */
	public function get_donor_meta( $give_donor_id ) {
		global $wpdb;

		$give_donor_id = absint( $give_donor_id );
		if ( ! $give_donor_id || ! $this->has_givewp_data() ) {
			return [];
		}

		$table = $wpdb->prefix . 'give_donormeta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE donor_id = %d',
				$table,
				$give_donor_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['meta_key'] ) ) {
				continue;
			}
			$out[ (string) $row['meta_key'] ] = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
		}
		return $out;
	}

	/**
	 * Per-form aggregate preview for the two-step migration UI.
	 *
	 * Returns one row per give_forms post, summed across all linked
	 * give_payment rows (donations, distinct donors, total amount) plus a
	 * per-form subscription count and the form's display title (preferring
	 * the v4 `give_campaigns.title` when that table exists, falling back
	 * to `posts.post_title`).
	 *
	 * The standalone-donor count is returned alongside — those donors have
	 * no payments and therefore no form_id, so they're surfaced as a
	 * separate row in the UI rather than folded into a campaign.
	 *
	 * GiveWP 4 introduced a `give_campaigns` table that 1:1-maps to forms
	 * via a `form_id` column; we LEFT JOIN it so v3 sites just see the
	 * post_title and v4 sites see the richer campaign title.
	 *
	 * @return array{campaigns:array<int,array{form_id:int,title:string,donations:int,donors:int,subscriptions:int,total_amount:float}>,standalone_donors:int}
	 * @since  1.0.0
	 */
	public function get_campaigns_preview() {
		global $wpdb;

		if ( ! $this->has_givewp_data() ) {
			return [
				'campaigns'         => [],
				'standalone_donors' => 0,
			];
		}

		$has_v4_campaigns = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'give_campaigns' )
		);

		// Per-form donation aggregates. JOIN postmeta on _give_payment_form_id
		// → payment ID, then sum amounts + distinct donor IDs.
		$campaigns_join = $has_v4_campaigns
			? "LEFT JOIN {$wpdb->prefix}give_campaigns c ON c.form_id = forms.ID"
			: '';
		$title_expr     = $has_v4_campaigns
			? 'COALESCE(NULLIF(c.campaign_title, ""), forms.post_title)'
			: 'forms.post_title';

		// GiveWP stores donation meta in its own `give_donationmeta`
		// custom table (FK donation_id → wp_posts.ID). wp_postmeta is
		// populated transparently by GiveWP's get_post_metadata filter at
		// runtime — but only when GiveWP is active, and raw SQL bypasses
		// that filter regardless. Read from the custom table directly
		// when it exists; fall back to wp_postmeta on legacy installs
		// that pre-date the custom-table store.
		[ $meta_table, $meta_fk ] = $this->resolve_donation_meta_source();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					forms.ID AS form_id,
					{$title_expr} AS title,
					COUNT(DISTINCT p.ID) AS donations,
					COUNT(DISTINCT pm_donor.meta_value) AS donors,
					COALESCE(SUM(CAST(pm_total.meta_value AS DECIMAL(20,2))), 0) AS total_amount
				FROM {$wpdb->posts} forms
				{$campaigns_join}
				LEFT JOIN {$meta_table} pm_form
					ON pm_form.meta_key = %s
					AND pm_form.meta_value = CAST(forms.ID AS CHAR)
				LEFT JOIN {$wpdb->posts} p
					ON p.ID = pm_form.{$meta_fk}
					AND p.post_type = %s
					AND p.post_status != %s
				LEFT JOIN {$meta_table} pm_donor
					ON pm_donor.{$meta_fk} = p.ID
					AND pm_donor.meta_key = %s
				LEFT JOIN {$meta_table} pm_total
					ON pm_total.{$meta_fk} = p.ID
					AND pm_total.meta_key = %s
				WHERE forms.post_type = %s
					AND forms.post_status IN ('publish', 'draft', 'private')
				GROUP BY forms.ID
				ORDER BY donations DESC, forms.post_title ASC",
				'_give_payment_form_id',
				'give_payment',
				'trash',
				'_give_payment_donor_id',
				'_give_payment_total',
				'give_forms'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = [];
		}

		// Per-form subscription counts, merged in afterwards so the main
		// aggregate query stays purely on the payments side (subscriptions
		// table doesn't exist on every site).
		$subs_by_form = [];
		$subs_table   = $wpdb->prefix . 'give_subscriptions';
		$subs_exists  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subs_table ) );
		if ( $subs_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sub_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT product_id AS form_id, COUNT(*) AS subscriptions FROM %i GROUP BY product_id',
					$subs_table
				),
				ARRAY_A
			);
			if ( is_array( $sub_rows ) ) {
				foreach ( $sub_rows as $sr ) {
					$fid = isset( $sr['form_id'] ) ? (int) $sr['form_id'] : 0;
					if ( $fid > 0 ) {
						$subs_by_form[ $fid ] = isset( $sr['subscriptions'] ) ? (int) $sr['subscriptions'] : 0;
					}
				}
			}
		}

		$campaigns = [];
		foreach ( $rows as $row ) {
			$form_id = isset( $row['form_id'] ) ? (int) $row['form_id'] : 0;
			if ( $form_id <= 0 ) {
				continue;
			}
			$campaigns[] = [
				'form_id'       => $form_id,
				'title'         => isset( $row['title'] ) ? (string) $row['title'] : '',
				'donations'     => isset( $row['donations'] ) ? (int) $row['donations'] : 0,
				'donors'        => isset( $row['donors'] ) ? (int) $row['donors'] : 0,
				'subscriptions' => isset( $subs_by_form[ $form_id ] ) ? $subs_by_form[ $form_id ] : 0,
				'total_amount'  => isset( $row['total_amount'] ) ? (float) $row['total_amount'] : 0.0,
			];
		}

		return [
			'campaigns'         => $campaigns,
			'standalone_donors' => $this->get_standalone_donor_count(),
		];
	}
}
