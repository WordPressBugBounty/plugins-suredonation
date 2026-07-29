<?php
/**
 * CSV export primitives shared by the Import & Export feature.
 *
 * Centralizes the two low-level concerns every CSV export needs:
 *  - CSV formula-injection escaping (so a malicious donor-supplied value
 *    cannot execute when an admin opens the file in a spreadsheet).
 *  - Building a CSV string from an array of rows via an in-memory stream.
 *
 * Per-entity column/query definitions (donations, donors) live in the
 * exporters that call these helpers; this class is format mechanics only.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Import_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared CSV export helper.
 *
 * @since 1.3.0
 */
class Csv_Exporter {

	/**
	 * Characters that, when leading a cell, can trigger formula/DDE execution
	 * in Excel, Google Sheets, and LibreOffice. Includes `|` and `%` which
	 * trigger DDE in some Excel locales beyond the standard formula prefixes.
	 *
	 * @var array<int, string>
	 * @since 1.3.0
	 */
	const FORMULA_PREFIXES = [ '=', '+', '-', '@', '|', '%', "\t", "\r" ];

	/**
	 * Escape a single cell value against CSV formula injection.
	 *
	 * Leading whitespace is stripped before inspecting the first character —
	 * Excel still interprets "  =cmd|..." as a formula even with leading
	 * spaces. When the (trimmed) value begins with a dangerous character the
	 * original value is prefixed with a single quote, which neutralizes the
	 * formula while remaining human-readable.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string Escaped cell value.
	 * @since 1.3.0
	 */
	public static function escape_cell( $value ) {
		$value    = is_scalar( $value ) ? (string) $value : '';
		$stripped = ltrim( $value );

		if ( '' !== $stripped && in_array( $stripped[0], self::FORMULA_PREFIXES, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Build a CSV string from an array of rows.
	 *
	 * Every cell is passed through escape_cell() so callers cannot forget the
	 * injection guard. The first row is typically the header labels (escaping
	 * static labels is a harmless no-op). Uses php://temp — an in-memory
	 * stream that spills to a temp file only if the payload is large — so
	 * nothing is written to a web-reachable path.
	 *
	 * @param array<int, array<int, mixed>> $rows Rows, each an ordered array of cell values.
	 * @return string CSV content, or an empty string on failure / no rows.
	 * @since 1.3.0
	 */
	public static function build( array $rows ) {
		if ( empty( $rows ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to an in-memory stream, not the filesystem.
		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			return '';
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$escaped = array_map( [ self::class, 'escape_cell' ], $row );
			// Explicit empty $escape (RFC-4180): omitting it applies PHP's
			// non-standard "\" escaping, which corrupts export->re-import
			// round-trips for values ending in a backslash and is deprecated
			// as of PHP 8.4. Valid on the plugin's PHP 7.4+ floor.
			fputcsv( $stream, $escaped, ',', '"', '' );
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing an in-memory stream.
		fclose( $stream );

		return is_string( $csv ) ? $csv : '';
	}
}
