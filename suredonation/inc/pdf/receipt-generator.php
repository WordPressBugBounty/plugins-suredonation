<?php
/**
 * PDF Receipt Generator.
 *
 * Generates PDF donation receipts using the mPDF library.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Pdf;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt_Generator class.
 *
 * @since 1.0.0
 */
class Receipt_Generator {
	/**
	 * Get an existing receipt PDF or generate a new one.
	 *
	 * @param int $donation_id Donation ID.
	 * @return string|false File path on success, false on failure.
	 * @since 1.0.0
	 */
	public static function get_or_generate( $donation_id ) {
		$donation = Donations::get( $donation_id );

		if ( ! $donation ) {
			return false;
		}

		if ( ! self::should_generate( $donation ) ) {
			return false;
		}

		// Check if a cached PDF exists.
		$existing_relative = $donation['receipt_pdf_url'] ?? '';

		if ( ! empty( $existing_relative ) ) {
			$existing_path = self::relative_to_path( Helper::get_string_value( $existing_relative ) );

			// is_file() so a directory can never be served as a cached receipt.
			if ( $existing_path && is_file( $existing_path ) ) {
				return $existing_path;
			}
		}

		return self::generate( $donation_id );
	}

	/**
	 * Generate a PDF receipt for a donation.
	 *
	 * @param int $donation_id Donation ID.
	 * @return string|false File path on success, false on failure.
	 * @since 1.0.0
	 */
	public static function generate( $donation_id ) {
		if ( ! Pdf_Utils::check_if_library_exists() || ! Pdf_Utils::is_php_compatible() ) {
			return false;
		}

		// Load the mPDF autoloader.
		require_once Pdf_Utils::get_library_path() . '/vendor/autoload.php';

		$donation = Donations::get( $donation_id );

		if ( ! $donation ) {
			return false;
		}

		if ( ! self::should_generate( $donation ) ) {
			return false;
		}

		$donor_id = Helper::get_integer_value( $donation['donor_id'] ?? 0 );
		$donor    = $donor_id ? Donors::get( $donor_id ) : null;

		$campaign_id    = Helper::get_integer_value( $donation['campaign_id'] ?? 0 );
		$campaign_title = $campaign_id ? (string) get_the_title( $campaign_id ) : '';

		// Build the receipt HTML.
		$html = self::build_receipt_html( $donation, $donor, $campaign_title );

		/**
		 * Filter the receipt HTML before PDF generation.
		 *
		 * SECURITY NOTE: The returned HTML is passed directly to mPDF's
		 * WriteHTML(). Resource fetching is blocked at the mPDF level by the
		 * empty 'whitelistStreamWrappers' in self::get_mpdf_config(), so an
		 * `<img src>` cannot reach a remote host or a local file — but that is
		 * the only guarantee. Everything else about the returned markup is
		 * trusted, so only ever return escaped content.
		 *
		 * @param string     $html     Receipt HTML.
		 * @param array      $donation Donation data.
		 * @param array|null $donor    Donor data.
		 * @since 1.0.0
		 */
		$html = apply_filters( 'suredonation_receipt_html', $html, $donation, $donor );

		// Ensure receipts directory exists.
		Pdf_Utils::ensure_receipts_dir();

		$receipts_dir     = Pdf_Utils::get_receipts_dir();
		$default_filename = self::generate_storage_filename();

		$filename = self::resolve_storage_filename( $default_filename, $donation, $donor );

		$filepath = $receipts_dir . '/' . $filename;

		try {
			$mpdf = new \Mpdf\Mpdf( self::get_mpdf_config( $donation, $donor ) );

			/**
			 * Fires after the mPDF instance is created, before the receipt HTML is written.
			 *
			 * Allows instance-level configuration the constructor config cannot
			 * express — e.g. SetProtection() for password-protected receipts or
			 * SetTitle()/SetAuthor() document metadata.
			 *
			 * @param \Mpdf\Mpdf                $mpdf     mPDF instance.
			 * @param array<string, mixed>      $donation Donation data.
			 * @param array<string, mixed>|null $donor    Donor data.
			 * @since 1.5.0
			 */
			do_action( 'suredonation_receipt_mpdf_instance', $mpdf, $donation, $donor );

			$mpdf->WriteHTML( $html );
			$mpdf->Output( $filepath, \Mpdf\Output\Destination::FILE );
		} catch ( \Exception $e ) {
			return false;
		}

		// Store the relative path in the donation record (portable across domain changes).
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $filepath );
		Donations::update( $donation_id, [ 'receipt_pdf_url' => $relative_path ] );

