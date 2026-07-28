<?php
/**
 * SureDonation Database Donors Table Class.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Database\Tables;

use SureDonation\Inc\Database\Base;
use SureDonation\Inc\Helper;
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
	 * @var array<string>
	 * @since 0.0.1
	 */
	private static $valid_order_columns = [
		'id',
		'email',
		'name',
		'total_donated',
		'donation_count',
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
			'import_source VARCHAR(20) NOT NULL DEFAULT ""',
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
			'company VARCHAR(255) NOT NULL DEFAULT "" AFTER phone',
			'address TEXT NOT NULL DEFAULT "" AFTER company',
			'import_source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER stripe_customer_id',
			'import_source VARCHAR(20) NOT NULL DEFAULT "" AFTER import_source_id',
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
	 * @param string $name  Donor name.
	 * @param string $phone Donor phone.
	 * @return int|false Donor ID or false on error.
	 * @since 0.0.1
	 */
	public static function get_or_create( $email, $name = '', $phone = '' ) {
		if ( empty( $email ) ) {
			return false;
		}

		$existing = self::get_by_email( $email );

		if ( $existing ) {
			// Update name/phone if provided and different.
			$updates = [];

			if ( ! empty( $name ) && $name !== $existing['name'] ) {
				$updates['name'] = $name;
			}

			if ( ! empty( $phone ) && $phone !== $existing['phone'] ) {
				$updates['phone'] = $phone;
			}

			if ( ! empty( $updates ) && isset( $existing['id'] ) ) {
				$existing_id = is_numeric( $existing['id'] ) ? (int) $existing['id'] : 0;
				if ( $existing_id > 0 ) {
					self::update( $existing_id, $updates );
				}
			}

			$existing_id = isset( $existing['id'] ) && is_numeric( $existing['id'] ) ? (int) $existing['id'] : 0;
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

		// Check if a WP user already exists with this email.
		$existing_user = get_user_by( 'email', $email );

		if ( $existing_user ) {
			self::update( $donor_id, [ 'user_id' => $existing_user->ID ] );
			return;
		}

		// Creating a brand-new WordPress account for a donor is gated behind an
		// explicit, default-off setting. On public (nopriv) donation paths this
		// prevents unsolicited account creation and new-user notification emails
		// for attacker-supplied emails. Linking to an already-existing user
		// (handled above) is always allowed.
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
	 * Update donor statistics after a donation.
	 *
	 * @param int   $donor_id Donor ID.
	 * @param float $amount   Donation amount.
	 * @return int|false Number of rows updated or false on error.
	 * @since 0.0.1
	 */
	public static function record_donation( $donor_id, $amount ) {
		if ( empty( $donor_id ) || $amount <= 0 ) {
			return false;
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
	 * Get top donors by total donated.
	 *
	 * @param int $limit Number of donors to retrieve.
	 * @return array<int, array<string, mixed>> Array of top donors.
	 * @since 0.0.1
	 */
	public static function get_top_donors( $limit = 10 ) {
		$instance = self::get_instance();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE donor_status = %s ORDER BY total_donated DESC LIMIT %d',
				$instance->get_tablename(),
				'active',
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
	 * @return array{total_donors: int, total_donated: float, average_donation: float} Aggregate stats.
	 * @since 1.0.0
	 */
	public static function get_aggregate_stats() {
		$instance = self::get_instance();
		global $wpdb;
		$table = $instance->get_tablename();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total_donors, COALESCE(SUM(total_donated), 0) AS total_donated, COALESCE(SUM(donation_count), 0) AS total_donation_count FROM %i',
				$table
			),
			ARRAY_A
		);

		$total_donors         = is_numeric( $row['total_donors'] ?? 0 ) ? (int) $row['total_donors'] : 0;
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
