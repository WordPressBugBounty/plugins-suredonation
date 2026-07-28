<?php
/**
 * PHP render for Input Block.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Blocks\Input;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Fields\Input_Markup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Input Block.
 *
 * @since 0.0.1
 */
class Block extends Base {
	/**
	 * Render the block
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 0.0.1
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content ); // Unused parameter.

		if ( empty( $attributes ) ) {
			return '';
		}

		// Load the Inputmask library only when this field uses an input pattern.
		if ( ! empty( $attributes['inputMask'] ) && 'none' !== $attributes['inputMask'] ) {
			wp_enqueue_script( 'suredonation-inputmask' );
		}

		$markup_class = new Input_Markup( $attributes );
		ob_start();
		echo wp_kses( $markup_class->markup(), self::get_allowed_form_html() );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
