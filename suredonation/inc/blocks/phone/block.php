<?php
/**
 * PHP render for Phone Number Block.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Blocks\Phone;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Fields\Phone_Markup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Phone Number Block.
 *
 * @since 1.1.1
 */
class Block extends Base {
	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 1.1.1
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content ); // Unused parameter.

		if ( empty( $attributes ) ) {
			return '';
		}

		// Load intl-tel-input and the phone initializer only when a phone field is
		// on the page. suredonation-phone declares the library as a dependency, so
		// enqueuing it pulls the library in too, in the right order.
		wp_enqueue_style( 'suredonation-intl-tel-input' );
		wp_enqueue_script( 'suredonation-phone' );

		$markup_class = new Phone_Markup( $attributes );
		ob_start();
		echo wp_kses( $markup_class->markup(), self::get_allowed_form_html() );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
