<?php
/**
 * Privacy Data — WordPress personal-data export/erase integration.
 *
 * Registers SureDonation with WordPress Tools → Export/Erase Personal Data so a
 * donor's data can be exported or erased on request. Erasure honors the Privacy
 * settings' Minimum Data Retention Period (full erase — no per-field retention):
 * donations still inside the retention window are retained; older ones are fully
 * anonymized.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Privacy;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Pdf\Receipt_Generator;
use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Privacy_Data class.
 *
 * @since 1.2.0
 */
class Privacy_Data {
	use Get_Instance;

	/**
	 * Constructor — register the exporter + eraser with WordPress.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_erasers' ] );
	}

	/**
	 * Register the SureDonation personal-data exporter.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $exporters Registered exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporters( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			$exporters = [];
		}
		$exporters['suredonation'] = [
			'exporter_friendly_name' => __( 'SureDonation Donations', 'suredonation' ),
			'callback'               => [ $this, 'export' ],
		];
		return $exporters;
	}

	/**
	 * Register the SureDonation personal-data eraser.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $erasers Registered erasers.
	 * @return array<string, mixed>
	 */
	public function register_erasers( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			$erasers = [];
		}
		$erasers['suredonation'] = [
			'eraser_friendly_name' => __( 'SureDonation Donations', 'suredonation' ),
			'callback'             => [ $this, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Export a donor's personal data (donor profile + their donations).
	 *
	 * Paginated per the WordPress exporter contract: the donor profile is emitted on
	 * the first page and donations are returned in fixed-size batches, so a donor with
	 * many donations doesn't load them all into one response.
	 *
	 * @since 1.2.0
	 * @param string $email_address The email being exported.
	 * @param int    $page          1-based page number supplied by WordPress.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email_address, $page = 1 ) {
		$email  = sanitize_email( (string) $email_address );
		$page   = max( 1, (int) $page );
		$batch  = 100;
		$export = [];

		// Donor profile is emitted once, on the first page.
		$donor = 1 === $page ? Donors::get_by_email( $email ) : null;
		if ( is_array( $donor ) && ! empty( $donor ) ) {
			$export[] = [
				'group_id'    => 'suredonation-donor',
				'group_label' => __( 'SureDonation Donor', 'suredonation' ),
				'item_id'     => 'suredonation-donor-' . absint( Helper::get_string_value( $donor['id'] ?? 0 ) ),
				'data'        => self::name_value_rows(
					[
						__( 'Name', 'suredonation' )    => $donor['name'] ?? '',
						__( 'Email', 'suredonation' )   => $donor['email'] ?? '',
						__( 'Phone', 'suredonation' )   => $donor['phone'] ?? '',
						__( 'Company', 'suredonation' ) => $donor['company'] ?? '',
						__( 'Address', 'suredonation' ) => $donor['address'] ?? '',
					]
				),
			];
		}

		$donations = Donations::get_by_donor_email( $email, $batch, ( $page - 1 ) * $batch );
		$donations = is_array( $donations ) ? $donations : [];
		foreach ( $donations as $donation ) {
			if ( ! is_array( $donation ) ) {
				continue;
			}

			// Mirrors the eraser's field set — everything the eraser treats as donor
			// PII (incl. ip / user-agent / referrer) must also be disclosed on export.
			$rows = [
				__( 'Amount', 'suredonation' )      => $donation['amount'] ?? '',
				__( 'Currency', 'suredonation' )    => $donation['currency'] ?? '',
				__( 'Status', 'suredonation' )      => $donation['status'] ?? '',
				__( 'Date', 'suredonation' )        => $donation['created_at'] ?? '',
				__( 'Donor Name', 'suredonation' )  => $donation['donor_name'] ?? '',
				__( 'Donor Email', 'suredonation' ) => $donation['donor_email'] ?? '',
				__( 'Donor Phone', 'suredonation' ) => $donation['donor_phone'] ?? '',
				__( 'Comment', 'suredonation' )     => $donation['donor_comment'] ?? '',
				__( 'IP Address', 'suredonation' )  => $donation['ip_address'] ?? '',
				__( 'User Agent', 'suredonation' )  => $donation['user_agent'] ?? '',
				__( 'Referrer', 'suredonation' )    => $donation['referer_url'] ?? '',
			];

			// Include the extra submitted form fields (label => value) if present.
			$fields = isset( $donation['donation_data']['fields'] ) && is_array( $donation['donation_data']['fields'] ) ? $donation['donation_data']['fields'] : [];
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || ! isset( $field['label'] ) ) {
					continue;
				}
				// Deduplicate repeated labels ("Label", "Label (2)", …) — a duplicate
				// key would silently overwrite the earlier field's value in the export.
				$label  = Helper::get_string_value( $field['label'] );
				$unique = $label;
				$suffix = 2;
				while ( array_key_exists( $unique, $rows ) ) {
					$unique = $label . ' (' . $suffix . ')';
					++$suffix;
				}
				$rows[ $unique ] = $field['value'] ?? '';
			}

			$export[] = [
				'group_id'    => 'suredonation-donations',
				'group_label' => __( 'SureDonation Donations', 'suredonation' ),
				'item_id'     => 'suredonation-donation-' . absint( Helper::get_string_value( $donation['id'] ?? 0 ) ),
				'data'        => self::name_value_rows( $rows ),
			];
		}

