<?php
/**
 * Elementor widget: Campaign Donors.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Elementor\Widgets;

use Elementor\Controls_Manager;
use SureDonation\Inc\Page_Builders\Elementor\Base_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign_Donors_Widget class.
 *
 * @since 1.2.0
 */
class Campaign_Donors_Widget extends Base_Widget {
	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'suredonation-campaign-donors';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return esc_html__( 'Campaign Donors', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-person';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-donors';
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
			[ 'label' => esc_html__( 'Campaign Donors', 'suredonation' ) ]
		);

		$this->add_campaign_control();

		$this->add_control(
			'donors_to_show',
			[
				'label'   => esc_html__( 'Number of donors', 'suredonation' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 5,
				'min'     => 1,
				'max'     => 50,
			]
		);

		$this->add_control(
			'show_button',
			[
				'label'        => esc_html__( 'Show button', 'suredonation' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$this->add_control(
			'button_text',
			[
				'label'     => esc_html__( 'Button text', 'suredonation' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Join the list', 'suredonation' ),
				'condition' => [ 'show_button' => 'yes' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Map the widget settings to the block attributes.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Widget settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		return [
			'donorsToShow' => $this->setting_int( $settings, 'donors_to_show', 5 ),
			'showButton'   => $this->setting_bool( $settings, 'show_button', false ),
			'buttonText'   => $this->setting_string( $settings, 'button_text', __( 'Join the list', 'suredonation' ) ),
		];
	}
}
