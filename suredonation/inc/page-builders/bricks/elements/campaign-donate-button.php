<?php
/**
 * Bricks element: Campaign Donate Button.
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
 * Campaign_Donate_Button class.
 *
 * @since 1.2.0
 */
class Campaign_Donate_Button extends Base_Element {
	/**
	 * Element name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $name = 'suredonation-campaign-donate-button';

	/**
	 * Element icon.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $icon = 'ti-money';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Campaign Donate Button', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'button', 'donate' ] );
	}

	/**
	 * Register the element controls.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		$this->controls['buttonText'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Button text', 'suredonation' ),
			'type'    => 'text',
			'default' => esc_html__( 'Donate', 'suredonation' ),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-donate-button';
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
			'buttonText' => $this->setting_string( $settings, 'buttonText', __( 'Donate', 'suredonation' ) ),
		];
	}
}