		// Done once a page returns fewer donations than the batch size.
		$done = count( $donations ) < $batch;

		if ( $done ) {
			/**
			 * Fires when a personal-data export including SureDonation data completes.
			 *
			 * @since 1.2.0
			 */
			do_action( 'suredonation_privacy_data_exported' );
		}

		return [
			'data' => $export,
			'done' => $done,
		];
	}

	/**
	 * Erase a donor's personal data, honoring the retention period.
	 *
	 * Donations still inside the Minimum Data Retention Period are retained; older
	 * donations are fully anonymized. The donor profile is anonymized only when all
	 * of their donations were erased (none retained).
	 *
	 * Handled in a single pass (not paginated like export): the donor-profile decision
	 * needs to know whether *any* donation across the whole set was retained, which
	 * can't be determined from one page in isolation.
	 *
	 * @since 1.2.0
	 * @param string $email_address The email being erased.
	 * @param int    $page          Page number (unused — all data handled at once).
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( $email_address, $page = 1 ) {
		unset( $page );
		$email          = sanitize_email( (string) $email_address );
		$items_removed  = false;
		$items_retained = false;
		$erase_failed   = false;
		$messages       = [];

		$donations = Donations::get_by_donor_email( $email );
		foreach ( is_array( $donations ) ? $donations : [] as $donation ) {
			if ( ! is_array( $donation ) || ! isset( $donation['id'] ) ) {
				continue;
			}

			if ( ! Privacy_Settings::is_donation_erasable( Helper::get_string_value( $donation['created_at'] ?? '' ) ) ) {
				$items_retained = true;
				continue;
			}

			// Strip the personal-data keys from donation_data, keep the rest
			// (e.g. refunds/notes are operational records, not donor PII).
			$donation_data = isset( $donation['donation_data'] ) && is_array( $donation['donation_data'] ) ? $donation['donation_data'] : [];
			unset( $donation_data['fields'] );

			// The receipt PDF is generated from the donor's name/email/address —
			// erasure must remove the file from disk, not just the DB columns.
			$receipt_deleted = Receipt_Generator::delete_receipt( Helper::get_string_value( $donation['receipt_pdf_url'] ?? '' ) );

			// Besides the direct PII columns, clear pseudonymous identifiers that
			// re-link the record to the donor at the payment provider (Stripe
			// customer/subscription ids) and the gateway log (can embed the donor's
			// email). transaction_id and subscription_status are kept deliberately:
			// the transaction reference is required for financial reconciliation /
			// refund handling and identifies the payment, not the person; the
			// status is a non-identifying enum.
			$anonymized = [
				'donor_name'             => wp_privacy_anonymize_data( 'text', Helper::get_string_value( $donation['donor_name'] ?? '' ) ),
				'donor_email'            => wp_privacy_anonymize_data( 'email', $email ),
				'donor_phone'            => '',
				'donor_comment'          => '',
				'ip_address'             => wp_privacy_anonymize_data( 'ip', Helper::get_string_value( $donation['ip_address'] ?? '' ) ),
				'user_agent'             => '',
				'referer_url'            => '',
				'donation_data'          => $donation_data,
				'customer_id'            => '',
				'subscription_id'        => '',
				'parent_subscription_id' => 0,
				'log'                    => '',
			];

			// Clear the receipt pointer only when the file is actually gone —
			// otherwise keep it so a retried erasure can still find the file.
			if ( $receipt_deleted ) {
				$anonymized['receipt_pdf_url'] = '';
			}

			$updated = Donations::update( absint( Helper::get_string_value( $donation['id'] ) ), $anonymized );

			// Donations::update() returns int|false — only report data as removed
			// when the write actually succeeded; a confirmed erasure for data still
			// in the database would be a silent compliance failure.
			if ( false !== $updated && $receipt_deleted ) {
				$items_removed = true;
			} else {
				$erase_failed = true;
			}
		}

		// Anonymize the donor profile only when nothing is being retained for them
		// (and nothing failed to erase — a failed donation write means PII may still
		// reference this donor). All-or-nothing by design (Charitable-style model):
		// while any donation is retained, its row still carries the donor's identity,
		// so scrubbing only the profile would not reduce what is held; the profile is
		// erased together with the last retained donation once the window lapses.
		$donor = Donors::get_by_email( $email );
		if ( is_array( $donor ) && ! empty( $donor ) && ! $items_retained && ! $erase_failed ) {
			$donor_id = absint( Helper::get_string_value( $donor['id'] ?? 0 ) );
			if ( $donor_id > 0 ) {
				Donors::clear_stripe_customer_id_by_email( $email );
				$updated = Donors::update(
					$donor_id,
					[
						// Keep the email unique per donor to respect the UNIQUE column.
						'email'   => 'deleted-' . $donor_id . '@site.invalid',
						'name'    => wp_privacy_anonymize_data( 'text', Helper::get_string_value( $donor['name'] ?? '' ) ),
						'phone'   => '',
						'company' => '',
						'address' => '',
					]
				);

				// Same int|false contract as the donation updates above.
				if ( false !== $updated ) {
					$items_removed = true;
				} else {
					$erase_failed = true;
				}
			}
		}

		if ( $items_retained ) {
			$messages[] = __( 'Some donation data was retained because it falls within the configured data retention period.', 'suredonation' );
		}

		if ( $erase_failed ) {
			$messages[] = __( 'Some SureDonation data could not be erased due to a database or filesystem error. Please retry or contact the site administrator.', 'suredonation' );
		}

		/**
		 * Fires when a personal-data erasure request has been processed for SureDonation data.
		 *
		 * @since 1.2.0
		 * @param array{items_removed: bool, items_retained: bool, erase_failed: bool} $outcome Erasure outcome flags.
		 */
		do_action(
			'suredonation_privacy_data_erased',
			[
				'items_removed'  => $items_removed,
				'items_retained' => $items_retained,
				'erase_failed'   => $erase_failed,
			]
		);

		return [
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	/**
	 * Convert a label => value map into the WordPress exporter row shape.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $map Label => value pairs.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function name_value_rows( $map ) {
		$rows = [];
		foreach ( $map as $label => $value ) {
			$rows[] = [
				'name'  => (string) $label,
				'value' => Helper::get_string_value( $value ),
			];
		}
		return $rows;
	}
}
