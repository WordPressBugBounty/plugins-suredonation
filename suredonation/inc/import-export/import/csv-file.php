<?php
/**
 * Uploaded-CSV storage + reader for the own-data importer.
 *
 * An uploaded import file is stashed in a protected uploads subdirectory under
 * a random token, then read back in ranges across import batches. Provides the
 * header + sample for the analyze step and offset/limit row ranges for the
 * batched import. Strips a UTF-8 BOM so the first header cell always matches.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Import_Export\Import;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV file store + reader.
 *
 * @since 1.3.0
 */
class Csv_File {

	/**
	 * Uploads subdirectory (relative to the uploads basedir) for import files.
	 *
	 * @var string
	 * @since 1.3.0
	 */
	const SUBDIR = 'suredonation-import';

	/**
	 * Absolute path to the protected import directory, creating and hardening
	 * it (deny-all .htaccess + silent index.php) on first use.
	 *
	 * @return string|false Directory path with trailing slash, or false on failure.
	 * @since 1.3.0
	 */
	private static function dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::SUBDIR . '/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time hardening of a private plugin directory.
			file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" );
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time hardening of a private plugin directory.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Move an uploaded temp file into the protected directory under a token.
	 *
	 * @param string $tmp_path Uploaded file's tmp_name.
	 * @return string|false Token on success, false otherwise.
	 * @since 1.3.0
	 */
	public static function store( $tmp_path ) {
		$dir = self::dir();
		if ( false === $dir || ! is_uploaded_file( $tmp_path ) ) {
			return false;
		}

		// Opportunistically sweep abandoned uploads (analyze without a start,
		// or a crashed run) so donor-PII files don't accumulate on disk.
		self::gc();

		$token = wp_generate_uuid4();
		$dest  = $dir . $token . '.csv';

		if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
			return false;
		}

