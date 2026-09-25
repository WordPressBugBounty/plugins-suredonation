<?php
/**
 * SureDonation Database Donations Table Class.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Database\Tables;

use SureDonation\Inc\Campaigns\Campaign_Stats;
use SureDonation\Inc\Database\Base;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Pdf\Receipt_Generator;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureDonation Database Donations Table Class.
 *
 * @since 0.0.1
 */
class Donations extends Base {
	use Get_Instance;

	/**
	 * Table suffix.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $table_suffix = 'donations';

	/**
	 * Table version.
	 *
	 * @var int
	 * @since 0.0.1
	 */
	protected $table_version = 7;

	/**
	 * Valid donor-comment moderation statuses.
	 *
	 * `approved` comments are public; `pending` is awaiting review (only reachable
	 * when the "Hold donor comments for review" setting is on); `rejected` is
	 * hidden but kept, so a moderator's decision is not destructive.
	 *
	 * @var array<string>
	 * @since 1.6.0
	 */
	private static $valid_comment_statuses = [
		'approved',
		'pending',
		'rejected',
	];

	/**
	 * Valid payment statuses.
	 *
	 * @var array<string>
	 * @since 0.0.1
	 */
	private static $valid_statuses = [
		'pending',
		'processing',
		'completed',
		'failed',
		'refunded',
		'partially_refunded',
		'cancelled',
		'suspicious',
		// Deliberately not 'failed'. A donor who closed the gateway window never
		// attempted a payment, and collapsing the two destroys the signal we most
		// need: our own capture failure rate. If abandonment and genuine failures
		// share a status, "most donors walk away at the gateway" (a product
		// problem) is indistinguishable from "our captures are breaking" (a bug).
		'abandoned',
	];

	/**
	 * Valid order columns.
	 *
	 * @var array<string>
	 * @since 0.0.1
	 */
	private static $valid_order_columns = [
		'id',
		'campaign_id',
		'amount',
		'created_at',
		'updated_at',
		'payment_status',
		'donor_name',
		'donor_email',
		'subscription_status',
		'subscription_id',
	];

	/**
	 * {@inheritDoc}
	 */
	public function get_schema() {
		return [
			'id'                     => [
				'type' => 'number',
			],
			'campaign_id'            => [
				'type' => 'number',
			],
			'donor_id'               => [
				'type'    => 'number',
				'default' => 0,
			],
			'form_id'                => [
				'type'    => 'number',
				'default' => 0,
			],
			'amount'                 => [
				'type'    => 'string',
				'default' => '0.00000000',
			],
			'fees_covered'           => [
				'type'    => 'string',
				'default' => '0.00000000',
			],
			'refunded_amount'        => [
				'type'    => 'string',
				'default' => '0.00000000',
			],
			'currency'               => [
				'type'    => 'string',
				'default' => 'USD',
			],
			'transaction_id'         => [
				'type'    => 'string',
				'default' => '',
			],
			'customer_id'            => [
				'type'    => 'string',
				'default' => '',
			],
			'stripe_account_id'      => [
				'type'    => 'string',
				'default' => '',
			],
			'gateway'                => [
				'type'    => 'string',
				'default' => 'stripe',
			],
			'payment_status'         => [
				'type'    => 'string',
				'default' => 'pending',
			],
			'payment_mode'           => [
				'type'    => 'string',
				'default' => 'test',
			],
			'donor_name'             => [
				'type'    => 'string',
				'default' => '',
			],
			'donor_email'            => [
				'type'    => 'string',
				'default' => '',
			],
			'donor_phone'            => [
				'type'    => 'string',
				'default' => '',
			],
			'is_anonymous'           => [
				'type'    => 'boolean',
				'default' => false,
			],
			'donation_type'          => [
				'type'    => 'string',
				'default' => 'one-time',
			],
			'subscription_id'        => [
				'type'    => 'string',
				'default' => '',
			],
			'subscription_status'    => [
				'type'    => 'string',
				'default' => '',
			],
			'parent_subscription_id' => [
				'type'    => 'number',
				'default' => 0,
			],
			'donor_comment'          => [
				'type'    => 'string',
				'default' => '',
			],
			'donor_comment_status'   => [
				'type'    => 'string',
				'default' => 'approved',
			],
			'receipt_sent'           => [
				'type'    => 'boolean',
				'default' => false,
			],
			'receipt_pdf_url'        => [
				'type'    => 'string',
				'default' => '',
			],
			'donation_data'          => [
				'type'    => 'array',
				'default' => [],
			],
			'log'                    => [
				'type'    => 'array',
				'default' => [],
			],
			'ip_address'             => [
				'type'    => 'string',
				'default' => '',
			],
			'user_agent'             => [
				'type'    => 'string',
				'default' => '',
			],
			'referer_url'            => [
				'type'    => 'string',
				'default' => '',
			],
			'import_source_id'       => [
				'type'    => 'number',
				'default' => 0,
			],
			'import_source'          => [
				'type'    => 'string',
				'default' => '',
			],
			'import_provenance'      => [
				'type'    => 'string',
				'default' => '',
			],
			'created_at'             => [
				'type' => 'datetime',
			],
			'updated_at'             => [
				'type' => 'datetime',
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_columns_definition() {
		return [
			'id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
			'campaign_id BIGINT(20) UNSIGNED NOT NULL',
			'donor_id BIGINT(20) UNSIGNED NULL',
			'form_id BIGINT(20) UNSIGNED NULL',
			'amount DECIMAL(26,8) NOT NULL',
			'fees_covered DECIMAL(26,8) NOT NULL DEFAULT 0',
			'refunded_amount DECIMAL(26,8) NOT NULL DEFAULT 0',
			'currency VARCHAR(10) NOT NULL',
			'transaction_id VARCHAR(255) NOT NULL',
			'customer_id VARCHAR(50) NOT NULL',
			'stripe_account_id VARCHAR(50) NOT NULL DEFAULT \'\'',
			'gateway VARCHAR(20) NOT NULL',
			'payment_status VARCHAR(50) NOT NULL',
			'payment_mode VARCHAR(20) NOT NULL',
			'donor_name VARCHAR(255) NOT NULL',
			'donor_email VARCHAR(255) NOT NULL',
			'donor_phone VARCHAR(50) NOT NULL',
			'is_anonymous TINYINT(1) NOT NULL DEFAULT 0',
			'donation_type VARCHAR(30) NOT NULL',
			'subscription_id VARCHAR(255) NOT NULL',
			'subscription_status VARCHAR(30) NOT NULL',
			'parent_subscription_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
			'donor_comment TEXT',
			'donor_comment_status VARCHAR(20) NOT NULL DEFAULT \'approved\'',
			'receipt_sent TINYINT(1) NOT NULL DEFAULT 0',
			'receipt_pdf_url VARCHAR(255) NOT NULL',
			'donation_data LONGTEXT',
			'log LONGTEXT',
			'ip_address VARCHAR(45) NOT NULL',
			'user_agent TEXT',
			'referer_url TEXT',
			'import_source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
			'import_source VARCHAR(20) NOT NULL DEFAULT \'\'',
			'import_provenance VARCHAR(64) NOT NULL DEFAULT \'\'',
			'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
			'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
			'INDEX idx_campaign (campaign_id)',
			'INDEX idx_donor (donor_id)',
			'INDEX idx_status (payment_status)',
			'INDEX idx_email (donor_email)',
			'INDEX idx_created (created_at)',
			'INDEX idx_form (form_id)',
			'INDEX idx_subscription (subscription_id)',
			'INDEX idx_subscription_status (subscription_status)',
			'INDEX idx_parent_subscription (parent_subscription_id)',
			'INDEX idx_import_source (import_source_id, import_source)',
			'INDEX idx_import_provenance (import_source, import_provenance)',
			'INDEX idx_stripe_account (stripe_account_id)',
		];
	}

	/**
	 * New columns added across versions.
	 *
	 * Version 2 added subscription support; version 4 added the
	 * source-agnostic pair `import_source_id` + `import_source` used by
	 * the migration tool for duplicate detection and rollback; version 5
	 * added `stripe_account_id` so donations record which connected Stripe
	 * account processed them (multiple Stripe accounts support); version 6
	 * added `import_provenance` — an indexed `(donation_post_id, source_campaign_id)`
	 * key the Charitable importer dedupes on with a single indexed lookup per
	 * row, instead of scanning + JSON-decoding every prior imported row per batch;
	 * version 7 added `donor_comment_status`, defaulting to `approved` so
	 * comments that predate moderation stay visible.
	 *
	 * Version 7 rather than 6: `import_provenance` had already taken 6 on dev
	 * while this branch was open, and the upgrade only runs when the number
	 * increases (Database\Base::set_db_upgradable()). Leaving both columns on 6
	 * would mean any site already upgraded to 6 never receives
	 * `donor_comment_status`, while get_schema() still declares it and
	 * prepare_data() names every declared column in the INSERT — so every
	 * donation would fail with "Unknown column 'donor_comment_status'".
	 *
	 * No index accompanies `donor_comment_status`: it is `approved` on virtually
	 * every row, so a `(campaign_id, donor_comment_status)` index measured ~3%
	 * better than the existing `idx_campaign` on a 200k-row table and still
	 * filesorted, while adding write cost to the plugin's hottest table. Its one
	 * reader (Campaign_Stats::get_donor_comments()) is also behind a 5-minute
	 * transient. Revisit only if that query shows up in real profiling.
	 *
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function get_new_columns_definition() {
		return [
			'subscription_id VARCHAR(255) NOT NULL AFTER donation_type',
			'subscription_status VARCHAR(30) NOT NULL AFTER subscription_id',
			'parent_subscription_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER subscription_status',
			'import_source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER referer_url',
			'import_source VARCHAR(20) NOT NULL DEFAULT \'\' AFTER import_source_id',
			'import_provenance VARCHAR(64) NOT NULL DEFAULT \'\' AFTER import_source',
			'stripe_account_id VARCHAR(50) NOT NULL DEFAULT \'\' AFTER customer_id',
			'donor_comment_status VARCHAR(20) NOT NULL DEFAULT \'approved\' AFTER donor_comment',
			'INDEX idx_subscription (subscription_id)',
			'INDEX idx_subscription_status (subscription_status)',
			'INDEX idx_parent_subscription (parent_subscription_id)',
			'INDEX idx_import_source (import_source_id, import_source)',
			'INDEX idx_import_provenance (import_source, import_provenance)',
			'INDEX idx_stripe_account (stripe_account_id)',
		];
	}

	/**
	 * One-time data migrations for the donations table.
	 *
	 * Each backfill is gated on the version being upgraded *into* (via
	 * $this->prev_version) so it runs exactly once, on the upgrade that adds the
	 * column, and is skipped on fresh installs (which create the column already
	 * populated / empty as appropriate) and on later upgrades.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function run_data_migrations() {
		// A failed CREATE/ALTER earlier in this upgrade already cleared the flag;
		// the column may not exist, so don't run an UPDATE against it.
		if ( ! $this->db_upgradable ) {
			return;
		}

		if ( $this->prev_version < 5 ) {
			$this->backfill_stripe_account_id();
		}

		if ( $this->prev_version < 6 ) {
			$this->backfill_import_provenance();
		}
	}

	/**
	 * Backfill `stripe_account_id` on the upgrade into v5.
	 *
	 * Before multi-account there could only be a single connected Stripe account,
	 * so every pre-v5 Stripe donation belongs to the current (single) default
	 * account. Backfill it so refunds and subscription lifecycle actions keep
	 * routing to the originating account after a second account is connected and
	 * the default is switched. Idempotent (touches only empty rows).
	 *
	 * @return void
	 * @since 1.3.0
	 */
	private function backfill_stripe_account_id() {
		if ( ! class_exists( '\SureDonation\Inc\Payments\Stripe\Stripe_Helper' ) ) {
			return;
		}

		// Runs during the v5 DB upgrade — before any second account can be
		// connected via the UI — so the default is still the single legacy account.
		$account_id = \SureDonation\Inc\Payments\Stripe\Stripe_Helper::get_default_account_id();
		if ( ! is_string( $account_id ) || '' === $account_id ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time backfill of a newly added column; not cacheable.
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET stripe_account_id = %s WHERE gateway = %s AND ( stripe_account_id = %s OR stripe_account_id IS NULL )',
				$this->get_tablename(),
				$account_id,
				'stripe',
				''
			)
		);

		// A transient failure (e.g. lock wait timeout on a busy table) must not
		// persist the new version: `prev_version >= 5` would then skip this
		// one-shot backfill forever. Leaving the version unwritten makes the
		// idempotent sequence retry on the next request.
		if ( false === $result ) {
			$this->db_upgradable = false;
		}
	}

	/**
	 * Backfill `import_provenance` on the upgrade into v6.
	 *
	 * The Charitable importer moved its dedupe key out of a per-batch scan of
	 * `donation_data` and onto this indexed column. Rows imported before v6 have
	 * an empty key, so a re-import after upgrade would fail to match them and
	 * insert duplicates. Reconstruct the key from the stored
	 * `donation_data.charitable` block — the same `(donation_post_id,
	 * source_campaign_id | campaign label)` rule the importer keys on — for every
	 * pre-v6 one-time Charitable row. Chunked so a large migrated table does not
	 * exhaust memory during the upgrade; idempotent (touches only empty keys).
	 *
	 * @return void
	 * @since 1.5.1
	 */
	private function backfill_import_provenance() {
		global $wpdb;
		$table = $this->get_tablename();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time chunked backfill of a newly added column; not cacheable.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, donation_data FROM %i WHERE import_source = %s AND donation_type != %s AND import_provenance = %s LIMIT 500',
					$table,
					'charitable',
					'recurring',
					''
				),
				ARRAY_A
			);

			if ( empty( $rows ) || ! is_array( $rows ) ) {
				break;
			}

			$fetched = count( $rows );

			foreach ( $rows as $row ) {
				$data = json_decode( (string) ( $row['donation_data'] ?? '' ), true );
				$c    = is_array( $data ) && isset( $data['charitable'] ) && is_array( $data['charitable'] ) ? $data['charitable'] : [];
				$post = isset( $c['donation_post_id'] ) ? absint( $c['donation_post_id'] ) : 0;

				// A row with no resolvable donation post can never be dedupe-matched
				// or rolled back; leave its key empty (it is already un-reversible)
				// rather than fabricate a colliding "0:…" key.
				if ( $post <= 0 ) {
					$key = '';
				} else {
					$campaign = isset( $c['source_campaign_id'] ) ? absint( $c['source_campaign_id'] ) : 0;
					// DB-path rows carry `campaign_name`; CSV-path rows carry
					// `campaign_title`. Either serves as the blank-id fallback label.
					$label = '';
					if ( isset( $c['campaign_title'] ) && is_scalar( $c['campaign_title'] ) ) {
						$label = (string) $c['campaign_title'];
					} elseif ( isset( $c['campaign_name'] ) && is_scalar( $c['campaign_name'] ) ) {
						$label = (string) $c['campaign_name'];
					}
					$key = self::build_provenance_key( $post, $campaign, $label );
				}

				if ( '' === $key ) {
					// Nothing to store, but stamp a sentinel so the WHERE clause
					// stops selecting this row and the loop terminates.
					$key = '-';
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time backfill update; not cacheable.
				$wpdb->update( $table, [ 'import_provenance' => $key ], [ 'id' => absint( $row['id'] ) ] );
			}
		} while ( 500 === $fetched );
	}

