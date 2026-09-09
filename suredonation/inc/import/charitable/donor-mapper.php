<?php
/**
 * Maps Charitable donors onto SureDonation donor rows.
 *
 * Shared resolver used by the donations phase (Free), the CSV path, and the
 * Pro subscription / standalone-donor phases. Resolves by email against the
 * existing SureDonation donors table, creating a new row only when needed —
 * and never creating a WordPress user.
 *
 * @package SureDonation
 * @since 1.5.1
 */

namespace SureDonation\Inc\Import\Charitable;

use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Donor_Mapper class.
 *
 * @since 1.5.1
 */
class Donor_Mapper {
	use Get_Instance;

	/**
	 * Resolve (or create) the SureDonation donor row for a Charitable donation.
	 *
	 * Caches the result on $progress['donor_map'] keyed by email so subsequent
	 * donations from the same donor in the same session do not re-query.
	 *
	 * @param  array<string, string> $snapshot            The donation's serialized donor snapshot (Source::extract_donor_snapshot()).
	 * @param  int                   $charitable_donor_id Charitable donors-table ID (0 when unknown).
	 * @param  array<string, string> $donation_meta       Flat donation post meta (for contact_consent fallback).
	 * @param  array<string, mixed>  $progress            Session progress (by reference; donor_map updated).
	 * @return int Donor ID (>0) or 0 on failure.
	 * @since  1.5.1
	 */
	public function get_or_create_for_donation( $snapshot, $charitable_donor_id, $donation_meta, &$progress ) {
		$snapshot            = is_array( $snapshot ) ? $snapshot : [];
		$donation_meta       = is_array( $donation_meta ) ? $donation_meta : [];
		$charitable_donor_id = absint( $charitable_donor_id );

		$donor_row = $charitable_donor_id > 0 ? Source::get_instance()->get_donor( $charitable_donor_id ) : null;
		$donor_row = is_array( $donor_row ) ? $donor_row : [];

		$email = isset( $snapshot['email'] ) ? sanitize_email( (string) $snapshot['email'] ) : '';
		if ( ( '' === $email || ! is_email( $email ) ) && isset( $donor_row['email'] ) && is_scalar( $donor_row['email'] ) ) {
			$email = sanitize_email( (string) $donor_row['email'] );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}

		// Session-level cache.
		$donor_map = isset( $progress['donor_map'] ) && is_array( $progress['donor_map'] ) ? $progress['donor_map'] : [];
		if ( isset( $donor_map[ $email ] ) && is_numeric( $donor_map[ $email ] ) ) {
			return (int) $donor_map[ $email ];
		}

		// Existing SureDonation donor by email?
		$existing = Donors::get_by_email( $email );
		if ( is_array( $existing ) && ! empty( $existing['id'] ) && is_numeric( $existing['id'] ) ) {
			$donor_id              = (int) $existing['id'];
			$donor_map[ $email ]   = $donor_id;
			$progress['donor_map'] = $donor_map;
			return $donor_id;
		}

		// Name: snapshot first, donors-table fallback.
		$snap_first = isset( $snapshot['first_name'] ) && is_scalar( $snapshot['first_name'] ) ? (string) $snapshot['first_name'] : '';
		$snap_last  = isset( $snapshot['last_name'] ) && is_scalar( $snapshot['last_name'] ) ? (string) $snapshot['last_name'] : '';
		$name       = trim( trim( $snap_first ) . ' ' . trim( $snap_last ) );
		if ( '' === $name ) {
			$row_first = isset( $donor_row['first_name'] ) && is_scalar( $donor_row['first_name'] ) ? (string) $donor_row['first_name'] : '';
			$row_last  = isset( $donor_row['last_name'] ) && is_scalar( $donor_row['last_name'] ) ? (string) $donor_row['last_name'] : '';
			$name      = trim( sanitize_text_field( $row_first ) . ' ' . sanitize_text_field( $row_last ) );
		}

		$wp_user_id = $this->resolve_wp_user_id( $donor_row, $email );

		// Address/phone: snapshot first; usermeta donor_* fallback for
		// registered users (Charitable's two storage locations).
		$address_struct = $this->address_struct_from_snapshot( $snapshot );
		$address        = $this->flatten_address( $address_struct );
		$phone          = isset( $snapshot['phone'] ) && is_scalar( $snapshot['phone'] ) ? sanitize_text_field( (string) $snapshot['phone'] ) : '';

		if ( '' === $address && $wp_user_id > 0 ) {
			$address_struct = $this->address_struct_from_usermeta( $wp_user_id );
			$address        = $this->flatten_address( $address_struct );
		}
		if ( '' === $phone && $wp_user_id > 0 ) {
			$user_phone = get_user_meta( $wp_user_id, 'donor_phone', true );
			$phone      = is_scalar( $user_phone ) ? sanitize_text_field( (string) $user_phone ) : '';
		}

		$contact_consent = ! empty( $donor_row['contact_consent'] ) || ! empty( $donation_meta['contact_consent'] );

		$donor_meta = $charitable_donor_id > 0 ? Source::get_instance()->get_donor_meta( $charitable_donor_id ) : [];
		$profile    = self::extract_donor_profile( $donor_meta );

		$charitable_block = array_filter(
			[
				'source_id'       => $charitable_donor_id,
				'wp_user_id'      => isset( $donor_row['user_id'] ) && is_numeric( $donor_row['user_id'] ) ? absint( $donor_row['user_id'] ) : 0,
				'import_id'       => isset( $progress['import_id'] ) && is_scalar( $progress['import_id'] ) ? (string) $progress['import_id'] : '',
				'address'         => $address_struct,
				'contact_consent' => $contact_consent,
				'date_joined'     => isset( $donor_row['date_joined'] ) && is_scalar( $donor_row['date_joined'] ) ? sanitize_text_field( (string) $donor_row['date_joined'] ) : '',
				'donor_meta'      => $profile['extra_meta'],
			],
			static function ( $v ) {
				if ( is_array( $v ) ) {
					return ! empty( $v );
				}
				if ( is_string( $v ) ) {
					return '' !== $v;
				}
				return ! empty( $v );
			}
		);

		// first_donation_date / last_donation_date are intentionally NOT set
		// here — Donation_Mapper stamps them after each insert using the
		// actual donation date, so they reflect the donor's real history
		// rather than the import time.
		$data = [
			'email'            => $email,
			'name'             => $name,
			'phone'            => $phone,
			'company'          => '',
			'user_id'          => $wp_user_id,
			'address'          => $address,
			'import_source_id' => $charitable_donor_id,
			'import_source'    => 'charitable',
			'donor_data'       => [ 'charitable' => $charitable_block ],
		];

		// Donors::add() bypasses maybe_link_wp_user() (which is only called from
		// get_or_create), so no WP user is silently created during import.
		$donor_id = Donors::add( $data );
		if ( ! $donor_id ) {
			return 0;
		}

		$donor_map[ $email ]   = (int) $donor_id;
		$progress['donor_map'] = $donor_map;
		return (int) $donor_id;
	}

