<?php
/**
 * Form Field Base Class.
 *
 * This file defines the base class for form field markup in the SureDonation package.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Field Base Class
 *
 * Defines the base class for form field markup generation.
 *
 * @since 0.0.1
 */
class Base {
	/**
	 * Stores the attributes of the block.
	 *
	 * @var array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	protected $attributes = [];

	/**
	 * Flag indicating if the field is required.
	 *
	 * @var bool
	 * @since 0.0.1
	 */
	protected $required = false;

	/**
	 * Width of the field (percentage).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $field_width = '';

	/**
	 * Stores the label for the field.
	 *
	 * @var string $label Label used for the input field.
	 * @since 0.0.1
	 */
	protected $label = '';

	/**
	 * Stores the help text.
	 *
	 * @var string $help
	 * @since 0.0.1
	 */
	protected $help = '';

	/**
	 * Validation error message for the field.
	 *
	 * @var string $error_msg Input field validation error message.
	 * @since 0.0.1
	 */
	protected $error_msg = '';

	/**
	 * Unique identifier for the block.
	 *
	 * @var string $block_id Unique identifier representing the block.
	 * @since 0.0.1
	 */
	protected $block_id = '';

	/**
	 * Stores the ID of the form.
	 *
	 * @var string $form_id Form ID.
	 * @since 0.0.1
	 */
	protected $form_id = '';

	/**
	 * Stores the block slug.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $block_slug = '';

	/**
	 * Stores the slug type (e.g., 'input', 'email').
	 *
	 * @var string $slug slug value.
	 * @since 0.0.1
	 */
	protected $slug = '';

	/**
	 * Data-required attribute value.
	 *
	 * @var string $data_require_attr Value of the data-required attribute.
	 * @since 0.0.1
	 */
	protected $data_require_attr = 'false';

	/**
	 * CSS class for block width.
	 *
	 * @var string $block_width The CSS class for block width.
	 * @since 0.0.1
	 */
	protected $block_width = '';

	/**
	 * Stores custom class names.
	 *
	 * @var string $class_name The value of the class name attribute.
	 * @since 0.0.1
	 */
	protected $class_name = '';

	/**
	 * Stores the placeholder text.
	 *
	 * @var string $placeholder HTML field placeholder.
	 * @since 0.0.1
	 */
	protected $placeholder = '';

	/**
	 * Stores the HTML placeholder attribute.
	 *
	 * @var string $placeholder_attr HTML field placeholder attribute.
	 * @since 0.0.1
	 */
	protected $placeholder_attr = '';

	/**
	 * Default value for the field.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $default = '';

	/**
	 * HTML attribute string for the default value.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $default_value_attr = '';

	/**
	 * Unique slug combining slug and block ID.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $unique_slug = '';

	/**
	 * Stores the field name for form submission.
	 *
	 * @var string $field_name HTML field name.
	 * @since 0.0.1
	 */
	protected $field_name = '';

	/**
	 * Options for select/checkbox/radio fields.
	 *
	 * @var array<mixed>
	 * @since 0.0.1
	 */
	protected $options = [];

	/**
	 * Checked state for the field.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $checked = '';

	/**
	 * HTML attribute string for the checked state.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $checked_attr = '';

	/**
	 * Stores the help text markup.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $help_markup = '';

	/**
	 * Stores the error message markup.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $error_msg_markup = '';

	/**
	 * Stores the HTML label markup.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $label_markup = '';

	/**
	 * Render the field markup.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public function markup() {
		return '';
	}

	/**
	 * Get CSS classes for the field wrapper.
	 *
	 * @param array<string> $extra_classes Extra classes to be added.
	 * @since 0.0.1
	 * @return string
	 */
	public function get_field_classes( $extra_classes = [] ) {
		$common_classes = [
			'sd-block-single',
			'sd-block',
			"sd-{$this->slug}-block",
			"sd-{$this->slug}-{$this->block_id}-block",
			$this->block_width,
			$this->class_name,
		];

		if ( $this->block_slug ) {
			$common_classes[] = "sd-slug-{$this->block_slug}";
		}

		if ( ! empty( $extra_classes ) && is_array( $extra_classes ) ) {
			$common_classes = array_merge( $common_classes, $extra_classes );
		}

		return Helper::join_strings( $common_classes );
	}

