<?php
/**
 * SureDonation Checkbox Markup Class.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Checkbox Markup Class.
 *
 * @since 0.0.1
 */
class Checkbox_Markup extends Base {
	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug = 'checkbox';

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render checkbox markup
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function markup() {
		$classes       = $this->get_field_classes();
		$aria_desc     = $this->get_aria_describedby();
		$required_mark = $this->required ? '<span class="sd-required" aria-hidden="true">*</span>' : '';

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<div class="sd-block-wrap sd-checkbox-wrap">
				<label class="sd-checkbox-label" for="<?php echo esc_attr( $this->unique_slug ); ?>">
					<input
						class="sd-input-checkbox"
						type="checkbox"
						name="<?php echo esc_attr( $this->field_name ); ?>"
						id="<?php echo esc_attr( $this->unique_slug ); ?>"
						value="1"
						<?php if ( ! empty( $aria_desc ) ) { ?>
							aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
						<?php } ?>
						data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
						aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
						<?php echo esc_attr( $this->checked_attr ); ?>
					/>
					<span class="sd-checkbox-text">
						<?php echo wp_kses_post( $this->label ); ?>
						<?php echo wp_kses_post( $required_mark ); ?>
					</span>
				</label>
			</div>
			<?php echo wp_kses_post( $this->help_markup ); ?>
			<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
