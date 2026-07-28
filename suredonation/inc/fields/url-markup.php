<?php
/**
 * SureDonation Url Markup Class.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureDonation\Inc\Helper;

/**
 * Url Markup Class.
 *
 * @since 1.1.1
 */
class Url_Markup extends Base {
	/**
	 * Invalid URL error message.
	 *
	 * @var string
	 * @since 1.1.1
	 */
	protected $invalid_url_msg = '';

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 1.1.1
	 */
	public function __construct( $attributes ) {
		$this->slug = 'url';

		$invalid_url_msg       = isset( $attributes['invalidUrlMsg'] ) ? Helper::get_string_value( $attributes['invalidUrlMsg'] ) : '';
		$this->invalid_url_msg = '' !== $invalid_url_msg ? $invalid_url_msg : __( 'Please enter a valid URL.', 'suredonation' );

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render url markup
	 *
	 * @since 1.1.1
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
					type="url"
					name="<?php echo esc_attr( $this->field_name ); ?>"
					id="<?php echo esc_attr( $this->unique_slug ); ?>"
					data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
					<?php if ( ! empty( $aria_desc ) ) { ?>
						aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
					<?php } ?>
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					data-invalid-url-msg="<?php echo esc_attr( $this->invalid_url_msg ); ?>"
					<?php if ( '' !== $this->default ) { ?>
						value="<?php echo esc_attr( $this->default ); ?>"
					<?php } ?>
					<?php if ( '' !== $this->placeholder ) { ?>
						placeholder="<?php echo esc_attr( $this->placeholder ); ?>"
					<?php } ?>
				/>
			</div>
			<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
