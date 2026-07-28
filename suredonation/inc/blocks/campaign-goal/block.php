<?php
/**
 * PHP render for the Campaign Goal block.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Blocks\Campaign_Goal;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Campaigns\Campaign_Stats;
use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign Goal block.
 *
 * @since 1.0.0
 */
class Block extends Base {
	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 1.0.0
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content );

		$campaign_id = Campaign_Page::resolve_campaign_id( $attributes );
		if ( ! $campaign_id ) {
			return '';
		}

		wp_enqueue_style( 'suredonation-campaign-blocks' );

		$stats    = Campaign_Stats::get_cached_stats( $campaign_id );
		$is_count = 'donation_count' === $stats['goal_type'];

		$raised_value = $is_count
			? number_format_i18n( $stats['donation_count'] )
			: Payment_Helper::format_amount( $stats['total_raised'] );

		$goal_value = $is_count
			? number_format_i18n( $stats['goal_amount'] )
			: Payment_Helper::format_amount( $stats['goal_amount'] );

		$percentage    = (float) $stats['progress_percentage'];
		$show_progress = ! isset( $attributes['showProgressBar'] ) || $attributes['showProgressBar'];

		$wrapper = get_block_wrapper_attributes( [ 'class' => 'suredonation-campaign-goal' ] );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
			<div class="suredonation-campaign-goal__amounts">
				<span class="suredonation-campaign-goal__raised"><?php echo esc_html( $raised_value ); ?></span>
				<span class="suredonation-campaign-goal__goal">
					<?php
					/* translators: %s: formatted goal amount or count. */
					printf( esc_html__( 'raised of %s goal', 'suredonation' ), esc_html( $goal_value ) );
					?>
				</span>
			</div>
			<?php if ( $show_progress ) : ?>
				<div class="suredonation-campaign-goal__bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) round( $percentage ) ); ?>" aria-valuemin="0" aria-valuemax="100">
					<span class="suredonation-campaign-goal__bar-fill" style="width:<?php echo esc_attr( (string) min( 100, $percentage ) ); ?>%"></span>
				</div>
			<?php endif; ?>
			<div class="suredonation-campaign-goal__meta">
				<span><?php echo esc_html( number_format_i18n( $stats['donor_count'] ) ); ?> <?php echo esc_html( _n( 'donor', 'donors', (int) $stats['donor_count'], 'suredonation' ) ); ?></span>
				<span><?php echo esc_html( (string) round( $percentage ) ); ?>%</span>
			</div>
		</div>
		<?php
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}
}
