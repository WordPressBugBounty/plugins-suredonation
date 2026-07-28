<?php
/**
 * SureDonation Input Markup Class.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureDonation\Inc\Helper;

/**
 * Input Markup Class.
 *
 * @since 0.0.1
 */
class Input_Markup extends Base {
	/**
	 * Maximum length of text allowed for an input field.
	 *
	 * @var int
	 * @since 0.0.1
	 */
	protected $max_length = 100;

	/**
	 * Input mask pattern for the input field.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $input_mask = '';

	/**
	 * Custom input mask pattern for the input field.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $custom_input_mask = '';

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug              = 'input';
		$this->max_length        = isset( $attributes['maxLength'] ) ? absint( Helper::get_string_value( $attributes['maxLength'] ) ) : 100;
		$this->input_mask        = isset( $attributes['inputMask'] ) ? Helper::get_string_value( $attributes['inputMask'] ) : 'none';
		$this->custom_input_mask = 'custom-mask' === $this->input_mask && isset( $attributes['customInputMask'] ) ? Helper::get_string_value( $attributes['customInputMask'] ) : '';

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render input markup
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function markup() {
		$classes   = $this->get_field_classes();
		$aria_desc = $this->get_aria_describedby();

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<?php echo wp_kses_post( $this->label_markup ); ?>
			<?php echo wp_kses_post( $this->help_markup ); ?>
			<div class="sd-block-wrap">
				<input
					class="sd-input-common sd-input-<?php echo esc_attr( $this->slug ); ?>"
					type="text"
					name="<?php echo esc_attr( $this->field_name ); ?>"
					id="<?php echo esc_attr( $this->unique_slug ); ?>"
					data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
					<?php if ( ! empty( $aria_desc ) ) { ?>
						aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
					<?php } ?>
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					maxlength="<?php echo esc_attr( (string) $this->max_length ); ?>"
					<?php if ( '' !== $this->default ) { ?>
						value="<?php echo esc_attr( $this->default ); ?>"
					<?php } ?>
					<?php if ( '' !== $this->placeholder ) { ?>
						placeholder="<?php echo esc_attr( $this->placeholder ); ?>"
					<?php } ?>
					data-sd-mask="<?php echo esc_attr( $this->input_mask ); ?>"
					<?php if ( ! empty( $this->custom_input_mask ) ) { ?>
						data-custom-sd-mask="<?php echo esc_attr( $this->custom_input_mask ); ?>"
					<?php } ?>
				/>
			</div>
			<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