	/**
	 * Setter for the properties of class based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 * @return void
	 */
	protected function set_properties( $attributes ) {
		$this->attributes         = $attributes;
		$this->required           = ! empty( $attributes['required'] );
		$this->field_width        = isset( $attributes['fieldWidth'] ) ? Helper::get_string_value( $attributes['fieldWidth'] ) : '';
		$this->label              = isset( $attributes['label'] ) ? Helper::get_string_value( $attributes['label'] ) : '';
		$this->help               = isset( $attributes['help'] ) ? Helper::get_string_value( $attributes['help'] ) : '';
		$this->error_msg          = isset( $attributes['errorMsg'] ) ? Helper::get_string_value( $attributes['errorMsg'] ) : '';
		$this->block_id           = isset( $attributes['block_id'] ) ? Helper::get_string_value( $attributes['block_id'] ) : '';
		$this->form_id            = isset( $attributes['formId'] ) ? Helper::get_string_value( $attributes['formId'] ) : '';
		$this->block_slug         = isset( $attributes['slug'] ) ? Helper::get_string_value( $attributes['slug'] ) : '';
		$this->placeholder        = isset( $attributes['placeholder'] ) ? Helper::get_string_value( $attributes['placeholder'] ) : '';
		$this->default            = isset( $attributes['defaultValue'] ) ? Helper::get_string_value( $attributes['defaultValue'] ) : '';
		$this->checked            = isset( $attributes['checked'] ) ? Helper::get_string_value( $attributes['checked'] ) : '';
		$this->options            = isset( $attributes['options'] ) && is_array( $attributes['options'] ) ? $attributes['options'] : [];
		$this->class_name         = isset( $attributes['className'] ) && is_string( $attributes['className'] ) ? ' ' . $attributes['className'] : '';
		$this->data_require_attr  = $this->required ? 'true' : 'false';
		$this->block_width        = $this->field_width ? ' sd-block-width-' . str_replace( '.', '-', $this->field_width ) : '';
		$this->placeholder_attr   = '' !== $this->placeholder ? ' placeholder="' . esc_attr( $this->placeholder ) . '" ' : '';
		$this->default_value_attr = '' !== $this->default ? ' value="' . esc_attr( $this->default ) . '" ' : '';
		$this->checked_attr       = $this->checked ? 'checked' : '';
	}

	/**
	 * Set the unique slug for the field.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	protected function set_unique_slug() {
		$this->unique_slug = 'sd-' . $this->slug . '-' . $this->block_id;
		$this->field_name  = $this->unique_slug;
	}

	/**
	 * Set markup properties (label, help, error).
	 *
	 * @since 0.0.1
	 * @return void
	 */
	protected function set_markup_properties() {
		$this->label_markup     = $this->generate_label_markup();
		$this->help_markup      = $this->generate_help_markup();
		$this->error_msg_markup = $this->generate_error_markup();
	}

