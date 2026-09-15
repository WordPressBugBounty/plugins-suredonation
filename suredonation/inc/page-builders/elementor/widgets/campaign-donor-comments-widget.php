<?php
/**
 * Elementor widget: Donor Comments.
 *
 * @package SureDonation
 * @since 1.6.0
 */

namespace SureDonation\Inc\Page_Builders\Elementor\Widgets;

use Elementor\Controls_Manager;
use SureDonation\Inc\Page_Builders\Elementor\Base_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign_Donor_Comments_Widget class.
 *
 * @since 1.6.0
 */
class Campaign_Donor_Comments_Widget extends Base_Widget {
	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'suredonation-campaign-donor-comments';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return esc_html__( 'Donor Comments', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-comments';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-donor-comments';
	}

	/**
	 * Register the widget controls.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content',
			[ 'label' => esc_html__( 'Donor Comments', 'suredonation' ) ]
		);

		$this->add_campaign_control();

		$this->add_control(
			'comments_to_show',
			[
				'label'   => esc_html__( 'Number of comments', 'suredonation' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 5,
				'min'     => 1,
				'max'     => 50,
			]
		);

		$this->add_control(
			'show_anonymous',
			[
				'label'        => esc_html__( 'Show comments on anonymous donations', 'suredonation' ),
				'description'  => esc_html__( 'The comment is kept but the donor name is shown as Anonymous.', 'suredonation' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'show_avatar',
			[
				'label'        => esc_html__( 'Show avatar', 'suredonation' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'show_amount',
			[
				'label'        => esc_html__( 'Show amount', 'suredonation' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'show_date',
			[
				'label'        => esc_html__( 'Show date', 'suredonation' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'comment_length',
			[
				'label'       => esc_html__( 'Comment length', 'suredonation' ),
				'description' => esc_html__( 'Longer comments are trimmed to this many characters with a read-more link. Set to 0 to always show the whole comment.', 'suredonation' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 150,
				'min'         => 0,
				'max'         => 1000,
			]
		);

		$this->add_control(
			'read_more_text',
			[
				'label'     => esc_html__( 'Read more text', 'suredonation' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Read more', 'suredonation' ),
				'condition' => [ 'comment_length!' => '0' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Map the widget settings to the block attributes.
	 *
	 * @since 1.6.0
	 * @param array<string, mixed> $settings Widget settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		return [
			'commentsToShow' => $this->setting_int( $settings, 'comments_to_show', 5 ),
			'showAnonymous'  => $this->setting_bool( $settings, 'show_anonymous', true ),
			'showAvatar'     => $this->setting_bool( $settings, 'show_avatar', true ),
			'showAmount'     => $this->setting_bool( $settings, 'show_amount', true ),
			'showDate'       => $this->setting_bool( $settings, 'show_date', true ),
			'commentLength'  => $this->setting_int( $settings, 'comment_length', 150 ),
			'readMoreText'   => $this->setting_string( $settings, 'read_more_text', __( 'Read more', 'suredonation' ) ),
		];
	}
}
