<?php
/**
 * Bricks element: Campaign Statistic.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Bricks\Elements;

use SureDonation\Inc\Page_Builders\Bricks\Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign_Stats class.
 *
 * @since 1.2.0
 */
class Campaign_Stats extends Base_Element {
	/**
	 * Valid statistic values (must match the block's get_statistic_config()).
	 *
	 * @since 1.2.0
	 */
	private const STATISTICS = [ 'average-donation', 'top-donation', 'total-raised', 'donor-count', 'donation-count' ];

	/**
	 * Element name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $name = 'suredonation-campaign-stats';

	/**
	 * Element icon.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $icon = 'ti-bar-chart';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Campaign Statistic', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'statistic', 'stats', 'raised' ] );
	}

	/**
	 * Register the element controls.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		$this->controls['statistic'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Statistic to display', 'suredonation' ),
			'type'    => 'select',
			'default' => 'average-donation',
			'options' => [
				'average-donation' => esc_html__( 'Average Donation', 'suredonation' ),
				'top-donation'     => esc_html__( 'Top Donation', 'suredonation' ),
				'total-raised'     => esc_html__( 'Total Raised', 'suredonation' ),
				'donor-count'      => esc_html__( 'Donors', 'suredonation' ),
				'donation-count'   => esc_html__( 'Donations', 'suredonation' ),
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-stats';
	}

	/**
	 * Map the element settings to the block attributes.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		$statistic = $this->setting_string( $settings, 'statistic', 'average-donation' );

		return [
			'statistic' => in_array( $statistic, self::STATISTICS, true ) ? $statistic : 'average-donation',
		];
	}
}
