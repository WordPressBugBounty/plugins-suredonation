<?php
/**
 * Elementor widget: Campaign Social Sharing.
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
 * Campaign_Social_Sharing_Widget class.
 *
 * @since 1.2.0
 */
class Campaign_Social_Sharing_Widget extends Base_Widget {
	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'suredonation-campaign-social-sharing';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return esc_html__( 'Campaign Social Sharing', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-share';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-social-sharing';
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
			[ 'label' => esc_html__( 'Campaign Social Sharing', 'suredonation' ) ]
		);

		$this->add_campaign_control();

		$this->add_control(
			'headline',
			[
				'label'   => esc_html__( 'Headline', 'suredonation' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Share:', 'suredonation' ),
			]
		);

		$this->add_control(
			'networks',
			[
				'label'       => esc_html__( 'Social networks', 'suredonation' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => [
					'twitter'   => esc_html__( 'Twitter / X', 'suredonation' ),
					'facebook'  => esc_html__( 'Facebook', 'suredonation' ),
					'linkedin'  => esc_html__( 'LinkedIn', 'suredonation' ),
					'pinterest' => esc_html__( 'Pinterest', 'suredonation' ),
					'mastodon'  => esc_html__( 'Mastodon', 'suredonation' ),
					'threads'   => esc_html__( 'Threads', 'suredonation' ),
					'bluesky'   => esc_html__( 'Bluesky', 'suredonation' ),
				],
				'default'     => [ 'twitter', 'facebook', 'linkedin', 'pinterest', 'bluesky' ],
			]
		);

		$this->add_control(
			'open_in_new_tab',
			[
				'label'        => esc_html__( 'Open Links In New Tab', 'suredonation' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'content_align',
			[
				'label'   => esc_html__( 'Alignment', 'suredonation' ),
				'type'    => Controls_Manager::CHOOSE,
				'default' => 'left',
				'options' => [
					'left'   => [
						'title' => esc_html__( 'Left', 'suredonation' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'suredonation' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => esc_html__( 'Right', 'suredonation' ),
						'icon'  => 'eicon-text-align-right',
					],
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
		$networks = isset( $settings['networks'] ) && is_array( $settings['networks'] )
			? array_values( array_map( 'strval', $settings['networks'] ) )
			: [ 'twitter', 'facebook', 'linkedin', 'pinterest', 'bluesky' ];

		$align = $this->setting_string( $settings, 'content_align', 'left' );

		return [
			'headline'     => $this->setting_string( $settings, 'headline', __( 'Share:', 'suredonation' ) ),
			'networks'     => $networks,
			'openInNewTab' => $this->setting_bool( $settings, 'open_in_new_tab', true ),
			'contentAlign' => in_array( $align, [ 'left', 'center', 'right' ], true ) ? $align : 'left',
		];
	}
}
