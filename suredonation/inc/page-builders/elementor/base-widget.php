<?php
/**
 * Base Elementor widget.
 *
 * Shared plumbing for SureDonation's Elementor widgets: the widget category, the
 * campaign selector control, and a render() that emits the matching SureDonation
 * block's server-side output (so the widget stays 1:1 with Gutenberg).
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Elementor;

use Elementor\Controls_Manager;
use Elementor\Plugin;
use Elementor\Widget_Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Page_Builders\Page_Builders;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Base_Widget class.
 *
 * @since 1.2.0
 */
abstract class Base_Widget extends Widget_Base {

	/**
	 * Widget category — every SureDonation widget lives under one category.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public function get_categories() {
		return [ Service_Provider::CATEGORY ];
	}

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
	 * Declare the campaign block styles as a widget dependency so Elementor
	 * enqueues them in the head whenever the widget is present. The block
	 * render's own wp_enqueue_style() would only run during the_content
	 * (footer print → FOUC); it remains as the non-Elementor fallback.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public function get_style_depends() {
		return [ 'suredonation-campaign-blocks' ];
	}
	/**
	 * The SureDonation block this widget renders (e.g. `suredonation/campaign-stats`).
	 *
	 * @since 1.2.0
	 * @return string
	 */
	abstract protected function block_name();

	/**
	 * Map the widget settings to the block's attributes (camelCase, matching
	 * the block's Gutenberg attributes).
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Widget settings.
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
		$this->add_control(
			'campaign_id',
			[
				'label'       => esc_html__( 'Campaign', 'suredonation' ),
				'type'        => Controls_Manager::SELECT2,
				'label_block' => true,
				'options'     => Page_Builders::get_campaign_options(),
				'default'     => '',
				'description' => esc_html__( 'Leave empty to use the current campaign when placed on a campaign page.', 'suredonation' ),
			]
		);
	}

	/**
	 * Render the widget by emitting the matching block's server-side output.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	protected function render() {
		$settings    = $this->get_settings_for_display();
		$campaign_id = $this->setting_int( $settings, 'campaign_id' );
		$resolved    = Campaign_Page::resolve_campaign_id( [ 'campaignId' => $campaign_id ] );

		if ( ! $resolved ) {
			if ( $this->is_edit_mode() ) {
				$this->render_placeholder( esc_html__( 'Select a campaign to preview this widget.', 'suredonation' ) );
			}
			return;
		}

		$attrs               = $this->get_block_attrs( $settings );
		$attrs['campaignId'] = $resolved;

		$this->echo_block( $attrs );
	}

	/**
	 * Emit the widget's block with the given attributes. render_block() runs the
	 * block's registered render_callback, producing the exact same
	 * (already-escaped) markup as the Gutenberg block.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return void
	 */
	protected function echo_block( array $attrs ) {
		echo render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block render output is escaped in the block's render callback.
			[
				'blockName'    => $this->block_name(),
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Whether Elementor is in edit mode.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	protected function is_edit_mode() {
		return class_exists( '\Elementor\Plugin' ) && Plugin::instance()->editor->is_edit_mode();
	}

	/**
	 * Print an editor-only placeholder message.
	 *
	 * @since 1.2.0
	 * @param string $message Placeholder text.
	 * @return void
	 */
	protected function render_placeholder( $message ) {
		printf(
			'<div class="suredonation-elementor-placeholder" style="padding:20px;text-align:center;border:1px dashed #c3c4c7;border-radius:6px;color:#50575e;">%s</div>',
			esc_html( $message )
		);
	}

	/**
	 * Read a setting as a string (settings values are mixed).
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Widget settings.
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
	 * @param array<string, mixed> $settings Widget settings.
	 * @param string               $key      Setting key.
	 * @param int                  $fallback  Fallback when unset.
	 * @return int
	 */
	protected function setting_int( $settings, $key, $fallback = 0 ) {
		return isset( $settings[ $key ] ) ? absint( Helper::get_string_value( $settings[ $key ] ) ) : $fallback;
	}

	/**
	 * Read an Elementor SWITCHER setting as a boolean ('yes' === on).
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Widget settings.
	 * @param string               $key      Setting key.
	 * @param bool                 $fallback  Fallback when unset.
	 * @return bool
	 */
	protected function setting_bool( $settings, $key, $fallback = false ) {
		if ( ! isset( $settings[ $key ] ) ) {
			return $fallback;
		}
		return 'yes' === Helper::get_string_value( $settings[ $key ] );
	}
}
