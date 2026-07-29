<?php
/**
 * Donors import mapper.
 *
 * Imports donor profiles from a SureDonation-exported CSV (a "pure donor"
 * import — profiles only, no donation history). Matches by unique email:
 * existing donors are updated with the non-empty fields provided, new donors
 * are created via Donors::add() (an existing WP user is linked by email; none
 * is created).
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Import_Export\Import;

use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donors import mapper.
 *
 * @phpstan-type ImportResult array{imported:int, skipped:int, errors:int, donors_created:int, donors_matched:int, error_log:array<int, string>}
 *
 * @since 1.3.0
 */
class Donors_Import_Mapper {

	use Get_Instance;

	/**
	 * Process one batch of donor rows.
	 *
	 * @param array<string, mixed> $progress Session progress (by reference).
	 * @param int                  $offset   Data-row offset.
	 * @return int Number of source rows fetched this batch.
	 * @since 1.3.0
	 */
	public function process_batch( array &$progress, $offset ) {
		$token   = Helper::get_string_value( $progress['token'] ?? '' );
		$mapping = is_array( $progress['mapping'] ?? null ) ? $progress['mapping'] : [];
		$options = is_array( $progress['options'] ?? null ) ? $progress['options'] : [];
		$dry_run = ! empty( $options['dry_run'] );

		// Offset 0 marks the start of the phase, so reset the byte cursor to the
		// top of the file; otherwise resume from the stored byte position.
		$byte_offset             = ( 0 === (int) $offset ) ? 0 : Helper::get_integer_value( $progress['byte_offset'] ?? 0 );
		$rows                    = Csv_File::read_batch( $token, $byte_offset, Import_Runner::BATCH_SIZE );
		$progress['byte_offset'] = $byte_offset;
		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$this->process_row( $row, $mapping, $progress, $dry_run );
		}