		/**
		 * Fires after a receipt PDF has been generated and stored.
		 *
		 * @param int                  $donation_id Donation ID.
		 * @param string               $filepath    Absolute path to the generated PDF.
		 * @param array<string, mixed> $donation    Donation data.
		 * @since 1.5.0
		 */
		do_action( 'suredonation_receipt_generated', $donation_id, $filepath, $donation );

		return $filepath;
	}

	/**
	 * Build the opaque on-disk filename for a receipt.
	 *
	 * 128 bits of lowercase hex and nothing else. The name is sized as a
	 * capability token rather than as a collision-avoidance suffix, because on
	 * servers that ignore the receipts directory's .htaccess it is the only
	 * thing standing between a request and a document carrying the donor's
	 * name, email and amount. Lowercase keeps the entropy honest on
	 * case-insensitive filesystems (macOS, Windows), where a mixed-case name
	 * collapses to a much smaller space than it appears to occupy.
	 *
	 * @return string
	 * @since 1.5.1
	 */
	private static function generate_storage_filename() {
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// random_bytes() only throws when the platform has no CSPRNG at all.
			// Be honest about the fallback: wp_rand() reaches for random_int()
			// first, which draws on the same source that just failed, so it lands
			// on its seeded md5/mt_rand stream -- salted and not trivially
			// predictable, but not cryptographic either. A host in that state
			// cannot keep any secret; a receipt name is the least of it.
			$token = strtolower( wp_generate_password( 32, false ) );
		}

		return 'sd-receipt-' . $token . '.pdf';
	}

	/**
	 * Get the filename a donor sees when a receipt is delivered.
	 *
	 * Deliberately separate from the name on disk: the stored file is named
	 * for unguessability, while the delivered copy is named for the person
	 * reading it. Used for the email attachment name and for the
	 * Content-Disposition of any authenticated download.
	 *
	 * @param array<string, mixed> $donation Donation data. Carries donor_name and
	 *                                       donor_email, so no separate donor record
	 *                                       is needed to build a donor-facing name.
	 * @return string
	 * @since 1.5.1
	 */
	public static function get_download_filename( $donation ) {
		$donation_id      = Helper::get_integer_value( $donation['id'] ?? 0 );
		$default_filename = $donation_id > 0
			? sprintf( 'donation-receipt-%d.pdf', $donation_id )
			: 'donation-receipt.pdf';

		/**
		 * Filter the filename a donor sees when a receipt is delivered.
		 *
		 * This never names anything on disk -- it is the name attached to the
		 * email and sent as Content-Disposition -- so it is free to carry
		 * donor-facing detail such as a receipt number.
		 *
		 * Deliberately NOT shaped like the storage name any more. That one
		 * collapses interior dots to guarantee a single extension, because it
		 * names a file inside a web-accessible directory; this one only ever
		 * becomes a Content-Disposition value or an email attachment key, never
		 * a path, so it keeps whatever dots the filter supplied and a filtered
		 * "x.php" stays "x.php.pdf". Nothing here touches a filesystem, and the
		 * name still ends in .pdf, so the OS treats it as one.
		 *
		 * @param string               $default_filename Default download filename.
		 * @param array<string, mixed> $donation         Donation data.
		 * @since 1.5.1
		 */
		$filename = apply_filters( 'suredonation_receipt_download_filename', $default_filename, $donation );
		$filename = sanitize_file_name( Helper::get_string_value( $filename ) );

		if ( '' === $filename ) {
			return $default_filename;
		}

		if ( 'pdf' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$filename .= '.pdf';
		}

		return $filename;
	}

	/**
	 * Whether a receipt should be generated or served for a donation.
	 *
	 * @param array<string, mixed> $donation Donation data.
	 * @return bool
	 * @since 1.5.0
	 */
	private static function should_generate( $donation ) {
		/**
		 * Short-circuit filter to disable receipt generation for a donation.
		 *
		 * Return false to prevent generating a new receipt PDF and to stop a
		 * previously cached one from being served. Lets extensions disable
		 * receipts selectively — e.g. a per-form "disable PDF receipt"
		 * setting keyed on the donation's form_id.
		 *
		 * @param bool                 $should_generate Whether to generate/serve the receipt. Default true.
		 * @param array<string, mixed> $donation        Donation data.
		 * @since 1.5.0
		 */
		return (bool) apply_filters( 'suredonation_should_generate_receipt', true, $donation );
	}

	/**
	 * Delete a receipt PDF file by its stored uploads-relative path.
	 *
	 * Used by the personal-data eraser: the receipt is generated from the donor's
	 * name/email/address, so an erasure must remove the file from disk, not just
	 * the database columns.
	 *
	 * A path that fails containment is refused rather than deleted, and still
	 * reports true: the caller's row should not be blocked forever by a
	 * pointer this function will never act on, and refusing to touch the file
	 * is the safe half of the trade.
	 *
	 * @since 1.2.0
	 * @param string $relative_path Relative path within the uploads directory.
	 * @return bool True when this function will do nothing further with the path (file removed, never existed, or refused as out of bounds), false when the file survived deletion.
	 */
	public static function delete_receipt( $relative_path ) {
		$filepath = self::relative_to_path( $relative_path );

		// file_exists() here, is_file() below, and the asymmetry is deliberate:
		// PHPStan narrows a repeated identical call, so guarding with is_file()
		// makes the post-delete is_file() read as always-false to it. The two
		// answers only differ for a directory named *.pdf, and that returns true
		// either way (nothing that is a file remains), so nothing is lost.
		if ( false === $filepath || ! file_exists( $filepath ) ) {
			return true;
		}

		wp_delete_file( $filepath );

		// Re-check with is_file() (not file_exists()) — wp_delete_file() has a
		// filesystem side effect PHPStan can't see, so re-calling the already
		// narrowed file_exists() reads as always-false to it.
		clearstatcache( true, $filepath );

		return ! is_file( $filepath );
	}

	/**
	 * Get the mPDF configuration.
	 *
	 * @param array<string, mixed>      $donation Donation data.
	 * @param array<string, mixed>|null $donor    Donor data.
	 * @return array<string,mixed>
	 * @since 1.0.0
	 */
	private static function get_mpdf_config( $donation = [], $donor = null ) {
		$config = [
			'mode'                       => 'utf-8',
			'format'                     => 'A4',
			'orientation'                => 'P',
			'margin_left'                => 15,
			'margin_right'               => 15,
			'margin_top'                 => 15,
			'margin_bottom'              => 15,
			'default_font'               => 'dejavusans',
			'tempDir'                    => Pdf_Utils::get_temp_dir(),
			// mPDF resolves `<img src>` and CSS url() through stream wrappers,
			// and its default whitelist is ['http', 'https', 'file'] — so out of
			// the box a src can reach an internal host (SSRF) or read a local
			// file into the PDF (LFI). The receipt HTML is server-templated with
			// escaped fields, but the suredonation_receipt_html filter and the
			// Pro receipt templates both put author-controlled HTML through
			// WriteHTML(), and wp_kses_post() permits <img src="http(s)://…">.
			//
			// Emptying the whitelist is the actual control: Mpdf\File\
			// StreamWrapperChecker then rejects every `scheme://` src before a
			// fetch happens. Nothing legitimate needs one — the logo is
			// embedded as a data: URI (no `://`, so the check never fires) and
			// local font/temp paths are plain paths. A blocked <img> degrades to
			// mPDF's own imageError() handling rather than failing the render.
			'whitelistStreamWrappers'    => [],
			// Kept as defence in depth: if a filter callback ever re-whitelists
			// http(s), requests still verify SSL. This is not what stops the
			// fetch — the whitelist above is.
			'curlAllowUnsafeSslRequests' => false,
		];

		/**
		 * Filter the mPDF configuration used for receipt generation.
		 *
		 * SECURITY NOTE: 'whitelistStreamWrappers' is emptied deliberately.
		 * Restoring any entry lets HTML that reaches WriteHTML() fetch that
		 * scheme, which is SSRF for http(s) and LFI for file://. It is
		 * force-reset to [] after this filter runs, so callbacks cannot widen
		 * it; resolve trusted assets (e.g. a logo attachment) to a data: URI or
		 * a validated local path server-side instead.
		 *
		 * @param array<string, mixed>      $config   mPDF configuration.
		 * @param array<string, mixed>      $donation Donation data.
		 * @param array<string, mixed>|null $donor    Donor data.
		 * @since 1.5.0
		 */
		$config = apply_filters( 'suredonation_receipt_mpdf_config', $config, $donation, $donor );
		$config = is_array( $config ) ? $config : [];

		// Enforced post-filter: an empty stream-wrapper whitelist is what gates
		// LFI/SSRF in mPDF, so it stays empty regardless of what filter
		// callbacks return.
		$config['whitelistStreamWrappers']    = [];
		$config['curlAllowUnsafeSslRequests'] = false;

		return $config;
	}

	/**
	 * Build the receipt HTML template.
	 *
	 * @param array<string, mixed>      $donation       Donation data.
	 * @param array<string, mixed>|null $donor   Donor data.
	 * @param string                    $campaign_title Campaign title.
	 * @return string HTML content.
	 * @since 1.0.0
	 */
	private static function build_receipt_html( $donation, $donor, $campaign_title ) {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$site_url  = esc_url( site_url() );

		$donation_id = Helper::get_integer_value( $donation['id'] ?? 0 );
		// Prefer the name captured on this specific donation — it is the correct
		// identity for a tax receipt and is unaffected by the donor record being
		// set-once. Fall back to the donor record only when the donation itself
		// carries no name.
		$donation_donor_name = Helper::get_string_value( $donation['donor_name'] ?? '' );
		$donor_name          = esc_html( '' !== $donation_donor_name ? $donation_donor_name : Helper::get_string_value( $donor['name'] ?? '' ) );
		$donor_email         = esc_html( Helper::get_string_value( $donor['email'] ?? '' ) );
		$payment_status      = esc_html( ucfirst( Helper::get_string_value( $donation['payment_status'] ?? '' ) ) );
		$payment_method      = esc_html( ucfirst( Helper::get_string_value( $donation['gateway'] ?? '' ) ) );
		$transaction_id      = esc_html( Helper::get_string_value( $donation['transaction_id'] ?? '' ) );
		$currency            = Helper::get_string_value( $donation['currency'] ?? 'USD' );
		$total               = Helper::get_float_value( $donation['amount'] ?? 0 );
		$fees_covered        = Helper::get_float_value( $donation['fees_covered'] ?? 0 );
		$amount              = $total - $fees_covered;
		$date                = Helper::get_string_value( $donation['created_at'] ?? '' );

		if ( ! empty( $date ) ) {
			$date_format    = Helper::get_string_value( get_option( 'date_format' ) );
			$timestamp      = strtotime( $date );
			$formatted_date = false !== $timestamp ? wp_date( $date_format, $timestamp ) : false;
			$date           = is_string( $formatted_date ) ? $formatted_date : $date;
		}

		$campaign_title = esc_html( $campaign_title );

		// Format amounts.
		$formatted_amount = self::format_currency( $amount, $currency );
		$formatted_fees   = self::format_currency( $fees_covered, $currency );
		$formatted_total  = self::format_currency( $total, $currency );

		// Build transaction ID row.
		$transaction_row = '';
		if ( ! empty( $transaction_id ) ) {
			$transaction_row = sprintf(
				'<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">%s</td>
				<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">%s</td></tr>',
				esc_html__( 'Transaction ID', 'suredonation' ),
				$transaction_id
			);
		}

		// Build fees row.
		$fees_row = '';
		if ( $fees_covered > 0 ) {
			$fees_row = sprintf(
				'<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">%s</td>
				<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">%s</td></tr>',
				esc_html__( 'Fees Covered', 'suredonation' ),
				$formatted_fees
			);
		}

		return '
		<div style="max-width:560px;margin:0 auto;font-family:DejaVu Sans,sans-serif;color:#111827;">
			<div style="text-align:center;margin-bottom:24px;padding-bottom:20px;border-bottom:2px solid #e5e7eb;">
				<h1 style="font-size:20px;margin:0 0 4px;color:#111827;">' . $site_name . '</h1>
				<p style="color:#6b7280;font-size:13px;margin:0;">' . esc_html__( 'Donation Receipt', 'suredonation' ) . '</p>
			</div>

			<p style="color:#6b7280;font-size:12px;margin:0 0 16px;text-align:right;">'
				. esc_html__( 'Receipt', 'suredonation' ) . ' #' . $donation_id . '</p>

			<table style="width:100%;border-collapse:collapse;margin-bottom:20px;border:1px solid #e5e7eb;border-radius:6px;">
				<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;width:40%;">'
					. esc_html__( 'Donor Name', 'suredonation' ) . '</td>
					<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">' . $donor_name . '</td></tr>
				<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">'
					. esc_html__( 'Donor Email', 'suredonation' ) . '</td>
					<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">' . $donor_email . '</td></tr>
			</table>

			<table style="width:100%;border-collapse:collapse;margin-bottom:20px;border:1px solid #e5e7eb;border-radius:6px;">
				<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;width:40%;">'
					. esc_html__( 'Campaign Name', 'suredonation' ) . '</td>
					<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">' . $campaign_title . '</td></tr>
				<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">'
					. esc_html__( 'Payment Status', 'suredonation' ) . '</td>
					<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">' . $payment_status . '</td></tr>
				<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">'
					. esc_html__( 'Payment Method', 'suredonation' ) . '</td>
					<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">' . $payment_method . '</td></tr>
				' . $transaction_row . '
				<tr><td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">'
					. esc_html__( 'Donation Amount', 'suredonation' ) . '</td>
					<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">' . $formatted_amount . '</td></tr>
				' . $fees_row . '
				<tr><td style="padding:10px 12px;background:#f9fafb;font-weight:bold;color:#111827;font-size:13px;">'
					. esc_html__( 'Donation Total', 'suredonation' ) . '</td>
					<td style="padding:10px 12px;background:#f9fafb;font-weight:bold;font-size:13px;color:#111827;">' . $formatted_total . '</td></tr>
			</table>

			<p style="color:#6b7280;font-size:12px;margin:0 0 4px;">'
				. esc_html__( 'Date', 'suredonation' ) . ': ' . esc_html( $date ) . '</p>

			<div style="margin-top:30px;padding-top:16px;border-top:1px solid #e5e7eb;text-align:center;">
				<p style="color:#9ca3af;font-size:11px;margin:0;">'
					. sprintf(
						/* translators: 1: Site name, 2: Site URL. */
						esc_html__( 'Generated by %1$s · %2$s', 'suredonation' ),
						$site_name,
						$site_url
					) . '</p>
			</div>
		</div>';
	}

	/**
	 * Format a monetary amount with currency symbol.
	 *
	 * @param float  $amount   Amount to format.
	 * @param string $currency Currency code.
	 * @return string Formatted amount.
	 * @since 1.0.0
	 */
	private static function format_currency( $amount, $currency = 'USD' ) {
		// Delegate to the single source of truth so the currency symbol,
		// decimal handling and sign position match every other surface
		// (this replaces a divergent local symbol map).
		return Payment_Helper::format_amount( $amount, $currency );
	}

	/**
	 * Convert a relative path to an absolute file path.
	 *
	 * Refuses any value that does not resolve to a plain file inside the
	 * receipts directory, so a pointer that ever became attacker-influenced
	 * cannot reach an arbitrary path through either consumer.
	 *
	 * @param string $relative_path Relative path within the uploads directory.
	 * @return string|false Absolute file path inside the receipts directory, or false.
	 * @since 1.0.0
	 */
	private static function relative_to_path( $relative_path ) {
		if ( empty( $relative_path ) ) {
			return false;
		}

		// Everything below is containment for a value this function does not
		// own. The column is written only by generate() today, and nothing
		// sanitizes it on the way into the database, so the stored string is
		// trusted purely because no write path currently exposes it. Both
		// consumers are destructive if that ever stops being true: this feeds
		// wp_delete_file() on every donation delete, and get_or_generate()
		// hands the resolved path to the donor-facing receipt download. Check
		// it here, once, rather than relying on every future caller.
		$normalized = wp_normalize_path( (string) $relative_path );

		// A null byte truncates the path inside the C filesystem calls.
		if ( false !== strpos( $normalized, "\0" ) ) {
			return false;
		}

		// A literal backslash, refused on the raw value before anything else
		// reads it. Normalization treats it as a separator, so the checks below
		// would measure a different path from the one this function returns, and
		// the realpath comparison would normalize it back again. No value the
		// generator has ever written contains one: the stored string is built
		// with '/' and sanitize_file_name() strips '\' from the filename.
		if ( false !== strpos( (string) $relative_path, '\\' ) ) {
			return false;
		}

		// Traversal, in any position. This does not stand alone: a segment such
		// as '.. ' is not matched here, and Windows strips trailing spaces
		// during path canonicalisation. The realpath() cross-check below is
		// what covers those, so do not remove it as redundant.
		if ( preg_match( '#(^|/)\.\.(/|$)#', $normalized ) ) {
			return false;
		}

		// Already absolute: a POSIX root, a Windows drive, or a stream wrapper
		// such as phar:// or http://. None can be a relative receipt path.
		if ( 0 === strpos( $normalized, '/' ) || preg_match( '#^[a-zA-Z]:/#', $normalized ) || preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $normalized ) ) {
			return false;
		}

		// One wp_upload_dir() call feeds both sides. The upload_dir filter runs
		// on every call, so fetching twice lets a filter that varies its answer
		// desynchronise the candidate from the directory it is measured against.
		$upload_dir   = wp_upload_dir();
		$base_dir     = wp_normalize_path( $upload_dir['basedir'] );
		$receipts_dir = $base_dir . '/suredonation/receipts';
		$candidate    = wp_normalize_path( $base_dir . '/' . $normalized );

		// Receipts live in exactly one directory. With traversal already
		// refused above, a prefix test is a containment test.
		if ( 0 !== strpos( $candidate, $receipts_dir . '/' ) ) {
			return false;
		}

		// Inside the directory is not enough: it also holds the .htaccess,
		// index.php and web.config that ensure_receipts_dir() writes to keep it
		// from being served. Resolving one of those would let a delete strip
		// the directory's protection and expose every donor receipt, which is a
		// worse outcome than the arbitrary delete this containment exists to
		// stop. Every name the generator can produce ends in .pdf.
		$basename = basename( $normalized );
		if ( '' === $basename || 0 === strpos( $basename, '.' ) || ! preg_match( '#\.pdf\z#i', $basename ) ) {
			return false;
		}

		// Built from the raw value, because this is what the function returns
		// and therefore what the callers act on. Keeping the returned string
		// byte-identical to the old behaviour matters (get_or_generate() hands
		// it back and it is compared), but the realpath check below has to
		// measure that same string: normalization turns a literal backslash
		// into a separator, so checking only the normalized form would leave a
		// symlink named with one unexamined.
		$filepath = $upload_dir['basedir'] . '/' . $relative_path;

		// A symlink can still point out of the directory, and realpath() is the
		// only thing that sees it. It resolves to false when the file is not
		// there yet, which is a normal state for both callers, so only an
		// existing file is cross-checked. Both sides are resolved so that a
		// symlinked uploads directory, which is common when media sits on
		// another volume, does not cause a false refusal.
		$real_path = realpath( $filepath );
		if ( false !== $real_path ) {
			$real_dir = realpath( $receipts_dir );
			if ( false === $real_dir || 0 !== strpos( wp_normalize_path( $real_path ), wp_normalize_path( $real_dir ) . '/' ) ) {
				return false;
			}
		}

		return $filepath;
	}

	/**
	 * Resolve the filename the receipt is stored under on disk.
	 *
	 * Extracted so the normalisation rules below are testable without
	 * rendering a PDF, which needs mPDF present.
	 *
	 * @param string                    $default_filename Generated opaque filename.
	 * @param array<string, mixed>      $donation         Donation data.
	 * @param array<string, mixed>|null $donor            Donor data.
	 * @return string Filename ending in exactly one .pdf extension.
	 * @since 1.5.1
	 */
	private static function resolve_storage_filename( $default_filename, $donation, $donor ) {
		/**
		 * Filter the receipt PDF filename ON DISK.
		 *
		 * This is not the name anyone receives: donors get the file under
		 * {@see self::get_download_filename()}, so the stored name deliberately
		 * carries no donation id, no configured prefix and no donor data -- only
		 * randomness. The receipts directory denies direct access through
		 * .htaccess, but servers that ignore it (nginx, IIS) serve the file as a
		 * static asset, which leaves this name as the only thing gating it. Treat
		 * a filtered value as a capability token and keep it unguessable.
		 *
		 * The filtered value is passed through sanitize_file_name() -- which
		 * strips path separators and collapses '..' -- and forced to a .pdf
		 * extension; an empty result falls back to the default.
		 *
		 * @param string                    $default_filename Generated filename.
		 * @param array<string, mixed>      $donation         Donation data.
		 * @param array<string, mixed>|null $donor            Donor data.
		 * @since 1.5.0
		 * @since 1.5.1 Names only the file on disk. To name the copy a donor
		 *              receives, use {@see 'suredonation_receipt_download_filename'}.
		 */
		$filename = apply_filters( 'suredonation_receipt_filename', $default_filename, $donation, $donor );

		// This is the call that makes the value safe: it strips path separators
		// and null bytes and collapses '..', so everything after it works on a
		// bare filename. It also normalises before the two tests below, which is
		// what keeps a filter's intended stem — "receipt.pdf " still ends in
		// .pdf once trimmed, and so resolves to receipt.pdf rather than
		// receipt-pdf.pdf.
		//
		// There is a second sanitize_file_name() inside the else. Measured
		// against 35 filtered values, including traversal, null bytes and bare
		// extension words, it changes no outcome's safety — the single-extension
		// guarantee comes from this call plus the dot collapse plus the appended
		// .pdf. What it does change is the name: it is why a stem left as a bare
		// extension word comes back as unnamed-file-exe.pdf instead of exe.pdf.
		// It is kept as defence in depth on a name that lands in a
		// web-accessible directory; the tests pin both effects.
		$filename = sanitize_file_name( Helper::get_string_value( $filename ) );

		if ( '' === $filename ) {
			$filename = $default_filename;
		} else {
			// Force exactly one extension, and make it .pdf. Appending to the
			// filtered value produced a double extension — a filter returning
			// "x.php" landed on disk as "x.php.pdf", which sanitize_file_name()
			// does not underscore (it early-returns for a two-part name) and
			// which some Apache configurations still hand to the PHP handler on
			// the strength of the inner extension. Stripping only the last
			// segment is not enough either: "x.php.pdf" would survive intact.
			//
			// So a trailing .pdf is dropped first — that is the normal case, and
			// the currently-released Pro's filter returns exactly that shape —
			// then every remaining dot is removed and one .pdf added back. That
			// is safe for this value specifically: it names the file on disk
			// only, is
			// documented as a capability token rather than anything a donor
			// sees, and the default is already dot-free (sd-receipt-<hex>). The
			// donor-facing name comes from get_download_filename() and is
			// untouched by this.
			// Order matters, and getting it wrong is what the first attempt at
			// this did. sanitize_file_name() *re-inserts* an extension when the
			// name it is given has none — it runs
			// wp_check_filetype( 'test.' . $filename ), so a bare 'exe' comes
			// back as 'unnamed-file.exe'. Collapsing the dots first therefore
			// handed core a token it turned back into a two-part name, and
			// 'exe.pdf' landed as 'unnamed-file.exe.pdf': two extensions, from
			// the very code meant to guarantee one.
			//
			// So sanitize first, collapse whatever dots that leaves, and make
			// .pdf the genuinely last operation on a dot-free token.
			$filename = (string) preg_replace( '/\.pdf$/i', '', $filename );
			$filename = sanitize_file_name( $filename );
			$filename = str_replace( '.', '-', $filename );
			$filename = '' === $filename ? $default_filename : $filename . '.pdf';
		}

		return $filename;
	}
}