		return $token;
	}

	/**
	 * Delete stored import files older than $max_age.
	 *
	 * A safety net for files whose session ended without an explicit delete
	 * (abandoned analyze, fatal error mid-run). Completed/failed runs delete
	 * their own file immediately; this only catches the leftovers.
	 *
	 * @param int $max_age Maximum file age in seconds.
	 * @return void
	 * @since 1.3.0
	 */
	public static function gc( $max_age = HOUR_IN_SECONDS ) {
		$dir = self::dir();
		if ( false === $dir ) {
			return;
		}

		$files = glob( $dir . '*.csv' );
		if ( ! is_array( $files ) ) {
			return;
		}

		$threshold = time() - max( 0, (int) $max_age );
		foreach ( $files as $file ) {
			if ( is_file( $file ) && (int) filemtime( $file ) < $threshold ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Resolve a token to its file path, confined to the import directory.
	 *
	 * @param string $token File token.
	 * @return string|false Absolute path, or false if invalid/missing.
	 * @since 1.3.0
	 */
	public static function path_for( $token ) {
		$dir = self::dir();
		if ( false === $dir || ! is_string( $token ) || ! preg_match( '/^[a-f0-9\-]{8,64}$/i', $token ) ) {
			return false;
		}

		$path = $dir . $token . '.csv';
		$real = realpath( $path );
		if ( false === $real || 0 !== strpos( $real, (string) realpath( $dir ) ) || ! is_file( $real ) ) {
			return false;
		}

		return $real;
	}

	/**
	 * Delete a stored import file.
	 *
	 * @param string $token File token.
	 * @return bool True when the file is gone.
	 * @since 1.3.0
	 */
	public static function delete( $token ) {
		$path = self::path_for( $token );
		if ( false === $path ) {
			return false;
		}
		wp_delete_file( $path );
		return true;
	}

	/**
	 * Read the header row (BOM-stripped).
	 *
	 * @param string $token File token.
	 * @return array<int, string> Header cells, or empty array.
	 * @since 1.3.0
	 */
	public static function read_header( $token ) {
		$path = self::path_for( $token );
		if ( false === $path ) {
			return [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a private import file line by line to bound memory.
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return [];
		}

		$header = fgetcsv( $handle, 0, ',', '"', '' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the import file handle.
		fclose( $handle );

		if ( ! is_array( $header ) ) {
			return [];
		}

		if ( isset( $header[0] ) ) {
			$header[0] = self::strip_bom( (string) $header[0] );
		}

		return array_map(
			static function ( $cell ) {
				return (string) $cell;
			},
			$header
		);
	}

	/**
	 * Read a range of data rows (excluding the header), 0-indexed.
	 *
	 * @param string $token  File token.
	 * @param int    $offset Data-row offset.
	 * @param int    $limit  Max rows to return.
	 * @return array<int, array<int, string>> Rows of cell values.
	 * @since 1.3.0
	 */
	public static function read_range( $token, $offset, $limit ) {
		$path = self::path_for( $token );
		if ( false === $path ) {
			return [];
		}

		$offset = max( 0, (int) $offset );
		$limit  = max( 0, (int) $limit );
		if ( 0 === $limit ) {
			return [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a private import file line by line to bound memory.
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return [];
		}

		fgetcsv( $handle, 0, ',', '"', '' ); // Skip header.

		$row_index = 0;
		$rows      = [];
		while ( true ) {
			$row = fgetcsv( $handle, 0, ',', '"', '' );
			if ( false === $row ) {
				break;
			}
			if ( $row_index >= $offset && count( $rows ) < $limit ) {
				$rows[] = array_map(
					static function ( $cell ) {
						return (string) $cell;
					},
					$row
				);
			}
			++$row_index;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the import file handle.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Read the next batch of data rows starting from a byte offset.
	 *
	 * Unlike read_range() (which rewinds and re-scans from the header every
	 * call), this seeks straight to $byte_offset, so a full import costs one
	 * linear pass instead of O(n^2/batch) re-scans. Pass 0 for the first batch
	 * (the header is skipped); the updated position is written back by
	 * reference for the next call.
	 *
	 * @param string $token       File token.
	 * @param int    $byte_offset Byte position to resume from (updated by reference).
	 * @param int    $limit       Max rows to return.
	 * @return array<int, array<int, string>> Rows of cell values.
	 * @since 1.3.0
	 */
	public static function read_batch( $token, &$byte_offset, $limit ) {
		$path = self::path_for( $token );
		if ( false === $path ) {
			return [];
		}

		$limit = max( 0, (int) $limit );
		if ( 0 === $limit ) {
			return [];
		}
		$byte_offset = max( 0, (int) $byte_offset );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a private import file line by line to bound memory.
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return [];
		}

		if ( $byte_offset > 0 ) {
			fseek( $handle, $byte_offset );
		} else {
			fgetcsv( $handle, 0, ',', '"', '' ); // Skip header on the first batch.
		}

		$rows = [];
		$read = 0;
		while ( $read < $limit ) {
			$row = fgetcsv( $handle, 0, ',', '"', '' );
			if ( false === $row ) {
				break;
			}
			$rows[] = array_map(
				static function ( $cell ) {
					return (string) $cell;
				},
				$row
			);
			++$read;
		}

		$pos         = ftell( $handle );
		$byte_offset = false === $pos ? $byte_offset : (int) $pos;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the import file handle.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Read the first N data rows (for the analyze preview).
	 *
	 * @param string $token File token.
	 * @param int    $limit Number of sample rows.
	 * @return array<int, array<int, string>> Sample rows.
	 * @since 1.3.0
	 */
	public static function read_sample( $token, $limit = 5 ) {
		return self::read_range( $token, 0, $limit );
	}

	/**
	 * Count data rows (excluding the header).
	 *
	 * @param string $token File token.
	 * @return int Row count.
	 * @since 1.3.0
	 */
	public static function count_rows( $token ) {
		$path = self::path_for( $token );
		if ( false === $path ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a private import file line by line to bound memory.
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return 0;
		}

		$count = -1; // Start at -1 so the header row isn't counted.
		while ( fgetcsv( $handle, 0, ',', '"', '' ) !== false ) {
			++$count;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the import file handle.
		fclose( $handle );

		return max( 0, $count );
	}

	/**
	 * Strip a leading UTF-8 BOM from a value.
	 *
	 * @param string $value Value.
	 * @return string Value without a leading BOM.
	 * @since 1.3.0
	 */
	private static function strip_bom( $value ) {
		if ( 0 === strncmp( $value, "\xEF\xBB\xBF", 3 ) ) {
			return substr( $value, 3 );
		}
		return $value;
	}
}