	/**
	 * Build the indexed dedupe key for a Charitable donation row.
	 *
	 * `"<donation_post_id>:<token>"`, where the token is the numeric campaign id
	 * when present, otherwise a short hash of the campaign label (so the
	 * per-campaign rows of a multi-campaign donation whose export left the
	 * Campaign ID cell blank stay distinct instead of collapsing to "<post>:0"),
	 * otherwise "0". Static so both the importer (Provenance_Dedupe) and the v6
	 * backfill derive identical keys.
	 *
	 * @param  int    $donation_post_id   Charitable donation post ID.
	 * @param  int    $source_campaign_id Charitable campaign ID (0 when absent).
	 * @param  string $campaign_label     Campaign title/name fallback (optional).
	 * @return string
	 * @since  1.5.1
	 */
	public static function build_provenance_key( $donation_post_id, $source_campaign_id, $campaign_label = '' ) {
		$post  = absint( $donation_post_id );
		$cid   = absint( $source_campaign_id );
		$label = trim( (string) $campaign_label );

		if ( $cid > 0 ) {
			$token = (string) $cid;
		} elseif ( '' !== $label ) {
			$token = 't:' . substr( md5( strtolower( $label ) ), 0, 12 );
		} else {
			$token = '0';
		}

		return $post . ':' . $token;
	}

	/**
	 * Add a new donation record.
	 *
	 * @param array<mixed> $data Donation data to insert.
	 * @return int|false The donation ID on success, false on error.
	 * @since 0.0.1
	 */
	public static function add( $data ) {
		// Use isset check — empty() would reject campaign_id=0 which is valid for standalone forms.
		if ( ! isset( $data['campaign_id'] ) ) {
			return false;
		}

		$instance = self::get_instance();

		// Set created_at if not provided (use GMT for consistency with TIMESTAMP column default).
		if ( ! isset( $data['created_at'] ) ) {
			$data['created_at'] = current_time( 'mysql', true );
		}

		$result = $instance->use_insert( $data );

		if ( $result ) {
			Campaign_Stats::clear_cache( absint( Helper::get_string_value( $data['campaign_id'] ) ) );

			// Notify integration hooks (e.g. OttoKit) about the new donation.
			// Imported rows carry an import_source and are skipped: migrating
			// historical donations must not replay automations.
			if ( empty( $data['import_source'] ) ) {
				$donation_id = absint( $result );
				$donation    = self::get( $donation_id );
				$donation    = is_array( $donation ) ? $donation : [];

				// Curated payload (internal/gateway-only columns omitted; donor
				// identity included, see the note in get_integration_payload())
				// shared by every hook below.
				$payload = self::get_integration_payload( $donation );

				/**
				 * Fires when a new donation record is created.
				 *
				 * @param int          $donation_id Newly created donation ID.
				 * @param array<mixed> $donation    Curated donation payload.
				 * @since 1.1.0
				 */
				do_action( 'suredonation_donation_created', $donation_id, $payload );

				/**
				 * Fires when a new donation record is created.
				 *
				 * Mirrors `suredonation_donation_created`; the OttoKit (formerly
				 * SureTriggers) "New Donation" trigger listens on this hook name.
				 *
				 * @param int          $donation_id Newly created donation ID.
				 * @param array<mixed> $donation    Curated donation payload.
				 * @since 1.2.0
				 */
				do_action( 'suredonation_new_donation', $donation_id, $payload );

				// Some donations are created already-completed rather than
				// transitioning through update() — recurring renewals and
				// admin-recorded paid donations. Fire the completion event here
				// too so integration hooks still see them.
				if ( 'completed' === ( $data['payment_status'] ?? '' ) ) {
					/**
					 * Fires when a donation payment is completed.
					 *
					 * @param int          $donation_id Donation ID.
					 * @param array<mixed> $donation    Curated donation payload after insertion.
					 * @since 1.2.0
					 */
					do_action( 'suredonation_donation_completed', $donation_id, $payload );
				}
			}
		}

		return $result;
	}

