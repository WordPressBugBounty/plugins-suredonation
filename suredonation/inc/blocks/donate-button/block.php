<?php
/**
 * PHP render for Donate Button Block.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Blocks\Donate_Button;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Fields\Donate_Button_Markup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donate Button Block.
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

		$markup_class = new Donate_Button_Markup( $attributes );
		ob_start();
		echo wp_kses( $markup_class->markup(), self::get_allowed_form_html() );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
