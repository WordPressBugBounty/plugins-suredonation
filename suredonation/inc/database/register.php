<?php
/**
 * SureDonation Database Tables Register Class.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Database;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureDonation Database Tables Register Class
 *
 * @since 0.0.1
 */
class Register {
	/**
	 * Init database registration.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public static function init() {
		/*
		 * ### Here, order is important. ###
		 * 1. Start the DB upgrade which also manages the internal versioning of each tables.
		 * 2. Init create method, which create the table if the table does not exists.
		 * 3. Finally, stop the DB upgrade and update the current version in option table.
		 */
		foreach ( static::get_db_tables() as $instance ) {
			$instance->start_db_upgrade();

			if ( $instance->is_db_upgradable() ) {
				// Only execute below methods if DB is upgradable.
				$instance->create( $instance->get_columns_definition() );
				$instance->maybe_add_new_columns( $instance->get_new_columns_definition() );
			}

			// Stop the upgrade process of current table and move to next.
			$instance->stop_db_upgrade();
		}
	}

	/**
	 * Returns an array of instances/objects of our custom tables.
	 *
	 * @return array<string,\SureDonation\Inc\Database\Base>
	 * @since 0.0.1
	 */
	public static function get_db_tables() {
		return [
			'donations' => Donations::get_instance(),
			'donors'    => Donors::get_instance(),
		];
	}
}
