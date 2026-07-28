<?php
/**
 * Elementor widget: Campaign Goal.
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
 * Campaign_Goal_Widget class.
 *
 * @since 1.2.0
 */
class Campaign_Goal_Widget extends Base_Widget {
	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'suredonation-campaign-goal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return esc_html__( 'Campaign Goal', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-skill-bar';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-goal';
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
			[ 'label' => esc_html__( 'Campaign Goal', 'suredonation' ) ]
		);

		$this->add_campaign_control();

		$this->add_control(
			'show_progress_bar',
			[
				'label'        => esc_html__( 'Show progress bar', 'suredonation' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
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
			'showProgressBar' => $this->setting_bool( $settings, 'show_progress_bar', true ),
		];
	}
}
