<?php
/**
 * Bricks element: Campaign Goal.
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
 * Campaign_Goal class.
 *
 * @since 1.2.0
 */
class Campaign_Goal extends Base_Element {
	/**
	 * Element name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $name = 'suredonation-campaign-goal';

	/**
	 * Element icon.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $icon = 'ti-target';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Campaign Goal', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'goal', 'progress' ] );
	}

	/**
	 * Register the element controls.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		// Inverted (default-off) control: Bricks never writes a control 'default'
		// into settings, so an untouched default-true checkbox would read as
		// false and flip the block's Gutenberg default. Absent = show the bar.
		$this->controls['hideProgressBar'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Hide progress bar', 'suredonation' ),
			'type'  => 'checkbox',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-goal';
	}

	/**
	 * Map the element settings to the block attributes.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		return [
			'showProgressBar' => ! $this->setting_bool( $settings, 'hideProgressBar' ),
		];
	}
}
