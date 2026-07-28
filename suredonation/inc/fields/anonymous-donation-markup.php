<?php
/**
 * SureDonation Anonymous Donation Markup Class.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Anonymous Donation Markup Class.
 *
 * @since 0.0.1
 */
class Anonymous_Donation_Markup extends Base {
	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug = 'anonymous-donation';

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render anonymous donation checkbox markup
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
			<div class="sd-block-wrap sd-checkbox-wrap sd-anonymous-donation-wrap">
				<label class="sd-checkbox-label" for="<?php echo esc_attr( $this->unique_slug ); ?>">
					<input
						class="sd-input-checkbox sd-anonymous-donation-input"
						type="checkbox"
						name="<?php echo esc_attr( $this->field_name ); ?>"
						id="<?php echo esc_attr( $this->unique_slug ); ?>"
						value="1"
						<?php if ( ! empty( $aria_desc ) ) { ?>
							aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
						<?php } ?>
						<?php echo esc_attr( $this->checked_attr ); ?>
					/>
					<span class="sd-checkbox-text">
						<?php echo wp_kses_post( $this->label ); ?>
					</span>
				</label>
			</div>
			<?php echo wp_kses_post( $this->help_markup ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
