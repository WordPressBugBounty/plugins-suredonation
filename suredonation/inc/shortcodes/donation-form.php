<?php
/**
 * Donation Form Shortcode
 *
 * Renders a donation form via shortcode [suredonation_form id="123"]
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Shortcodes;

use SureDonation\Inc\Fields\Form_Renderer;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Post_Types\Donation_Form as Donation_Form_CPT;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donation_Form shortcode class.
 *
 * @since 0.0.1
 */
class Donation_Form {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_shortcode( 'suredonation_form', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the donation form shortcode.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string Form HTML.
	 * @since 0.0.1
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			[
				'id' => 0,
			],
			$atts,
			'suredonation_form'
		);

		$form_id = absint( $atts['id'] );

		if ( ! $form_id ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="suredonation-error">' . esc_html__( 'Please specify a form ID: [suredonation_form id="123"]', 'suredonation' ) . '</p>';
			}
			return '';
		}

		// Get the form post.
		$form = get_post( $form_id );

		if ( ! $form || Donation_Form_CPT::POST_TYPE !== $form->post_type ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="suredonation-error">' . esc_html__( 'Invalid donation form ID.', 'suredonation' ) . '</p>';
			}
			return '';
		}

		if ( 'publish' !== $form->post_status && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		// Enqueue frontend assets.
		$this->enqueue_assets( $form_id, $form );

		// Render the form.
		return $this->render_form( $form );
	}

	/**
	 * Render the form HTML.
	 *
	 * @param \WP_Post $form Form post object.
	 * @return string Form HTML.
	 * @since 0.0.1
	 */
	private function render_form( $form ) {
		$campaign_id = Donation_Form_CPT::get_form_campaign_id( $form->ID );

		// Shared markup (also used by the donation-form block).
		return Form_Renderer::render( $form, $campaign_id );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @param int      $form_id Form post ID.
	 * @param \WP_Post $form    Form post object.
	 * @return void
	 * @since 0.0.1
	 */
	private function enqueue_assets( $form_id, $form ) {
		// Enqueue the shared compiled frontend stylesheet (same handle the block uses).
		wp_enqueue_style( 'suredonation-donation-form' );

		// Enqueue frontend script (registered globally in Assets\Register).
		wp_enqueue_script( 'suredonation-form-frontend' );

		// Localize script with payment and success message configuration.
		wp_localize_script(
			'suredonation-form-frontend',
			'suredonationPayment',
			Helper::get_form_payment_settings( $form_id )
		);

		// Expose the resolved validation messages so client-side validation
		// mirrors the server's configured messages (matches the block render).
		wp_localize_script(
			'suredonation-form-frontend',
			'suredonationValidationMessages',
			\SureDonation\Inc\Field_Validation::get_resolved_validation_messages()
		);

		// Enqueue Stripe.js if Stripe is configured.
		$this->maybe_enqueue_stripe();

		// Allow payment gateway extensions to enqueue their scripts (e.g., PayPal SDK).
		do_action( 'suredonation_enqueue_form_frontend_scripts', $form_id, $form->post_content );
	}

	/**
	 * Enqueue Stripe.js if Stripe is configured.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	private function maybe_enqueue_stripe() {
		if ( ! class_exists( 'SureDonation\Inc\Payments\Stripe\Stripe_Helper' ) ) {
			return;
		}

		// Load Stripe.js when any connected account exists; the per-form
		// publishable key is passed to the frontend via the payment block markup.
		if ( \SureDonation\Inc\Payments\Stripe\Stripe_Helper::is_stripe_connected() ) {
			wp_enqueue_script(
				'stripe-js',
				'https://js.stripe.com/v3/',
				[],
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External script.
				true
			);
		}
	}
}
