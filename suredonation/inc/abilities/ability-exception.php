<?php
/**
 * Abilities API Exception
 *
 * Carries a machine-readable error code alongside the message so a failed
 * ability can surface a meaningful `WP_Error` code rather than one generic
 * bucket for every failure.
 *
 * @package SureDonation
 * @since 1.5.0
 */

namespace SureDonation\Inc\Abilities;

use Exception;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability_Exception class.
 *
 * @since 1.5.0
 */
class Ability_Exception extends Exception {
	/**
	 * Default code used when a call site does not supply one.
	 */
	public const DEFAULT_CODE = 'suredonation_ability_error';

	/**
	 * Machine-readable error code.
	 *
	 * Exception::$code is an int by convention, so the string code lives here
	 * rather than being squeezed through the parent constructor.
	 *
	 * @var string
	 * @since 1.5.0
	 */
	protected $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Machine-readable code (e.g. `campaign_not_found`).
	 * @param string $message    Human-readable, translated message.
	 * @since 1.5.0
	 */
	public function __construct( $error_code, $message ) {
		parent::__construct( $message );
		$this->error_code = '' !== $error_code ? $error_code : self::DEFAULT_CODE;
	}

	/**
	 * Get the machine-readable error code.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function get_error_code() {
		return $this->error_code;
	}
}
