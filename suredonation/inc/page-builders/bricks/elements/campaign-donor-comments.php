<?php
/**
 * Bricks element: Donor Comments.
 *
 * @package SureDonation
 * @since 1.6.0
 */

namespace SureDonation\Inc\Page_Builders\Bricks\Elements;

use SureDonation\Inc\Page_Builders\Bricks\Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign_Donor_Comments class.
 *
 * @since 1.6.0
 */
class Campaign_Donor_Comments extends Base_Element {
	/**
	 * Element name.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public $name = 'suredonation-campaign-donor-comments';

	/**
	 * Element icon.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public $icon = 'ti-comments';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.6.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Donor Comments', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'comments', 'messages', 'wall' ] );
	}

	/**
	 * Register the element controls.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		$this->controls['commentsToShow'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Number of comments', 'suredonation' ),
			'type'    => 'number',
			'default' => 5,
			'min'     => 1,
			'max'     => 50,
		];

		// Inverted (default-off) controls — see Base_Element::setting_bool():
		// an untouched default-true checkbox would otherwise flip the block's
		// Gutenberg default. Absent = the corresponding element is shown.
		$this->controls['hideAnonymous'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Hide comments on anonymous donations', 'suredonation' ),
			'description' => esc_html__( 'By default the comment is kept and the donor name is shown as Anonymous.', 'suredonation' ),
			'type'        => 'checkbox',
		];

		$this->controls['hideAvatar'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Hide avatar', 'suredonation' ),
			'type'  => 'checkbox',
		];

		$this->controls['hideAmount'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Hide amount', 'suredonation' ),
			'type'  => 'checkbox',
		];

		$this->controls['hideDate'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Hide date', 'suredonation' ),
			'type'  => 'checkbox',
		];

		$this->controls['commentLength'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Comment length', 'suredonation' ),
			'description' => esc_html__( 'Longer comments are trimmed to this many characters with a read-more link. Set to 0 to always show the whole comment.', 'suredonation' ),
			'type'        => 'number',
			'default'     => 150,
			'min'         => 0,
			'max'         => 1000,
		];

		$this->controls['readMoreText'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Read more text', 'suredonation' ),
			'type'    => 'text',
			'default' => esc_html__( 'Read more', 'suredonation' ),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-donor-comments';
	}

	/**
	 * Map the element settings to the block attributes.
	 *
	 * @since 1.6.0
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		return [
			'commentsToShow' => max( 1, min( 50, $this->setting_int( $settings, 'commentsToShow', 5 ) ) ),
			'showAnonymous'  => ! $this->setting_bool( $settings, 'hideAnonymous' ),
			'showAvatar'     => ! $this->setting_bool( $settings, 'hideAvatar' ),
			'showAmount'     => ! $this->setting_bool( $settings, 'hideAmount' ),
			'showDate'       => ! $this->setting_bool( $settings, 'hideDate' ),
			'commentLength'  => min( 1000, $this->setting_int( $settings, 'commentLength', 150 ) ),
			'readMoreText'   => $this->setting_string( $settings, 'readMoreText', __( 'Read more', 'suredonation' ) ),
		];
	}
}