	/**
	 * Update a donation record.
	 *
	 * @param int                 $donation_id Donation ID to update.
	 * @param array<string,mixed> $data        Data to update.
	 * @return int|false Number of rows updated or false on error.
	 * @since 0.0.1
	 */
	public static function update( $donation_id, $data = [] ) {
		if ( empty( $donation_id ) ) {
			return false;
		}

		// Capture the current status and refunded amount before the write so
		// integration hooks (e.g. OttoKit) can react to the transition and to
		// refund events, not just the resulting values.
		$old_status   = '';
		$old_refunded = 0.0;
		if ( isset( $data['payment_status'] ) || isset( $data['refunded_amount'] ) ) {
			$existing     = self::get( absint( $donation_id ) );
			$old_status   = is_array( $existing ) ? Helper::get_string_value( $existing['payment_status'] ?? '' ) : '';
			$old_refunded = is_array( $existing ) ? Helper::get_float_value( $existing['refunded_amount'] ?? 0 ) : 0.0;
		}

		// Set updated_at.
		$data['updated_at'] = current_time( 'mysql' );

		$updated = self::get_instance()->use_update( $data, [ 'id' => absint( $donation_id ) ] );

		// Status/amount changes (e.g. a webhook completing a pending donation)
		// affect the cached stats and donor lists.
		if ( $updated ) {
			$donation = self::get( absint( $donation_id ) );
			$donation = is_array( $donation ) ? $donation : [];
			if ( ! empty( $donation['campaign_id'] ) ) {
				Campaign_Stats::clear_cache( absint( Helper::get_string_value( $donation['campaign_id'] ) ) );
			}

			// Curated payload (internal/gateway-only columns omitted; donor
			// identity included, see the note in get_integration_payload())
			// shared by every hook below.
			$payload = self::get_integration_payload( $donation );

			if ( isset( $data['payment_status'] ) ) {
				$new_status = Helper::get_string_value( $data['payment_status'] );

				if ( $new_status !== $old_status ) {
					/**
					 * Fires when a donation's payment status changes.
					 *
					 * @param int          $donation_id Donation ID.
					 * @param string       $new_status  New payment status.
					 * @param string       $old_status  Previous payment status (empty string if unknown).
					 * @param array<mixed> $donation    Curated donation payload after the update.
					 * @since 1.1.0
					 */
					do_action( 'suredonation_donation_status_changed', absint( $donation_id ), $new_status, $old_status, $payload );

					// Fire the completion event for any genuine transition into
					// 'completed' — including admin review states (suspicious,
					// cancelled) — but never for refund reversals that restore
					// the 'completed' status (refunded/partially_refunded ->
					// completed), which would replay the completion automation.
					if ( 'completed' === $new_status && ! in_array( $old_status, [ 'completed', 'refunded', 'partially_refunded' ], true ) ) {
						/**
						 * Fires when a donation payment is completed.
						 *
						 * @param int          $donation_id Donation ID.
						 * @param array<mixed> $donation    Curated donation payload after the update.
						 * @since 1.2.0
						 */
						do_action( 'suredonation_donation_completed', absint( $donation_id ), $payload );
					}
				}
			}

			// A rise in refunded_amount means a refund was processed. Keying off
			// the amount (not the status string) catches repeat partial refunds
			// that leave the status as partially_refunded, and excludes refund
			// reversals where the amount drops.
			if ( isset( $data['refunded_amount'] ) ) {
				$new_refunded = Helper::get_float_value( $data['refunded_amount'] );

				if ( $new_refunded - $old_refunded > 0.0001 ) {
					/**
					 * Fires when a donation is refunded, fully or partially.
					 *
					 * @param int          $donation_id    Donation ID.
					 * @param float        $refund_amount  Amount refunded in this event.
					 * @param float        $total_refunded Cumulative amount refunded to date.
					 * @param array<mixed> $donation       Curated donation payload after the update.
					 * @since 1.2.0
					 */
					do_action( 'suredonation_donation_refunded', absint( $donation_id ), $new_refunded - $old_refunded, $new_refunded, $payload );
				}
			}
		}

		return $updated;
	}

	/**
	 * Build a curated donation payload for integration hooks.
	 *
	 * Trims the raw database row to the fields advertised in the OttoKit embed
	 * `sample_response`, omitting internal and gateway-only columns that must not
	 * leave the site (ip_address, user_agent, referer_url, the admin `log`, the
	 * gateway `customer_id`, and the full `donation_data` submission). Monetary
	 * values are cast to float to match the sample the automation builder maps
	 * against (the raw column is a DECIMAL string). Shared by every `do_action`
	 * in add()/update() so no listener — OttoKit or otherwise — receives the raw
	 * row.
	 *
	 * Anonymous donations carry their real donor identity here. The anonymous
	 * checkbox is a display-only flag — the data is stored and processed as
	 * usual, and only the public donor wall / recent donations / top donors mask
	 * it. Automations that need to treat anonymous donors differently branch on
	 * the `is_anonymous` field in this payload; blanking the identity instead
	 * would silently break receipting and CRM sync for those donations.
	 *
	 * @param array<string,mixed> $donation Raw donation record from self::get().
	 * @return array<string,mixed> Curated, integration-safe payload.
	 * @since 1.2.0
	 */
	public static function get_integration_payload( $donation ) {
		if ( ! is_array( $donation ) ) {
			return [];
		}

		$is_anonymous = ! empty( $donation['is_anonymous'] );

		$payload = [
			'id'                   => isset( $donation['id'] ) ? absint( Helper::get_string_value( $donation['id'] ) ) : 0,
			'campaign_id'          => isset( $donation['campaign_id'] ) ? absint( Helper::get_string_value( $donation['campaign_id'] ) ) : 0,
			'form_id'              => isset( $donation['form_id'] ) ? absint( Helper::get_string_value( $donation['form_id'] ) ) : 0,
			'donor_id'             => isset( $donation['donor_id'] ) ? absint( Helper::get_string_value( $donation['donor_id'] ) ) : 0,
			'donor_name'           => Helper::get_string_value( $donation['donor_name'] ?? '' ),
			'donor_email'          => Helper::get_string_value( $donation['donor_email'] ?? '' ),
			'donor_phone'          => Helper::get_string_value( $donation['donor_phone'] ?? '' ),
			'amount'               => Helper::get_float_value( $donation['amount'] ?? 0 ),
			'fees_covered'         => Helper::get_float_value( $donation['fees_covered'] ?? 0 ),
			'refunded_amount'      => Helper::get_float_value( $donation['refunded_amount'] ?? 0 ),
			'currency'             => Helper::get_string_value( $donation['currency'] ?? '' ),
			'gateway'              => Helper::get_string_value( $donation['gateway'] ?? '' ),
			'payment_status'       => Helper::get_string_value( $donation['payment_status'] ?? '' ),
			'payment_mode'         => Helper::get_string_value( $donation['payment_mode'] ?? '' ),
			'donation_type'        => Helper::get_string_value( $donation['donation_type'] ?? '' ),
			'transaction_id'       => Helper::get_string_value( $donation['transaction_id'] ?? '' ),
			'subscription_id'      => Helper::get_string_value( $donation['subscription_id'] ?? '' ),
			'subscription_status'  => Helper::get_string_value( $donation['subscription_status'] ?? '' ),
			'donor_comment'        => Helper::get_string_value( $donation['donor_comment'] ?? '' ),
			'donor_comment_status' => Helper::get_string_value( $donation['donor_comment_status'] ?? '' ),
			'is_anonymous'         => $is_anonymous,
			'created_at'           => Helper::get_string_value( $donation['created_at'] ?? '' ),
			'updated_at'           => Helper::get_string_value( $donation['updated_at'] ?? '' ),
		];

		/**
		 * Filter the curated donation payload passed to every integration hook.
		 *
		 * The payload carries the donor's real identity even for anonymous
		 * donations, because the anonymous checkbox only masks public donor
		 * lists — automations still need a usable record, and they can branch on
		 * the `is_anonymous` field. A site with a stricter policy (for example an
		 * automation that posts donor names somewhere public) can use this filter
		 * to blank or drop fields before they reach OttoKit or any third-party
		 * listener.
		 *
		 * @param array<string,mixed> $payload  Curated payload.
		 * @param array<string,mixed> $donation Raw donation record.
		 * @since 1.4.0
		 */
		return apply_filters( 'suredonation_integration_payload', $payload, $donation );
	}

	/**
	 * Get a single donation by ID.
	 *
	 * @param int $donation_id Donation ID.
	 * @return array<mixed>|null Donation data or null if not found.
	 * @since 0.0.1
	 */
	public static function get( $donation_id ) {
		if ( empty( $donation_id ) ) {
			return null;
		}

		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$instance->get_tablename(),
				absint( $donation_id )
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		return $instance->decode_by_datatype( $result );
	}

	/**
	 * Get all donations with pagination.
	 *
	 * @param int    $limit   Number of records to return.
	 * @param int    $offset  Offset for pagination.
	 * @param string $orderby Column to order by.
	 * @param string $order   Order direction (ASC or DESC).
	 * @return array<mixed> Array of donations.
	 * @since 0.0.1
	 */
	public static function get_all( $limit = 10, $offset = 0, $orderby = 'created_at', $order = 'DESC' ) {
		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// Validate orderby column.
		if ( ! in_array( $orderby, self::$valid_order_columns, true ) ) {
			$orderby = 'created_at';
		}

		// Validate order direction.
		$order = strtoupper( $order );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$order = 'DESC';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data changes frequently, caching would show stale results.
		$results = 'ASC' === $order
			? $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY %i ASC LIMIT %d, %d',
					$table,
					$orderby,
					absint( $offset ),
					absint( $limit )
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY %i DESC LIMIT %d, %d',
					$table,
					$orderby,
					absint( $offset ),
					absint( $limit )
				),
				ARRAY_A
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Get donations for admin listing with optional filters.
	 *
	 * @param string $status      Payment status filter ('all' for no filter).
	 * @param int    $campaign_id Campaign ID filter (0 for no filter).
	 * @param string $search      Search term for donor_name, donor_email, or transaction_id.
	 * @param int    $limit       Number of records to return.
	 * @param int    $offset      Offset for pagination.
	 * @param string $orderby     Column to order by.
	 * @param string $order       Order direction (ASC or DESC).
	 * @return array<mixed> Array of donations.
	 * @since 0.0.1
	 */
	public static function get_admin_list( $status = 'all', $campaign_id = 0, $search = '', $limit = 10, $offset = 0, $orderby = 'created_at', $order = 'DESC' ) {
		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// Validate orderby column.
		if ( ! in_array( $orderby, self::$valid_order_columns, true ) ) {
			$orderby = 'created_at';
		}

		// Validate order direction.
		$order = strtoupper( $order );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$order = 'DESC';
		}

