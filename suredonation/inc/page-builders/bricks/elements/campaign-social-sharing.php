<?php
/**
 * Bricks element: Campaign Social Sharing.
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
 * Campaign_Social_Sharing class.
 *
 * @since 1.2.0
 */
class Campaign_Social_Sharing extends Base_Element {
	/**
	 * Default networks (matches the block.json default).
	 *
	 * @since 1.2.0
	 */
	private const DEFAULT_NETWORKS = [ 'twitter', 'facebook', 'linkedin', 'pinterest', 'bluesky' ];

	/**
	 * All valid network keys (must match the control options and the block's
	 * get_networks()).
	 *
	 * @since 1.2.0
	 */
	private const VALID_NETWORKS = [ 'twitter', 'facebook', 'linkedin', 'pinterest', 'mastodon', 'threads', 'bluesky' ];

	/**
	 * Element name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $name = 'suredonation-campaign-social-sharing';

	/**
	 * Element icon.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $icon = 'ti-share';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Campaign Social Sharing', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'social', 'share', 'sharing' ] );
	}

	/**
	 * Register the element controls.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		$this->controls['headline'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Headline', 'suredonation' ),
			'type'    => 'text',
			'default' => esc_html__( 'Share:', 'suredonation' ),
		];

		$this->controls['networks'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Social networks', 'suredonation' ),
			'type'     => 'select',
			'multiple' => true,
			'options'  => [
				'twitter'   => esc_html__( 'Twitter / X', 'suredonation' ),
				'facebook'  => esc_html__( 'Facebook', 'suredonation' ),
				'linkedin'  => esc_html__( 'LinkedIn', 'suredonation' ),
				'pinterest' => esc_html__( 'Pinterest', 'suredonation' ),
				'mastodon'  => esc_html__( 'Mastodon', 'suredonation' ),
				'threads'   => esc_html__( 'Threads', 'suredonation' ),
				'bluesky'   => esc_html__( 'Bluesky', 'suredonation' ),
			],
			'default'  => self::DEFAULT_NETWORKS,
		];

		// Inverted (default-off) control — see Base_Element::setting_bool():
		// an untouched default-true checkbox would otherwise flip the block's
		// Gutenberg default. Absent = links open in a new tab.
		$this->controls['openInSameTab'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Open Links In Same Tab', 'suredonation' ),
			'type'  => 'checkbox',
		];

		$this->controls['contentAlign'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Alignment', 'suredonation' ),
			'type'    => 'select',
			'default' => 'left',
			'options' => [
				'left'   => esc_html__( 'Left', 'suredonation' ),
				'center' => esc_html__( 'Center', 'suredonation' ),
				'right'  => esc_html__( 'Right', 'suredonation' ),
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/campaign-social-sharing';
	}

	/**
	 * Map the element settings to the block attributes.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		// Allowlist at the element level too (the block sink re-validates) —
		// consistent with the statistic/contentAlign handling.
		$networks = isset( $settings['networks'] ) && is_array( $settings['networks'] )
			? array_values( array_intersect( array_map( 'strval', $settings['networks'] ), self::VALID_NETWORKS ) )
			: self::DEFAULT_NETWORKS;

		$align = $this->setting_string( $settings, 'contentAlign', 'left' );

		return [
			'headline'     => $this->setting_string( $settings, 'headline', __( 'Share:', 'suredonation' ) ),
			'networks'     => $networks,
			'openInNewTab' => ! $this->setting_bool( $settings, 'openInSameTab' ),
			'contentAlign' => in_array( $align, [ 'left', 'center', 'right' ], true ) ? $align : 'left',
		];
	}
}
