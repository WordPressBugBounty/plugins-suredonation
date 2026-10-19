<?php
/**
 * SureDonation Database Donors Table Class.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Database\Tables;

use SureDonation\Inc\Database\Base;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureDonation Database Donors Table Class.
 *
 * @since 0.0.1
 */
class Donors extends Base {
	use Get_Instance;

	/**
	 * Table suffix.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $table_suffix = 'donors';

	/**
	 * Table version.
	 *
	 * @var int
	 * @since 0.0.1
	 */
	protected $table_version = 4;

	/**
	 * Valid donor statuses.
	 *
	 * @var array<string>
	 * @since 0.0.1
	 */
	private static $valid_statuses = [
		'active',
		'inactive',
		'blocked',
	];

	/**
	 * Valid order columns.
	 *
	 * `total_donated` and `donation_count` are deliberately absent. They exist
	 * on this table, but the list renders the mode-scoped figures computed from
	 * donation rows, so ordering by the stored columns would sort the page by a
	 * number the reader cannot see: on a site that rehearsed before going live,
	 * the top of "highest donors" would be rows showing 0.00. Ordering on the
	 * scoped figure means aggregating before the LIMIT, which is a rewrite of
	 * both branches of get_admin_list() rather than a predicate.
	 *
	 * @var array<string>
	 * @since 0.0.1
	 * @since 1.7.0 The two money columns are no longer orderable.
	 */
	private static $valid_order_columns = [
		'id',
		'email',
		'name',
		'created_at',
		'updated_at',
		'last_donation_date',
	];