		// Build query based on filters.
		// Note: Renewal records (donation_type = 'renewal') are intentionally included in the listing.
		// They are shown alongside parent subscriptions so admins can see all transaction activity.
		// Renewals are also accessible from the parent donation's subscription detail billing history.
		// With no status filter, abandoned rows are left out: they are kept as
		// funnel data (a campaign with 40 starts against 3 completions has
		// learned something real) but a donor who walked away from the gateway is
		// not a transaction an admin needs in their default view. Asking for the
		// status explicitly still returns them, and count_admin_list() mirrors
		// this or the pagination totals disagree with the rows.
		$has_status   = 'all' !== $status;
		$has_campaign = $campaign_id > 0;
		$has_search   = ! empty( $search );
		$is_asc       = 'ASC' === $order;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data changes frequently, caching would show stale results.

		// All three filters.
		if ( $has_status && $has_campaign && $has_search ) {
			$search_term = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$results     = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s AND campaign_id = %d AND (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) ORDER BY %i ASC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						absint( $campaign_id ),
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s AND campaign_id = %d AND (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) ORDER BY %i DESC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						absint( $campaign_id ),
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		} elseif ( $has_status && $has_campaign ) {
			$results = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s AND campaign_id = %d ORDER BY %i ASC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						absint( $campaign_id ),
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s AND campaign_id = %d ORDER BY %i DESC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						absint( $campaign_id ),
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		} elseif ( $has_status && $has_search ) {
			$search_term = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$results     = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s AND (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) ORDER BY %i ASC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s AND (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) ORDER BY %i DESC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		} elseif ( $has_campaign && $has_search ) {
			$search_term = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$results     = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE campaign_id = %d AND (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) AND payment_status != \'abandoned\' ORDER BY %i ASC LIMIT %d, %d',
						$table,
						absint( $campaign_id ),
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE campaign_id = %d AND (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) AND payment_status != \'abandoned\' ORDER BY %i DESC LIMIT %d, %d',
						$table,
						absint( $campaign_id ),
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		} elseif ( $has_status ) {
			$results = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s ORDER BY %i ASC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status = %s ORDER BY %i DESC LIMIT %d, %d',
						$table,
						sanitize_text_field( $status ),
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		} elseif ( $has_campaign ) {
			$results = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE campaign_id = %d AND payment_status != \'abandoned\' ORDER BY %i ASC LIMIT %d, %d',
						$table,
						absint( $campaign_id ),
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE campaign_id = %d AND payment_status != \'abandoned\' ORDER BY %i DESC LIMIT %d, %d',
						$table,
						absint( $campaign_id ),
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		} elseif ( $has_search ) {
			$search_term = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$results     = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) AND payment_status != \'abandoned\' ORDER BY %i ASC LIMIT %d, %d',
						$table,
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE (donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s) AND payment_status != \'abandoned\' ORDER BY %i DESC LIMIT %d, %d',
						$table,
						$search_term,
						$search_term,
						$search_term,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		} else {
			$results = $is_asc
				? $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status != \'abandoned\' ORDER BY %i ASC LIMIT %d, %d',
						$table,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				)
				: $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE payment_status != \'abandoned\' ORDER BY %i DESC LIMIT %d, %d',
						$table,
						$orderby,
						absint( $offset ),
						absint( $limit )
					),
					ARRAY_A
				);
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Build the WHERE clause + prepare-args for an export query.
	 *
	 * Always constrains to one-time donations (subscription_id = '' AND
	 * parent_subscription_id = 0) so recurring/renewal rows never leak into the
	 * free export — recurring export is Pro (see the Import & Export spec, #237).
	 * Optional filters: status, campaign_id, payment_mode, gateway, and a
	 * created_at date range (after / before).
	 *
	 * @param array<string, mixed> $filters Filter map.
	 * @param array<int, mixed>    $args    Prepare-args, populated by reference in placeholder order.
	 * @return string WHERE clause (without the "WHERE" keyword); placeholders only, no interpolated values.
	 * @since 1.3.0
	 */
	private static function build_export_where( $filters, &$args ) {
		$conditions = [ '1=1' ];

		/**
		 * Whether the donations export is restricted to one-time donations.
		 *
		 * True by default so recurring/renewal rows never leak into the free
		 * export; Pro returns false to include subscriptions and renewals.
		 *
		 * @param bool $one_time_only Whether to restrict to one-time donations.
		 */
		if ( apply_filters( 'suredonation_export_one_time_only', true ) ) {
			$conditions[] = 'subscription_id = %s';
			$conditions[] = 'parent_subscription_id = %d';
			$args[]       = '';
			$args[]       = 0;
		}

		$status = sanitize_text_field( Helper::get_string_value( $filters['status'] ?? '' ) );
		if ( '' !== $status && 'all' !== $status ) {
			$conditions[] = 'payment_status = %s';
			$args[]       = $status;
		}

		$campaign_id = absint( Helper::get_string_value( $filters['campaign_id'] ?? 0 ) );
		if ( $campaign_id > 0 ) {
			$conditions[] = 'campaign_id = %d';
			$args[]       = $campaign_id;
		}

		$payment_mode = sanitize_text_field( Helper::get_string_value( $filters['payment_mode'] ?? '' ) );
		if ( '' !== $payment_mode ) {
			$conditions[] = 'payment_mode = %s';
			$args[]       = $payment_mode;
		}

		$gateway = sanitize_text_field( Helper::get_string_value( $filters['gateway'] ?? '' ) );
		if ( '' !== $gateway ) {
			$conditions[] = 'gateway = %s';
			$args[]       = $gateway;
		}

		$after = sanitize_text_field( Helper::get_string_value( $filters['after'] ?? '' ) );
		if ( '' !== $after ) {
			$conditions[] = 'created_at >= %s';
			$args[]       = $after;
		}

		$before = sanitize_text_field( Helper::get_string_value( $filters['before'] ?? '' ) );
		if ( '' !== $before ) {
			// A date-only `before` (Y-m-d) coerces to 00:00:00, which would
			// silently drop donations made later that same day. Normalize to
			// end-of-day so the whole end date is inclusive; full datetimes
			// are left untouched.
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ) {
				$before .= ' 23:59:59';
			}
			$conditions[] = 'created_at <= %s';
			$args[]       = $before;
		}

		return implode( ' AND ', $conditions );
	}

	/**
	 * Count one-time donations matching the export filters.
	 *
	 * @param array<string, mixed> $filters Filter map (see build_export_where()).
	 * @return int Matching row count.
	 * @since 1.3.0
	 */
	public static function count_for_export( $filters = [] ) {
		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		$args  = [];
		$where = self::build_export_where( $filters, $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export count over live data.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built only from static placeholder fragments; every value is passed through prepare args.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( [ $table ], $args ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Fetch one-time donations for export, decoded.
	 *
	 * @param array<string, mixed> $filters Filter map (see build_export_where()).
	 * @param int                  $limit   Max rows to return (0 = no limit).
	 * @param int                  $offset  Offset for pagination.
	 * @return array<int, array<string, mixed>> Decoded donation rows.
	 * @since 1.3.0
	 */
	public static function get_for_export( $filters = [], $limit = 0, $offset = 0 ) {
		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		$args  = [];
		$where = self::build_export_where( $filters, $args );

		$sql          = "SELECT * FROM %i WHERE {$where} ORDER BY created_at DESC";
		$prepare_args = array_merge( [ $table ], $args );

		if ( $limit > 0 ) {
			$sql           .= ' LIMIT %d, %d';
			$prepare_args[] = absint( $offset );
			$prepare_args[] = absint( $limit );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export query over live data.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql is assembled only from static placeholder fragments; every value is passed through prepare args.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Get donations by status with pagination.
	 *
	 * @param string $status  Payment status.
	 * @param int    $limit   Number of records to return.
	 * @param int    $offset  Offset for pagination.
	 * @param string $orderby Column to order by.
	 * @param string $order   Order direction (ASC or DESC).
	 * @return array<mixed> Array of donations.
	 * @since 0.0.1
	 */
	public static function get_by_status( $status, $limit = 10, $offset = 0, $orderby = 'created_at', $order = 'DESC' ) {
		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// Validate orderby column.
		if ( ! in_array( $orderby, self::$valid_order_columns, true ) ) {
			$orderby = 'created_at';
		}

		// Validate order direction.
		$order = strtoupper( $order );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$order = 'DESC';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data changes frequently, caching would show stale results.
		$results = 'ASC' === $order
			? $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE payment_status = %s ORDER BY %i ASC LIMIT %d, %d',
					$table,
					sanitize_text_field( $status ),
					$orderby,
					absint( $offset ),
					absint( $limit )
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE payment_status = %s ORDER BY %i DESC LIMIT %d, %d',
					$table,
					sanitize_text_field( $status ),
					$orderby,
					absint( $offset ),
					absint( $limit )
				),
				ARRAY_A
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Get donations by campaign ID with pagination.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $limit       Number of records to return.
	 * @param int    $offset      Offset for pagination.
	 * @param string $orderby     Column to order by.
	 * @param string $order       Order direction (ASC or DESC).
	 * @return array<mixed> Array of donations.
	 * @since 0.0.1
	 */
	public static function get_by_campaign_id( $campaign_id, $limit = 100, $offset = 0, $orderby = 'created_at', $order = 'DESC' ) {
		if ( empty( $campaign_id ) ) {
			return [];
		}

		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// Validate orderby column.
		if ( ! in_array( $orderby, self::$valid_order_columns, true ) ) {
			$orderby = 'created_at';
		}

		// Validate order direction.
		$order = strtoupper( $order );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$order = 'DESC';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data changes frequently, caching would show stale results.
		$results = 'ASC' === $order
			? $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE campaign_id = %d ORDER BY %i ASC LIMIT %d, %d',
					$table,
					absint( $campaign_id ),
					$orderby,
					absint( $offset ),
					absint( $limit )
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE campaign_id = %d ORDER BY %i DESC LIMIT %d, %d',
					$table,
					absint( $campaign_id ),
					$orderby,
					absint( $offset ),
					absint( $limit )
				),
				ARRAY_A
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Delete a donation record and its receipt PDF.
	 *
	 * `receipt_pdf_url` is the only pointer to the receipt on disk, so once the
	 * row is gone nothing can reach the file again and it would sit in the
	 * uploads directory indefinitely, holding the donor's name and email
	 * alongside the amount (a Pro template can add more through
	 * `suredonation_receipt_html`). The file is removed first, and the row and
	 * its pointer are kept while the file survives so a retry can still reach
	 * it - the same retry contract the privacy eraser follows.
	 *
	 * A pointer that fails containment in `relative_to_path()` is the one
	 * exception: it reports "nothing to delete" and does not block the row,
	 * because no caller will ever act on it.
	 *
	 * @param int $donation_id Donation ID.
	 * @return int|false Number of rows deleted, or false on error or when the receipt file could not be removed.
	 * @since 0.0.1
	 */
	public static function delete( $donation_id ) {
		if ( empty( $donation_id ) ) {
			return false;
		}

		$donation_id = absint( $donation_id );
		$donation    = self::get( $donation_id );

		// delete_receipt() is a no-op that reports success when the column is
		// empty or the file is already gone, so donations without a receipt
		// fall straight through to the row delete.
		if ( is_array( $donation ) && ! Receipt_Generator::delete_receipt( Helper::get_string_value( $donation['receipt_pdf_url'] ?? '' ) ) ) {
			return false;
		}

		return self::get_instance()->use_delete( [ 'id' => $donation_id ] );
	}

	/**
	 * Get donations by donor email.
	 *
	 * @param string $email  Donor email.
	 * @param int    $limit  Max rows to return; 0 (default) returns all rows.
	 * @param int    $offset Row offset, applied only when $limit > 0.
	 * @return array<mixed> Array of donations.
	 * @since 0.0.1
	 */
	public static function get_by_donor_email( $email, $limit = 0, $offset = 0 ) {
		if ( empty( $email ) ) {
			return [];
		}

		$instance = self::get_instance();
		global $wpdb;

		$limit  = max( 0, (int) $limit );
		$offset = max( 0, (int) $offset );

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE donor_email = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					$instance->get_tablename(),
					sanitize_email( $email ),
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE donor_email = %s ORDER BY created_at DESC, id DESC',
					$instance->get_tablename(),
					sanitize_email( $email )
				),
				ARRAY_A
			);
		}

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Get donation by transaction ID.
	 *
	 * @param string $transaction_id Transaction ID.
	 * @return array<string, mixed>|null Donation data or null if not found.
	 * @since 0.0.1
	 */
	public static function get_by_transaction_id( $transaction_id ) {
		if ( empty( $transaction_id ) ) {
			return null;
		}

		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE transaction_id = %s LIMIT 1',
				$instance->get_tablename(),
				sanitize_text_field( $transaction_id )
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		return $instance->decode_by_datatype( $result );
	}

	/**
	 * Get donation by gateway subscription ID.
	 *
	 * Recurring handling lives in Pro, but the table (and its
	 * `idx_subscription` index) belongs here, so free-side code that only needs
	 * to resolve a row — such as the PayPal webhook listener recording why a
	 * delivery was rejected — can look one up without depending on Pro.
	 *
	 * Renewals carry the same `subscription_id` as the subscription they belong
	 * to, so the column is deliberately not unique. The parent row (the one with
	 * no `parent_subscription_id`) is preferred and the oldest id breaks any
	 * remaining tie, so the result does not depend on the query plan.
	 *
	 * @param string $subscription_id Gateway subscription ID.
	 * @return array<string, mixed>|null Donation data or null if not found.
	 * @since 1.4.0
	 */
	public static function get_by_subscription_id( $subscription_id ) {
		if ( empty( $subscription_id ) ) {
			return null;
		}

		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE subscription_id = %s ORDER BY parent_subscription_id ASC, id ASC LIMIT 1',
				$instance->get_tablename(),
				sanitize_text_field( $subscription_id )
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		return $instance->decode_by_datatype( $result );
	}

	/**
	 * Get total donations count (no filters).
	 *
	 * @return int Total count.
	 * @since 0.0.1
	 */
	public static function count_all() {
		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$instance->get_tablename()
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get total donations count by payment status.
	 *
	 * @param string $status Payment status.
	 * @return int Total count.
	 * @since 0.0.1
	 */
	public static function count_by_status( $status ) {
		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE payment_status = %s',
				$instance->get_tablename(),
				sanitize_text_field( $status )
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get the count of completed, live-mode donations.
	 *
	 * Used to gate the review admin notice: a completed live donation is the
	 * signal that the site has taken a genuine (non-test) donation.
	 *
	 * @param string $gateway Optional gateway to scope the count to, e.g. 'paypal'.
	 *                        Empty counts every gateway.
	 * @return int Count of completed live donations.
	 * @since 1.2.0
	 * @since 1.5.1 Optionally scoped to one gateway.
	 */
	public static function count_live_completed( $gateway = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		if ( '' !== $gateway ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE payment_status = %s AND payment_mode = %s AND gateway = %s',
					$instance->get_tablename(),
					'completed',
					'live',
					$gateway
				)
			);

			return is_numeric( $count ) ? (int) $count : 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE payment_status = %s AND payment_mode = %s',
				$instance->get_tablename(),
				'completed',
				'live'
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get total donations count by campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int Total count.
	 * @since 0.0.1
	 */
	public static function count_by_campaign( $campaign_id ) {
		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE campaign_id = %d',
				$instance->get_tablename(),
				absint( $campaign_id )
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get total donations count by status and campaign.
	 *
	 * @param string $status      Payment status ('all' for no filter).
	 * @param int    $campaign_id Optional campaign ID (0 for no filter).
	 * @return int Total count.
	 * @since 0.0.1
	 */
	public static function get_total_donations_by_status( $status = 'all', $campaign_id = 0 ) {
		$instance = self::get_instance();
		global $wpdb;

		// Both filters.
		if ( 'all' !== $status && $campaign_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE payment_status = %s AND campaign_id = %d',
					$instance->get_tablename(),
					sanitize_text_field( $status ),
					absint( $campaign_id )
				)
			);
			return is_numeric( $count ) ? (int) $count : 0;
		}

		// Status filter only.
		if ( 'all' !== $status ) {
			return self::count_by_status( $status );
		}

		// Campaign filter only.
		if ( $campaign_id > 0 ) {
			return self::count_by_campaign( $campaign_id );
		}

		// No filters.
		return self::count_all();
	}

	/**
	 * Build the currency / payment-mode scope for a reporting query.
	 *
	 * Amounts in different currencies cannot be summed into one figure, and test
	 * donations must not be counted alongside live ones. Both filters are opt-in
	 * so existing callers keep their behaviour; the abilities always pass them.
	 *
	 * @param string       $currency     Currency code ('' for no filter).
	 * @param string       $payment_mode 'test' or 'live' ('' for no filter).
	 * @param array<mixed> $args         Prepare args, appended to by reference.
	 * @param string       $after        GMT MySQL datetime; only rows created at or after it ('' for no window). Since 1.6.1.
	 * @param string       $before       GMT MySQL datetime; only rows created before it ('' for no upper bound). Since 1.6.1.
	 * @return string SQL fragment beginning with " AND ", or '' when unscoped.
	 * @since 1.5.0
	 */
	private static function scope_fragment( $currency, $payment_mode, array &$args, $after = '', $before = '' ) {
		$extra = '';

		$currency = is_string( $currency ) ? strtoupper( trim( $currency ) ) : '';
		if ( '' !== $currency ) {
			$extra .= ' AND currency = %s';
			$args[] = $currency;
		}

		$payment_mode = is_string( $payment_mode ) ? strtolower( trim( $payment_mode ) ) : '';
		if ( in_array( $payment_mode, [ 'test', 'live' ], true ) ) {
			$extra .= ' AND payment_mode = %s';
			$args[] = $payment_mode;
		}

		// created_at is stored in GMT (add() uses current_time( 'mysql', true )),
		// so callers must pass a GMT datetime or the window drifts by the site offset.
		$after = is_string( $after ) ? trim( $after ) : '';
		if ( '' !== $after ) {
			$extra .= ' AND created_at >= %s';
			$args[] = $after;
		}

		$before = is_string( $before ) ? trim( $before ) : '';
		if ( '' !== $before ) {
			$extra .= ' AND created_at < %s';
			$args[] = $before;
		}

		return $extra;
	}
	/**
	 * Get global dashboard statistics.
	 *
	 * @param string $currency     Currency code to scope to ('' for no filter).
	 * @param string $payment_mode 'test' or 'live' ('' for no filter).
	 * @param string $after        GMT MySQL datetime; only donations created at or after it ('' for all time). Since 1.6.1.
	 * @param string $before       GMT MySQL datetime; only donations created before it ('' for no upper bound). Since 1.6.1.
	 * @return array{total_donations: string, total_raised: string, unique_donors: string, average_donation: string, largest_donation: string} Dashboard statistics.
	 * @since 0.0.1
	 */
	public static function get_dashboard_stats( $currency = '', $payment_mode = '', $after = '', $before = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		$args  = [ $instance->get_tablename() ];
		$extra = self::scope_fragment( $currency, $payment_mode, $args, $after, $before );

		$sql = "SELECT
					COUNT(*) as total_donations,
					COALESCE(SUM(amount - refunded_amount), 0) as total_raised,
					COUNT(DISTINCT donor_email) as unique_donors,
					COALESCE(AVG(amount - refunded_amount), 0) as average_donation,
					COALESCE(MAX(amount - refunded_amount), 0) as largest_donation
				FROM %i
				WHERE payment_status IN ('completed', 'partially_refunded')
					{$extra}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $extra is built only from static placeholder fragments; every value travels in $args.
		$stats = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return $stats ? $stats : [
			'total_donations'  => 0,
			'total_raised'     => 0,
			'unique_donors'    => 0,
			'average_donation' => 0,
			'largest_donation' => 0,
		];
	}

	/**
	 * Get recent donations globally (all campaigns).
	 *
	 * @param int    $limit        Number of donations to retrieve.
	 * @param string $currency     Currency code to scope to ('' for no filter).
	 * @param string $payment_mode 'test' or 'live' ('' for no filter).
	 * @return array<int, array<string, mixed>> Array of recent donations.
	 * @since 0.0.1
	 */
	public static function get_recent_donations_global( $limit = 5, $currency = '', $payment_mode = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		$args   = [ $instance->get_tablename() ];
		$extra  = self::scope_fragment( $currency, $payment_mode, $args );
		$args[] = absint( $limit );

		$sql = "SELECT * FROM %i
				WHERE payment_status IN ('completed', 'partially_refunded')
					{$extra}
				ORDER BY created_at DESC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $extra is built only from static placeholder fragments; every value travels in $args.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Get top campaigns by donations.
	 *
	 * @param int    $limit        Number of campaigns to retrieve.
	 * @param string $currency     Currency code to scope to ('' for no filter).
	 * @param string $payment_mode 'test' or 'live' ('' for no filter).
	 * @param string $after        GMT MySQL datetime; only donations created at or after it ('' for all time). Since 1.6.1.
	 * @return array<int, array{campaign_id: string, donation_count: string, total_raised: string, unique_donors: string}> Array of top campaigns with stats.
	 * @since 0.0.1
	 */
	public static function get_top_campaigns( $limit = 5, $currency = '', $payment_mode = '', $after = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		$args   = [ $instance->get_tablename(), SUREDONATION_POST_TYPE ];
		$extra  = self::scope_fragment( $currency, $payment_mode, $args, $after );
		$args[] = absint( $limit );

		// The join is what makes LIMIT meaningful: orphaned campaign_ids (post
		// deleted, donations kept) still carry donations, so filtering them in
		// PHP after a SQL LIMIT returned fewer than the requested top-N while
		// valid campaigns sat below the cut.
		$sql = "SELECT
					d.campaign_id,
					p.post_title AS campaign_title,
					COUNT(*) as donation_count,
					COALESCE(SUM(amount - refunded_amount), 0) as total_raised,
					COUNT(DISTINCT donor_email) as unique_donors
				FROM %i AS d
				INNER JOIN {$wpdb->posts} AS p
					ON p.ID = d.campaign_id
					AND p.post_type = %s
				WHERE payment_status IN ('completed', 'partially_refunded')
					{$extra}
				GROUP BY d.campaign_id
				ORDER BY total_raised DESC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $extra is built only from static placeholder fragments; every value travels in $args.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return $results ? $results : [];
	}

	/**
	 * Published campaigns whose most recent completed donation is older than
	 * $before, or that have never received one.
	 *
	 * The scope (currency / payment mode) applies to the donations side of
	 * the join, so a campaign whose only gifts fall outside the scope is
	 * reported as never-donated rather than dropped. Campaigns that used to
	 * receive donations sort first, most recently active first — they are
	 * the ones an admin acts on — and never-donated campaigns fill whatever
	 * is left of the limit, so a site with many that never converted does not
	 * show the same five forever.
	 *
	 * @param string $before       GMT MySQL datetime; a campaign is quiet when its last completed donation is earlier than this.
	 * @param int    $limit        Number of campaigns to retrieve.
	 * @param string $currency     Currency code to scope donations to ('' for no filter).
	 * @param string $payment_mode 'test' or 'live' ('' for no filter).
	 * @return array<int, array{campaign_id: string, campaign_title: string, last_donation_at: string|null}>
	 * @since 1.6.1
	 */
	public static function get_stale_campaigns( $before, $limit = 5, $currency = '', $payment_mode = '' ) {
		$before = is_string( $before ) ? trim( $before ) : '';
		if ( '' === $before ) {
			return [];
		}

		$instance = self::get_instance();
		global $wpdb;

		$args   = [ $instance->get_tablename() ];
		$extra  = self::scope_fragment( $currency, $payment_mode, $args );
		$args[] = SUREDONATION_POST_TYPE;
		$args[] = $before;
		$args[] = absint( $limit );

		$sql = "SELECT
					p.ID AS campaign_id,
					p.post_title AS campaign_title,
					MAX(d.created_at) AS last_donation_at
				FROM {$wpdb->posts} AS p
				LEFT JOIN %i AS d
					ON d.campaign_id = p.ID
					AND d.payment_status IN ('completed', 'partially_refunded')
					{$extra}
				WHERE p.post_type = %s
					AND p.post_status = 'publish'
				GROUP BY p.ID, p.post_title
				HAVING MAX(d.created_at) IS NULL OR MAX(d.created_at) < %s
				ORDER BY (MAX(d.created_at) IS NULL) ASC, MAX(d.created_at) DESC, p.ID ASC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $extra is built only from static placeholder fragments; every value travels in $args.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return $results ? $results : [];
	}

	/**
	 * Get donation trends over time.
	 *
	 * @param string $after       Start date (ISO format).
	 * @param string $before      End date (ISO format).
	 * @param string $group       Grouping: 'day', 'week', or 'month'.
	 * @param string $currency     Currency code to scope to ('' for no currency filter).
	 * @param int    $campaign_id  Campaign to scope to (0 for all campaigns).
	 * @param string $payment_mode 'test' or 'live' ('' for no filter).
	 * @return array<int, array{period: string, donation_count: string, total_amount: string}> Array of donation trends.
	 * @since 0.0.1
	 */
	public static function get_donation_trends( $after = '', $before = '', $group = 'day', $currency = '', $campaign_id = 0, $payment_mode = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		// Default to last 30 days if no dates provided.
		if ( empty( $after ) ) {
			$after = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}
		if ( empty( $before ) ) {
			$before = gmdate( 'Y-m-d' );
		}

		// Determine date format based on grouping.
		switch ( $group ) {
			case 'month':
				$date_format = '%Y-%m-01';
				break;
			case 'week':
				$date_format = '%x-%v'; // ISO year-week.
				break;
			case 'day':
			default:
				$date_format = '%Y-%m-%d';
				break;
		}

		// Amounts of different currencies cannot be summed into one figure, so
		// scope the query to a single currency. Callers that don't care still
		// get coherent numbers because the default is the store currency.
		$currency = is_string( $currency ) ? strtoupper( trim( $currency ) ) : '';
		$extra    = '';
		$args     = [ $date_format, $instance->get_tablename(), $after, $before ];

		if ( '' !== $currency ) {
			$extra .= ' AND currency = %s';
			$args[] = $currency;
		}

		if ( $campaign_id > 0 ) {
			$extra .= ' AND campaign_id = %d';
			$args[] = absint( $campaign_id );
		}

		// Test and live donations must not be summed together either.
		$payment_mode = is_string( $payment_mode ) ? strtolower( trim( $payment_mode ) ) : '';
		if ( in_array( $payment_mode, [ 'test', 'live' ], true ) ) {
			$extra .= ' AND payment_mode = %s';
			$args[] = $payment_mode;
		}

		$sql = "SELECT
					DATE_FORMAT(created_at, %s) as period,
					COUNT(*) as donation_count,
					COALESCE(SUM(amount - refunded_amount), 0) as total_amount
				FROM %i
				WHERE payment_status IN ('completed', 'partially_refunded')
					AND DATE(created_at) >= %s
					AND DATE(created_at) <= %s
					{$extra}
				GROUP BY period
				ORDER BY period ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $extra is built only from static placeholder fragments; every value is passed through prepare args.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return $results ? $results : [];
	}

	/**
	 * Count donations recorded through a donation form, in any status.
	 *
	 * Used to protect a form from permanent deletion while donation rows still
	 * reference it, mirroring count_by_campaign()'s role for campaigns.
	 *
	 * @param int $form_id Donation form post ID.
	 * @return int Donation count.
	 * @since 1.5.0
	 */
	public static function count_by_form( $form_id ) {
		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard on a destructive action; must read live data.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE form_id = %d',
				$instance->get_tablename(),
				absint( $form_id )
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get completed entry count and revenue for a single donation form.
	 *
	 * @param int $form_id Donation form post ID.
	 * @return array{entries: int, revenue: float} Form totals.
	 * @since 1.5.0
	 */
	public static function get_form_stats( $form_id ) {
		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live totals; caching would show stale figures.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) as entries, COALESCE(SUM(amount - refunded_amount), 0) as revenue FROM %i WHERE form_id = %d AND payment_status = %s',
				$instance->get_tablename(),
				absint( $form_id ),
				'completed'
			),
			ARRAY_A
		);

		return [
			'entries' => is_array( $result ) ? (int) ( $result['entries'] ?? 0 ) : 0,
			'revenue' => is_array( $result ) ? (float) ( $result['revenue'] ?? 0 ) : 0.0,
		];
	}

	/**
	 * Get entry and revenue totals for several forms in one query.
	 *
	 * get_form_stats() is a per-form query, so formatting a page of N forms ran
	 * N COUNT/SUM queries. This collapses that to one GROUP BY for the page.
	 *
	 * @param array<int> $form_ids Form IDs to total.
	 * @return array<int, array{entries: int, revenue: float}> Totals keyed by form ID; every requested ID is present.
	 * @since 1.5.0
	 */
	public static function get_form_stats_bulk( array $form_ids ) {
		// intval, not absint: absint( -1 ) is 1, which would silently total a
		// real form the caller never asked about.
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $form_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		// Every requested id gets an entry, so callers never have to special-case
		// a form that simply has no donations yet.
		$stats = [];
		foreach ( $ids as $id ) {
			$stats[ $id ] = [
				'entries' => 0,
				'revenue' => 0.0,
			];
		}

		if ( empty( $ids ) ) {
			return $stats;
		}

		$instance = self::get_instance();
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live totals; placeholders are generated from a count, every value is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				sprintf(
					'SELECT form_id, COUNT(*) as entries, COALESCE(SUM(amount - refunded_amount), 0) as revenue FROM %%i WHERE form_id IN ( %s ) AND payment_status = %%s GROUP BY form_id',
					$placeholders
				),
				array_merge( [ $instance->get_tablename() ], $ids, [ 'completed' ] )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$form_id = absint( $row['form_id'] ?? 0 );
			if ( ! isset( $stats[ $form_id ] ) ) {
				continue;
			}

			$stats[ $form_id ] = [
				'entries' => (int) ( $row['entries'] ?? 0 ),
				'revenue' => (float) ( $row['revenue'] ?? 0 ),
			];
		}

		return $stats;
	}

	/**
	 * Count donations matching the admin-list filters.
	 *
	 * Mirrors get_admin_list()'s WHERE clause, including the search term. The
	 * older get_total_donations_by_status() ignores `$search`, so any searched
	 * listing reported the unfiltered total and paginated against it.
	 *
	 * @param string $status      Payment status filter ('all' for no filter).
	 * @param int    $campaign_id Campaign ID filter (0 for no filter).
	 * @param string $search      Search term for donor_name, donor_email, or transaction_id.
	 * @return int Matching row count.
	 * @since 1.5.0
	 */
	public static function count_admin_list( $status = 'all', $campaign_id = 0, $search = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		$conditions = [ '1=1' ];
		$args       = [ $instance->get_tablename() ];

		if ( 'all' !== $status ) {
			$conditions[] = 'payment_status = %s';
			$args[]       = sanitize_text_field( $status );
		} else {
			// Mirrors get_admin_list(): abandoned rows are out of the unfiltered
			// listing, so the total has to leave them out too or the last page
			// comes back short.
			$conditions[] = "payment_status != 'abandoned'";
		}

		if ( $campaign_id > 0 ) {
			$conditions[] = 'campaign_id = %d';
			$args[]       = absint( $campaign_id );
		}

		if ( ! empty( $search ) ) {
			$conditions[] = '(donor_name LIKE %s OR donor_email LIKE %s OR transaction_id LIKE %s)';
			$term         = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$args[]       = $term;
			$args[]       = $term;
			$args[]       = $term;
		}

		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built only from static placeholder fragments; every value is passed through prepare args.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $args ) );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get recent donations for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Number of donations to retrieve.
	 * @return array<mixed> Array of recent donations.
	 * @since 0.0.1
	 */
	public static function get_recent_donations( $campaign_id, $limit = 5 ) {
		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE campaign_id = %d AND payment_status IN ('completed', 'partially_refunded') ORDER BY created_at DESC LIMIT %d",
				$instance->get_tablename(),
				absint( $campaign_id ),
				absint( $limit )
			),
			ARRAY_A
		);

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Get paginated donations for a specific donor.
	 *
	 * @param int $donor_id Donor ID.
	 * @param int $limit    Number of records to return.
	 * @param int $offset   Offset for pagination.
	 * @return array{donations: array<int, array<string, mixed>>, total: int} Paginated donations and total count.
	 * @since 1.0.0
	 */
	public static function get_by_donor_id( $donor_id, $limit = 10, $offset = 0 ) {
		if ( empty( $donor_id ) ) {
			return [
				'donations' => [],
				'total'     => 0,
			];
		}

		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE donor_id = %d',
				$table,
				absint( $donor_id )
			)
		);

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE donor_id = %d ORDER BY created_at DESC LIMIT %d, %d',
				$table,
				absint( $donor_id ),
				absint( $offset ),
				absint( $limit )
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $results || ! is_array( $results ) ) {
			$results = [];
		}

		return [
			'donations' => array_map( [ $instance, 'decode_by_datatype' ], $results ),
			'total'     => is_numeric( $total ) ? (int) $total : 0,
		];
	}

	/**
	 * Get donation activity data for a specific donor (for chart).
	 *
	 * @param int    $donor_id Donor ID.
	 * @param string $after    Start date (Y-m-d).
	 * @param string $before   End date (Y-m-d).
	 * @return array{chart_data: array<int, array{date: string, amount: float}>, stats: array{lifetime: float, highest: float, average: float}} Activity data.
	 * @since 1.0.0
	 */
	public static function get_donor_activity( $donor_id, $after = '', $before = '' ) {
		if ( empty( $donor_id ) ) {
			return [
				'chart_data' => [],
				'stats'      => [
					'lifetime' => 0,
					'highest'  => 0,
					'average'  => 0,
				],
			];
		}

		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// Default date range: last 30 days.
		if ( empty( $after ) ) {
			$after = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}
		if ( empty( $before ) ) {
			$before = gmdate( 'Y-m-d' );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Chart data: donations grouped by date.
		$chart_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as amount
				FROM %i
				WHERE donor_id = %d
					AND payment_status IN ('completed', 'partially_refunded')
					AND DATE(created_at) >= %s
					AND DATE(created_at) <= %s
				GROUP BY DATE(created_at)
				ORDER BY date ASC",
				$table,
				absint( $donor_id ),
				$after,
				$before
			),
			ARRAY_A
		);

		// Lifetime stats for this donor.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(amount - refunded_amount), 0) as lifetime,
					COALESCE(MAX(amount), 0) as highest,
					COALESCE(AVG(amount), 0) as average
				FROM %i
				WHERE donor_id = %d AND payment_status IN ('completed', 'partially_refunded')",
				$table,
				absint( $donor_id )
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$stats = is_array( $stats ) ? $stats : [];

		return [
			'chart_data' => is_array( $chart_data ) ? $chart_data : [],
			'stats'      => [
				'lifetime' => is_numeric( $stats['lifetime'] ?? 0 ) ? round( (float) ( $stats['lifetime'] ?? 0 ), 2 ) : 0,
				'highest'  => is_numeric( $stats['highest'] ?? 0 ) ? round( (float) ( $stats['highest'] ?? 0 ), 2 ) : 0,
				'average'  => is_numeric( $stats['average'] ?? 0 ) ? round( (float) ( $stats['average'] ?? 0 ), 2 ) : 0,
			],
		];
	}

	/**
	 * Update donation status.
	 *
	 * @param int    $donation_id Donation ID.
	 * @param string $status      New status.
	 * @return int|false Number of rows updated or false on error.
	 * @since 0.0.1
	 */
	public static function update_status( $donation_id, $status ) {
		if ( empty( $donation_id ) || ! in_array( $status, self::$valid_statuses, true ) ) {
			return false;
		}

		return self::update( $donation_id, [ 'payment_status' => $status ] );
	}

	/**
	 * Get valid payment statuses.
	 *
	 * @return array<string> Valid statuses.
	 * @since 0.0.1
	 */
	public static function get_valid_statuses() {
		return self::$valid_statuses;
	}

	/**
	 * Get valid donor-comment moderation statuses.
	 *
	 * @return array<string> Valid donor-comment statuses.
	 * @since 1.6.0
	 */
	public static function get_valid_comment_statuses() {
		return self::$valid_comment_statuses;
	}

	/**
	 * Resolve the moderation status a newly captured donor comment should get.
	 *
	 * Held for review only when the site owner has opted in; otherwise comments
	 * publish straight away, matching how GiveWP and Charitable behave out of the
	 * box. An empty comment gets `approved` so a donation with nothing to moderate
	 * never shows up in a review queue.
	 *
	 * @param string $comment The captured comment.
	 * @return string One of self::$valid_comment_statuses.
	 * @since 1.6.0
	 */
	public static function initial_comment_status( $comment ) {
		if ( '' === trim( Helper::get_string_value( $comment ) ) ) {
			return 'approved';
		}

		$donor_settings = Helper::get_array_value(
			Helper::get_suredonation_option( \SureDonation\Inc\API\Settings_API::DONOR_OPTION_KEY, [] )
		);

		return ! empty( $donor_settings['hold_donor_comments'] ) ? 'pending' : 'approved';
	}

	/**
	 * Add a log entry to a donation.
	 *
	 * @param int                  $donation_id Donation ID.
	 * @param string               $action      Action type (e.g., 'status_change', 'refund', 'webhook').
	 * @param string               $message     Log message.
	 * @param array<string, mixed> $data        Optional additional data.
	 * @return int|false Number of rows updated or false on error.
	 * @since 0.0.1
	 */
	public static function add_log( $donation_id, $action, $message, $data = [] ) {
		if ( empty( $donation_id ) ) {
			return false;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return false;
		}

		// Get existing log or initialize empty array.
		// Note: decode_by_datatype() already decodes JSON to array, so check for array first.
		$log_data = $donation['log'] ?? [];
		if ( is_array( $log_data ) ) {
			$log = $log_data;
		} elseif ( is_string( $log_data ) && ! empty( $log_data ) ) {
			$log = json_decode( $log_data, true );
			if ( ! is_array( $log ) ) {
				$log = [];
			}
		} else {
			$log = [];
		}

		// Add new log entry.
		$log[] = [
			'action'    => sanitize_text_field( $action ),
			'message'   => sanitize_text_field( $message ),
			'data'      => $data,
			'timestamp' => current_time( 'mysql' ),
		];

		return self::update( $donation_id, [ 'log' => $log ] );
	}

	/**
	 * Get log entries for a donation.
	 *
	 * @param int $donation_id Donation ID.
	 * @return array<int, array<string, mixed>> Log entries.
	 * @since 0.0.1
	 */
	public static function get_log( $donation_id ) {
		if ( empty( $donation_id ) ) {
			return [];
		}

		$donation = self::get( $donation_id );
		if ( ! $donation || empty( $donation['log'] ) ) {
			return [];
		}

		// Note: decode_by_datatype() already decodes JSON to array, so check for array first.
		$log_data = $donation['log'];
		if ( is_array( $log_data ) ) {
			return $log_data;
		}

		if ( is_string( $log_data ) ) {
			$log = json_decode( $log_data, true );
			return is_array( $log ) ? $log : [];
		}

		return [];
	}

	/**
	 * Add refund data to donation_data for audit trail and duplicate prevention.
	 *
	 * Stores each refund with its ID as the key for O(1) lookups.
	 *
	 * @param int                  $donation_id Donation ID.
	 * @param array<string, mixed> $refund_data Refund data to store.
	 * @return bool True on success, false on failure.
	 * @since 0.0.1
	 */
	public static function add_refund_to_donation_data( $donation_id, $refund_data ) {
		$refund_id = $refund_data['refund_id'] ?? '';

		if ( empty( $refund_id ) || empty( $donation_id ) ) {
			return false;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return false;
		}

		// Get existing donation_data.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			$donation_data = [];
		}

		// Initialize refunds array if not exists.
		if ( ! isset( $donation_data['refunds'] ) || ! is_array( $donation_data['refunds'] ) ) {
			$donation_data['refunds'] = [];
		}

		// Store with refund ID as key for O(1) lookup (duplicate prevention).
		$donation_data['refunds'][ $refund_id ] = $refund_data;

		// Update donation_data in database.
		$result = self::update( $donation_id, [ 'donation_data' => $donation_data ] );

		return false !== $result;
	}

	/**
	 * Store the submitted form field values under the donation_data['fields'] key.
	 *
	 * The donation_data column is shared JSON (also holds refunds, notes and
	 * subscription metadata), so the field data is merged under a dedicated
	 * 'fields' key and never overwrites the column.
	 *
	 * Fields are written at donation creation (before the payment is confirmed)
	 * and are intentionally retained for abandoned/failed donations — pending
	 * records are legitimate business data (recovery, reconciliation, reporting).
	 * There is deliberately no automatic PII purge here; erasure is handled on
	 * demand via the admin delete actions (and can be wired to WordPress's
	 * personal-data eraser hooks if a retention policy is later required).
	 *
	 * @param int                                                $donation_id Donation ID.
	 * @param array<string, array{label: string, value: string}> $field_data  Submitted fields as label/value pairs.
	 * @return bool True on success, false on failure.
	 * @since 1.1.1
	 */
	public static function set_submitted_fields( $donation_id, $field_data ) {
		if ( empty( $donation_id ) || empty( $field_data ) || ! is_array( $field_data ) ) {
			return false;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return false;
		}

		// Get existing donation_data.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			$donation_data = [];
		}

		// Merge under a dedicated key — never overwrite the shared column.
		$donation_data['fields'] = $field_data;

		// Update donation_data in database.
		$result = self::update( $donation_id, [ 'donation_data' => $donation_data ] );

		return false !== $result;
	}

	/**
	 * Check if a refund already exists in the donation data.
	 *
	 * This prevents duplicate processing of the same refund.
	 *
	 * @param int    $donation_id Donation ID.
	 * @param string $refund_id   Refund ID to check.
	 * @return bool True if refund already exists, false otherwise.
	 * @since 0.0.1
	 */
	public static function check_refund_exists( $donation_id, $refund_id ) {
		if ( empty( $donation_id ) || empty( $refund_id ) ) {
			return false;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return false;
		}

		// Get donation_data and parse if needed.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			return false;
		}

		// Check if refunds array exists and contains this refund ID.
		if ( empty( $donation_data['refunds'] ) || ! is_array( $donation_data['refunds'] ) ) {
			return false;
		}

		// O(1) lookup using refund ID as array key.
		return isset( $donation_data['refunds'][ $refund_id ] );
	}

	/**
	 * Add a note to a donation.
	 *
	 * @param int    $donation_id Donation ID.
	 * @param string $note_content Note content.
	 * @param int    $author_id   Author user ID.
	 * @return array{success: bool, note_id: string|null} Result with success status and note ID.
	 * @since 0.0.1
	 */
	public static function add_note( $donation_id, $note_content, $author_id = 0 ) {
		$result = [
			'success' => false,
			'note_id' => null,
		];

		if ( empty( $donation_id ) || empty( $note_content ) ) {
			return $result;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return $result;
		}

		// Get existing donation_data.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			$donation_data = [];
		}

		// Initialize notes array if not exists.
		if ( ! isset( $donation_data['notes'] ) || ! is_array( $donation_data['notes'] ) ) {
			$donation_data['notes'] = [];
		}

		// Generate unique note ID.
		$note_id = uniqid( 'note_', true );

		// Get author info.
		$author_name = __( 'System', 'suredonation' );
		if ( $author_id > 0 ) {
			$user = get_userdata( $author_id );
			if ( $user ) {
				$author_name = $user->display_name;
			}
		}

		// Add new note.
		$donation_data['notes'][ $note_id ] = [
			'id'          => $note_id,
			'content'     => wp_kses_post( $note_content ),
			'author_id'   => $author_id,
			'author_name' => $author_name,
			'created_at'  => current_time( 'mysql' ),
		];

		// Update donation_data in database.
		$update_result = self::update( $donation_id, [ 'donation_data' => $donation_data ] );

		if ( false !== $update_result ) {
			$result['success'] = true;
			$result['note_id'] = $note_id;
		}

		return $result;
	}

	/**
	 * Get notes for a donation with pagination.
	 *
	 * @param int $donation_id Donation ID.
	 * @param int $page        Current page (1-indexed).
	 * @param int $per_page    Notes per page.
	 * @return array{notes: array<int, array<string, mixed>>, total: int, total_pages: int} Paginated notes.
	 * @since 0.0.1
	 */
	public static function get_notes( $donation_id, $page = 1, $per_page = 3 ) {
		$result = [
			'notes'       => [],
			'total'       => 0,
			'total_pages' => 0,
		];

		if ( empty( $donation_id ) ) {
			return $result;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return $result;
		}

		// Get donation_data and parse if needed.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			return $result;
		}

		// Get notes array.
		if ( empty( $donation_data['notes'] ) || ! is_array( $donation_data['notes'] ) ) {
			return $result;
		}

		// Convert to array values and sort by created_at (newest first).
		$all_notes = array_values( $donation_data['notes'] );
		usort(
			$all_notes,
			static function ( $a, $b ) {
				return strtotime( $b['created_at'] ?? '0' ) - strtotime( $a['created_at'] ?? '0' );
			}
		);

		$total       = count( $all_notes );
		$total_pages = (int) ceil( $total / $per_page );
		$offset      = ( $page - 1 ) * $per_page;

		// Get paginated notes.
		$notes = array_slice( $all_notes, $offset, $per_page );

		return [
			'notes'       => $notes,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * Delete a note from a donation.
	 *
	 * @param int    $donation_id Donation ID.
	 * @param string $note_id     Note ID to delete.
	 * @return bool True on success, false on failure.
	 * @since 0.0.1
	 */
	public static function delete_note( $donation_id, $note_id ) {
		if ( empty( $donation_id ) || empty( $note_id ) ) {
			return false;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return false;
		}

		// Get donation_data and parse if needed.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			return false;
		}

		// Check if note exists.
		if ( empty( $donation_data['notes'] ) || ! isset( $donation_data['notes'][ $note_id ] ) ) {
			return false;
		}

		// Remove the note.
		unset( $donation_data['notes'][ $note_id ] );

		// Update donation_data in database.
		$result = self::update( $donation_id, [ 'donation_data' => $donation_data ] );

		return false !== $result;
	}

	/**
	 * Remove a refund from donation_data.
	 *
	 * Used when a refund is canceled.
	 *
	 * @param int    $donation_id Donation ID.
	 * @param string $refund_id   Refund ID to remove.
	 * @return array{removed: bool, refund_data: array<string, mixed>|null} Result with removed status and refund data.
	 * @since 0.0.1
	 */
	public static function remove_refund_from_donation_data( $donation_id, $refund_id ) {
		$result = [
			'removed'     => false,
			'refund_data' => null,
		];

		if ( empty( $donation_id ) || empty( $refund_id ) ) {
			return $result;
		}

		$donation = self::get( $donation_id );
		if ( ! $donation ) {
			return $result;
		}

		// Get donation_data and parse if needed.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			return $result;
		}

		// Check if refund exists.
		if ( empty( $donation_data['refunds'] ) || ! isset( $donation_data['refunds'][ $refund_id ] ) ) {
			return $result;
		}

		// Store the refund data before removing.
		$result['refund_data'] = $donation_data['refunds'][ $refund_id ];

		// Remove the refund.
		unset( $donation_data['refunds'][ $refund_id ] );

		// Update donation_data in database.
		$update_result = self::update( $donation_id, [ 'donation_data' => $donation_data ] );

		$result['removed'] = false !== $update_result;

		return $result;
	}
}
