<?php
/**
 * Elementor widget: Donation Form.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Elementor\Widgets;

use Elementor\Controls_Manager;
use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Page_Builders\Elementor\Base_Widget;
use SureDonation\Inc\Page_Builders\Page_Builders;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donation_Form_Widget class.
 *
 * @since 1.2.0
 */
class Donation_Form_Widget extends Base_Widget {
	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'suredonation-donation-form';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return esc_html__( 'Donation Form', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * The donation form uses its own style handle, not the campaign blocks one.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public function get_style_depends() {
		return [ 'suredonation-donation-form' ];
	}

	/**
	 * Declare the form frontend script so Elementor enqueues it whenever the
	 * widget is present (the block render's own enqueue + localization still
	 * runs and remains the non-Elementor fallback).
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public function get_script_depends() {
		return [ 'suredonation-form-frontend' ];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/donation-form';
	}

	/**
	 * Register the widget controls.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content',
			[ 'label' => esc_html__( 'Donation Form', 'suredonation' ) ]
		);

		// Mirrors the Gutenberg donation-form block inspector, which exposes only a
		// campaign selector and a form selector (the form's own display options are
		// configured in the form editor, not on the embed).
		$this->add_campaign_control();

		// Elementor (free) has no dependent/AJAX dropdown, so we reproduce the
		// block's campaign-scoped form list by registering one form control per
		// campaign and gating each on the campaign selection — exactly one is ever
		// visible. A campaign-less fallback lists every form (the widget can be
		// placed without a campaign, e.g. on a campaign page where it inherits the
		// current one). The resolved value is read back in resolve_form_id().
		$this->add_control(
			'form_id',
			[
				'label'       => esc_html__( 'Donation form', 'suredonation' ),
				'type'        => Controls_Manager::SELECT2,
				'label_block' => true,
				'options'     => Page_Builders::get_donation_form_options(),
				'default'     => '',
				'condition'   => [ 'campaign_id' => '' ],
			]
		);

		foreach ( Page_Builders::get_campaign_options() as $campaign_id => $campaign_label ) {
			$campaign_id = absint( $campaign_id );
			if ( ! $campaign_id ) {
				continue; // Skip the leading "Select a campaign" placeholder entry.
			}

			// Pre-select the campaign's default form, matching how the Gutenberg
			// block auto-selects it once a campaign is chosen.
			$default_form_id = Campaign_Cpt::get_default_form_id( $campaign_id );

			$this->add_control(
				'form_id_' . $campaign_id,
				[
					'label'       => esc_html__( 'Donation form', 'suredonation' ),
					'type'        => Controls_Manager::SELECT2,
					'label_block' => true,
					'options'     => Page_Builders::get_campaign_form_options( $campaign_id ),
					'default'     => $default_form_id ? (string) $default_form_id : '',
					'condition'   => [ 'campaign_id' => (string) $campaign_id ],
				]
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Render the donation form block. Unlike the campaign-context widgets this
	 * gates on a selected form (a campaign is optional).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$form_id  = $this->resolve_form_id( $settings );

		if ( ! $form_id ) {
			if ( $this->is_edit_mode() ) {
				$this->render_placeholder( esc_html__( 'Select a donation form to display.', 'suredonation' ) );
			}
			return;
		}

		$form = get_post( $form_id );
		if ( ! $form instanceof \WP_Post || 'publish' !== $form->post_status ) {
			if ( $this->is_edit_mode() ) {
				$this->render_placeholder( esc_html__( 'The selected donation form is unavailable.', 'suredonation' ) );
			}
			return;
		}

		$this->echo_block( $this->get_block_attrs( $settings ) );
	}

	/**
	 * Map the widget settings to the block attributes.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Widget settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		$attrs = [
			'formId' => $this->resolve_form_id( $settings ),
		];

		$campaign_id = $this->setting_int( $settings, 'campaign_id' );
		if ( $campaign_id ) {
			$attrs['campaignId'] = $campaign_id;
		}

		return $attrs;
	}

	/**
	 * Resolve the selected form ID from the campaign-scoped control.
	 *
	 * The form selector is split into one control per campaign (`form_id_{id}`)
	 * plus a campaign-less fallback (`form_id`); only the control matching the
	 * current campaign selection holds the chosen value.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Widget settings.
	 * @return int
	 */
	protected function resolve_form_id( $settings ) {
		$campaign_id = $this->setting_int( $settings, 'campaign_id' );
		$control_key = $campaign_id ? 'form_id_' . $campaign_id : 'form_id';

		return $this->setting_int( $settings, $control_key );
	}
}
