<?php
/**
 * SureDonation Number Markup Class.
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
 * Number Markup Class.
 *
 * @since 0.0.1
 */
class Number_Markup extends Base {
	/**
	 * Minimum value allowed.
	 *
	 * @var int
	 * @since 0.0.1
	 */
	protected $min_value;

	/**
	 * Maximum value allowed (0 = no limit).
	 *
	 * @var int
	 * @since 0.0.1
	 */
	protected $max_value;

	/**
	 * Step value for number input.
	 *
	 * @var int|float
	 * @since 0.0.1
	 */
	protected $step;

	/**
	 * Whether to show currency symbol.
	 *
	 * @var bool
	 * @since 0.0.1
	 */
	protected $show_currency;

	/**
	 * Currency symbol.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $currency_symbol;

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug          = 'number';
		$this->min_value     = isset( $attributes['minValue'] ) ? absint( Helper::get_string_value( $attributes['minValue'] ) ) : 1;
		$this->max_value     = isset( $attributes['maxValue'] ) ? absint( Helper::get_string_value( $attributes['maxValue'] ) ) : 0;
		$this->step          = isset( $attributes['step'] ) ? (float) Helper::get_string_value( $attributes['step'] ) : 1;
		$this->show_currency = ! empty( $attributes['showCurrency'] );

		// Get currency symbol from settings.
		$this->currency_symbol = Helper::get_string_value( Helper::get_suredonation_option( 'currency_symbol', '$' ) );

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render number markup
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function markup() {
		$classes   = $this->get_field_classes( $this->show_currency ? [ 'sd-has-currency' ] : [] );
		$aria_desc = $this->get_aria_describedby();

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<?php echo wp_kses_post( $this->label_markup ); ?>
			<?php echo wp_kses_post( $this->help_markup ); ?>
			<div class="sd-block-wrap sd-number-wrap">
				<?php if ( $this->show_currency ) { ?>
					<span class="sd-currency-symbol"><?php echo esc_html( $this->currency_symbol ); ?></span>
				<?php } ?>
				<input
					class="sd-input-common sd-input-<?php echo esc_attr( $this->slug ); ?>"
					type="number"
					name="<?php echo esc_attr( $this->field_name ); ?>"
					id="<?php echo esc_attr( $this->unique_slug ); ?>"
					data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
					<?php if ( ! empty( $aria_desc ) ) { ?>
						aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
					<?php } ?>
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					min="<?php echo esc_attr( (string) $this->min_value ); ?>"
					<?php if ( $this->max_value > 0 ) { ?>
						max="<?php echo esc_attr( (string) $this->max_value ); ?>"
					<?php } ?>
					step="<?php echo esc_attr( (string) $this->step ); ?>"
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