	/**
	 * Reduce a flat charitable_donormeta map into the structured profile shape
	 * the mappers consume (mirrors the GiveWP profile extractor so the Pro
	 * standalone-donor mapper can reuse it unchanged).
	 *
	 * Charitable's free plugin defines no fixed donormeta vocabulary, so
	 * everything lands in extra_meta and is preserved raw on donor_data.
	 *
	 * @param  array<string, string> $donor_meta Flat donormeta map.
	 * @return array{phone: string, company: string, address_str: string, address_struct: array<string, string>, extra_meta: array<string, string>}
	 * @since  1.5.1
	 */
	public static function extract_donor_profile( $donor_meta ) {
		$donor_meta = is_array( $donor_meta ) ? $donor_meta : [];

		$extra = [];
		foreach ( $donor_meta as $key => $value ) {
			$clean_key = sanitize_key( (string) $key );
			if ( '' === $clean_key || ! is_scalar( $value ) ) {
				continue;
			}
			$extra[ $clean_key ] = sanitize_text_field( (string) $value );
		}

		return [
			'phone'          => '',
			'company'        => '',
			'address_str'    => '',
			'address_struct' => [],
			'extra_meta'     => $extra,
		];
	}

	/**
	 * Build the structured address from a donation's donor snapshot.
	 *
	 * @param  array<string, string> $snapshot Donor snapshot.
	 * @return array<string, string> line1/line2/city/state/zip/country (empty values omitted).
	 * @since  1.5.1
	 */
	private function address_struct_from_snapshot( $snapshot ) {
		$pick = static function ( $key ) use ( $snapshot ) {
			return isset( $snapshot[ $key ] ) && is_scalar( $snapshot[ $key ] ) ? sanitize_text_field( (string) $snapshot[ $key ] ) : '';
		};

		return array_filter(
			[
				'line1'   => $pick( 'address' ),
				'line2'   => $pick( 'address_2' ),
				'city'    => $pick( 'city' ),
				'state'   => $pick( 'state' ),
				'zip'     => $pick( 'postcode' ),
				'country' => $pick( 'country' ),
			]
		);
	}

	/**
	 * Build the structured address from Charitable's donor_* usermeta keys.
	 *
	 * @param  int $user_id WordPress user ID.
	 * @return array<string, string>
	 * @since  1.5.1
	 */
	private function address_struct_from_usermeta( $user_id ) {
		$map = [
			'line1'   => 'donor_address',
			'line2'   => 'donor_address_2',
			'city'    => 'donor_city',
			'state'   => 'donor_state',
			'zip'     => 'donor_postcode',
			'country' => 'donor_country',
		];

		$struct = [];
		foreach ( $map as $slot => $meta_key ) {
			$raw   = get_user_meta( $user_id, $meta_key, true );
			$value = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
			if ( '' !== $value ) {
				$struct[ $slot ] = $value;
			}
		}

		return $struct;
	}

	/**
	 * Flatten a structured address to the single-line string stored on the
	 * donors table.
	 *
	 * @param  array<string, string> $struct Structured address.
	 * @return string
	 * @since  1.5.1
	 */
	private function flatten_address( $struct ) {
		return implode( ', ', array_filter( array_map( 'trim', array_values( (array) $struct ) ) ) );
	}

	/**
	 * Link to an existing WordPress user only — never create one.
	 *
	 * Tries the charitable_donors.user_id column first, then an email match.
	 *
	 * @param  array<string, mixed> $donor_row Charitable donors-table row (may be empty).
	 * @param  string               $email     Donor email.
	 * @return int WP user ID or 0.
	 * @since  1.5.1
	 */
	private function resolve_wp_user_id( $donor_row, $email ) {
		$row_user_id = isset( $donor_row['user_id'] ) && is_numeric( $donor_row['user_id'] ) ? absint( $donor_row['user_id'] ) : 0;
		if ( $row_user_id > 0 ) {
			$user = get_userdata( $row_user_id );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}
}
