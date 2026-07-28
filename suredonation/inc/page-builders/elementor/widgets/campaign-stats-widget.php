<?php
/**
 * Elementor widget: Campaign Statistic.
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
 * Campaign_Stats_Widget class.
 *
 * @since 1.2.0
 */
class Campaign_Stats_Widget extends Base_Widget {
	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'suredonation-campaign-stats';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return esc_html__( 'Campaign Statistic', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-counter';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-stats';
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
			[ 'label' => esc_html__( 'Campaign Statistic', 'suredonation' ) ]
		);

		$this->add_campaign_control();

		$this->add_control(
			'statistic',
			[
				'label'   => esc_html__( 'Statistic to display', 'suredonation' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'average-donation',
				'options' => [
					'average-donation' => esc_html__( 'Average Donation', 'suredonation' ),
					'top-donation'     => esc_html__( 'Top Donation', 'suredonation' ),
					'total-raised'     => esc_html__( 'Total Raised', 'suredonation' ),
					'donor-count'      => esc_html__( 'Donors', 'suredonation' ),
					'donation-count'   => esc_html__( 'Donations', 'suredonation' ),
				],
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
			'statistic' => $this->setting_string( $settings, 'statistic', 'average-donation' ),
		];
	}
}
