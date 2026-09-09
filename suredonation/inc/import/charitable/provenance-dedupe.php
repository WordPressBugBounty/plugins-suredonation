<?php
/**
 * Shared (donation_post_id, source_campaign_id) dedupe for the Charitable
 * import paths.
 *
 * Both the direct-DB (Donation_Mapper) and CSV (Csv_Parser) paths import the
 * same gift keyed on the (Charitable donation post, source campaign) pair,
 * which both writers store in the indexed `import_provenance` column. Keeping
 * the dedupe in one trait means the two paths can never drift apart on the key,
 * the recurring guard, or the unknown-campaign (0) handling.
 *
 * Per-row dedupe is a single indexed lookup on
 * idx_import_provenance (import_source, import_provenance) — not a scan +
 * JSON-decode of every prior imported row. Each import batch is a separate HTTP
 * request (fresh process), so there is no per-request set to preload; the
 * in-request $seen cache only short-circuits rows this same request already
 * wrote (a multi-campaign donation's repeated rows, or a re-uploaded CSV row).
 *
 * @package SureDonation
 * @since 1.5.1
 */

namespace SureDonation\Inc\Import\Charitable;

use SureDonation\Inc\Database\Tables\Donations;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provenance_Dedupe trait.
 *
 * @since 1.5.1
 */
trait Provenance_Dedupe {

	/**
	 * Keys ("<post>:<token>") written earlier in *this* request, so a repeated
	 * row skips without a second DB round-trip. Not a full prior-import set —
	 * that lives in the indexed column and is queried per row.
	 *
	 * @var array<string, bool>
	 * @since 1.5.1
	 */
	protected $seen = [];

	/**
	 * Stable dedupe key for a (donation post, source campaign) pair.
	 *
	 * Delegates to Donations::build_provenance_key() so the importer and the v6
	 * backfill derive byte-identical keys. The label is the blank-campaign-id
	 * fallback (a CSV export that left the Campaign ID cell empty): keying on the
	 * campaign title keeps the per-campaign rows of a multi-campaign donation
	 * distinct instead of collapsing to "<post>:0" and being deduped away.
	 *
	 * @param  int    $donation_post_id   Charitable donation post ID.
	 * @param  int    $source_campaign_id Charitable campaign ID (0 when unknown).
	 * @param  string $campaign_label     Campaign title/name fallback (optional).
	 * @return string
	 * @since  1.5.1
	 */
	protected function provenance_key( $donation_post_id, $source_campaign_id, $campaign_label = '' ) {
		return Donations::build_provenance_key( $donation_post_id, $source_campaign_id, $campaign_label );
	}

	/**
	 * Whether a (donation post, source campaign) pair has already been imported
	 * — earlier in this request, or by any prior import (indexed column lookup).
	 *
	 * Restricted to one-time rows: a Pro subscription plan-post row (donation_type
	 * = 'recurring') never masks a one-time donation that shares a post id.
	 *
	 * @param  int    $donation_post_id   Charitable donation post ID.
	 * @param  int    $source_campaign_id Charitable campaign ID (0 when unknown).
	 * @param  string $campaign_label     Campaign title/name fallback (optional).
	 * @return bool
	 * @since  1.5.1
	 */
	protected function provenance_seen( $donation_post_id, $source_campaign_id, $campaign_label = '' ) {
		$key = $this->provenance_key( $donation_post_id, $source_campaign_id, $campaign_label );
		if ( isset( $this->seen[ $key ] ) ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'suredonation_donations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed per-row dedupe lookup during a migration; not cacheable.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE import_source = %s AND import_provenance = %s AND donation_type != %s LIMIT 1',
				$table,
				'charitable',
				$key,
				'recurring'
			)
		);

		return null !== $found;
	}

	/**
	 * Record a (donation post, source campaign) pair as written this request so
	 * a later row in the same request skips it.
	 *
	 * @param  int    $donation_post_id   Charitable donation post ID.
	 * @param  int    $source_campaign_id Charitable campaign ID (0 when unknown).
	 * @param  string $campaign_label     Campaign title/name fallback (optional).
	 * @return void
	 * @since  1.5.1
	 */
	protected function mark_provenance_seen( $donation_post_id, $source_campaign_id, $campaign_label = '' ) {
		$this->seen[ $this->provenance_key( $donation_post_id, $source_campaign_id, $campaign_label ) ] = true;
	}
}
