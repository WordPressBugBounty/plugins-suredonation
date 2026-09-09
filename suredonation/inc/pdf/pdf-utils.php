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
	 * The denial files only bind where the server reads them: .htaccess on
	 * Apache and LiteSpeed, web.config on IIS. nginx and Caddy read neither, so
	 * there the unguessable 128-bit filename is the only control — which is why
	 * the generator proceeds regardless of what this returns. A host that
	 * cannot write a denial file should not lose the ability to issue receipts;
	 * refusing would trade a real feature for a control those servers were
	 * never going to enforce anyway.
	 *
	 * There is no receipt download route in this plugin. Delivery — email
	 * attachment and authenticated streaming download alike — lives entirely
	 * in Pro; an earlier version of this note claimed a REST streaming
	 * endpoint here, and none exists.
	 *
	 * @return bool True when the directory exists *and* every denial file this
	 *              method writes is present. Callers that need to know whether
	 *              the directory is actually hardened can act on it; the
	 *              generator deliberately does not, for the reason above.
	 * @since 1.0.0
	 */
	public static function ensure_receipts_dir() {
		$dir = self::get_receipts_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Bail before writing if the directory is not there. Every write below
		// is unconditional on the directory existing, so on a host where
		// wp_mkdir_p() fails — an unwritable uploads path, an open_basedir
		// restriction — each one emitted a PHP warning into the log before
		// failing anyway. Nothing downstream is worse off: the caller already
		// treats a false return as "not hardened".
		if ( ! is_dir( $dir ) ) {
			return false;
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

		// IIS reads neither .htaccess nor index.php as an access rule, so it
		// needs its own denial. Written unconditionally rather than detected:
		// the file is inert on Apache/nginx/LiteSpeed, and detecting the server
		// from $_SERVER['SERVER_SOFTWARE'] is unreliable behind a proxy.
		//
		// requestFiltering under system.webServer/security, matching SureMail's
		// attachments directory — minus its <handlers><remove> block. That block
		// is belt-and-braces on top of two controls already here, and IIS raises
		// a configuration error when <remove> names a handler the site does not
		// have (the names are host-specific — SureMail hardcodes
		// 'php-7.4.33'). Shipping something that can itself 500 the directory
		// is the exact failure this rewrite exists to remove, and it cannot be
		// tested from here, so it is left out: hiddenSegments already refuses
		// every request to the path, and fileExtensions already refuses
		// executables.
		//
		// The first attempt used
		// system.webServer/authorization, which is not a real section — the
		// ASP.NET element misplaced. IIS answers 500.19 on an unrecognised
		// section, so the directory ended up "protected" by a configuration
		// error rather than by the control this claims, and would have opened
		// the moment somebody fixed the 500. requestFiltering also needs no
		// extra role service, where URL Authorization 500s unless it is
		// installed.
		$web_config = $dir . '/web.config';
		if ( ! file_exists( $web_config ) ) {
			$config = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<configuration>' . "\n"
				. '  <system.webServer>' . "\n"
				. '    <security>' . "\n"
				. '      <requestFiltering>' . "\n"
				. '        <hiddenSegments>' . "\n"
				. '          <add segment="receipts" />' . "\n"
				. '        </hiddenSegments>' . "\n"
				. '        <fileExtensions allowUnlisted="true">' . "\n"
				. '          <add fileExtension=".php" allowed="false" />' . "\n"
				. '          <add fileExtension=".phtml" allowed="false" />' . "\n"
				. '          <add fileExtension=".php3" allowed="false" />' . "\n"
				. '          <add fileExtension=".php4" allowed="false" />' . "\n"
				. '          <add fileExtension=".php5" allowed="false" />' . "\n"
				. '          <add fileExtension=".php7" allowed="false" />' . "\n"
				. '          <add fileExtension=".phar" allowed="false" />' . "\n"
				. '          <add fileExtension=".phps" allowed="false" />' . "\n"
				. '          <add fileExtension=".pht" allowed="false" />' . "\n"
				. '          <add fileExtension=".phpt" allowed="false" />' . "\n"
				. '          <add fileExtension=".inc" allowed="false" />' . "\n"
				. '        </fileExtensions>' . "\n"
				. '      </requestFiltering>' . "\n"
				. '    </security>' . "\n"
				. '  </system.webServer>' . "\n"
				. '</configuration>' . "\n";
			file_put_contents( $web_config, $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// The directory has to exist *and* be protected. Returning only
		// is_dir() reported success on a directory whose hardening writes had
		// silently failed — an open receipts directory holding donor names,
		// addresses and amounts, with the caller told everything was fine.
		// web.config included: it was left out, so on IIS — the one platform it
		// exists for — a failed write still reported success.
		return is_dir( $dir ) && file_exists( $htaccess ) && file_exists( $index ) && file_exists( $web_config );
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
