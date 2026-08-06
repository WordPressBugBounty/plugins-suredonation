<?php
/**
 * PHP render for Donation Form Block.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Blocks\Donation_Form;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Fields\Form_Renderer;
use SureDonation\Inc\Fields\Form_Styling;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Post_Types\Donation_Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donation Form Block.
 *
 * @since 0.0.1
 */
class Block extends Base {
	/**
	 * Render the block
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 0.0.1
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content ); // Unused parameter.

		if ( empty( $attributes ) ) {
			return '';
		}

		// A custom form is required.
		$form_id = isset( $attributes['formId'] ) ? absint( Helper::get_string_value( $attributes['formId'] ) ) : 0;

		if ( ! $form_id ) {
			// Show message only to users who can edit posts.
			if ( current_user_can( 'edit_posts' ) ) {
				return '<div class="suredonation-notice">' . esc_html__( 'Please select a donation form in the block settings.', 'suredonation' ) . '</div>';
			}
			return '';
		}

		return $this->render_custom_form( $form_id, $attributes );
	}

	/**
	 * Render a custom donation form.
	 *
	 * @param int                  $form_id    Form post ID.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 * @since 0.0.1
	 */
	private function render_custom_form( $form_id, $attributes ) {
		// Get the form post.
		$form = get_post( $form_id );
		if ( ! $form instanceof \WP_Post || Donation_Form::POST_TYPE !== $form->post_type ) {
			return '<div class="suredonation-notice">' . esc_html__( 'Invalid form selected.', 'suredonation' ) . '</div>';
		}

		// Check if form is published.
		if ( 'publish' !== $form->post_status ) {
			return '<div class="suredonation-notice">' . esc_html__( 'This form is not available.', 'suredonation' ) . '</div>';
		}

		// Get the campaign ID from block attributes or form meta.
		$campaign_id = isset( $attributes['campaignId'] ) ? absint( Helper::get_string_value( $attributes['campaignId'] ) ) : 0;
		if ( ! $campaign_id ) {
			$campaign_id = Donation_Form::get_form_campaign_id( $form_id );
		}

		// Validate campaign if set.
		if ( $campaign_id ) {
			$campaign = get_post( $campaign_id );
			if ( ! $campaign instanceof \WP_Post || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
				return '<div class="suredonation-notice">' . esc_html__( 'Invalid campaign selected.', 'suredonation' ) . '</div>';
			}

			// Only reflect published campaigns (mirrors the form publish check
			// above) so a draft/private campaign's details can't surface in the
			// preview via a passed campaign ID.
			if ( 'publish' !== $campaign->post_status ) {
				return '<div class="suredonation-notice">' . esc_html__( 'This campaign is not available.', 'suredonation' ) . '</div>';
			}

			// Check campaign status.
			$campaign_status = Helper::get_campaign_meta_value( $campaign_id, 'campaign_status', 'active' );
			if ( 'paused' === $campaign_status || 'completed' === $campaign_status ) {
				return '<div class="suredonation-notice">' . esc_html__( 'This campaign is not currently accepting donations.', 'suredonation' ) . '</div>';
			}
		}

		// Enqueue frontend styles for custom form blocks. Skipped when the form
		// has default styling disabled — the site's own CSS then controls the
		// appearance (scripts always load so payment/validation keep working).
		// Note: Frontend JS is handled by the payment block (form-frontend.js).
		if ( ! Form_Styling::is_default_styling_disabled( $form_id ) ) {
			wp_enqueue_style( 'suredonation-donation-form' );
		}

		// Enqueue form frontend script.
		wp_enqueue_script( 'suredonation-form-frontend' );

		/**
		 * Fires when a donation form is rendered on the frontend.
		 *
		 * Allows payment gateway extensions to enqueue their scripts (e.g., PayPal SDK).
		 *
		 * @param int    $form_id      The donation form post ID.
		 * @param string $form_content The form post content (blocks).
		 * @since 1.0.0
		 */
		do_action( 'suredonation_enqueue_form_frontend_scripts', $form_id, $form->post_content );

		// Pass form settings to frontend (wp_localize_script handles script-context escaping).
		wp_localize_script(
			'suredonation-form-frontend',
			'suredonationPayment',
			Helper::get_form_payment_settings( $form_id )
		);

		// Expose the resolved validation messages so client-side validation
		// mirrors the server's configured messages.
		wp_localize_script(
			'suredonation-form-frontend',
			'suredonationValidationMessages',
			\SureDonation\Inc\Field_Validation::get_resolved_validation_messages()
		);

		// Shared markup (also used by the [suredonation_form] shortcode).
		$form_html = Form_Renderer::render( $form, $campaign_id );

		return $this->maybe_anchor_form( $form_html );
	}

	/**
	 * Wrap the first donation form rendered on the page in the campaign form
	 * anchor.
	 *
	 * The Campaign Donate Button links to `#suredonation-donation-form`. Owning
	 * that anchor here (rather than relying on a wrapping group set up by the
	 * page seeder) keeps the scroll target alive wherever the form block is
	 * placed — including after a user removes and manually re-adds it. Only the
	 * first instance per request is anchored so the id stays unique on the page.
	 *
	 * "First" means first in PHP render order for the request, not first in the
	 * visible page. On the campaign page that is the only/intended form. The
	 * known trade-offs of that scope: a form rendered earlier in the request
	 * (e.g. a sidebar/widget form) would claim the anchor instead, and
	 * ServerSideRender emits the anchored wrapper into the block's editor
	 * preview too. Both are acceptable for the campaign-page use case.
	 *
	 * @param string $form_html Rendered form markup.
	 * @return string
	 * @since 1.1.0
	 */
	private function maybe_anchor_form( $form_html ) {
		static $anchored = false;

		if ( $anchored || '' === $form_html ) {
			return $form_html;
		}

		$anchored = true;

		return '<div id="' . esc_attr( Campaign_Page::FORM_ANCHOR ) . '" class="suredonation-donation-form-anchor">' . $form_html . '</div>';
	}
}
