<?php
/**
 * The blocks base file.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Blocks;

use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Block base class.
 *
 * @since 0.0.1
 */
abstract class Base {
	/**
	 * Optional directory to .json block data files.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $directory = '';

	/**
	 * Register the block for dynamic output
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register() {
		register_block_type(
			$this->get_dir(),
			apply_filters(
				'suredonation_block_registration_args',
				[ 'render_callback' => [ $this, 'render' ] ]
			)
		);
	}

	/**
	 * Get the called class directory path
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public function get_dir() {
		if ( $this->directory ) {
			return $this->directory;
		}

		$reflector = new \ReflectionClass( $this );
		$fn        = (string) $reflector->getFileName();
		return dirname( $fn );
	}

	/**
	 * Render the block
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content ); // Unused parameter in base class.
		unset( $attributes ); // Unused parameter in base class.
		return '';
	}

	/**
	 * Get allowed HTML tags for form markup.
	 *
	 * The wp_kses_post() doesn't allow form elements, so we need a custom allowed tags array.
	 * This is safe because the markup is generated internally by trusted code that already
	 * escapes user input with esc_attr(), esc_html(), etc.
	 *
	 * @return array<string, array<string, bool>> Allowed HTML tags and attributes.
	 * @since 0.0.1
	 */
	protected static function get_allowed_form_html() {
		return Helper::get_allowed_form_html();
	}

	/**
	 * Build the wrapper class string shared by display blocks (Heading, HTML).
	 *
	 * Display blocks have no field name, so they intentionally omit the
	 * per-instance `sd-{slug}-{block_id}-block` class that field blocks carry.
	 * This derives the common width/className/slug classes and prepends the
	 * block-specific base class.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $base_class Block-specific base class (e.g. 'sd-heading-block').
	 * @return string Joined wrapper class string.
	 * @since 1.1.1
	 */
	protected static function get_display_block_classes( $attributes, $base_class ) {
		$field_width = isset( $attributes['fieldWidth'] ) ? Helper::get_string_value( $attributes['fieldWidth'] ) : '';
		$block_width = $field_width ? ' sd-block-width-' . str_replace( '.', '-', $field_width ) : '';
		$class_name  = isset( $attributes['className'] ) && is_string( $attributes['className'] ) ? ' ' . $attributes['className'] : '';
		$block_slug  = isset( $attributes['slug'] ) && is_string( $attributes['slug'] ) ? $attributes['slug'] : '';
		$slug_class  = $block_slug ? ' sd-slug-' . $block_slug : '';

		return Helper::join_strings(
			[
				'sd-block-single',
				'sd-block',
				$base_class,
				$block_width,
				$class_name,
				$slug_class,
			]
		);
	}
}