	/**
	 * {@inheritDoc}
	 */
	public function get_schema() {
		return [
			'id'                  => [
				'type' => 'number',
			],
			'email'               => [
				'type' => 'string',
			],
			'name'                => [
				'type'    => 'string',
				'default' => '',
			],
			'phone'               => [
				'type'    => 'string',
				'default' => '',
			],
			'company'             => [
				'type'    => 'string',
				'default' => '',
			],
			'address'             => [
				'type'    => 'string',
				'default' => '',
			],
			'user_id'             => [
				'type'    => 'number',
				'default' => 0,
			],
			'total_donated'       => [
				'type'    => 'decimal',
				'default' => 0,
			],
			'donation_count'      => [
				'type'    => 'number',
				'default' => 0,
			],
			'largest_donation'    => [
				'type'    => 'decimal',
				'default' => 0,
			],
			'first_donation_date' => [
				'type' => 'datetime',
			],
			'last_donation_date'  => [
				'type' => 'datetime',
			],
			'donor_tags'          => [
				'type'    => 'array',
				'default' => [],
			],
			'donor_status'        => [
				'type'    => 'string',
				'default' => 'active',
			],
			'donor_data'          => [
				'type'    => 'array',
				'default' => [],
			],
			'stripe_customer_id'  => [
				'type'    => 'string',
				'default' => '',
			],
			'import_source_id'    => [
				'type'    => 'number',
				'default' => 0,
			],
			'import_source'       => [
				'type'    => 'string',
				'default' => '',
			],
			'created_at'          => [
				'type' => 'datetime',
			],
			'updated_at'          => [
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
			'email VARCHAR(255) NOT NULL UNIQUE',
			'name VARCHAR(255) NOT NULL',
			'phone VARCHAR(50) NOT NULL',
			'user_id BIGINT(20) UNSIGNED NULL',
			'total_donated DECIMAL(26,8) NOT NULL DEFAULT 0',
			'donation_count INT(11) NOT NULL DEFAULT 0',
			'largest_donation DECIMAL(26,8) NOT NULL DEFAULT 0',
			'first_donation_date TIMESTAMP NULL',
			'last_donation_date TIMESTAMP NULL',
			'donor_tags LONGTEXT',
			'donor_status VARCHAR(20) NOT NULL',
			'donor_data LONGTEXT',
			'stripe_customer_id VARCHAR(255) DEFAULT NULL',
			'import_source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
			'import_source VARCHAR(20) NOT NULL DEFAULT \'\'',
			'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
			'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
			'INDEX idx_email (email)',
			'INDEX idx_user (user_id)',
			'INDEX idx_total (total_donated)',
			'INDEX idx_status (donor_status)',
			'INDEX idx_import_source (import_source_id, import_source)',
		];
	}

	/**
	 * New columns added across versions.
	 *
	 * Version 3 added company/address; version 4 added the
	 * source-agnostic pair `import_source_id` + `import_source` used by
	 * the migration tool.
	 *
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function get_new_columns_definition() {
		// Keep migration defaults consistent with the schema's runtime
		// defaults (the column definitions above use 'default' => ''). Mixing
		// NOT NULL DEFAULT '' for one column with DEFAULT NULL for another
		// produces silent divergence at the data layer — a future
		// `WHERE address = ''` filter would miss legacy rows that landed as
		// NULL from the migration.
		return [
			'company VARCHAR(255) NOT NULL DEFAULT \'\' AFTER phone',
			'address TEXT NOT NULL DEFAULT \'\' AFTER company',
			'import_source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER stripe_customer_id',
			'import_source VARCHAR(20) NOT NULL DEFAULT \'\' AFTER import_source_id',
			'INDEX idx_import_source (import_source_id, import_source)',
		];
	}

	/**
	 * Add a new donor record.
	 *
	 * @param array<mixed> $data Donor data to insert.
	 * @return int|false The donor ID on success, false on error.
	 * @since 0.0.1
	 */
	public static function add( $data ) {
		if ( empty( $data['email'] ) ) {
			return false;
		}

		$instance = self::get_instance();

		// Set created_at if not provided.
		if ( ! isset( $data['created_at'] ) ) {
			$data['created_at'] = current_time( 'mysql' );
		}

		return $instance->use_insert( $data );
	}

	/**
	 * Update a donor record.
	 *
	 * @param int                 $donor_id Donor ID to update.
	 * @param array<string,mixed> $data     Data to update.
	 * @return int|false Number of rows updated or false on error.
	 * @since 0.0.1
	 */
	public static function update( $donor_id, $data = [] ) {
		if ( empty( $donor_id ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql' );

		return self::get_instance()->use_update( $data, [ 'id' => absint( $donor_id ) ] );
	}

	/**
	 * Get a single donor by ID.
	 *
	 * @param int $donor_id Donor ID.
	 * @return array<mixed>|null Donor data or null if not found.
	 * @since 0.0.1
	 */
	public static function get( $donor_id ) {
		if ( empty( $donor_id ) ) {
			return null;
		}

		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$instance->get_tablename(),
				absint( $donor_id )
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		return $instance->decode_by_datatype( $result );
	}

	/**
	 * Get donor by email.
	 *
	 * @param string $email Donor email.
	 * @return array<mixed>|null Donor data or null if not found.
	 * @since 0.0.1
	 */
	public static function get_by_email( $email ) {
		if ( empty( $email ) ) {
			return null;
		}

		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s',
				$instance->get_tablename(),
				sanitize_email( $email )
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		return $instance->decode_by_datatype( $result );
	}

	/**
	 * Get all donors with pagination.
	 *
	 * @param int    $limit   Number of records to return.
	 * @param int    $offset  Offset for pagination.
	 * @param string $orderby Column to order by.
	 * @param string $order   Order direction (ASC or DESC).
	 * @return array<mixed> Array of donors.
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
	 * Get donors by status with pagination.
	 *
	 * @param string $status  Donor status.
	 * @param int    $limit   Number of records to return.
	 * @param int    $offset  Offset for pagination.
	 * @param string $orderby Column to order by.
	 * @param string $order   Order direction (ASC or DESC).
	 * @return array<mixed> Array of donors.
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
					'SELECT * FROM %i WHERE donor_status = %s ORDER BY %i ASC LIMIT %d, %d',
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
					'SELECT * FROM %i WHERE donor_status = %s ORDER BY %i DESC LIMIT %d, %d',
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
	 * Delete a donor.
	 *
	 * @param int $donor_id Donor ID.
	 * @return int|false Number of rows deleted or false on error.
	 * @since 0.0.1
	 */
	public static function delete( $donor_id ) {
		if ( empty( $donor_id ) ) {
			return false;
		}

		return self::get_instance()->use_delete( [ 'id' => absint( $donor_id ) ] );
	}

	/**
	 * Get or create donor by email.
	 *
	 * @param string $email Donor email.
	 * @param string $name  Donor name. Stored when creating a new donor, or backfilled onto an existing donor only when its stored name is empty; never overwrites a populated value.
	 * @param string $phone Donor phone. Stored when creating a new donor, or backfilled onto an existing donor only when its stored phone is empty; never overwrites a populated value.
	 * @return int|false Donor ID or false on error.
	 * @since 0.0.1
	 * @since 1.4.0 A subsequent donation no longer overwrites an existing donor's name/phone; missing values are backfilled, populated ones are left intact.
	 */
	public static function get_or_create( $email, $name = '', $phone = '' ) {
		if ( empty( $email ) ) {
			return false;
		}

		$existing = self::get_by_email( $email );

		if ( $existing ) {
			$existing_id = isset( $existing['id'] ) && is_numeric( $existing['id'] ) ? (int) $existing['id'] : 0;

			// Backfill name/phone only when the stored value is empty — a later
			// donation never overwrites a populated donor name/phone. This closes
			// the unauthenticated-tampering vector (an attacker who knows a
			// donor's email cannot change that donor's existing name/phone on a
			// bare, unverified match) while still letting genuinely missing
			// details fill in from a later donation — e.g. an optional-name
			// gateway, or a phone field mapped after the donor's first donation.
			// The name/phone entered for each donation are always captured on the
			// donation row regardless, and admins can edit a donor directly via
			// the donor management endpoints.
			$updates = [];
			if ( ! empty( $name ) && '' === (string) ( $existing['name'] ?? '' ) ) {
				$updates['name'] = $name;
			}
			if ( ! empty( $phone ) && '' === (string) ( $existing['phone'] ?? '' ) ) {
				$updates['phone'] = $phone;
			}
			if ( ! empty( $updates ) && $existing_id > 0 ) {
				self::update( $existing_id, $updates );
			}

			return $existing_id > 0 ? $existing_id : false;
		}

		// Create new donor.
		$donor_id = self::add(
			[
				'email'               => sanitize_email( $email ),
				'name'                => sanitize_text_field( $name ),
				'phone'               => sanitize_text_field( $phone ),
				'first_donation_date' => current_time( 'mysql' ),
			]
		);

		if ( $donor_id ) {
			// Auto-create or link WP user for this donor.
			self::maybe_link_wp_user( $donor_id, sanitize_email( $email ), sanitize_text_field( $name ) );
		}

		return $donor_id;
	}

	/**
	 * Link a donor to an existing WP user, or create a new WP user if none exists.
	 *
	 * @param int    $donor_id Donor ID.
	 * @param string $email    Donor email.
	 * @param string $name     Donor name.
	 * @return void
	 * @since 1.0.0
	 */
	public static function maybe_link_wp_user( $donor_id, $email, $name = '' ) {
		if ( empty( $donor_id ) || empty( $email ) ) {
			return;
		}

		// Check if donor already has a linked user.
		$donor = self::get( $donor_id );
		if ( $donor && ! empty( $donor['user_id'] ) && $donor['user_id'] > 0 ) {
			return;
		}

		// Deliberately no bind here at all, not even for a donor who is
		// currently logged in as a WP account whose own email matches.
		// get_or_create() -- and therefore this method -- runs at
		// payment-intent creation, before any charge completes (see
		// Stripe_Frontend::create_pending_donation()), so "logged in as a
		// matching account" is reachable by an attacker who abandons at the
		// card step: register with a victim's email on a site whose
		// registration flow doesn't verify it, open any donation form while
		// logged in as that account, and the row binds without ever paying.
		// A later guest donation under the same email then attaches to the
		// attacker's row. The verified path is
		// SureDonationPro\Inc\DonorDashboard\Account_Link -- one mailed,
		// one-time-token click, whether the donor got here by donating first
		// or registering first.

		// Creating a brand-new WordPress account for a donor is gated behind an
		// explicit, default-off setting. On public (nopriv) donation paths this
		// prevents unsolicited account creation and new-user notification emails
		// for attacker-supplied emails.
		$donor_settings = Helper::get_suredonation_option( 'donor_settings', [] );
		if ( empty( $donor_settings['create_wp_user'] ) ) {
			return;
		}

		// Create a new WP user.
		$username = sanitize_user( $email, true );
		$password = wp_generate_password( 24, true, true );

		$user_data = [
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => 'suredonation_donor',
		];

		// Split name into first/last if provided.
		if ( ! empty( $name ) ) {
			$parts                     = explode( ' ', $name, 2 );
			$user_data['first_name']   = $parts[0];
			$user_data['last_name']    = $parts[1] ?? '';
			$user_data['display_name'] = $name;
		}

		/**
		 * Filter the user data before creating a WP user for a donor.
		 *
		 * Return false to prevent user creation.
		 *
		 * @param array  $user_data WP user data array for wp_insert_user().
		 * @param int    $donor_id  Donor ID.
		 * @param string $email     Donor email.
		 * @since 1.0.0
		 */
		$user_data = apply_filters( 'suredonation_new_donor_user_data', $user_data, $donor_id, $email );

		if ( false === $user_data || ! is_array( $user_data ) ) {
			return;
		}

		$user_id = wp_insert_user( $user_data );

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		// Link the WP user to the donor.
		self::update( $donor_id, [ 'user_id' => $user_id ] );

		// Send new user notification email.
		wp_new_user_notification( $user_id, null, 'user' );

		/**
		 * Fires after a WP user is created and linked to a donor.
		 *
		 * @param int    $user_id  WP user ID.
		 * @param int    $donor_id Donor ID.
		 * @param string $email    Donor email.
		 * @since 1.0.0
		 */
		do_action( 'suredonation_donor_user_created', $user_id, $donor_id, $email );
	}

	/**
	 * Record a donation against a donor's aggregates exactly once.
	 *
	 * Two paths complete the same Stripe donation — the client-side confirm
	 * (`complete_donation()`) and the `payment_intent.succeeded` webhook — and
	 * neither knows whether the other got there first. The webhook applies no
	 * "still pending" guard, so calling record_donation() from both would double
	 * a donor's total; calling it from neither (until now) left the totals stale
	 * on every donation whose webhook never arrived.
	 *
	 * Keyed on the donation, not the donor, so a second genuine gift still
	 * counts. Marked before the write: a duplicated total is harder to notice
	 * and impossible to unpick, whereas a missed one is visible against the
	 * donation list and recomputable.
	 *
	 * @param  int               $donor_id    Donor row ID.
	 * @param  float             $amount      Donation amount, in $currency.
	 * @param  int               $donation_id Donation row ID this call is for.
	 * @param  string            $currency    Currency of $amount. Empty means base currency.
	 * @param  float|string|null $exchange_rate Rate stamped on the donation row; null falls back to a live lookup.
	 * @return bool True when this call recorded it, false when already recorded or invalid.
	 * @since  1.6.0
	 */
	public static function record_donation_once( $donor_id, $amount, $donation_id, $currency = '', $exchange_rate = null ) {
		$donation_id = absint( $donation_id );

		if ( $donation_id <= 0 ) {
			return false;
		}

		$key = 'suredonation_donor_recorded_' . $donation_id;

		if ( get_transient( $key ) ) {
			return false;
		}

		// Marked before the write, so two racers cannot both get through on a
		// read that saw nothing.
		set_transient( $key, true, WEEK_IN_SECONDS );

		$recorded = (bool) self::record_donation( $donor_id, $amount, $currency, $exchange_rate );

		if ( ! $recorded ) {
			// record_donation() refuses a non-positive amount or a donor row
			// that no longer exists (the privacy eraser can remove one), and
			// returns false without writing anything. Leaving the marker up
			// after that would be worse than not having it: the gateway
			// webhook retry is this row's safety net, and it would find the
			// marker and skip, so the donation would never reach the donor's
			// totals at all.
			delete_transient( $key );
		}

		return $recorded;
	}

	/**
	 * Update donor statistics after a donation.
	 *
	 * Unconditional: it takes no status and no donation id, so it cannot tell a
	 * repeat call for the same donation from a second gift. Callers that can be
	 * reached twice for one donation should use record_donation_once().
	 *
	 * `total_donated` and `largest_donation` are single-currency columns, so an
	 * amount given in a donation currency is converted to the site's base before
	 * it is added. Without that a 10,000 JPY gift would be banked as 10,000 of
	 * the base currency and outrank every real donor on the leaderboard. Passing
	 * no currency keeps the historic behaviour of treating the amount as base.
	 *
	 * @param int               $donor_id Donor ID.
	 * @param float             $amount   Donation amount, in $currency.
	 * @param string            $currency Currency of $amount. Empty means base currency.
	 * @param float|string|null $exchange_rate Rate stamped on the donation row. A stamped 0 means the row is unpriced and is banked at face value; null means no rate travelled with the call and one is looked up live.
	 * @return int|false Number of rows updated or false on error.
	 * @since 0.0.1
	 */
	public static function record_donation( $donor_id, $amount, $currency = '', $exchange_rate = null ) {
		if ( empty( $donor_id ) || $amount <= 0 ) {
			return false;
		}

		if ( '' !== $currency ) {
			// Prefer the rate stamped on the donation row. Re-deriving a live
			// one makes this column disagree with every other reader, which all
			// divide by the row's own exchange_rate: an admin completing a
			// pending donation a week later would bank it at that week's rate.
			// It also stops a donation in a currency the site no longer prices
			// from silently never reaching the donor's totals.
			if ( is_numeric( $exchange_rate ) ) {
				$stamped = (float) $exchange_rate;

				// A stamped 0 is not a missing rate. It is a recorded "nothing
				// could price this currency when the row was written", and the
				// row is already in the books being counted at face value by
				// every SQL reader. Looking up a live rate here would re-open
				// the very split this branch exists to close: an imported row
				// stamped 0, priced by the admin a month later and only then
				// completed, would reach the donor's total converted while the
				// campaign that received it still counted it whole.
				$amount = $stamped > 0 ? (float) $amount / $stamped : (float) $amount;
			} else {
				// Nothing was stamped, so there is no recorded decision to
				// honour and a live rate is the best answer available.
				$converted = Payment_Helper::convert_to_base( (float) $amount, $currency );

				// A null conversion means nothing can price this currency: an
				// imported row, or one taken before the currency was removed
				// from the settings. Every SQL reader already degrades exactly
				// that row to face value through
				// `COALESCE( NULLIF( exchange_rate, 0 ), 1 )`, so do the same
				// here rather than refusing.
				//
				// Refusing looked safer but split the books: the campaign
				// goal, the dashboard and analytics all counted the row at
				// face value while this column alone left it out, so a donor's
				// total disagreed with the campaign they gave to. Retrying does
				// not resolve it either - the rate is still missing on the next
				// pass - so the donation simply never landed. Face value is
				// what this column did before multi-currency, and it keeps
				// every reader telling the same story.
				$amount = null === $converted ? (float) $amount : $converted;
			}
		}

		$donor = self::get( $donor_id );

		if ( ! $donor ) {
			return false;
		}

		$total_value     = $donor['total_donated'] ?? 0;
		$current_total   = is_numeric( $total_value ) ? (float) $total_value : 0.0;
		$count_value     = $donor['donation_count'] ?? 0;
		$current_count   = is_numeric( $count_value ) ? (int) $count_value : 0;
		$largest_value   = $donor['largest_donation'] ?? 0;
		$current_largest = is_numeric( $largest_value ) ? (float) $largest_value : 0.0;

		$updates = [
			'total_donated'      => $current_total + $amount,
			'donation_count'     => $current_count + 1,
			'last_donation_date' => current_time( 'mysql' ),
		];

		if ( $amount > $current_largest ) {
			$updates['largest_donation'] = $amount;
		}

		return self::update( $donor_id, $updates );
	}

	/**
	 * Get top donors by money actually received.
	 *
	 * @param int    $limit        Number of donors to retrieve.
	 * @param string $payment_mode 'test' or 'live' ('' for no filter).
	 * @return array<int, array{id: string, name: string, email: string, total_donated: string, donation_count: string}> Ranked donors. Not full donor rows: only the columns a leaderboard needs.
	 * @since 0.0.1
	 * @since 1.7.0 Computed from donation rows and scoped to a payment mode.
	 */
	public static function get_top_donors( $limit = 10, $payment_mode = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		// Ranked on money actually received, joined from the donation rows. The
		// denormalised total_donated column this used to order by counts every
		// mode and is never decremented on refund, so a rehearsal could put a
		// donor at the top of a live leaderboard.
		$args   = [ $instance->get_tablename(), $wpdb->prefix . 'suredonation_donations', 'active' ];
		$extra  = Donations::scope_fragment_for( '', $payment_mode, $args, 'dn' );
		$args[] = absint( $limit );

		$sql = "SELECT d.id, d.name, d.email,
					COALESCE(SUM( ( dn.amount - dn.refunded_amount ) / COALESCE( NULLIF( dn.exchange_rate, 0 ), 1 ) ), 0) AS total_donated,
					COUNT(dn.id) AS donation_count
				FROM %i d
				INNER JOIN %i dn ON dn.donor_id = d.id
				WHERE d.donor_status = %s
				AND dn.payment_status IN ('completed', 'partially_refunded')
				{$extra}
				GROUP BY d.id, d.name, d.email
				ORDER BY total_donated DESC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live totals; $extra is static placeholder text and every value travels in $args.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Get total donors count.
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
	 * Get total donors count by status.
	 *
	 * @param string $status Donor status ('all' for no filter).
	 * @return int Total count.
	 * @since 0.0.1
	 */
	public static function get_total_donors( $status = 'all' ) {
		$instance = self::get_instance();
		global $wpdb;

		if ( 'all' === $status ) {
			return self::count_all();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE donor_status = %s',
				$instance->get_tablename(),
				sanitize_text_field( $status )
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get valid donor statuses.
	 *
	 * @return array<string> Valid statuses.
	 * @since 0.0.1
	 */
	public static function get_valid_statuses() {
		return self::$valid_statuses;
	}

	/**
	 * Get Stripe customer ID for a donor by email.
	 *
	 * @param string $email Donor email.
	 * @return string|null Stripe customer ID or null if not found.
	 * @since 0.0.1
	 */
	public static function get_stripe_customer_id_by_email( $email ) {
		if ( empty( $email ) ) {
			return null;
		}

		$donor = self::get_by_email( $email );

		if ( $donor && ! empty( $donor['stripe_customer_id'] ) && is_string( $donor['stripe_customer_id'] ) ) {
			return $donor['stripe_customer_id'];
		}

		return null;
	}

	/**
	 * Update Stripe customer ID for a donor by email.
	 *
	 * @param string $email              Donor email.
	 * @param string $stripe_customer_id Stripe customer ID.
	 * @return bool True on success, false on failure.
	 * @since 0.0.1
	 */
	public static function set_stripe_customer_id_by_email( $email, $stripe_customer_id ) {
		if ( empty( $email ) || empty( $stripe_customer_id ) ) {
			return false;
		}

		$donor = self::get_by_email( $email );

		if ( ! $donor || empty( $donor['id'] ) ) {
			return false;
		}

		$donor_id = is_numeric( $donor['id'] ) ? (int) $donor['id'] : 0;
		if ( $donor_id <= 0 ) {
			return false;
		}

		$result = self::update( $donor_id, [ 'stripe_customer_id' => sanitize_text_field( $stripe_customer_id ) ] );

		return false !== $result;
	}

	/**
	 * Clear Stripe customer ID for a donor by email.
	 *
	 * This is used when a cached customer ID is no longer valid
	 * (e.g., customer was deleted from Stripe or mode switched).
	 *
	 * @param string $email Donor email.
	 * @return bool True on success, false on failure.
	 * @since 0.0.1
	 */
	public static function clear_stripe_customer_id_by_email( $email ) {
		if ( empty( $email ) ) {
			return false;
		}

		$donor = self::get_by_email( $email );

		if ( ! $donor || empty( $donor['id'] ) ) {
			return false;
		}

		$donor_id = is_numeric( $donor['id'] ) ? (int) $donor['id'] : 0;
		if ( $donor_id <= 0 ) {
			return false;
		}

		$result = self::update( $donor_id, [ 'stripe_customer_id' => '' ] );

		return false !== $result;
	}

	/**
	 * Get the Stripe customer ID for a donor on a specific connected account.
	 *
	 * Reads the per-account map stored in `donor_data['stripe_customers']`.
	 * Falls back to the legacy single `stripe_customer_id` column when the
	 * account is the site default, so pre-multi-account donors keep working.
	 *
	 * @param string $email      Donor email.
	 * @param string $account_id Stripe account id (`acct_…`).
	 * @param bool   $is_default Whether this is the site default account.
	 * @return string Customer ID, or '' when none is stored.
	 * @since 1.3.0
	 */
	public static function get_stripe_customer_id_for_account( $email, $account_id, $is_default = false ) {
		if ( empty( $email ) || empty( $account_id ) ) {
			return '';
		}

		$donor = self::get_by_email( $email );
		if ( ! $donor ) {
			return '';
		}

		$donor_data = isset( $donor['donor_data'] ) && is_array( $donor['donor_data'] ) ? $donor['donor_data'] : [];
		$map        = isset( $donor_data['stripe_customers'] ) && is_array( $donor_data['stripe_customers'] ) ? $donor_data['stripe_customers'] : [];

		if ( isset( $map[ $account_id ] ) && is_string( $map[ $account_id ] ) && '' !== $map[ $account_id ] ) {
			return $map[ $account_id ];
		}

		// Legacy fallback: the single column holds the default account's customer.
		if ( $is_default && ! empty( $donor['stripe_customer_id'] ) && is_string( $donor['stripe_customer_id'] ) ) {
			return $donor['stripe_customer_id'];
		}

		return '';
	}

	/**
	 * Store the Stripe customer ID for a donor on a specific connected account.
	 *
	 * Writes the per-account map in `donor_data['stripe_customers']` and mirrors
	 * the default account's customer into the legacy `stripe_customer_id` column
	 * so back-compat readers keep working.
	 *
	 * @param string $email       Donor email.
	 * @param string $account_id  Stripe account id (`acct_…`).
	 * @param string $customer_id Stripe customer ID.
	 * @param bool   $is_default  Whether this is the site default account.
	 * @return bool True on success, false on failure.
	 * @since 1.3.0
	 */
	public static function set_stripe_customer_id_for_account( $email, $account_id, $customer_id, $is_default = false ) {
		if ( empty( $email ) || empty( $account_id ) || empty( $customer_id ) ) {
			return false;
		}

		$donor = self::get_by_email( $email );
		if ( ! $donor || empty( $donor['id'] ) ) {
			return false;
		}
		$donor_id = is_numeric( $donor['id'] ) ? (int) $donor['id'] : 0;
		if ( $donor_id <= 0 ) {
			return false;
		}

		$donor_data = isset( $donor['donor_data'] ) && is_array( $donor['donor_data'] ) ? $donor['donor_data'] : [];
		$map        = isset( $donor_data['stripe_customers'] ) && is_array( $donor_data['stripe_customers'] ) ? $donor_data['stripe_customers'] : [];

		$map[ $account_id ]             = sanitize_text_field( $customer_id );
		$donor_data['stripe_customers'] = $map;

		$update = [ 'donor_data' => $donor_data ];
		if ( $is_default ) {
			$update['stripe_customer_id'] = sanitize_text_field( $customer_id );
		}

		$result = self::update( $donor_id, $update );

		return false !== $result;
	}

	/**
	 * Clear the stored Stripe customer ID for a donor on a specific account.
	 *
	 * Used when a cached customer id is no longer valid on that account
	 * (deleted in Stripe, or a test/live mismatch).
	 *
	 * @param string $email      Donor email.
	 * @param string $account_id Stripe account id (`acct_…`).
	 * @param bool   $is_default Whether this is the site default account.
	 * @return bool True on success, false on failure.
	 * @since 1.3.0
	 */
	public static function clear_stripe_customer_id_for_account( $email, $account_id, $is_default = false ) {
		if ( empty( $email ) || empty( $account_id ) ) {
			return false;
		}

		$donor = self::get_by_email( $email );
		if ( ! $donor || empty( $donor['id'] ) ) {
			return false;
		}
		$donor_id = is_numeric( $donor['id'] ) ? (int) $donor['id'] : 0;
		if ( $donor_id <= 0 ) {
			return false;
		}

		$donor_data = isset( $donor['donor_data'] ) && is_array( $donor['donor_data'] ) ? $donor['donor_data'] : [];
		$map        = isset( $donor_data['stripe_customers'] ) && is_array( $donor_data['stripe_customers'] ) ? $donor_data['stripe_customers'] : [];
		unset( $map[ $account_id ] );
		$donor_data['stripe_customers'] = $map;

		$update = [ 'donor_data' => $donor_data ];
		if ( $is_default ) {
			$update['stripe_customer_id'] = '';
		}

		$result = self::update( $donor_id, $update );

		return false !== $result;
	}

	/**
	 * Get donors for admin listing with optional filters.
	 *
	 * @param string $search      Search term for name, email, or phone.
	 * @param int    $campaign_id Campaign ID filter (0 for no filter).
	 * @param string $status      Donor status filter ('all' for no filter).
	 * @param int    $limit       Number of records to return.
	 * @param int    $offset      Offset for pagination.
	 * @param string $orderby     Column to order by.
	 * @param string $order       Order direction (ASC or DESC).
	 * @param string $after       Start date filter (Y-m-d).
	 * @param string $before      End date filter (Y-m-d).
	 * @return array<mixed> Array of donors.
	 * @since 1.0.0
	 */
	public static function get_admin_list( $search = '', $campaign_id = 0, $status = 'all', $limit = 20, $offset = 0, $orderby = 'created_at', $order = 'DESC', $after = '', $before = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		$donors_table    = $instance->get_tablename();
		$donations_table = $wpdb->prefix . 'suredonation_donations';

		// Validate orderby column.
		if ( ! in_array( $orderby, self::$valid_order_columns, true ) ) {
			$orderby = 'created_at';
		}

		// Validate order direction.
		$order = strtoupper( $order );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$order = 'DESC';
		}

		$conditions = self::build_admin_list_conditions( $search, $campaign_id, $status, $after, $before );
		$where      = $conditions['where'];
		$query_args = $conditions['args'];

		if ( $conditions['has_campaign'] ) {
			$order_col = 'd.' . $orderby;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic query with validated conditions.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare-safe conditions, $order_col and $order are validated against whitelists.
					"SELECT DISTINCT d.* FROM %i d INNER JOIN %i don ON d.id = don.donor_id {$where} ORDER BY {$order_col} {$order} LIMIT %d, %d",
					array_merge( [ $donors_table, $donations_table ], $query_args, [ absint( $offset ), absint( $limit ) ] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic query with validated conditions.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare-safe conditions, $orderby and $order are validated against whitelists.
					"SELECT * FROM %i {$where} ORDER BY {$orderby} {$order} LIMIT %d, %d",
					array_merge( [ $donors_table ], $query_args, [ absint( $offset ), absint( $limit ) ] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

		if ( ! $results || ! is_array( $results ) ) {
			return [];
		}

		return array_map( [ $instance, 'decode_by_datatype' ], $results );
	}

	/**
	 * Get total donors count with filters.
	 *
	 * @param string $search      Search term for name, email, or phone.
	 * @param int    $campaign_id Campaign ID filter (0 for no filter).
	 * @param string $status      Donor status filter ('all' for no filter).
	 * @param string $after       Start date filter (Y-m-d).
	 * @param string $before      End date filter (Y-m-d).
	 * @return int Total count.
	 * @since 1.0.0
	 */
	public static function get_total_donors_filtered( $search = '', $campaign_id = 0, $status = 'all', $after = '', $before = '' ) {
		$instance = self::get_instance();
		global $wpdb;

		$donors_table    = $instance->get_tablename();
		$donations_table = $wpdb->prefix . 'suredonation_donations';

		$conditions = self::build_admin_list_conditions( $search, $campaign_id, $status, $after, $before );
		$where      = $conditions['where'];
		$query_args = $conditions['args'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Data changes frequently, caching would show stale counts.
		if ( $conditions['has_campaign'] ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare-safe conditions.
					"SELECT COUNT(DISTINCT d.id) FROM %i d INNER JOIN %i don ON d.id = don.donor_id {$where}",
					array_merge( [ $donors_table, $donations_table ], $query_args )
				)
			);
		} elseif ( ! empty( $query_args ) ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare-safe conditions.
					"SELECT COUNT(*) FROM %i {$where}",
					array_merge( [ $donors_table ], $query_args )
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i',
					$donors_table
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Get aggregate donor statistics.
	 *
	 * @param string $payment_mode 'test' or 'live' ('' for no filter).
	 * @return array{total_donors: int, total_donated: float, average_donation: float} Aggregate stats.
	 * @since 1.0.0
	 * @since 1.7.0 The money figures come from donation rows, scoped to a payment mode.
	 */
	public static function get_aggregate_stats( $payment_mode = '' ) {
		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// The donor count is a count of people and matches the list below it, so
		// it stays a plain count of donor records. The two money figures do not:
		// they came from the denormalised columns, which count every mode and are
		// never decremented on refund, so they are computed from the donation rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$donor_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		// Joined to the donors table so the money describes the same population as
		// the count beside it. A donation row whose donor was deleted, or that
		// carries no donor id, would otherwise be counted in "Total Donations"
		// while being unrepresented in "Total Donors".
		$args  = [ $wpdb->prefix . 'suredonation_donations', $table ];
		$extra = Donations::scope_fragment_for( '', $payment_mode, $args, 'dn' );
		$sql   = "SELECT COALESCE(SUM( ( dn.amount - dn.refunded_amount ) / COALESCE( NULLIF( dn.exchange_rate, 0 ), 1 ) ), 0) AS total_donated,
					COUNT(*) AS total_donation_count
				FROM %i dn
				INNER JOIN %i d ON dn.donor_id = d.id
				WHERE dn.payment_status IN ('completed', 'partially_refunded')
				{$extra}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live totals; $extra is static placeholder text and every value travels in $args.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$total_donors         = is_numeric( $donor_count ) ? (int) $donor_count : 0;
		$total_donated        = is_numeric( $row['total_donated'] ?? 0 ) ? (float) $row['total_donated'] : 0.0;
		$total_donation_count = is_numeric( $row['total_donation_count'] ?? 0 ) ? (int) $row['total_donation_count'] : 0;
		$average_donation     = $total_donation_count > 0 ? $total_donated / $total_donation_count : 0.0;

		return [
			'total_donors'     => $total_donors,
			'total_donated'    => $total_donated,
			'average_donation' => round( $average_donation, 2 ),
		];
	}

	/**
	 * Build WHERE conditions and prepare args for admin list queries.
	 *
	 * @param string $search      Search term for name, email, or phone.
	 * @param int    $campaign_id Campaign ID filter (0 for no filter).
	 * @param string $status      Donor status filter ('all' for no filter).
	 * @param string $after       Start date filter (Y-m-d).
	 * @param string $before      End date filter (Y-m-d).
	 * @return array{where: string, args: array<mixed>, has_campaign: bool} Query parts.
	 * @since 1.0.0
	 */
	private static function build_admin_list_conditions( $search, $campaign_id, $status, $after = '', $before = '' ) {
		global $wpdb;

		$has_search   = ! empty( $search );
		$has_campaign = $campaign_id > 0;
		$has_status   = 'all' !== $status && ! empty( $status ) && in_array( $status, self::$valid_statuses, true );

		$conditions = [];
		$args       = [];

		if ( $has_campaign ) {
			$conditions[] = 'don.campaign_id = %d';
			$args[]       = absint( $campaign_id );
		}

		if ( $has_status ) {
			$col_prefix   = $has_campaign ? 'd.' : '';
			$conditions[] = $col_prefix . 'donor_status = %s';
			$args[]       = sanitize_text_field( $status );
		}

		if ( $has_search ) {
			$col_prefix   = $has_campaign ? 'd.' : '';
			$search_term  = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$conditions[] = '(' . $col_prefix . 'name LIKE %s OR ' . $col_prefix . 'email LIKE %s OR ' . $col_prefix . 'phone LIKE %s)';
			$args[]       = $search_term;
			$args[]       = $search_term;
			$args[]       = $search_term;
		}

		if ( ! empty( $after ) ) {
			$col_prefix   = $has_campaign ? 'd.' : '';
			$conditions[] = $col_prefix . 'last_donation_date >= %s';
			$args[]       = sanitize_text_field( $after ) . ' 00:00:00';
		}

		if ( ! empty( $before ) ) {
			$col_prefix   = $has_campaign ? 'd.' : '';
			$conditions[] = $col_prefix . 'last_donation_date <= %s';
			$args[]       = sanitize_text_field( $before ) . ' 23:59:59';
		}

		$where = ! empty( $conditions ) ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

		return [
			'where'        => $where,
			'args'         => $args,
			'has_campaign' => $has_campaign,
		];
	}
}
