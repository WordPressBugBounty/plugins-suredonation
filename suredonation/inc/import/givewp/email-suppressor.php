<?php
/**
 * Email suppression for migration sessions.
 *
 * Activated for the duration of write-phase batches so the import does
 * not blast donation receipts or admin notifications to donors when
 * their historical records are inserted. Mirrors Charitable's pattern
 * (add_filter( 'charitable_send_email', '__return_false' )).
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Email_Suppressor class.
 *
 * @since 1.0.0
 */
class Email_Suppressor {
	use Get_Instance;

	/**
	 * Whether suppression is currently engaged.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Engage email suppression filters.
	 *
	 * Safe to call repeatedly; only adds the filters once.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function activate() {
		if ( $this->active ) {
			return;
		}

		add_filter( 'suredonation_send_email', '__return_false', 999 );
		add_filter( 'suredonation_pro_send_email', '__return_false', 999 );

		$this->active = true;
	}

	/**
	 * Disengage email suppression filters.
	 *
	 * Safe to call repeatedly.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function deactivate() {
		if ( ! $this->active ) {
			return;
		}

		remove_filter( 'suredonation_send_email', '__return_false', 999 );
		remove_filter( 'suredonation_pro_send_email', '__return_false', 999 );

		$this->active = false;
	}

	/**
	 * Whether suppression is engaged.
	 *
	 * @return bool
	 * @since  1.0.0
	 */
	public function is_active() {
		return $this->active;
	}
}
