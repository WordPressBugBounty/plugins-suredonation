<?php
/**
 * Donor-User Linking
 *
 * Links WordPress users to donor records on login.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc;

use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor_User_Link class.
 *
 * @since 1.0.0
 */
class Donor_User_Link {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'wp_login', [ $this, 'link_donor_on_login' ], 10, 2 );
	}

	/**
	 * Link donor record to WP user on login.
	 *
	 * If the logged-in user's email matches a donor without a user_id,
	 * link them automatically.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       WP_User object.
	 * @return void
	 * @since 1.0.0
	 */
	public function link_donor_on_login( $user_login, $user ) {
		unset( $user_login );

		if ( ! $user instanceof \WP_User || empty( $user->user_email ) ) {
			return;
		}

		$donor = Donors::get_by_email( $user->user_email );

		if ( ! $donor || empty( $donor['id'] ) ) {
			return;
		}

		$donor_id = is_numeric( $donor['id'] ) ? (int) $donor['id'] : 0;
		if ( $donor_id <= 0 ) {
			return;
		}

		// Already linked to this user.
		$existing_user_id = isset( $donor['user_id'] ) && is_numeric( $donor['user_id'] ) ? (int) $donor['user_id'] : 0;
		if ( $existing_user_id === $user->ID ) {
			return;
		}

		// Link the donor to this WP user (only if not linked to a different user).
		// Direct $wpdb->update so the WHERE clause can carry the
		// "still unlinked" condition atomically. A read-then-write via
		// Donors::update() would race against another concurrent login
		// that shares this email (email-change flows, shared-mailbox
		// households) and could silently overwrite a link the other
		// request just established.
		if ( $existing_user_id <= 0 ) {
			global $wpdb;
			$donors_table = $wpdb->prefix . 'suredonation_donors';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic conditional update; the abstraction layer doesn't support multi-column WHERE.
			$wpdb->update(
				$donors_table,
				[
					'user_id'    => $user->ID,
					'updated_at' => current_time( 'mysql' ),
				],
				[
					'id'      => $donor_id,
					'user_id' => 0,
				],
				[ '%d', '%s' ],
				[ '%d', '%d' ]
			);
		}
	}
}
