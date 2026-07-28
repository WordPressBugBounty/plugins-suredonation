<?php
/**
 * PHP render for the Campaign Statistic block.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Blocks\Campaign_Stats;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Campaigns\Campaign_Stats as Stats;
use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign Statistic block.
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

		$statistic = isset( $attributes['statistic'] ) ? (string) $attributes['statistic'] : 'average-donation';
		$stats     = Stats::get_cached_stats( $campaign_id );

		$config = self::get_statistic_config( $statistic, $stats );

		$wrapper = get_block_wrapper_attributes( [ 'class' => 'suredonation-campaign-stat suredonation-campaign-stat--' . sanitize_html_class( $statistic ) ] );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
			<span class="suredonation-campaign-stat__value"><?php echo esc_html( $config['value'] ); ?></span>
			<span class="suredonation-campaign-stat__label"><?php echo esc_html( $config['label'] ); ?></span>
		</div>
		<?php
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}

	/**
	 * Resolve the display value and label for a given statistic.
	 *
	 * @param string               $statistic Statistic key.
	 * @param array<string, mixed> $stats     Campaign stats.
	 * @return array{value:string,label:string}
	 * @since 1.0.0
	 */
	private static function get_statistic_config( $statistic, $stats ) {
		switch ( $statistic ) {
			case 'total-raised':
				return [
					'value' => Payment_Helper::format_amount( $stats['total_raised'] ),
					'label' => __( 'Total Raised', 'suredonation' ),
				];
			case 'top-donation':
				return [
					'value' => Payment_Helper::format_amount( $stats['largest_donation'] ),
					'label' => __( 'Top Donation', 'suredonation' ),
				];
			case 'donor-count':
				return [
					'value' => number_format_i18n( $stats['donor_count'] ),
					'label' => __( 'Donors', 'suredonation' ),
				];
			case 'donation-count':
				return [
					'value' => number_format_i18n( $stats['donation_count'] ),
					'label' => __( 'Donations', 'suredonation' ),
				];
			case 'average-donation':
			default:
				return [
					'value' => Payment_Helper::format_amount( $stats['average_donation'] ),
					'label' => __( 'Average Donation', 'suredonation' ),
				];
		}
	}
}
