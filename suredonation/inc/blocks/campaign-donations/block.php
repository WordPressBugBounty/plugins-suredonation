<?php
/**
 * PHP render for the Campaign Donations block.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Blocks\Campaign_Donations;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Campaigns\Campaign_Stats;
use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign Donations block.
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

		$limit     = isset( $attributes['donationsToShow'] ) ? absint( $attributes['donationsToShow'] ) : 5;
		$limit     = max( 1, $limit );
		$show_anon = ! isset( $attributes['showAnonymous'] ) || $attributes['showAnonymous'];

		// Over-fetch when anonymous donations are hidden so the visible list can
		// still fill even if many recent donations are anonymous. Capped to keep
		// the query bounded.
		$fetch     = $show_anon ? $limit : min( $limit * 5, 100 );
		$donations = Campaign_Stats::get_cached_recent_donations( $campaign_id, $fetch );

		if ( ! is_array( $donations ) ) {
			$donations = [];
		}

		if ( ! $show_anon ) {
			$donations = array_filter(
				$donations,
				static function ( $donation ) {
					return empty( $donation['is_anonymous'] );
				}
			);
		}

		$donations = array_slice( $donations, 0, $limit );

		$wrapper = get_block_wrapper_attributes( [ 'class' => 'suredonation-campaign-donations' ] );

		ob_start();
		?>
		<?php
		$show_button = ! isset( $attributes['showButton'] ) || $attributes['showButton'];
		$button_text = ! empty( $attributes['buttonText'] ) ? (string) $attributes['buttonText'] : __( 'Donate', 'suredonation' );
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
			<div class="suredonation-campaign-donations__header">
				<h2 class="suredonation-campaign-donations__title"><?php esc_html_e( 'Recent Donations', 'suredonation' ); ?></h2>
				<?php if ( $show_button ) : ?>
					<a class="suredonation-campaign-donations__donate-link" href="#<?php echo esc_attr( Campaign_Page::FORM_ANCHOR ); ?>"><?php echo esc_html( $button_text ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $donations ) ) : ?>
				<p class="suredonation-campaign-donations__empty"><?php esc_html_e( 'Be the first to donate.', 'suredonation' ); ?></p>
			<?php else : ?>
				<ul class="suredonation-campaign-donations__list">
					<?php foreach ( $donations as $donation ) : ?>
						<?php
						$is_anon    = ! empty( $donation['is_anonymous'] );
						$name       = $is_anon ? __( 'Anonymous', 'suredonation' ) : ( $donation['donor_name'] ?? __( 'Anonymous', 'suredonation' ) );
						$amount     = Payment_Helper::format_amount( $donation['amount'] ?? 0 );
						$time       = ! empty( $donation['created_at'] ) ? strtotime( (string) $donation['created_at'] ) : false;
						$avatar_url = Campaign_Page::donor_avatar_url( $donation['donor_email'] ?? '', $is_anon );
						?>
						<li class="suredonation-campaign-donations__item">
							<?php if ( $avatar_url ) : ?>
								<div class="suredonation-campaign-donations__avatar">
									<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" loading="lazy" />
								</div>
							<?php endif; ?>
							<div class="suredonation-campaign-donations__info">
								<div class="suredonation-campaign-donations__description">
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: 1: donor name, 2: donation amount. */
											__( '%1$s donated %2$s', 'suredonation' ),
											'<strong>' . esc_html( $name ) . '</strong>',
											'<strong>' . esc_html( $amount ) . '</strong>'
										)
									);
									?>
								</div>
								<?php if ( $time ) : ?>
									<span class="suredonation-campaign-donations__date">
										<?php
										/* translators: %s: human-readable time difference, e.g. "3 weeks". */
										printf( esc_html__( '%s ago', 'suredonation' ), esc_html( human_time_diff( $time ) ) );
										?>
									</span>
								<?php endif; ?>
							</div>
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
