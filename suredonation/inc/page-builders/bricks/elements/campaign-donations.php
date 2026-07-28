<?php
/**
 * Bricks element: Campaign Donations.
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
 * Campaign_Donations class.
 *
 * @since 1.2.0
 */
class Campaign_Donations extends Base_Element {
	/**
	 * Element name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $name = 'suredonation-campaign-donations';

	/**
	 * Element icon.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $icon = 'ti-list';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Campaign Donations', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'donations', 'recent' ] );
	}

	/**
	 * Register the element controls.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		$this->controls['donationsToShow'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Number of donations', 'suredonation' ),
			'type'    => 'number',
			'default' => 5,
			'min'     => 1,
			'max'     => 50,
		];

		// Inverted (default-off) control — see Base_Element::setting_bool():
		// an untouched default-true checkbox would otherwise flip the block's
		// Gutenberg default. Absent = anonymous donations shown.
		$this->controls['hideAnonymous'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Hide anonymous donations', 'suredonation' ),
			'type'  => 'checkbox',
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
			'default'  => esc_html__( 'Donate', 'suredonation' ),
			'required' => [ 'showButton', '=', true ],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-donations';
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
			'donationsToShow' => max( 1, min( 50, $this->setting_int( $settings, 'donationsToShow', 5 ) ) ),
			'showAnonymous'   => ! $this->setting_bool( $settings, 'hideAnonymous' ),
			'showButton'      => $this->setting_bool( $settings, 'showButton' ),
			'buttonText'      => $this->setting_string( $settings, 'buttonText', __( 'Donate', 'suredonation' ) ),
		];
	}
}
