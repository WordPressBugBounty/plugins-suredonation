<?php
/**
 * PHP render for Dropdown Block.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Blocks\Dropdown;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Fields\Dropdown_Markup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Dropdown Block.
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

		// Load tom-select and the dropdown initializer only when a dropdown is on
		// the page. suredonation-dropdown declares tom-select as a dependency, so
		// enqueuing it pulls the library in too, in the right order.
		wp_enqueue_style( 'suredonation-tom-select' );
		wp_enqueue_script( 'suredonation-dropdown' );

		$markup_class = new Dropdown_Markup( $attributes );
		ob_start();
		echo wp_kses( $markup_class->markup(), self::get_allowed_form_html() );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
