<?php
/**
 * Bricks element: Campaign Donors.
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
 * Campaign_Donors class.
 *
 * @since 1.2.0
 */
class Campaign_Donors extends Base_Element {
	/**
	 * Element name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $name = 'suredonation-campaign-donors';

	/**
	 * Element icon.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $icon = 'ti-user';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Campaign Donors', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'donors', 'supporters' ] );
	}

	/**
	 * Register the element controls.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		$this->controls['donorsToShow'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Number of donors', 'suredonation' ),
			'type'    => 'number',
			'default' => 5,
			'min'     => 1,
			'max'     => 50,
		];

		$this->controls['showButton'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Show button', 'suredonation' ),
			'type'  => 'checkbox',
		];

		$this->controls['buttonText'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Button text', 'suredonation' ),
			'type'     => 'text',
			'default'  => esc_html__( 'Join the list', 'suredonation' ),
			'required' => [ 'showButton', '=', true ],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-donors';
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
			'donorsToShow' => max( 1, min( 50, $this->setting_int( $settings, 'donorsToShow', 5 ) ) ),
			'showButton'   => $this->setting_bool( $settings, 'showButton' ),
			'buttonText'   => $this->setting_string( $settings, 'buttonText', __( 'Join the list', 'suredonation' ) ),
		];
	}
}
