<?php
/**
 * PHP render for the Campaign Donate Button block.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Blocks\Campaign_Donate_Button;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Campaigns\Campaign_Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign Donate Button block.
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

		$text   = ! empty( $attributes['buttonText'] ) ? (string) $attributes['buttonText'] : __( 'Donate', 'suredonation' );
		$stats  = Campaign_Stats::get_cached_stats( $campaign_id );
		$status = isset( $stats['campaign_status'] ) ? (string) $stats['campaign_status'] : 'active';

		// A paused or completed campaign should not invite new donations.
		$is_active = 'active' === $status;

		if ( ! $is_active ) {
			$disabled_text = 'completed' === $status
				? __( 'Campaign ended', 'suredonation' )
				: __( 'Donations paused', 'suredonation' );

			$wrapper = get_block_wrapper_attributes(
				[ 'class' => 'suredonation-campaign-donate-button suredonation-campaign-donate-button--disabled' ]
			);

			ob_start();
			?>
			<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
				<span class="suredonation-campaign-donate-button__link suredonation-campaign-donate-button__link--disabled" aria-disabled="true">
					<?php echo esc_html( $disabled_text ); ?>
				</span>
			</div>
			<?php
			$output = ob_get_clean();

			return false !== $output ? $output : '';
		}

		$wrapper = get_block_wrapper_attributes( [ 'class' => 'suredonation-campaign-donate-button' ] );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
			<a class="suredonation-campaign-donate-button__link" href="#<?php echo esc_attr( Campaign_Page::FORM_ANCHOR ); ?>">
				<?php echo esc_html( $text ); ?>
			</a>
		</div>
		<?php
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}
}
