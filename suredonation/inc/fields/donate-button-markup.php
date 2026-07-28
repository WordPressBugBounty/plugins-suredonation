<?php
/**
 * SureDonation Donate Button Markup Class file.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SureDonation Donate Button Markup Class.
 *
 * @since 0.0.1
 */
class Donate_Button_Markup extends Base {
	/**
	 * Text displayed on the button.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $button_text;

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug        = 'donate-button';
		$this->button_text = $attributes['buttonText'] ?? __( 'Donate Now', 'suredonation' );

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render donate button markup
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function markup() {
		$classes = $this->get_field_classes();

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<button
				type="submit"
				id="sd-submit-btn"
				class="sd-submit-button"
			>
				<div class="sd-submit-wrap">
					<span class="sd-button-text"><?php echo esc_html( $this->button_text ); ?></span>
					<span class="sd-button-spinner" style="display: none;">
						<svg class="sd-spinner" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle class="sd-spinner-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
							<path class="sd-spinner-head" d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
						</svg>
					</span>
				</div>
			</button>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
