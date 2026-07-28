<?php
/**
 * PDF Library Manager.
 *
 * Handles downloading and deleting the mPDF library via AJAX.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Pdf;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pdf_Manager class.
 *
 * @since 1.0.0
 */
class Pdf_Manager {
	use Get_Instance;

	/**
	 * GitHub URL for the PDF library zip.
	 */
	public const LIBRARY_URL = 'https://raw.githubusercontent.com/brainstormforce/sureforms-libraries/master/pdf.zip';

	/**
	 * Expected SHA-256 hash of the PDF library zip for integrity verification.
	 *
	 * Update this hash whenever the library zip is updated in the repository.
	 */
	public const LIBRARY_HASH = '71ea36ac9e41f38e395f8e62d6be04efd1c9290fc34eb464a6c34fabee632994';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_suredonation_download_pdf_library', [ $this, 'download_pdf_library' ] );
		add_action( 'wp_ajax_suredonation_delete_pdf_library', [ $this, 'delete_pdf_library' ] );
	}

	/**
	 * Download and install the PDF library.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function download_pdf_library() {
		check_ajax_referer( 'suredonation_pdf_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'suredonation' ) ] );
		}

		if ( ! Pdf_Utils::is_php_compatible() ) {
			wp_send_json_error( [ 'message' => __( 'PHP 8.0 or higher is required for the PDF library.', 'suredonation' ) ] );
		}

		// Include required WordPress file handling functions.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Download the zip file.
		$tmp_file = download_url( self::LIBRARY_URL );

		if ( is_wp_error( $tmp_file ) ) {
			wp_send_json_error( [ 'message' => $tmp_file->get_error_message() ] );
		}

		// Verify file integrity.
		$file_hash = hash_file( 'sha256', $tmp_file );
		if ( self::LIBRARY_HASH !== $file_hash ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( [ 'message' => __( 'Library integrity check failed. The downloaded file may be corrupted or tampered with.', 'suredonation' ) ] );
		}

		// Prepare target directory.
		$target_dir = WP_PLUGIN_DIR . '/suredonation-libraries';

		// Initialize WP_Filesystem.
		WP_Filesystem();
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( [ 'message' => __( 'Could not initialize filesystem.', 'suredonation' ) ] );
		}

		// Create target directory if it doesn't exist.
		if ( ! $wp_filesystem->exists( $target_dir ) ) {
			$wp_filesystem->mkdir( $target_dir, FS_CHMOD_DIR );
		}

		// Remove existing pdf directory if present.
		$pdf_dir = $target_dir . '/pdf';
		if ( $wp_filesystem->exists( $pdf_dir ) ) {
			$wp_filesystem->delete( $pdf_dir, true );
		}

		// Per-entry path validation before extraction. WP's unzip_file()
		// applies basic safety but doesn't reject absolute paths or
		// directory traversal on every PHP / zip combination. Validate
		// each entry resolves inside $target_dir before letting unzip_file
		// touch the disk — belt-and-suspenders against a tampered zip
		// (covered today by the hash check, but cheap defense-in-depth).
		$zip_check = self::validate_zip_entries( $tmp_file, $target_dir );
		if ( is_wp_error( $zip_check ) ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( [ 'message' => $zip_check->get_error_message() ] );
		}

		// Unzip the library.
		$result = unzip_file( $tmp_file, $target_dir );

		// Clean up temp file.
		wp_delete_file( $tmp_file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Verify the library was installed correctly.
		if ( ! Pdf_Utils::check_if_library_exists() ) {
			wp_send_json_error( [ 'message' => __( 'Library extraction failed. Please try again.', 'suredonation' ) ] );
		}

		// Create receipts directory.
		Pdf_Utils::ensure_receipts_dir();

		wp_send_json_success( [ 'message' => __( 'PDF library installed successfully.', 'suredonation' ) ] );
	}

	/**
	 * Delete the PDF library.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function delete_pdf_library() {
		check_ajax_referer( 'suredonation_pdf_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'suredonation' ) ] );
		}

		$target_dir = WP_PLUGIN_DIR . '/suredonation-libraries';

		// Initialize WP_Filesystem.
		WP_Filesystem();
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			wp_send_json_error( [ 'message' => __( 'Could not initialize filesystem.', 'suredonation' ) ] );
		}

		if ( $wp_filesystem->exists( $target_dir ) ) {
			$wp_filesystem->delete( $target_dir, true );
		}

		wp_send_json_success( [ 'message' => __( 'PDF library removed successfully.', 'suredonation' ) ] );
	}

	/**
	 * Validate every entry in a zip resolves inside the target directory.
	 *
	 * Rejects absolute-path entries, parent-directory traversal, and any
	 * entry whose resolved path escapes $target_dir. Also enforces a max
	 * uncompressed size guard against zip-bomb expansion. Returns a
	 * WP_Error on the first unsafe entry; null on success.
	 *
	 * @param string $zip_path   Path to the downloaded zip.
	 * @param string $target_dir Absolute path where the zip will extract.
	 * @return \WP_Error|null
	 * @since 1.0.0
	 */
	private static function validate_zip_entries( $zip_path, $target_dir ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Can't validate without ZipArchive; let unzip_file's own
			// defenses run rather than blocking the install. Hash check
			// upstream is the primary integrity defense.
			return null;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'unsafe_zip', __( 'Could not open library archive for validation.', 'suredonation' ) );
		}

		// 50MB uncompressed cap — the mPDF zip is ~10MB; anything wildly
		// larger is either a corrupted archive or a zip-bomb attempt.
		$max_uncompressed = 50 * 1024 * 1024;
		$total_size       = 0;

		$target_real = rtrim( wp_normalize_path( $target_dir ), '/' );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $numFiles is a PHP stdlib property on ZipArchive.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
				$zip->close();
				return new \WP_Error( 'unsafe_zip_entry', __( 'Archive contains an unreadable entry.', 'suredonation' ) );
			}

			$name = (string) $stat['name'];

			// Reject absolute paths and parent-directory traversal up-front
			// — the cheapest checks that catch the common malicious patterns.
			if ( '' === $name || '/' === $name[0] || false !== strpos( $name, '..' ) || preg_match( '#^[a-zA-Z]:[\\\/]#', $name ) ) {
				$zip->close();
				return new \WP_Error( 'unsafe_zip_entry', __( 'Archive contains an unsafe path.', 'suredonation' ) );
			}

			// Verify the resolved entry stays inside $target_dir.
			$resolved = wp_normalize_path( $target_real . '/' . $name );
			if ( 0 !== strpos( $resolved, $target_real . '/' ) ) {
				$zip->close();
				return new \WP_Error( 'unsafe_zip_entry', __( 'Archive contains a path escaping the target directory.', 'suredonation' ) );
			}

			$total_size += isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			if ( $total_size > $max_uncompressed ) {
				$zip->close();
				return new \WP_Error( 'unsafe_zip', __( 'Archive uncompressed size exceeds the safety limit.', 'suredonation' ) );
			}
		}

		$zip->close();
		return null;
	}
}
