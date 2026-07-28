<?php
/**
 * PDF Utility helpers.
 *
 * Static methods for checking library existence, PHP compatibility, and file paths.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Pdf;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pdf_Utils class.
 *
 * @since 1.0.0
 */
class Pdf_Utils {
	/**
	 * Check if the PDF library is installed.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function check_if_library_exists() {
		return file_exists( self::get_library_path() . '/vendor/autoload.php' );
	}

	/**
	 * Check if the current PHP version meets the minimum requirement.
	 *
	 * MPDF 8.x requires PHP 8.0+.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function is_php_compatible() {
		return version_compare( PHP_VERSION, '8.0.0', '>=' );
	}

	/**
	 * Get the PDF library directory path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function get_library_path() {
		return WP_PLUGIN_DIR . '/suredonation-libraries/pdf';
	}

	/**
	 * Get the receipts directory path or URL.
	 *
	 * @param string $type 'path' or 'url'.
	 * @return string
	 * @since 1.0.0
	 */
	public static function get_receipts_dir( $type = 'path' ) {
		$upload_dir = wp_upload_dir();

		if ( 'url' === $type ) {
			return $upload_dir['baseurl'] . '/suredonation/receipts';
		}

		return $upload_dir['basedir'] . '/suredonation/receipts';
	}

	/**
	 * Ensure the receipts directory exists with security files.
	 *
	 * @return bool True if directory exists or was created.
	 * @since 1.0.0
	 */
	public static function ensure_receipts_dir() {
		$dir = self::get_receipts_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Add .htaccess to prevent direct access.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// Add index.php for directory listing protection.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return is_dir( $dir );
	}

	/**
	 * Get the temp directory for mPDF.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function get_temp_dir() {
		$temp_dir = wp_normalize_path( trailingslashit( \get_temp_dir() ) ) . 'suredonation-mpdf';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		return $temp_dir;
	}
}
