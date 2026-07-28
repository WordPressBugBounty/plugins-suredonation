<?php
/**
 * PHP render for the Campaign Donors block.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Blocks\Campaign_Donors;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Campaigns\Campaign_Stats;
use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign Donors block.
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

		$limit  = isset( $attributes['donorsToShow'] ) ? absint( $attributes['donorsToShow'] ) : 5;
		$limit  = max( 1, $limit );
		$donors = Campaign_Stats::get_cached_top_donors( $campaign_id, $limit );

		if ( ! is_array( $donors ) ) {
			$donors = [];
		}

		$wrapper = get_block_wrapper_attributes( [ 'class' => 'suredonation-campaign-donors' ] );

		ob_start();
		?>
		<?php
		$show_button = ! isset( $attributes['showButton'] ) || $attributes['showButton'];
		$button_text = ! empty( $attributes['buttonText'] ) ? (string) $attributes['buttonText'] : __( 'Join the list', 'suredonation' );
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
			<div class="suredonation-campaign-donors__header">
				<h2 class="suredonation-campaign-donors__title"><?php esc_html_e( 'Top Donors', 'suredonation' ); ?></h2>
				<?php if ( $show_button ) : ?>
					<a class="suredonation-campaign-donors__donate-link" href="#<?php echo esc_attr( Campaign_Page::FORM_ANCHOR ); ?>"><?php echo esc_html( $button_text ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $donors ) ) : ?>
				<p class="suredonation-campaign-donors__empty"><?php esc_html_e( 'No donors yet.', 'suredonation' ); ?></p>
			<?php else : ?>
				<ul class="suredonation-campaign-donors__list">
					<?php
					$rank = 0;
					foreach ( $donors as $donor ) :
						++$rank;
						$name  = ! empty( $donor['donor_name'] ) ? $donor['donor_name'] : __( 'Anonymous', 'suredonation' );
						$total = Payment_Helper::format_amount( $donor['total_donated'] ?? 0 );
						// Top donors are non-anonymous (filtered in the query).
						$avatar_url = Campaign_Page::donor_avatar_url( $donor['donor_email'] ?? '', false );
						?>
						<li class="suredonation-campaign-donors__item">
							<?php if ( $avatar_url ) : ?>
								<div class="suredonation-campaign-donors__avatar">
									<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" loading="lazy" />
								</div>
							<?php endif; ?>
							<div class="suredonation-campaign-donors__info">
								<span class="suredonation-campaign-donors__name"><?php echo esc_html( $name ); ?></span>
								<?php if ( $rank <= 3 ) : ?>
									<span class="suredonation-campaign-donors__ribbon" data-position="<?php echo esc_attr( (string) $rank ); ?>" aria-hidden="true">
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"></circle><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"></path></svg>
									</span>
								<?php endif; ?>
							</div>
							<span class="suredonation-campaign-donors__amount"><?php echo esc_html( $total ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}
}
