<?php
/**
 * Assets Register
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Assets;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register class for frontend assets.
 *
 * @since 0.0.1
 */
class Register {

	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
		// Progressive-enhancement gate: mark that JS is available before the body
		// paints, so enhanced fields (e.g. the dropdown) can hide their native
		// control and show a matching placeholder without a flash. The native
		// control stays visible when this class is absent (JS disabled/failed).
		add_action( 'wp_head', [ $this, 'print_js_gate' ], 1 );
	}

	/**
	 * Print the progressive-enhancement gate inline in the head.
	 *
	 * A one-liner that adds the `suredonation-js` class to <html> synchronously,
	 * before the body renders. The class only has a visual effect where a
	 * SureDonation field stylesheet is loaded, so printing it on every front-end
	 * page is harmless and keeps the fix independent of where forms are embedded.
	 *
	 * @return void
	 * @since 1.1.1
	 */
	public function print_js_gate() {
		if ( is_admin() ) {
			return;
		}

		wp_print_inline_script_tag(
			"document.documentElement.classList.add('suredonation-js');",
			[ 'id' => 'suredonation-js-gate' ]
		);
	}

	/**
	 * Register frontend assets.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_frontend_assets() {
		// Register donation form frontend styles (for custom Gutenberg forms).
		// Use filemtime so rebuilds bust the browser cache.
		$style_file    = SUREDONATION_DIR . 'assets/build/blocks/donation-form/style-style.css';
		$style_version = file_exists( $style_file )
			? (string) filemtime( $style_file )
			: SUREDONATION_VER;

		wp_register_style(
			'suredonation-donation-form',
			SUREDONATION_URL . 'assets/build/blocks/donation-form/style-style.css',
			[],
			$style_version
		);

		// Register campaign display blocks frontend styles.
		// Enqueued conditionally by each campaign block render only when present.
		$campaign_style_file    = SUREDONATION_DIR . 'assets/build/blocks/campaign/style-style.css';
		$campaign_style_version = file_exists( $campaign_style_file )
			? (string) filemtime( $campaign_style_file )
			: SUREDONATION_VER;

		wp_register_style(
			'suredonation-campaign-blocks',
			SUREDONATION_URL . 'assets/build/blocks/campaign/style-style.css',
			[],
			$campaign_style_version
		);

		// Register donation form frontend script (used by both block and shortcode render paths).
		$form_frontend_asset_file = SUREDONATION_DIR . 'assets/build/form-frontend.asset.php';
		$form_frontend_asset      = file_exists( $form_frontend_asset_file )
			? require $form_frontend_asset_file
			: [
				'dependencies' => [],
				'version'      => SUREDONATION_VER,
			];

		wp_register_script(
			'suredonation-form-frontend',
			SUREDONATION_URL . 'assets/build/form-frontend.js',
			$form_frontend_asset['dependencies'],
			$form_frontend_asset['version'],
			true
		);

		// Register the Inputmask library (vendored).
		// Enqueued conditionally by the input block render only when a field uses an input pattern.
		wp_register_script(
			'suredonation-inputmask',
			SUREDONATION_URL . 'assets/js/vendor/inputmask.min.js',
			[],
			SUREDONATION_VER,
			true
		);

		// Register the tom-select library (vendored) + its styles.
		// Enqueued conditionally by the dropdown block render only when present.
		wp_register_style(
			'suredonation-tom-select',
			SUREDONATION_URL . 'assets/css/vendor/tom-select.css',
			[],
			SUREDONATION_VER
		);

		wp_register_script(
			'suredonation-tom-select',
			SUREDONATION_URL . 'assets/js/vendor/tom-select.min.js',
			[],
			SUREDONATION_VER,
			true
		);

		// Register the dropdown initializer. Depends on tom-select so enqueuing
		// the dropdown handle pulls the library in, in the right order.
		$dropdown_asset_file = SUREDONATION_DIR . 'assets/build/blocks/dropdown/frontend.asset.php';
		$dropdown_asset      = file_exists( $dropdown_asset_file )
			? require $dropdown_asset_file
			: [
				'dependencies' => [],
				'version'      => SUREDONATION_VER,
			];

		wp_register_script(
			'suredonation-dropdown',
			SUREDONATION_URL . 'assets/build/blocks/dropdown/frontend.js',
			array_merge( $dropdown_asset['dependencies'], [ 'suredonation-tom-select', 'wp-a11y' ] ),
			$dropdown_asset['version'],
			true
		);

		// Register the intl-tel-input library (vendored) + its styles.
		// Enqueued conditionally by the phone block render only when present.
		wp_register_style(
			'suredonation-intl-tel-input',
			SUREDONATION_URL . 'assets/css/vendor/intl/intlTelInput.min.css',
			[],
			SUREDONATION_VER
		);

		wp_register_script(
			'suredonation-intl-tel-input',
			SUREDONATION_URL . 'assets/js/vendor/intl/intTelInputWithUtils.min.js',
			[],
			SUREDONATION_VER,
			true
		);

		// Register the phone initializer. Depends on intl-tel-input so enqueuing
		// the phone handle pulls the library in, in the right order.
		$phone_asset_file = SUREDONATION_DIR . 'assets/build/blocks/phone/frontend.asset.php';
		$phone_asset      = file_exists( $phone_asset_file )
			? require $phone_asset_file
			: [
				'dependencies' => [],
				'version'      => SUREDONATION_VER,
			];

		wp_register_script(
			'suredonation-phone',
			SUREDONATION_URL . 'assets/build/blocks/phone/frontend.js',
			array_merge( $phone_asset['dependencies'], [ 'suredonation-intl-tel-input' ] ),
			$phone_asset['version'],
			true
		);
	}
}
