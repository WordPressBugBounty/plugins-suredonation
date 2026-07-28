<?php
/**
 * SureDonation Email Markup Class.
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
 * Email Markup Class.
 *
 * @since 0.0.1
 */
class Email_Markup extends Base {
	/**
	 * Invalid email error message.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $invalid_email_msg = '';

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug              = 'email';
		$this->invalid_email_msg = Helper::get_string_value( $attributes['invalidEmailMsg'] ) ?? __( 'Please enter a valid email address.', 'suredonation' );

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render email markup
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
					type="email"
					name="<?php echo esc_attr( $this->field_name ); ?>"
					id="<?php echo esc_attr( $this->unique_slug ); ?>"
					data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
					<?php if ( ! empty( $aria_desc ) ) { ?>
						aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
					<?php } ?>
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					data-invalid-email-msg="<?php echo esc_attr( $this->invalid_email_msg ); ?>"
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