		return count( $rows );
	}

	/**
	 * Import a single donor row.
	 *
	 * @param array<int, string>       $row      Raw CSV cells.
	 * @param array<int|string, mixed> $mapping  Header index => field.
	 * @param array<string, mixed>     $progress Session progress (by reference).
	 * @param bool                     $dry_run  Whether to write.
	 * @return void
	 * @since 1.3.0
	 */
	private function process_row( $row, $mapping, &$progress, $dry_run ) {
		/** @var array<string, ImportResult> $results */
		$results = &$progress['results'];
		$result  = &$results['donors'];
		$data    = Column_Map::apply_row( $row, $mapping );

		$email = sanitize_email( Helper::get_string_value( $data['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			++$result['errors'];
			if ( count( $result['error_log'] ) < 50 ) {
				$result['error_log'][] = __( 'Row skipped: missing or invalid email.', 'suredonation' );
			}
			return;
		}

		$fields   = $this->build_fields( $data, $email );
		$existing = Donors::get_by_email( $email );

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			if ( ! $dry_run ) {
				Donors::update( (int) $existing['id'], $this->fields_for_update( $fields, $data ) );
			}
			++$result['donors_matched'];
			++$result['skipped'];
			return;
		}

		++$result['donors_created'];
		++$result['imported'];

		if ( $dry_run ) {
			return;
		}

		$user              = get_user_by( 'email', $email );
		$fields['user_id'] = $user instanceof \WP_User ? (int) $user->ID : null;
		$donor_id          = Donors::add( $fields );
		if ( $donor_id ) {
			Import_Runner::track_created( $progress, 'donors', (int) $donor_id );
			// Action documented in the donations import mapper.
			do_action( 'suredonation_import_donor_inserted', (int) $donor_id, $email );
		}
	}

	/**
	 * Build the donor column data from a mapped row.
	 *
	 * Only non-empty values are included so an update doesn't blank existing
	 * fields. Aggregate columns are taken from the CSV as provided.
	 *
	 * @param array<string, string> $data  Mapped row fields.
	 * @param string                $email Sanitized email.
	 * @return array<string, mixed> Donor column data.
	 * @since 1.3.0
	 */
	private function build_fields( $data, $email ) {
		$fields = [
			'email'         => $email,
			'import_source' => 'suredonation',
		];

		$text_map = [
			'name'    => 'name',
			'phone'   => 'phone',
			'company' => 'company',
		];
		foreach ( $text_map as $field => $source ) {
			$value = sanitize_text_field( Helper::get_string_value( $data[ $source ] ?? '' ) );
			if ( '' !== $value ) {
				$fields[ $field ] = $value;
			}
		}

		$address = sanitize_textarea_field( Helper::get_string_value( $data['address'] ?? '' ) );
		if ( '' !== $address ) {
			$fields['address'] = $address;
		}

		$status                 = sanitize_text_field( Helper::get_string_value( $data['donor_status'] ?? '' ) );
		$fields['donor_status'] = '' !== $status ? $status : 'active';

		$source_id = isset( $data['import_source_id'] ) ? absint( $data['import_source_id'] ) : 0;
		if ( $source_id > 0 ) {
			$fields['import_source_id'] = $source_id;
		}

		$tags = trim( Helper::get_string_value( $data['donor_tags'] ?? '' ) );
		if ( '' !== $tags ) {
			$fields['donor_tags'] = array_values(
				array_filter(
					array_map(
						static function ( $tag ) {
							return sanitize_text_field( trim( $tag ) );
						},
						explode( ',', $tags )
					)
				)
			);
		}

		foreach ( [ 'total_donated', 'largest_donation' ] as $decimal_field ) {
			$raw = Helper::get_string_value( $data[ $decimal_field ] ?? '' );
			if ( '' !== trim( $raw ) ) {
				$fields[ $decimal_field ] = number_format( (float) preg_replace( '/[^0-9.\-]/', '', $raw ), 8, '.', '' );
			}
		}

		$count = Helper::get_string_value( $data['donation_count'] ?? '' );
		if ( '' !== trim( $count ) ) {
			$fields['donation_count'] = absint( $count );
		}

		foreach ( [ 'first_donation_date', 'last_donation_date' ] as $date_field ) {
			$raw = trim( Helper::get_string_value( $data[ $date_field ] ?? '' ) );
			if ( '' !== $raw ) {
				$ts = strtotime( $raw );
				if ( false !== $ts ) {
					$fields[ $date_field ] = gmdate( 'Y-m-d H:i:s', $ts );
				}
			}
		}

		return $fields;
	}

	/**
	 * Reduce the full field set to the columns that are safe to write on an
	 * EXISTING donor. Re-importing an already-known donor must not:
	 *  - retag their provenance (`import_source`) — a native donor would be
	 *    relabelled 'suredonation';
	 *  - silently reactivate a blocked/inactive donor — `donor_status`
	 *    defaults to 'active' only for newly created donors, so it is written
	 *    on update only when the CSV explicitly supplied a status;
	 *  - clobber the live-computed aggregate columns with the CSV snapshot.
	 * Profile fields (name/phone/company/address/tags) still update when the
	 * CSV provides a non-empty value.
	 *
	 * @param array<string, mixed>  $fields Full field set from build_fields().
	 * @param array<string, string> $data   Mapped row (to detect an explicit status).
	 * @return array<string, mixed> Fields safe to write on an existing donor.
	 * @since 1.3.0
	 */
	private function fields_for_update( $fields, $data ) {
		unset(
			$fields['import_source'],
			$fields['total_donated'],
			$fields['largest_donation'],
			$fields['donation_count'],
			$fields['first_donation_date'],
			$fields['last_donation_date']
		);

		$status = sanitize_text_field( Helper::get_string_value( $data['donor_status'] ?? '' ) );
		if ( '' === $status ) {
			unset( $fields['donor_status'] );
		}

		return $fields;
	}
}
