<?php
/**
 * Singleton Trait
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get_Instance trait for singleton pattern.
 *
 * @since 0.0.1
 */
trait Get_Instance {
	/**
	 * Instance object.
	 *
	 * @var self Class Instance.
	 * @since 0.0.1
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return self initialized object of class.
	 * @since 0.0.1
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
