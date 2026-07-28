<?php
/**
 * Base Bricks element.
 *
 * Shared plumbing for SureDonation's Bricks elements: the element category, the
 * campaign selector control, and a render() that emits the matching SureDonation
 * block's server-side output (so the element stays 1:1 with Gutenberg).
 *
 * Only ever loaded through \Bricks\Elements::register_element() behind a
 * class_exists( '\Bricks\Elements' ) gate — never reference element classes
 * from generic plugin code (the \Bricks\Element parent won't exist).
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Bricks;

use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Page_Builders\Page_Builders;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Base_Element class.
 *
 * @since 1.2.0
 */
abstract class Base_Element extends \Bricks\Element {
	/**
	 * Element category — every SureDonation element lives under one category.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $category = Service_Provider::CATEGORY;

	/**
	 * Default keywords; subclasses may extend.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public function get_keywords() {
		return [ 'suredonation', 'donation', 'campaign' ];
	}

	/**
	 * Load the campaign block styles on the front end and in the builder canvas.
	 * The block render enqueues the same handle itself; this covers the builder,
	 * where the element markup is rendered before that enqueue can take effect.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'suredonation-campaign-blocks' );
	}

	/**
	 * Render the element by emitting the matching block's server-side output.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function render() {
		$settings = $this->settings;
		$resolved = Campaign_Page::resolve_campaign_id(
			[ 'campaignId' => $this->setting_int( $settings, 'campaignId' ) ]
		);

		if ( ! $resolved ) {
			// Builder-only info box; on the front end this renders nothing,
			// matching the block's empty output (self-gated on is_frontend).
			$this->render_element_placeholder(
				[
					'icon-class'  => $this->icon,
					'description' => esc_html__( 'Select a campaign, or place this element on a campaign page.', 'suredonation' ),
				]
			);
			return;
		}

		$attrs               = $this->get_block_attrs( $settings );
		$attrs['campaignId'] = $resolved;

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks root attributes are escaped by the builder.

		// render_block() runs the block's registered render_callback, producing the
		// exact same (already-escaped) markup as the Gutenberg block.
		echo render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block render output is escaped in the block's render callback.
			[
				'blockName'    => $this->block_name(),
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

		echo '</div>';
	}

	/**
	 * The SureDonation block this element renders (e.g. `suredonation/campaign-stats`).
	 *
	 * @since 1.2.0
	 * @return string
	 */
	abstract protected function block_name();

	/**
	 * Map the element settings to the block's attributes (camelCase, matching
	 * the block's Gutenberg attributes).
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	abstract protected function get_block_attrs( $settings );

	/**
	 * Add the shared "Campaign" selector control (published campaigns).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	protected function add_campaign_control() {
		$options = Page_Builders::get_campaign_options();
		unset( $options[''] ); // Bricks selects use 'placeholder', not an empty option.

		$this->controls['campaignId'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Campaign', 'suredonation' ),
			'type'        => 'select',
			'options'     => $options,
			'searchable'  => true,
			'clearable'   => true,
			'placeholder' => esc_html__( 'Select a campaign', 'suredonation' ),
			'description' => esc_html__( 'Leave empty to use the current campaign when placed on a campaign page.', 'suredonation' ),
		];
	}

	/**
	 * Read a setting as a string (settings values are mixed).
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @param string               $key      Setting key.
	 * @param string               $fallback  Fallback when empty/unset.
	 * @return string
	 */
	protected function setting_string( $settings, $key, $fallback = '' ) {
		if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] ) {
			return $fallback;
		}
		return Helper::get_string_value( $settings[ $key ] );
	}

	/**
	 * Read a setting as a non-negative integer.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @param string               $key      Setting key.
	 * @param int                  $fallback  Fallback when unset.
	 * @return int
	 */
	protected function setting_int( $settings, $key, $fallback = 0 ) {
		return isset( $settings[ $key ] ) ? absint( Helper::get_string_value( $settings[ $key ] ) ) : $fallback;
	}

	/**
	 * Read a Bricks checkbox setting as a boolean. Bricks stores `true` when
	 * checked and omits the key when unchecked — and it NEVER seeds a control's
	 * 'default' into settings, so absence always reads as false.
	 *
	 * Contract: this helper is safe only for controls whose Gutenberg block
	 * default is false. A default-true block option MUST be modeled as an
	 * inverted, default-off control (e.g. hideProgressBar → ! setting_bool()),
	 * otherwise an untouched element passes an explicit false to render_block()
	 * and flips the block.json default (which only fills MISSING attributes).
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @param string               $key      Setting key.
	 * @return bool
	 */
	protected function setting_bool( $settings, $key ) {
		return isset( $settings[ $key ] ) && false !== $settings[ $key ];
	}
}
