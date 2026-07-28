<?php
/**
 * Elementor widget: Campaign Donate Button.
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
 * Campaign_Donate_Button_Widget class.
 *
 * @since 1.2.0
 */
class Campaign_Donate_Button_Widget extends Base_Widget {
	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'suredonation-campaign-donate-button';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return esc_html__( 'Campaign Donate Button', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-button';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-donate-button';
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
			[ 'label' => esc_html__( 'Campaign Donate Button', 'suredonation' ) ]
		);

		$this->add_campaign_control();

		$this->add_control(
			'button_text',
			[
				'label'   => esc_html__( 'Button text', 'suredonation' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Donate', 'suredonation' ),
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
			'buttonText' => $this->setting_string( $settings, 'button_text', __( 'Donate', 'suredonation' ) ),
		];
	}
}
