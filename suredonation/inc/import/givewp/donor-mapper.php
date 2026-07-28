<?php
/**
 * Donor resolver for the GiveWP migration tool.
 *
 * Looks up or creates the SureDonation donor row that a given GiveWP
 * payment belongs to. Matches Charitable's verified pattern: link to an
 * existing WP user if one is found by id or email, otherwise store
 * user_id = 0. Never creates a WP user during migration — the donor
 * dashboard's magic-link auth works against the donors table alone
 * (see suredonation-pro/inc/donor-dashboard/magic-link.php:187).
 *
 * When the GiveWP donor id is known, the row is enriched from
 * give_donormeta so phone, company, structured address, and any
 * gateway-specific keys (Stripe customer id) survive the migration
 * instead of being lost to payment-meta only.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Donor_Mapper class.
 *
 * @since 1.0.0
 */
class Donor_Mapper {
	use Get_Instance;

	/**
	 * GiveWP donormeta keys that the enrichment logic maps to dedicated
	 * SureDonation donor columns (phone, company), top-level donor_data
	 * slots the donor dashboard reads (donor_data.avatar_url), or
	 * structured slots under donor_data.givewp.address /
	 * .stripe_customer_id. Anything outside this set is preserved raw
	 * under donor_data.givewp.donor_meta.
	 */
	const STANDARD_DONOR_META_KEYS = [
		'_give_donor_first_name',
		'_give_donor_last_name',
		'_give_donor_company',
		'_give_donor_phone',
		'_give_donor_avatar',
		'_give_donor_anonymous',
		'_give_donor_address_billing_line1_0',
		'_give_donor_address_billing_line2_0',
		'_give_donor_address_billing_city_0',
		'_give_donor_address_billing_state_0',
		'_give_donor_address_billing_zip_0',
		'_give_donor_address_billing_country_0',
		'_give_stripe_customer_id',
	];

