<?php
/**
 * Bricks service provider.
 *
 * Registers the SureDonation element category and all campaign/donation
 * elements with Bricks Builder. Self-gates on Bricks being active.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Bricks;

use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Service_Provider class.
 *
 * @since 1.2.0
 */
class Service_Provider {
	use Get_Instance;

	/**
	 * Element category slug (label registered via `bricks/builder/i18n`).
	 *
	 * @since 1.2.0
	 */
	public const CATEGORY = 'suredonation';

	/**
	 * Constructor — Bricks collects custom elements on init (priority 11,
	 * matching the SureForms integration).
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_elements' ], 11 );
	}

	/**
	 * Register every SureDonation element with Bricks.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function register_elements() {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}

		add_filter( 'bricks/builder/i18n', [ $this, 'bricks_translatable_strings' ] );

		// Explicit class names are required: Bricks' fallback picks the last
		// declared class after including the file, which would be the autoloaded
		// Base_Element parent instead of the element itself. `::class` does not
		// trigger the autoloader, so this stays fatal-safe without Bricks.
		$elements = [
			__DIR__ . '/elements/donation-form.php'      => Elements\Donation_Form::class,
			__DIR__ . '/elements/campaign-goal.php'      => Elements\Campaign_Goal::class,
			__DIR__ . '/elements/campaign-stats.php'     => Elements\Campaign_Stats::class,
			__DIR__ . '/elements/campaign-donations.php' => Elements\Campaign_Donations::class,
			__DIR__ . '/elements/campaign-donors.php'    => Elements\Campaign_Donors::class,
			__DIR__ . '/elements/campaign-donate-button.php' => Elements\Campaign_Donate_Button::class,
			__DIR__ . '/elements/campaign-social-sharing.php' => Elements\Campaign_Social_Sharing::class,
		];

		foreach ( $elements as $file => $class_name ) {
			\Bricks\Elements::register_element( $file, '', $class_name );
		}
	}

	/**
	 * Register the SureDonation category label with the builder.
	 *
	 * @since 1.2.0
	 * @param array<string, string> $i18n Builder translatable strings.
	 * @return array<string, string>
	 */
	public function bricks_translatable_strings( $i18n ) {
		$i18n[ self::CATEGORY ] = __( 'SureDonation', 'suredonation' );
		return $i18n;
	}
}
