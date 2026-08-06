<?php
/**
 * Donation form markup renderer.
 *
 * Shared by the donation-form block and the [suredonation_form] shortcode so
 * both produce identical markup (wrapper, form, field blocks, success box) and
 * pick up per-form styling from a single place.
 *
 * Callers handle validation, campaign resolution, and asset enqueueing; this
 * class only builds the markup.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Fields;

use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Form_Renderer class.
 *
 * @since 1.0.0
 */
class Form_Renderer {

	/**
	 * Render the donation form markup for a form + campaign.
	 *
	 * @param \WP_Post $form        Donation form post.
	 * @param int      $campaign_id Resolved campaign ID (0 for a standalone form).
	 * @return string Form HTML.
	 * @since 1.0.0
	 */
	public static function render( $form, $campaign_id ) {
		if ( ! $form instanceof \WP_Post ) {
			return '';
		}

		$form_id        = (int) $form->ID;
		$campaign_id    = (int) $campaign_id;
		$unique_form_id = 'suredonation-form-' . $form_id . '-' . wp_rand();
		$form_style     = Form_Styling::get_style_attr( $form_id );
		$nonce_action   = Helper::get_donation_nonce_action( $campaign_id );
		$blocks         = parse_blocks( $form->post_content );

		// Marker class when default styling is disabled, so custom CSS can
		// target the unstyled state (get_style_attr already returned '').
		$container_classes = 'sd-form-container';
		if ( Form_Styling::is_default_styling_disabled( $form_id ) ) {
			$container_classes .= ' sd-styling-none';
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $unique_form_id ); ?>" class="<?php echo esc_attr( $container_classes ); ?>" data-form-id="<?php echo esc_attr( (string) $form_id ); ?>" data-campaign-id="<?php echo esc_attr( (string) $campaign_id ); ?>"<?php echo '' !== $form_style ? ' style="' . esc_attr( $form_style ) . '"' : ''; ?>>
			<form class="sd-form" method="post">
				<?php wp_nonce_field( $nonce_action, 'suredonation_nonce' ); ?>
				<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign_id ); ?>">
				<?php if ( ! $campaign_id ) { ?>
				<input type="hidden" name="is_standalone" value="1">
				<?php } ?>
				<input type="hidden" name="action" value="suredonation_submit_donation">
				<?php Helper::render_honeypot_field(); ?>

				<?php
				// Privacy fields (consent / privacy policy / terms) enabled in the
				// Privacy settings are injected as the last thing before the submit
				// button so the donor sees them right before submitting. Anchor on the
				// donate button; if a form has none, fall back to the payment block, and
				// finally to the end of the form. The anchor may be nested inside a
				// layout block (Group/Columns), so match the top-level block that either
				// is, or contains, the anchor.
				$privacy_fields   = \SureDonation\Inc\Privacy\Privacy_Frontend::render_form_fields();
				$privacy_anchor   = Helper::block_tree_contains( $blocks, 'suredonation/donate-button' ) ? 'suredonation/donate-button' : 'suredonation/payment';
				$privacy_injected = false;
				foreach ( $blocks as $block ) {
					if ( empty( $block['blockName'] ) ) {
						continue;
					}
					$is_anchor = $block['blockName'] === $privacy_anchor
						|| ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && Helper::block_tree_contains( $block['innerBlocks'], $privacy_anchor ) );
					if ( ! $privacy_injected && '' !== $privacy_fields && $is_anchor ) {
						echo wp_kses( $privacy_fields, Helper::get_allowed_form_html() );
						$privacy_injected = true;
					}
					$block['attrs']['formId'] = $form_id;
					// Allow the data: protocol so a lazy-load optimizer's inline SVG
					// placeholder (Image block) survives this second kses pass.
					echo wp_kses( render_block( $block ), Helper::get_allowed_form_html(), array_merge( wp_allowed_protocols(), [ 'data' ] ) );
				}
				if ( ! $privacy_injected && '' !== $privacy_fields ) {
					echo wp_kses( $privacy_fields, Helper::get_allowed_form_html() );
				}
				?>
			</form>
			<!-- Success Message Container -->
			<div class="sd-single-form sd-success-box">
				<div aria-live="polite" aria-atomic="true" role="alert" id="sd-success-message-<?php echo esc_attr( (string) $form_id ); ?>" class="sd-success-box-description"></div>
			</div>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