	/**
	 * Resolve (or create) the SureDonation donor row for a given GiveWP payment.
	 *
	 * Caches the result on $progress['donor_map'] keyed by email so subsequent
	 * payments from the same donor in the same session do not re-query.
	 *
	 * @param  array $payment_meta Flat assoc of GiveWP payment post meta.
	 * @param  array $progress     Session progress (passed by reference; donor_map updated).
	 * @return int Donor ID (>0) or 0 on failure.
	 * @since  1.0.0
	 */
	public function get_or_create_for_payment( $payment_meta, &$progress ) {
		$payment_meta = is_array( $payment_meta ) ? $payment_meta : [];

		$email = isset( $payment_meta['_give_payment_donor_email'] ) ? sanitize_email( $payment_meta['_give_payment_donor_email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}

		// Session-level cache.
		if ( isset( $progress['donor_map'][ $email ] ) ) {
			return (int) $progress['donor_map'][ $email ];
		}

		// Existing SureDonation donor by email?
		$existing = Donors::get_by_email( $email );
		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$donor_id                        = (int) $existing['id'];
			$progress['donor_map'][ $email ] = $donor_id;
			return $donor_id;
		}

		// Build the row from payment meta.
		$first_name = isset( $payment_meta['_give_donor_billing_first_name'] ) ? sanitize_text_field( $payment_meta['_give_donor_billing_first_name'] ) : '';
		$last_name  = isset( $payment_meta['_give_donor_billing_last_name'] ) ? sanitize_text_field( $payment_meta['_give_donor_billing_last_name'] ) : '';
		$name       = trim( $first_name . ' ' . $last_name );

		// Optionally enrich from give_donors row + give_donormeta.
		$give_donor_id = isset( $payment_meta['_give_payment_donor_id'] ) ? absint( $payment_meta['_give_payment_donor_id'] ) : 0;
		$give_donor    = $give_donor_id > 0 ? Source::get_instance()->get_donor( $give_donor_id ) : null;
		if ( is_array( $give_donor ) && '' === $name && isset( $give_donor['name'] ) && is_scalar( $give_donor['name'] ) ) {
			$name = sanitize_text_field( (string) $give_donor['name'] );
		}

		$donor_meta = $give_donor_id > 0 ? Source::get_instance()->get_donor_meta( $give_donor_id ) : [];
		$profile    = self::extract_donor_profile( $donor_meta );

		// Prefer the structured donor-level address; fall back to billing
		// fields on the payment meta if donor meta is empty.
		$address = '' !== $profile['address_str']
			? $profile['address_str']
			: $this->address_from_payment_meta( $payment_meta );

		$wp_user_id = $this->resolve_wp_user_id( $payment_meta, $email );

		$givewp_block = array_filter(
			[
				'source_id'          => $give_donor_id,
				'wp_user_id'         => $this->raw_give_user_id( $payment_meta ),
				'import_id'          => isset( $progress['import_id'] ) ? (string) $progress['import_id'] : '',
				'address'            => $profile['address_struct'],
				'stripe_customer_id' => $profile['stripe_customer_id'],
				'donor_meta'         => $profile['extra_meta'],
				'anonymous'          => $profile['anonymous'],
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

		// Avatar URL goes at the top level of donor_data so the donor
		// dashboard's resolve_avatar_url() picks it up for non-WP-linked
		// donors. For WP-linked donors the dashboard resolver checks
		// user_meta(`suredonation_avatar_url`) first (so the override
		// also feeds the site-wide get_avatar / get_avatar_url filter
		// added in suredonation-pro PR #7), so mirror the URL there
		// too when we have a user_id.
		$donor_data = [ 'givewp' => $givewp_block ];
		if ( '' !== $profile['avatar_url'] ) {
			$donor_data['avatar_url'] = $profile['avatar_url'];
			if ( $wp_user_id > 0 ) {
				update_user_meta( $wp_user_id, 'suredonation_avatar_url', $profile['avatar_url'] );
			}
		}

		// first_donation_date / last_donation_date are intentionally NOT
		// set here. They're stamped lazily by Donation_Mapper after each
		// donation insert (using the actual payment date), so for the
		// freshly created donor they accurately reflect their oldest
		// and newest contribution rather than the import time.
		$data = [
			'email'            => $email,
			'name'             => $name,
			'phone'            => $profile['phone'],
			'company'          => $profile['company'],
			'user_id'          => $wp_user_id,
			'address'          => $address,
			'import_source_id' => $give_donor_id,
			'import_source'    => 'givewp',
			'donor_data'       => $donor_data,
		];

		// Donors::add() bypasses maybe_link_wp_user() (which is only called from
		// get_or_create), so no WP user is silently created during import.
		$donor_id = Donors::add( $data );
		if ( ! $donor_id ) {
			return 0;
		}

		$progress['donor_map'][ $email ] = (int) $donor_id;
		return (int) $donor_id;
	}

	/**
	 * Reduce a flat give_donormeta map into the structured profile our
	 * mappers consume: dedicated columns (phone, company), structured
	 * address parts, and known gateway keys, plus any unknown keys
	 * preserved raw under `extra_meta`.
	 *
	 * Mirrors the meta keys Charitable's importer reads (see
	 * class-charitable-givewp-importer.php:1093+).
	 *
	 * @param  array<string,string> $donor_meta Flat give_donormeta map.
	 * @return array{phone:string,company:string,avatar_url:string,stripe_customer_id:string,anonymous:bool,address_str:string,address_struct:array<string,string>,extra_meta:array<string,string>}
	 * @since  1.0.0
	 */
	public static function extract_donor_profile( $donor_meta ) {
		$donor_meta = is_array( $donor_meta ) ? $donor_meta : [];

		$get = static function ( $key ) use ( $donor_meta ) {
			return isset( $donor_meta[ $key ] ) ? (string) $donor_meta[ $key ] : '';
		};

		$address_struct = array_filter(
			[
				'line1'   => sanitize_text_field( $get( '_give_donor_address_billing_line1_0' ) ),
				'line2'   => sanitize_text_field( $get( '_give_donor_address_billing_line2_0' ) ),
				'city'    => sanitize_text_field( $get( '_give_donor_address_billing_city_0' ) ),
				'state'   => sanitize_text_field( $get( '_give_donor_address_billing_state_0' ) ),
				'zip'     => sanitize_text_field( $get( '_give_donor_address_billing_zip_0' ) ),
				'country' => sanitize_text_field( $get( '_give_donor_address_billing_country_0' ) ),
			],
			static function ( $v ) {
				return '' !== $v;
			}
		);

		$address_str = sanitize_text_field( implode( ', ', $address_struct ) );

		$extra_meta = [];
		foreach ( $donor_meta as $k => $v ) {
			$key = (string) $k;
			if ( in_array( $key, self::STANDARD_DONOR_META_KEYS, true ) ) {
				continue;
			}
			$extra_meta[ $key ] = is_scalar( $v ) ? (string) $v : '';
		}

		return [
			'phone'              => sanitize_text_field( $get( '_give_donor_phone' ) ),
			'company'            => sanitize_text_field( $get( '_give_donor_company' ) ),
			// Donor dashboard reads donor_data.avatar_url; the mapper
			// stores it at that top-level slot, this field just surfaces
			// the value from give_donormeta for the mapper to use.
			'avatar_url'         => esc_url_raw( $get( '_give_donor_avatar' ) ),
			'stripe_customer_id' => sanitize_text_field( $get( '_give_stripe_customer_id' ) ),
			'anonymous'          => '1' === $get( '_give_donor_anonymous' ),
			'address_str'        => $address_str,
			'address_struct'     => $address_struct,
			'extra_meta'         => $extra_meta,
		];
	}

	/**
	 * Build a flat display address from payment-side billing meta.
	 *
	 * Fallback when donor-level meta is empty (older GiveWP installs or
	 * forms that disabled address capture).
	 *
	 * @param  array<string,mixed> $payment_meta Payment meta.
	 * @return string
	 * @since  1.0.0
	 */
	private function address_from_payment_meta( $payment_meta ) {
		$parts = array_filter(
			[
				isset( $payment_meta['_give_donor_billing_address1'] ) ? (string) $payment_meta['_give_donor_billing_address1'] : '',
				isset( $payment_meta['_give_donor_billing_address2'] ) ? (string) $payment_meta['_give_donor_billing_address2'] : '',
				isset( $payment_meta['_give_donor_billing_city'] ) ? (string) $payment_meta['_give_donor_billing_city'] : '',
				isset( $payment_meta['_give_donor_billing_state'] ) ? (string) $payment_meta['_give_donor_billing_state'] : '',
				isset( $payment_meta['_give_donor_billing_zip'] ) ? (string) $payment_meta['_give_donor_billing_zip'] : '',
				isset( $payment_meta['_give_donor_billing_country'] ) ? (string) $payment_meta['_give_donor_billing_country'] : '',
			]
		);
		return sanitize_text_field( implode( ', ', $parts ) );
	}

	/**
	 * Resolve a WP user ID for an imported donor, link-only-if-exists pattern.
	 *
	 * @param  array  $payment_meta GiveWP payment meta.
	 * @param  string $email        Donor email (already sanitized).
	 * @return int 0 if no WP user found.
	 * @since  1.0.0
	 */
	private function resolve_wp_user_id( $payment_meta, $email ) {
		$give_user_id = $this->raw_give_user_id( $payment_meta );
		if ( $give_user_id > 0 ) {
			$user = get_userdata( $give_user_id );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/**
	 * Extract the GiveWP-stored WP user ID from payment meta as a positive int.
	 *
	 * @param  array $payment_meta Payment meta.
	 * @return int 0 if not set or non-numeric.
	 * @since  1.0.0
	 */
	private function raw_give_user_id( $payment_meta ) {
		if ( ! is_array( $payment_meta ) || ! isset( $payment_meta['_give_payment_user_id'] ) ) {
			return 0;
		}
		$raw = $payment_meta['_give_payment_user_id'];
		return is_numeric( $raw ) ? absint( $raw ) : 0;
	}
}
