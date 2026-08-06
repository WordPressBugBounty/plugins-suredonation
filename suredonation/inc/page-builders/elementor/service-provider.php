<?php
/**
 * Elementor service provider.
 *
 * Registers the SureDonation widget category and all campaign/donation widgets
 * with Elementor, and loads the campaign block styles into the editor preview
 * so the widgets render styled while editing.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Elementor;

use SureDonation\Inc\Page_Builders\Elementor\Widgets\Campaign_Donate_Button_Widget;
use SureDonation\Inc\Page_Builders\Elementor\Widgets\Campaign_Donations_Widget;
use SureDonation\Inc\Page_Builders\Elementor\Widgets\Campaign_Donors_Widget;
use SureDonation\Inc\Page_Builders\Elementor\Widgets\Campaign_Goal_Widget;
use SureDonation\Inc\Page_Builders\Elementor\Widgets\Campaign_Social_Sharing_Widget;
use SureDonation\Inc\Page_Builders\Elementor\Widgets\Campaign_Stats_Widget;
use SureDonation\Inc\Page_Builders\Elementor\Widgets\Donation_Form_Widget;
use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Service_Provider class.
 *
 * @since 1.2.0
 */
class Service_Provider {
	use Get_Instance;

	/**
	 * Widget category slug.
	 *
	 * @since 1.2.0
	 */
	public const CATEGORY = 'suredonation';

	/**
	 * Constructor — register hooks only when Elementor is available.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
		add_action( 'elementor/preview/enqueue_styles', [ $this, 'enqueue_preview_assets' ] );
		add_action( 'elementor/editor/before_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
	}

	/**
	 * Load the editor-panel script that backs the Donation Form widget's
	 * "Edit Form" button.
	 *
	 * Runs in the Elementor editor panel (not the preview iframe), which is
	 * where the control's `event` is broadcast on `elementor.channels.editor`.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function enqueue_editor_assets() {
		wp_enqueue_script(
			'suredonation-elementor-editor',
			plugins_url( 'assets/editor.js', __FILE__ ),
			[],
			SUREDONATION_VER,
			true
		);

		wp_localize_script(
			'suredonation-elementor-editor',
			'suredonationElementorData',
			[
				'adminUrl' => admin_url(),
			]
		);
	}

	/**
	 * Register the SureDonation widget category.
	 *
	 * @since 1.2.0
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ) {
		if ( ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			[
				'title' => esc_html__( 'SureDonation', 'suredonation' ),
				'icon'  => 'eicon-heart',
			]
		);
	}

	/**
	 * Register every SureDonation widget.
	 *
	 * @since 1.2.0
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ) {
		if ( ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		$widget_classes = [
			Donation_Form_Widget::class,
			Campaign_Goal_Widget::class,
			Campaign_Stats_Widget::class,
			Campaign_Donations_Widget::class,
			Campaign_Donors_Widget::class,
			Campaign_Donate_Button_Widget::class,
			Campaign_Social_Sharing_Widget::class,
		];

		// Construct inside the loop so one broken widget can't abort
		// registration of the others.
		foreach ( $widget_classes as $widget_class ) {
			$widgets_manager->register( new $widget_class() );
		}
	}

	/**
	 * Load the campaign block styles into the Elementor editor preview so the
	 * server-rendered widgets look correct while editing. On the front end each
	 * block's render_callback enqueues the same handle itself.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function enqueue_preview_assets() {
		wp_enqueue_style( 'suredonation-campaign-blocks' );
	}
}
