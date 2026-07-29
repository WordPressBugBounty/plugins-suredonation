<?php
/**
 * SureDonation Database Tables Base Class.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Database;

use SureDonation\Inc\Helper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureDonation Database Tables Base Class
 *
 * Provides basic database operations using $wpdb methods.
 * All queries in child classes use $wpdb->prepare() with explicit placeholders.
 *
 * @since 0.0.1
 */
abstract class Base {
	/**
	 * Option key for database table versions within consolidated options.
	 *
	 * @since 0.0.1
	 */
	public const VERSION_OPTION_KEY = 'database_table_versions';

	/**
	 * WordPress Database class instance.
	 *
	 * @var \wpdb
	 * @since 0.0.1
	 */
	protected $wpdb;

	/**
	 * Current database table prefix mixed with 'suredonation_' as ending.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $table_prefix;

	/**
	 * Custom table suffix without any prefix. This needs to be overridden from child class.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $table_suffix;

	/**
	 * Version for current custom table.
	 *
	 * @var int
	 * @since 0.0.1
	 */
	protected $table_version = 1;

	/**
	 * Full table name mixed with table prefix and table suffix.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	private $table_name;

	/**
	 * Whether or not the current database table is upgradable.
	 *
	 * @var bool
	 * @since 0.0.1
	 */
	private $db_upgradable;

	/**
	 * Previously stored version of this table before the current upgrade
	 * (0 when the table had no recorded version yet). Exposed so child classes
	 * can gate one-time data migrations in run_data_migrations().
	 *
	 * @var int
	 * @since 1.3.0
	 */
	protected $prev_version = 0;

	/**
	 * Current table database result caches.
	 *
	 * @var array<mixed>
	 * @since 0.0.1
	 */
	private $caches = [];

	/**
	 * Init class.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb         = $wpdb;
		$this->table_prefix = $this->wpdb->prefix . 'suredonation_';
		$this->table_name   = $this->table_prefix . $this->table_suffix;
	}

	/**
	 * Actions to initialize during object unload.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function __destruct() {
		$this->stop_db_upgrade();
	}

	/**
	 * Returns the current table schema.
	 *
	 * @return array<string,array<mixed>>
	 * @since 0.0.1
	 */
	abstract public function get_schema();

	/**
	 * Current table columns definition to create table.
	 *
	 * @return array<string>
	 * @since 0.0.1
	 */
	abstract public function get_columns_definition();

	/**
	 * Columns to add if the table already exists. Override in child class.
	 * Each entry is a bare SQL fragment: "column_name TYPE [constraints] [AFTER other_col]"
	 * or "INDEX index_name (column)" for indexes.
	 *
	 * @return array<string>
	 * @since 1.0.0
	 */
	public function get_new_columns_definition() {
		return [];
	}

	/**
	 * Run one-time data migrations for this table after its columns are in
	 * place. Called only while the table is upgradable (see register.php).
	 * No-op by default; override in a child class and gate on $this->prev_version.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function run_data_migrations() {}

	/**
	 * Start the database upgrade process.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function start_db_upgrade() {
		$versions     = Helper::get_suredonation_option( self::VERSION_OPTION_KEY, [] );
		$versions     = is_array( $versions ) ? $versions : [];
		$prev_version = ! empty( $versions[ $this->table_suffix ] ) ? absint( $versions[ $this->table_suffix ] ) : false;

		$this->prev_version = $prev_version ? (int) $prev_version : 0;

		if ( ! $prev_version ) {
			$this->db_upgradable = true;
			return;
		}

		$this->db_upgradable = $this->table_version > $prev_version;
	}

	/**
	 * Stop the database upgrade process.
	 *
	 * @return bool Returns true on success.
	 * @since 0.0.1
	 */
	public function stop_db_upgrade() {
		if ( ! $this->db_upgradable ) {
			return false;
		}

		$versions = Helper::get_suredonation_option( self::VERSION_OPTION_KEY, [] );
		$versions = is_array( $versions ) ? $versions : [];

		$versions[ $this->table_suffix ] = $this->table_version;

		Helper::update_suredonation_option( self::VERSION_OPTION_KEY, $versions );

		return true;
	}

	/**
	 * Check if current table's DB is upgradable or not.
	 *
	 * @return bool True or false depending if DB is upgradable or not.
	 * @since 0.0.1
	 */
	public function is_db_upgradable() {
		return $this->db_upgradable;
	}

