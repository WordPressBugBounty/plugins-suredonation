<?php
/**
 * PHP render for Image Block.
 *
 * A display-only block that renders an image within the donation form
 * (e.g. an organization logo or cause photo). It carries no submission
 * value and does not participate in field validation.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Blocks\Image;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Image Block.
 *
 * @since 1.3.0
 */
class Block extends Base {
	/**
	 * Registered image sizes the block may request.
	 *
	 * @since 1.3.0
	 */
	private const SIZES = [ 'thumbnail', 'medium', 'large', 'full' ];

	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 1.3.0
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content ); // Unused parameter.

		if ( empty( $attributes ) ) {
			return '';
		}

		$image_id  = isset( $attributes['imageId'] ) ? absint( Helper::get_string_value( $attributes['imageId'] ) ) : 0;
		$image_url = isset( $attributes['imageUrl'] ) ? Helper::get_string_value( $attributes['imageUrl'] ) : '';
		$alt       = isset( $attributes['imageAlt'] ) ? Helper::get_string_value( $attributes['imageAlt'] ) : '';

		// Nothing selected — a display block with no image contributes nothing.
		if ( ! $image_id && '' === $image_url ) {
			return '';
		}

		// Restrict the size to the supported set so it can be passed to core safely.
		$size = isset( $attributes['sizeSlug'] ) ? Helper::get_string_value( $attributes['sizeSlug'] ) : 'full';
		if ( ! in_array( $size, self::SIZES, true ) ) {
			$size = 'full';
		}

		// Restrict alignment to a known set so it can be used safely in a class.
		$align = isset( $attributes['textAlign'] ) ? Helper::get_string_value( $attributes['textAlign'] ) : 'left';
		if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
			$align = 'left';
		}

		// Object fit — applied as a class (CSS carries the property) so no inline
		// styles pass through kses. Empty means the browser default.
		$fit = isset( $attributes['objectFit'] ) ? Helper::get_string_value( $attributes['objectFit'] ) : '';
		if ( ! in_array( $fit, [ 'fill', 'cover', 'contain' ], true ) ) {
			$fit = '';
		}

		// Optional fixed dimensions (px) from the editor resize handles /
		// Dimensions controls. 0 = auto. Emitted as width/height CSS, which
		// wp_kses passes through safecss_filter_attr.
		$width_px  = isset( $attributes['width'] ) ? max( 0, (int) Helper::get_string_value( $attributes['width'] ) ) : 0;
		$height_px = isset( $attributes['height'] ) ? max( 0, (int) Helper::get_string_value( $attributes['height'] ) ) : 0;
		$style     = '';
		if ( $width_px ) {
			$style .= 'width:' . $width_px . 'px;';
		}
		if ( $height_px ) {
			$style .= 'height:' . $height_px . 'px;';
		}

		// Prefer the attachment render (correct srcset/sizes/lazy-loading, and it
		// tracks the file if it was edited/regenerated); fall back to the stored
		// URL when the attachment no longer resolves (e.g. deleted media).
		$image_markup = '';
		if ( $image_id ) {
			$image_markup = wp_get_attachment_image(
				$image_id,
				$size,
				false,
				array_filter(
					[
						'class' => 'sd-image' . ( $fit ? ' sd-image--fit-' . $fit : '' ),
						'alt'   => $alt,
						'style' => $style,
					],
					static fn ( $value, $key ) => 'alt' === $key || '' !== $value,
					ARRAY_FILTER_USE_BOTH
				)
			);
		}

		if ( '' === $image_markup && '' !== $image_url ) {
			$image_markup = sprintf(
				'<img class="sd-image%3$s" src="%1$s" alt="%2$s"%4$s loading="lazy" decoding="async" />',
				esc_url( $image_url ),
				esc_attr( $alt ),
				$fit ? esc_attr( ' sd-image--fit-' . $fit ) : '',
				$style ? ' style="' . esc_attr( $style ) . '"' : ''
			);
		}

		if ( '' === $image_markup ) {
			return '';
		}

		$wrapper_classes = self::get_display_block_classes( $attributes, 'sd-image-block' );

		// Optional caption, rendered as a semantic figcaption.
		$caption_markup = '';
		$caption        = isset( $attributes['caption'] ) ? Helper::get_string_value( $attributes['caption'] ) : '';
		if ( ! empty( $attributes['enableCaption'] ) && '' !== trim( $caption ) ) {
			// Caption alignment is independent of the image alignment; empty
			// means it follows the figure (image) alignment.
			$caption_align = isset( $attributes['captionAlign'] ) ? Helper::get_string_value( $attributes['captionAlign'] ) : '';
			if ( ! in_array( $caption_align, [ 'left', 'center', 'right' ], true ) ) {
				$caption_align = '';
			}

			$caption_markup = sprintf(
				'<figcaption class="sd-image-caption%2$s">%1$s</figcaption>',
				esc_html( $caption ),
				$caption_align ? esc_attr( ' sd-image-caption-align-' . $caption_align ) : ''
			);
		}

		$markup = sprintf(
			'<div class="%1$s"><figure class="sd-image-wrap sd-image-align-%2$s">%3$s%4$s</figure></div>',
			esc_attr( $wrapper_classes ),
			esc_attr( $align ),
			$image_markup,
			$caption_markup
		);

		ob_start();
		// Allow the data: protocol so a lazy-load optimizer's inline SVG
		// placeholder in src survives kses (the markup is server-generated).
		echo wp_kses( $markup, self::get_allowed_form_html(), array_merge( wp_allowed_protocols(), [ 'data' ] ) );
		return (string) ob_get_clean();
	}
}
