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

		// Position the symbol per the global setting while keeping the raw
		// value (presets intentionally show "$3", not "$3.00").
		return Payment_Helper::position_currency_symbol( $symbol, $trimmed );
	}
}
