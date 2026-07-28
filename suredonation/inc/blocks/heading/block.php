<?php
/**
 * PHP render for Heading Block.
 *
 * A display-only block that renders a section heading within the donation form.
 * It carries no submission value and does not participate in field validation.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Blocks\Heading;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Heading Block.
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

		$text = isset( $attributes['headingText'] ) ? Helper::get_string_value( $attributes['headingText'] ) : '';
		if ( '' === trim( $text ) ) {
			return '';
		}

		// Restrict the tag to the supported set (H1–H6, P, Div) so it can be used
		// safely as an element name.
		$tag = isset( $attributes['headingTag'] ) ? strtolower( Helper::get_string_value( $attributes['headingTag'] ) ) : 'h2';
		if ( ! in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div' ], true ) ) {
			$tag = 'h2';
		}

		// Restrict alignment to a known set so it can be used safely in a class.
		$align = isset( $attributes['textAlign'] ) ? Helper::get_string_value( $attributes['textAlign'] ) : 'left';
		if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
			$align = 'left';
		}

		$wrapper_classes = self::get_display_block_classes( $attributes, 'sd-heading-block' );

		$heading_markup = sprintf(
			'<%1$s class="sd-heading sd-heading-align-%2$s">%3$s</%1$s>',
			esc_attr( $tag ),
			esc_attr( $align ),
			esc_html( $text )
		);

		// Optional subheading, placed above or below the heading.
		$sub_markup = '';
		$sub_text   = isset( $attributes['subHeadingText'] ) ? Helper::get_string_value( $attributes['subHeadingText'] ) : '';
		if ( ! empty( $attributes['enableSubHeading'] ) && '' !== trim( $sub_text ) ) {
			$sub_markup = sprintf(
				'<p class="sd-subheading sd-subheading-align-%1$s">%2$s</p>',
				esc_attr( $align ),
				esc_html( $sub_text )
			);
		}

		$sub_position = isset( $attributes['subHeadingPosition'] ) ? Helper::get_string_value( $attributes['subHeadingPosition'] ) : 'below';
		if ( ! in_array( $sub_position, [ 'above', 'below' ], true ) ) {
			$sub_position = 'below';
		}

		// Optional separator (rule), placed relative to the heading/subheading.
		$sep_markup = '';
		$sep_style  = isset( $attributes['separatorStyle'] ) ? Helper::get_string_value( $attributes['separatorStyle'] ) : 'none';
		if ( in_array( $sep_style, [ 'solid', 'double', 'dashed', 'dotted' ], true ) ) {
			$sep_markup = sprintf(
				'<hr class="sd-heading-separator sd-heading-separator--%1$s sd-heading-separator-align-%2$s" />',
				esc_attr( $sep_style ),
				esc_attr( $align )
			);
		}

		$sep_position = isset( $attributes['separatorPosition'] ) ? Helper::get_string_value( $attributes['separatorPosition'] ) : 'below-heading';
		if ( ! in_array( $sep_position, [ 'above-heading', 'below-heading', 'below-subheading' ], true ) ) {
			$sep_position = 'below-heading';
		}

		// Order the heading and subheading, then weave in the separator at its anchor.
		$order = ( 'above' === $sub_position && '' !== $sub_markup )
			? [ 'sub', 'heading' ]
			: [ 'heading', 'sub' ];

		$inner      = '';
		$sep_placed = false;
		foreach ( $order as $part ) {
			if ( 'heading' === $part ) {
				if ( '' !== $sep_markup && 'above-heading' === $sep_position ) {
					$inner     .= $sep_markup;
					$sep_placed = true;
				}
				$inner .= $heading_markup;
				if ( '' !== $sep_markup && 'below-heading' === $sep_position ) {
					$inner     .= $sep_markup;
					$sep_placed = true;
				}
			} elseif ( '' !== $sub_markup ) {
				$inner .= $sub_markup;
				if ( '' !== $sep_markup && 'below-subheading' === $sep_position ) {
					$inner     .= $sep_markup;
					$sep_placed = true;
				}
			}
		}

		// Fallback: separator anchored to a missing subheading still renders.
		if ( '' !== $sep_markup && ! $sep_placed ) {
			$inner .= $sep_markup;
		}

		$markup = sprintf(
			'<div class="%1$s">%2$s</div>',
			esc_attr( $wrapper_classes ),
			$inner
		);

		ob_start();
		echo wp_kses( $markup, self::get_allowed_form_html() );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
