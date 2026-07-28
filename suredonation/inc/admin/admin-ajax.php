<?php
/**
 * Admin AJAX handlers.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin-side AJAX for recommended-plugin install and activate.
 *
 * Installation reuses WordPress core's `wp_ajax_install_plugin` handler;
 * activation is handled here.
 *
 * @since 1.0.0
 */
class Admin_Ajax {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_suredonation_recommended_plugin_install', 'wp_ajax_install_plugin' );
		add_action( 'wp_ajax_suredonation_recommended_plugin_activate', [ $this, 'recommended_plugin_activate' ] );
		add_action( 'wp_ajax_suredonation_integration', [ $this, 'generate_data_for_suretriggers_integration' ] );
	}

	/**
	 * Activate a recommended plugin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function recommended_plugin_activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error(
				[
					'success' => false,
					'message' => __( 'You do not have permission to activate plugins.', 'suredonation' ),
				]
			);
		}

		if ( ! check_ajax_referer( 'suredonation_plugin_manager', 'security', false ) ) {
			wp_send_json_error(
				[
					'success' => false,
					'message' => __( 'Invalid security token. Please refresh the page and try again.', 'suredonation' ),
				]
			);
		}

		$plugin_init = isset( $_POST['init'] ) ? sanitize_text_field( wp_unslash( $_POST['init'] ) ) : '';

		if ( '' === $plugin_init ) {
			wp_send_json_error(
				[
					'success' => false,
					'message' => __( 'No plugin specified.', 'suredonation' ),
				]
			);
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$activated = activate_plugin( $plugin_init, '', false, true );

		if ( is_wp_error( $activated ) ) {
			wp_send_json_error(
				[
					'success' => false,
					'message' => $activated->get_error_message(),
				]
			);
		}

		wp_send_json_success(
			[
				'success' => true,
				'message' => __( 'Plugin activated successfully.', 'suredonation' ),
			]
		);
	}

	/**
	 * Generate the configuration payload required by the OttoKit (formerly
	 * SureTriggers) embed to render the automation builder iframe.
	 *
	 * Called from the donation form settings. The embed is scoped to the
	 * form's CAMPAIGN — `embedded_identifier` and `selected_options` use the
	 * campaign ID so every form in a campaign shares the same automations,
	 * matching OttoKit's campaign-level trigger filtering.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function generate_data_for_suretriggers_integration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to access this page.', 'suredonation' ) ] );
		}

		if ( ! check_ajax_referer( 'suredonation_suretriggers_nonce', 'security', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'suredonation' ) ] );
		}

		if ( ! Helper::is_suretriggers_ready() ) {
			wp_send_json_error(
				[
					'code'    => 'invalid_secret_key',
					'message' => __( 'OttoKit is not configured properly.', 'suredonation' ),
				]
			);
		}

		$form_id = isset( $_POST['formId'] ) ? absint( wp_unslash( $_POST['formId'] ) ) : 0;

		if ( ! $form_id || Donation_Form::POST_TYPE !== get_post_type( $form_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid form ID.', 'suredonation' ) ] );
		}

		// Resolve the campaign this form belongs to — automations are scoped per campaign.
		$campaign_id = absint( Helper::get_string_value( get_post_meta( $form_id, Donation_Form::META_CAMPAIGN_ID, true ) ) );

		if ( ! $campaign_id ) {
			wp_send_json_error(
				[
					'code'    => 'no_campaign',
					'message' => __( 'This form is not linked to a campaign yet.', 'suredonation' ),
				]
			);
		}

		$campaign      = get_post( $campaign_id );
		$campaign_name = ( $campaign && ! empty( $campaign->post_title ) )
			? $campaign->post_title
			/* translators: %d: Campaign ID. */
			: sprintf( __( 'Campaign #%d', 'suredonation' ), $campaign_id );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter is owned by the OttoKit plugin.
		$api_url = apply_filters( 'suretriggers_get_iframe_url', SUREDONATION_SURETRIGGERS_INTEGRATION_BASE_URL );

		// Format required by OttoKit to inject the builder iframe into the target id.
		$body = [
			'client_id'           => 'SureDonation',
			'st_embed_url'        => $api_url,
			'embedded_identifier' => $campaign_id,
			'target'              => 'suretriggers-iframe-wrapper', // The div where OttoKit injects the iframe must have this id.
			'event'               => [
				'label'       => __( 'Donation Completed', 'suredonation' ),
				'value'       => 'suredonation_donation_completed',
				'description' => __( 'Runs when a donation payment is completed', 'suredonation' ),
			],
			'summary'             => $campaign_name,
			'selected_options'    => [
				// Key must match the OttoKit trigger's campaign field name
				// (`suredonation_campaign`) for the builder to pre-select it.
				'suredonation_campaign' => [
					'value' => $campaign_id,
					'label' => $campaign_name,
				],
			],
			'integration'         => 'SureDonation',
			// Flat shape — keys must mirror the runtime trigger context that
			// OttoKit's WP module emits (get_donation_context / the pluggable
			// "Fetch Data"). A nested `donation` object here makes OttoKit label
			// the fields "Donation Campaign Id" etc., which then don't match the
			// flat runtime keys ("Campaign Id"), so field mappings resolve empty.
			'sample_response'     => [
				'donation_id'         => 12,
				'campaign_id'         => $campaign_id,
				'campaign_title'      => $campaign_name,
				'form_id'             => $form_id,
				'form_title'          => get_the_title( $form_id ),
				'donor_id'            => 1,
				'donor_name'          => __( 'John Doe', 'suredonation' ),
				'donor_email'         => 'john@example.com',
				'donor_phone'         => '',
				'amount'              => 100.00,
				'fees_covered'        => 0,
				'currency'            => Payment_Helper::get_currency(),
				'payment_status'      => 'completed',
				'payment_mode'        => 'live',
				'gateway'             => 'stripe',
				'transaction_id'      => 'pi_1234567890',
				'donation_type'       => 'one-time',
				'is_anonymous'        => 0,
				'donor_comment'       => '',
				'subscription_id'     => '',
				'subscription_status' => '',
				'created_at'          => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
			],
		];

		wp_send_json_success(
			[
				'message' => 'success',
				/**
				 * Filter the configuration payload passed to the OttoKit embed.
				 *
				 * @param array<string,mixed> $body        Embed configuration payload.
				 * @param int                 $campaign_id Resolved campaign ID.
				 * @param int                 $form_id     Donation form ID the embed was opened from.
				 * @since 1.2.0
				 */
				'data'    => apply_filters( 'suredonation_suretriggers_integration_data_filter', $body, $campaign_id, $form_id ),
			]
		);
	}
}
