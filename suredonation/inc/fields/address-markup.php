<?php
/**
 * SureDonation Address Markup Class.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Address Markup Class.
 *
 * The address field is a container: its sub-fields (street, city, state, postal
 * code, country) are real suredonation/input and suredonation/dropdown inner
 * blocks that render and validate themselves. This class only renders the
 * fieldset/legend wrapper around that inner block content.
 *
 * @since 1.1.1
 */
class Address_Markup extends Base {
	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 1.1.1
	 */
	public function __construct( $attributes ) {
		$this->slug = 'address';

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render address markup.
	 *
	 * @param string $content Inner block content (the rendered sub-fields).
	 * @since 1.1.1
	 * @return string
	 */
	public function markup( $content = '' ) {
		$classes = $this->get_field_classes();

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<fieldset>
				<?php if ( '' !== $this->label ) { ?>
					<legend class="sd-block-legend sd-label">
						<?php echo esc_html( $this->label ); ?>
					</legend>
				<?php } ?>
				<?php echo wp_kses_post( $this->help_markup ); ?>
				<div class="sd-block-wrap sd-address-fields">
					<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner block content already rendered and escaped by each child block. ?>
				</div>
			</fieldset>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