	/**
	 * Returns full table name.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public function get_tablename() {
		return $this->table_name;
	}

	/**
	 * Conditionally returns current database charset or collate.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public function get_charset_collate() {
		$charset_collate = '';

		if ( $this->wpdb->has_cap( 'collation' ) ) {
			if ( ! empty( $this->wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET {$this->wpdb->charset}";
			}
			if ( ! empty( $this->wpdb->collate ) ) {
				$charset_collate .= " COLLATE {$this->wpdb->collate}";
			}
		}

		return $charset_collate;
	}

	/**
	 * Create table.
	 *
	 * @param array<string> $columns Array of columns.
	 * @return int|bool
	 * @since 0.0.1
	 */
	public function create( $columns = [] ) {
		if ( ! $this->db_upgradable ) {
			return false;
		}

		if ( empty( $columns ) ) {
			return false;
		}

		$columns_list = implode( ', ', $columns );
		$wpdb         = $this->wpdb;

		// `$wpdb->prepare()` cannot be used here: it escapes single quotes in
		// the interpolated column-definition string, turning literal `DEFAULT ''`
		// into `DEFAULT \'\'`, which MySQL rejects with a syntax error. The
		// table name, column list, and charset are all hardcoded DDL (not user
		// input), so direct concatenation is safe.
		//
		// Column definitions must quote string literals with single quotes.
		// wpdb strips the composite `ANSI` sql_mode on connect but not a
		// standalone `ANSI_QUOTES`, under which `DEFAULT ""` parses as an empty
		// identifier and fails the statement permanently on every retry.
		$query = sprintf(
			'CREATE TABLE IF NOT EXISTS `%s` ( %s ) %s',
			esc_sql( $this->get_tablename() ),
			$columns_list,
			$this->get_charset_collate()
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $query );

		if ( false === $result ) {
			$this->db_upgradable = false;
		}

		return $result;
	}

	/**
	 * Add new columns to an existing table conditionally.
	 *
	 * Checks existing columns/indexes and only adds missing ones via ALTER TABLE.
	 *
	 * @param array<string> $new_columns Column definitions to add.
	 * @return int|bool Result of ALTER query, or false.
	 * @since 1.0.0
	 */
	public function maybe_add_new_columns( $new_columns = [] ) {
		if ( ! $new_columns ) {
			return false;
		}

		if ( ! $this->db_upgradable ) {
			return false;
		}

		$existing_columns = $this->get_columns();

		if ( ! $existing_columns ) {
			// Table does not exist or is new.
			return false;
		}

		$existing_indexes = $this->get_indexes();
		$alter_queries    = [];
		$wpdb             = $this->wpdb;

		foreach ( $new_columns as $column_definition ) {
			// Check if this is an INDEX definition.
			preg_match( '/INDEX\s+(.*?)\s+\(/', $column_definition, $index_matches );

			if ( ! empty( $index_matches[1] ) ) {
				if ( isset( $existing_indexes[ $index_matches[1] ] ) ) {
					continue; // Index already exists.
				}
				// Index definitions come from get_new_columns() — hardcoded DDL, not user input.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Hardcoded DDL fragment from get_new_columns().
				$alter_queries[] = 'ADD ' . $column_definition;
				continue;
			}

			// Extract column name from definition.
			preg_match( '/(\w+)\s/', $column_definition, $column_matches );
			$column_name = $column_matches[1] ?? '';

			if ( ! isset( $existing_columns[ $column_name ] ) ) {
				// Column definitions come from get_new_columns() — hardcoded DDL, not user input.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Hardcoded DDL fragment from get_new_columns().
				$alter_queries[] = 'ADD COLUMN ' . $column_definition;
			}
		}

		if ( ! $alter_queries ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- ALTER TABLE with prepared table name and hardcoded DDL fragments.
		$result = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ', $this->get_tablename() ) . implode( ', ', $alter_queries ) . ';' );

		if ( false === $result ) {
			$this->db_upgradable = false;
		}

		return $result;
	}

	/**
	 * Returns an array columns of current table.
	 *
	 * @return array<string,array<string,mixed>>
	 * @since 0.0.1
	 */
	public function get_columns() {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->get_tablename() ), ARRAY_A );

		if ( empty( $columns ) ) {
			return [];
		}

