<?php
/**
 * PHP render for Multi-Choice Block.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Blocks\Donation_Amount;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Fields\Donation_Amount_Markup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Multi-Choice Block.
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

		$markup_class = new Donation_Amount_Markup( $attributes );
		ob_start();
		// Allow the data: protocol so a lazy-load optimizer's inline placeholder in
		// a per-option image's src survives kses. Without it this pass strips the
		// src and the option image renders blank, while the form renderer's second
		// pass (inc/fields/form-renderer.php) would have let it through — the bug
		// class documented in inc/blocks/CLAUDE.md, which the Image block already
		// compensates for the same way.
		echo wp_kses( $markup_class->markup(), self::get_allowed_form_html(), array_merge( wp_allowed_protocols(), [ 'data' ] ) );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