	/**
	 * Generate label markup.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	protected function generate_label_markup() {
		if ( empty( $this->label ) ) {
			return '';
		}

		$required_mark = $this->required ? '<span class="sd-required" aria-hidden="true">*</span>' : '';

		return sprintf(
			'<label for="%s" class="sd-label">%s%s</label>',
			esc_attr( $this->unique_slug ),
			esc_html( $this->label ),
			$required_mark
		);
	}

	/**
	 * Generate help text markup.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	protected function generate_help_markup() {
		if ( empty( $this->help ) ) {
			return '';
		}

		return sprintf(
			'<p id="sd-help-%s" class="sd-help">%s</p>',
			esc_attr( $this->block_id ),
			esc_html( $this->help )
		);
	}

	/**
	 * Generate error message markup.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	protected function generate_error_markup() {
		$error_text = $this->error_msg ? $this->error_msg : $this->default_required_message();

		return sprintf(
			'<p id="sd-error-%s" class="sd-error" role="alert" style="display: none;">%s</p>',
			esc_attr( $this->block_id ),
			esc_html( $error_text )
		);
	}

	/**
	 * Resolve the default required-field message for this field type.
	 *
	 * Mirrors the server-side resolution so the pre-rendered message matches
	 * what the server would return: the admin-configured global default (Global
	 * Settings → Form Validation) for the field type, falling back to a generic
	 * message for field types without a configurable default.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function default_required_message() {
		// Derive the key via the same helper the server uses, so the key is
		// normalized identically (e.g. hyphenated field types) and the editor /
		// markup / server never resolve different keys for the same field.
		$message = \SureDonation\Inc\Field_Validation::get_validation_message(
			\SureDonation\Inc\Field_Validation::required_message_key( 'suredonation/' . $this->slug )
		);

		return '' !== $message ? $message : __( 'This field is required.', 'suredonation' );
	}

	/**
	 * Get aria-describedby attribute value.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	protected function get_aria_describedby() {
		$describedby = [];

		if ( ! empty( $this->help ) ) {
			$describedby[] = 'sd-help-' . $this->block_id;
		}

		$describedby[] = 'sd-error-' . $this->block_id;

		return implode( ' ', $describedby );
	}

	/**
	 * Darken a hex color by a percentage.
	 *
	 * @param string $hex     Hex color code.
	 * @param int    $percent Percentage to darken (0-100).
	 * @return string Darkened hex color.
	 * @since 0.0.1
	 */
	protected function darken_color( $hex, $percent = 15 ) {
		$hex = ltrim( $hex, '#' );

		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		$r = max( 0, $r - ( $r * $percent / 100 ) );
		$g = max( 0, $g - ( $g * $percent / 100 ) );
		$b = max( 0, $b - ( $b * $percent / 100 ) );

		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Lighten a hex color by a percentage.
	 *
	 * @param string $hex     Hex color code.
	 * @param int    $percent Percentage to lighten (0-100).
	 * @return string Lightened hex color.
	 * @since 0.0.1
	 */
	protected function lighten_color( $hex, $percent = 90 ) {
		$hex = ltrim( $hex, '#' );

		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		$r = min( 255, $r + ( ( 255 - $r ) * $percent / 100 ) );
		$g = min( 255, $g + ( ( 255 - $g ) * $percent / 100 ) );
		$b = min( 255, $b + ( ( 255 - $b ) * $percent / 100 ) );

		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Get the checked/unchecked SVG icon for a radio or checkbox option.
	 *
	 * Shared by every field that renders the option-pill markup (the donation
	 * amount choices and the payment-type chooser), so the two cannot drift apart.
	 *
	 * @param string $type Icon type (circle-checked, circle-unchecked, square-checked, square-unchecked).
	 * @param string $classes CSS class.
	 * @return string SVG markup.
	 * @since 1.5.1
	 */
	protected function get_svg_icon( $type, $classes = '' ) {
		$class_attr = $classes ? ' class="' . esc_attr( $classes ) . '"' : '';

		switch ( $type ) {
			case 'circle-checked':
				return '<svg' . $class_attr . ' width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M15.1663 7.38674V8.00007C15.1655 9.43769 14.7 10.8365 13.8392 11.988C12.9785 13.1394 11.7685 13.9817 10.3899 14.3893C9.0113 14.797 7.53785 14.748 6.18932 14.2498C4.8408 13.7516 3.68944 12.8308 2.90698 11.6248C2.12452 10.4188 1.75287 8.99211 1.84746 7.55761C1.94205 6.12312 2.49781 4.75762 3.43186 3.66479C4.36591 2.57195 5.6282 1.81033 7.03047 1.4935C8.43274 1.17668 9.89985 1.32163 11.213 1.90674" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M15.1667 2.6665L8.5 9.33984L6.5 7.33984" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';
			case 'circle-unchecked':
				return '<svg' . $class_attr . ' width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M7.99967 14.6668C11.6816 14.6668 14.6663 11.6821 14.6663 8.00016C14.6663 4.31826 11.6816 1.3335 7.99967 1.3335C4.31778 1.3335 1.33301 4.31826 1.33301 8.00016C1.33301 11.6821 4.31778 14.6668 7.99967 14.6668Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';
			case 'square-checked':
				return '<svg' . $class_attr . ' width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M6.5 7.33366L8.5 9.33366L15.1667 2.66699" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M14.5 8V12.6667C14.5 13.0203 14.3595 13.3594 14.1095 13.6095C13.8594 13.8595 13.5203 14 13.1667 14H3.83333C3.47971 14 3.14057 13.8595 2.89052 13.6095C2.64048 13.3594 2.5 13.0203 2.5 12.6667V3.33333C2.5 2.97971 2.64048 2.64057 2.89052 2.39052C3.14057 2.14048 3.47971 2 3.83333 2H11.1667" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';
			case 'square-unchecked':
				return '<svg' . $class_attr . ' width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M12.6667 2H3.33333C2.59695 2 2 2.59695 2 3.33333V12.6667C2 13.403 2.59695 14 3.33333 14H12.6667C13.403 14 14 13.403 14 12.6667V3.33333C14 2.59695 13.403 2 12.6667 2Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';
			default:
				return '';
		}
	}
}
