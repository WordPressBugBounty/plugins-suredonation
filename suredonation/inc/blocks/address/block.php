<?php
/**
 * PHP render for Address Block.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Blocks\Address;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Fields\Address_Markup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Address Block.
 *
 * @since 1.1.1
 */
class Block extends Base {
	/**
	 * Render the block.
	 *
	 * The address block is a container: $content holds the already-rendered inner
	 * sub-fields (input + dropdown blocks). We only wrap them in the fieldset.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Rendered inner block content.
	 * @return string
	 * @since 1.1.1
	 */
	public function render( $attributes, $content = '' ) {
		if ( empty( $attributes ) ) {
			return $content;
		}

		$markup_class = new Address_Markup( $attributes );
		ob_start();
		echo wp_kses( $markup_class->markup( $content ), self::get_allowed_form_html() );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
