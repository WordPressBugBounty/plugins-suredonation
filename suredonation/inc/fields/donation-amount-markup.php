<?php
/**
 * SureDonation Donation Amount Markup Class.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donation Amount Markup Class.
 *
 * @since 0.0.1
 */
class Donation_Amount_Markup extends Base {
	/**
	 * Choice type (radio or checkbox).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $choice_type;

	/**
	 * Layout (horizontal or vertical).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $layout;

	/**
	 * Choice width (width of each option in horizontal layout).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $choice_width;

	/**
	 * Whether the optional custom amount input is shown after the presets.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	protected $allow_custom_amount;

	/**
	 * Minimum allowed value for the custom amount input. 0 means no min.
	 *
	 * @var float
	 * @since 1.0.0
	 */
	protected $custom_amount_min;

	/**
	 * Maximum allowed value for the custom amount input. 0 means no max.
	 *
	 * @var float
	 * @since 1.0.0
	 */
	protected $custom_amount_max;

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug                = 'donation-amount';
		$this->choice_type         = isset( $attributes['choiceType'] ) ? Helper::get_string_value( $attributes['choiceType'] ) : 'radio';
		$this->layout              = isset( $attributes['layout'] ) ? Helper::get_string_value( $attributes['layout'] ) : 'horizontal';
		$this->choice_width        = isset( $attributes['choiceWidth'] ) ? Helper::get_string_value( $attributes['choiceWidth'] ) : '50';
		$this->allow_custom_amount = ! isset( $attributes['allowCustomAmount'] ) || (bool) $attributes['allowCustomAmount'];
		$this->custom_amount_min   = isset( $attributes['customAmountMin'] ) && is_numeric( $attributes['customAmountMin'] )
			? (float) $attributes['customAmountMin']
			: 0.0;
		$this->custom_amount_max   = isset( $attributes['customAmountMax'] ) && is_numeric( $attributes['customAmountMax'] )
			? (float) $attributes['customAmountMax']
			: 0.0;

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render donation-amount markup
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function markup() {
		$classes            = $this->get_field_classes( [ 'sd-' . $this->choice_type . '-mode' ] );
		$vertical_class     = 'vertical' === $this->layout ? ' sd-vertical-layout' : '';
		$choice_width_class = ' sd-choice-width-' . str_replace( '.', '-', $this->choice_width );
		$wrap_class         = 'sd-block-wrap sd-donation-amount-wrap' . $choice_width_class . $vertical_class;
		$svg_type           = 'radio' === $this->choice_type ? 'circle' : 'square';
		$checked_svg        = $this->get_svg_icon( $svg_type . '-checked', 'sd-donation-amount-icon' );
		$unchecked_svg      = $this->get_svg_icon( $svg_type . '-unchecked', 'sd-donation-amount-icon-unchecked' );
		$input_name         = 'checkbox' === $this->choice_type ? $this->field_name . '[]' : $this->field_name;

		// Get the default value for the hidden input (preselected option).
		$hidden_value = '';
		if ( ! empty( $this->default ) && ! empty( $this->options ) && is_array( $this->options ) ) {
			foreach ( $this->options as $option ) {
				$option_value = $option['value'] ?? ( $option['label'] ?? '' );
				if ( $this->default === $option_value ) {
					$hidden_value = $option_value;
					break;
				}
			}
		}

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<fieldset>
				<!-- Hidden input to store the selected value for use by payment block and form submission -->
				<input
					type="hidden"
					class="sd-input-donation-amount-hidden sd-input-common"
					name="<?php echo esc_attr( $this->field_name ); ?>-value"
					data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					value="<?php echo esc_attr( $hidden_value ); ?>"
				/>
				<legend class="sd-block-legend">
					<?php echo wp_kses_post( $this->label_markup ); ?>
				</legend>
				<?php echo wp_kses_post( $this->help_markup ); ?>
				<?php
				$show_custom_amount = $this->allow_custom_amount && 'radio' === $this->choice_type;
				?>
				<?php if ( ! empty( $this->options ) && is_array( $this->options ) ) { ?>
					<div class="<?php echo esc_attr( $wrap_class ); ?>" role="group" aria-labelledby="sd-label-<?php echo esc_attr( $this->block_id ); ?>">
						<?php foreach ( $this->options as $index => $option ) { ?>
							<?php
							$option_label = $option['label'] ?? '';
							$option_value = $option['value'] ?? $option_label;
							$option_id    = $this->unique_slug . '-' . $index;
							$is_checked   = $this->default === $option_value;
							$checked_attr = $is_checked ? 'checked' : '';
							?>
							<div class="sd-donation-amount-single">
								<input
									type="<?php echo esc_attr( $this->choice_type ); ?>"
									id="<?php echo esc_attr( $option_id ); ?>"
									name="<?php echo esc_attr( $input_name ); ?>"
									value="<?php echo esc_attr( $option_value ); ?>"
									class="sd-input-<?php echo esc_attr( $this->choice_type ); ?>"
									data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
									<?php echo esc_attr( $checked_attr ); ?>
								/>
								<div class="sd-block-content-wrap">
									<div class="sd-option-container">
										<label for="<?php echo esc_attr( $option_id ); ?>"><?php echo esc_html( $this->format_option_label( $option_label ) ); ?></label>
									</div>
									<div class="sd-icon-container">
										<?php
										$allowed_svg = [
											'svg'    => [
												'class'   => true,
												'width'   => true,
												'height'  => true,
												'viewbox' => true,
												'fill'    => true,
												'xmlns'   => true,
												'aria-hidden' => true,
											],
											'circle' => [
												'cx'     => true,
												'cy'     => true,
												'r'      => true,
												'stroke' => true,
												'stroke-width' => true,
												'fill'   => true,
											],
											'rect'   => [
												'x'      => true,
												'y'      => true,
												'width'  => true,
												'height' => true,
												'rx'     => true,
												'stroke' => true,
												'stroke-width' => true,
											],
											'path'   => [
												'd'      => true,
												'stroke' => true,
												'stroke-width' => true,
												'stroke-linecap' => true,
												'stroke-linejoin' => true,
											],
										];
										echo wp_kses( $checked_svg, $allowed_svg );
										echo wp_kses( $unchecked_svg, $allowed_svg );
										?>
									</div>
								</div>
							</div>
						<?php } ?>
						<?php if ( $show_custom_amount ) { ?>
							<div class="sd-donation-amount-single sd-donation-amount-custom">
								<input
									type="number"
									id="sd-custom-amount-<?php echo esc_attr( $this->block_id ); ?>"
									class="sd-input-common sd-donation-amount-custom-input"
									data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
									step="0.01"
									<?php if ( $this->custom_amount_min > 0 ) { ?>
										min="<?php echo esc_attr( (string) $this->custom_amount_min ); ?>"
									<?php } ?>
									<?php if ( $this->custom_amount_max > 0 ) { ?>
										max="<?php echo esc_attr( (string) $this->custom_amount_max ); ?>"
									<?php } ?>
									placeholder="<?php esc_attr_e( 'Enter custom amount', 'suredonation' ); ?>"
									aria-label="<?php esc_attr_e( 'Enter custom amount', 'suredonation' ); ?>"
								/>
							</div>
						<?php } ?>
					</div>
				<?php } ?>
				<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
			</fieldset>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get SVG icon for radio/checkbox
	 *
	 * @param string $type Icon type (circle-checked, circle-unchecked, square-checked, square-unchecked).
	 * @param string $classes CSS class.
	 * @return string SVG markup.
	 * @since 0.0.1
	 */
	private function get_svg_icon( $type, $classes = '' ) {
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

	/**
	 * Format an option label — if the label is purely numeric, prepend the
	 * configured currency symbol. Custom (non-numeric) labels render as-is.
	 *
	 * @param string $label Raw option label.
	 * @return string Display label.
	 * @since 1.0.0
	 */
	private function format_option_label( $label ) {
		$trimmed = trim( $label );

		if ( '' === $trimmed || ! is_numeric( $trimmed ) ) {
			return $label;
		}

		$currency = Payment_Helper::get_global_setting( 'currency', 'USD' );
		$symbol   = Payment_Helper::get_currency_symbol( is_string( $currency ) ? $currency : 'USD' );

		return $symbol . $trimmed;
	}
}