		$_columns = [];
		if ( is_array( $columns ) ) {
			foreach ( $columns as $column ) {
				if ( ! is_string( $column['Field'] ) ) {
					continue;
				}

				$_columns[ $column['Field'] ] = $column;
			}
		}
		return $_columns;
	}

	/**
	 * Returns an array indexes of current table.
	 *
	 * @return array<mixed>
	 * @since 0.0.1
	 */
	public function get_indexes() {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $this->get_tablename() ), ARRAY_A );

		if ( empty( $indexes ) ) {
			return [];
		}

		$_indexes = [];
		if ( is_array( $indexes ) ) {
			foreach ( $indexes as $index ) {
				$_indexes[ $index['Key_name'] ] = $index; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
		return $_indexes;
	}

	/**
	 * Insert data. Wrapper method for wpdb::insert.
	 *
	 * @param array<mixed>              $data   Data to insert.
	 * @param array<string>|string|null $format Optional format specifiers.
	 * @return int|false The id of the inserted entry, or false on error.
	 * @since 0.0.1
	 */
	public function use_insert( $data, $format = null ) {
		$prepared_data = $this->prepare_data( $data );

		if ( is_null( $format ) ) {
			$format = $prepared_data['format'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert( $this->get_tablename(), $prepared_data['data'], $format ); // @phpstan-ignore argument.type
		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Update a row data of current table. Wrapper method for wpdb::update.
	 *
	 * @param array<string,mixed> $data  Data to update.
	 * @param array<string,mixed> $where WHERE clauses.
	 * @return int|false The number of rows updated, or false on error.
	 * @since 0.0.1
	 */
	public function use_update( $data, $where ) {
		$prepared_data = $this->prepare_data( $data, true );
		$format        = $prepared_data['format'];

		$this->cache_reset();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->update(
			$this->get_tablename(),
			$prepared_data['data'],
			$where,
			$format // @phpstan-ignore argument.type
		);
	}

	/**
	 * Delete a row data of current table. Wrapper method for wpdb::delete.
	 *
	 * @param array<string,mixed>  $where        WHERE clauses.
	 * @param array<string>|string $where_format Optional format specifiers.
	 * @return int|false The number of rows deleted, or false on error.
	 * @since 0.0.1
	 */
	public function use_delete( $where, $where_format = null ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->delete( $this->get_tablename(), $where, $where_format );
	}

	/**
	 * Retrieve a cached value by its key.
	 *
	 * @param string $key The cache key.
	 * @return mixed|null The cached value or null.
	 * @since 0.0.1
	 */
	protected function cache_get( $key ) {
		$key = md5( $key );
		if ( ! isset( $this->caches[ $key ] ) ) {
			return null;
		}
		return $this->caches[ $key ];
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   The cache key.
	 * @param mixed  $value The value to store.
	 * @return mixed The stored value.
	 * @since 0.0.1
	 */
	protected function cache_set( $key, $value ) {
		$key                  = md5( $key );
		$this->caches[ $key ] = $value;
		return $value;
	}

	/**
	 * Reset the cache.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	protected function cache_reset() {
		$this->caches = [];
	}

	/**
	 * Prepare and format data based on the schema.
	 *
	 * @param array<mixed> $data          Data to prepare.
	 * @param bool         $skip_defaults Whether to skip defaults.
	 * @return array<array<mixed>> Prepared data with format specifiers.
	 * @since 0.0.1
	 */
	protected function prepare_data( $data, $skip_defaults = false ) {
		$_data  = [];
		$format = [];

		foreach ( $this->get_schema() as $key => $value ) {
			if ( ! isset( $data[ $key ] ) ) {
				if ( $skip_defaults || ! isset( $value['default'] ) ) {
					continue;
				}
				$data[ $key ] = $value['default'];
			}

			$value_type    = isset( $value['type'] ) && is_string( $value['type'] ) ? $value['type'] : 'string';
			$format[]      = $this->get_format_by_datatype( $value_type );
			$_data[ $key ] = $this->encode_by_datatype( $data[ $key ], $value_type );
		}

		return [
			'data'   => $_data,
			'format' => $format,
		];
	}

	/**
	 * Get the SQL format specifier based on the provided data type.
	 *
	 * @param string $type The data type.
	 * @return string The SQL format specifier.
	 * @since 0.0.1
	 */
	protected function get_format_by_datatype( $type ) {
		$format = '%s';

		switch ( $type ) {
			case 'string':
			case 'array':
			case 'datetime':
				$format = '%s';
				break;

			case 'number':
			case 'boolean':
				$format = '%d';
				break;

			case 'decimal':
				$format = '%f';
				break;
		}

		return $format;
	}

	/**
	 * Decode data based on the schema data types.
	 *
	 * @param array<mixed> $data Data to decode.
	 * @return array<mixed> Decoded data.
	 * @since 0.0.1
	 */
	protected function decode_by_datatype( $data ) {
		$_data = [];

		foreach ( $this->get_schema() as $key => $schema ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$value = $data[ $key ];
			if ( isset( $schema['type'] ) && 'array' === $schema['type'] ) {
				$json_string   = is_scalar( $value ) ? (string) $value : '';
				$_data[ $key ] = json_decode( $json_string, true );
				if ( ! is_array( $_data[ $key ] ) ) {
					$_data[ $key ] = [];
				}
			} else {
				$_data[ $key ] = $value;
			}
		}

		return $_data;
	}

	/**
	 * Encode a value based on the specified data type.
	 *
	 * @param mixed  $value The value to encode.
	 * @param string $type  The data type.
	 * @return mixed The encoded value.
	 * @since 0.0.1
	 */
	protected function encode_by_datatype( $value, $type ) {
		switch ( $type ) {
			case 'string':
				return is_scalar( $value ) ? (string) $value : '';

			case 'number':
				return is_numeric( $value ) ? (int) $value : 0;

			case 'boolean':
				return (bool) $value;

			case 'array':
				return wp_json_encode( is_array( $value ) ? $value : [] );

			case 'datetime':
				return $value;

			case 'decimal':
				return is_numeric( $value ) ? (float) $value : 0.0;
		}

		return $value;
	}
}
