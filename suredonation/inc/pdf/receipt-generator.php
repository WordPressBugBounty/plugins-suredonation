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

			if ( $existing_path && file_exists( $existing_path ) ) {
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
		$default_filename = sprintf( 'suredonation-receipt-%d-%s.pdf', $donation_id, wp_generate_password( 8, false ) );

		/**
		 * Filter the receipt PDF filename.
		 *
		 * The default filename carries a random suffix; the receipts directory
		 * is access-protected, but the suffix keeps individual filenames
		 * unguessable as defense in depth. The filtered value is passed through
		 * sanitize_file_name() and forced to a .pdf extension; an empty result
		 * falls back to the default.
		 *
		 * @param string                    $default_filename Generated filename.
		 * @param array<string, mixed>      $donation         Donation data.
		 * @param array<string, mixed>|null $donor            Donor data.
		 * @since 1.5.0
		 */
		$filename = apply_filters( 'suredonation_receipt_filename', $default_filename, $donation, $donor );
		$filename = sanitize_file_name( Helper::get_string_value( $filename ) );

		if ( '' === $filename ) {
			$filename = $default_filename;
		} elseif ( 'pdf' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$filename .= '.pdf';
		}

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
	 * @since 1.2.0
	 * @param string $relative_path Relative path within the uploads directory.
	 * @return bool True when no file remains (deleted or never existed), false when it survived deletion.
	 */
	public static function delete_receipt( $relative_path ) {
		$filepath = self::relative_to_path( $relative_path );

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
	 * @param string $relative_path Relative path within the uploads directory.
	 * @return string|false Absolute file path or false.
	 * @since 1.0.0
	 */
	private static function relative_to_path( $relative_path ) {
		if ( empty( $relative_path ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'] . '/' . $relative_path;
	}
}
