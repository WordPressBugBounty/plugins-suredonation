<?php
/**
 * SureDonation Cover Fees Markup Class.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;

/**
 * Cover Fees Markup Class.
 *
 * @since 0.0.1
 */
class Cover_Fees_Markup extends Base {
	/**
	 * Fee percentage (e.g., 2.9 for Stripe).
	 *
	 * @var float
	 */
	protected $fee_percentage;

	/**
	 * Fixed fee amount (e.g., 0.30 for Stripe).
	 *
	 * @var float
	 */
	protected $fee_fixed;

	/**
	 * Fee mode: 'all_gateways' or 'per_gateway'.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $fee_mode;

	/**
	 * Per-gateway fee configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	protected $gateway_fees;

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug = 'cover-fees';

		$use_global = $attributes['useGlobalDefaults'] ?? true;

		if ( $use_global ) {
			$fee_config = Payment_Helper::get_fee_recovery_settings();
		} else {
			$fee_config = [
				'fee_percentage' => Helper::get_float_value( $attributes['feePercentage'] ?? 2.9 ),
				'fee_fixed'      => Helper::get_float_value( $attributes['feeFixed'] ?? 0.30 ),
				'fee_mode'       => $attributes['feeMode'] ?? 'all_gateways',
				'gateways'       => $attributes['gatewayFees'] ?? [],
			];
		}

		$this->fee_mode       = is_string( $fee_config['fee_mode'] ?? null ) ? $fee_config['fee_mode'] : 'all_gateways';
		$this->fee_percentage = is_numeric( $fee_config['fee_percentage'] ?? null ) ? (float) $fee_config['fee_percentage'] : 2.9;
		$this->fee_fixed      = is_numeric( $fee_config['fee_fixed'] ?? null ) ? (float) $fee_config['fee_fixed'] : 0.30;
		$this->gateway_fees   = is_array( $fee_config['gateways'] ?? null ) ? $fee_config['gateways'] : [];

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render cover fees checkbox markup
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
			<div class="sd-block-wrap sd-checkbox-wrap sd-cover-fees-wrap">
				<label class="sd-checkbox-label" for="<?php echo esc_attr( $this->unique_slug ); ?>">
					<input
						class="sd-input-checkbox sd-cover-fees-input"
						type="checkbox"
						name="<?php echo esc_attr( $this->field_name ); ?>"
						id="<?php echo esc_attr( $this->unique_slug ); ?>"
						value="1"
						data-fee-percentage="<?php echo esc_attr( Helper::get_string_value( $this->fee_percentage ) ); ?>"
						data-fee-fixed="<?php echo esc_attr( Helper::get_string_value( $this->fee_fixed ) ); ?>"
						data-fee-mode="<?php echo esc_attr( $this->fee_mode ); ?>"
						<?php if ( 'per_gateway' === $this->fee_mode && ! empty( $this->gateway_fees ) ) { ?>
							data-gateway-fees="<?php echo esc_attr( (string) wp_json_encode( $this->gateway_fees ) ); ?>"
						<?php } ?>
						<?php if ( ! empty( $aria_desc ) ) { ?>
							aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
						<?php } ?>
						<?php echo esc_attr( $this->checked_attr ); ?>
					/>
					<span class="sd-checkbox-text">
						<?php echo wp_kses_post( $this->label ); ?>
						<span class="sd-cover-fees-amount"></span>
					</span>
				</label>
			</div>
			<?php echo wp_kses_post( $this->help_markup ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
