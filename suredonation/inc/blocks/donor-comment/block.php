<?php
/**
 * PHP render for the Donor Comment block.
 *
 * @package SureDonation
 * @since 1.6.0
 */

namespace SureDonation\Inc\Blocks\Donor_Comment;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Fields\Donor_Comment_Markup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donor Comment Block.
 *
 * @since 1.6.0
 */
class Block extends Base {
	/**
	 * Render the block
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 1.6.0
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content ); // Unused parameter.

		if ( empty( $attributes ) ) {
			return '';
		}

		$markup_class = new Donor_Comment_Markup( $attributes );
		ob_start();
		echo wp_kses( $markup_class->markup(), self::get_allowed_form_html() );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
