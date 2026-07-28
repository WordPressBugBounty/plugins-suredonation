<?php
/**
 * Blocks Register
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Blocks;

use SureDonation\Inc\Payments\Offline\Offline_Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register class for blocks.
 *
 * @since 0.0.1
 */
class Register {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_embed_block_script' ], 5 );
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_campaign_editor_assets' ] );
		add_filter( 'block_categories_all', [ $this, 'register_block_category' ], 10, 2 );
		add_filter( 'block_editor_settings_all', [ $this, 'add_campaign_iframe_styles' ], 10, 2 );
		add_filter( 'block_editor_settings_all', [ $this, 'add_phone_iframe_styles' ], 10, 2 );
	}

	/**
	 * Register the donation form embed block editor script.
	 *
	 * Runs before register_blocks() so the handle exists when block.json is read.
	 * Not gated by post type — the embed block should work on all post types.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_embed_block_script() {
		$asset_file = SUREDONATION_DIR . 'assets/build/blocks/donation-form/editor.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => SUREDONATION_VER,
			];

		wp_register_script(
			'suredonation-donation-form-editor',
			SUREDONATION_URL . 'assets/build/blocks/donation-form/editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Data for the block editor placeholder (logo). The campaign blocks
		// bundle defines the same global elsewhere; localizing it here keeps the
		// logo available wherever the donation form block is inserted.
		wp_localize_script(
			'suredonation-donation-form-editor',
			'suredonationCampaignBlocks',
			$this->get_campaign_blocks_data()
		);

		wp_register_style(
			'suredonation-donation-form-editor',
			SUREDONATION_URL . 'assets/build/blocks/donation-form/editor.css',
			[],
			$asset['version']
		);
	}

	/**
	 * Data localized for the block editor placeholders (logo).
	 *
	 * Shared by the donation form embed block and the campaign display blocks,
	 * both of which expose it on the `suredonationCampaignBlocks` JS global.
	 *
	 * `currentPostType` lets a block scope its editor registration to a single
	 * post type (the Campaign Donate Button registers only on the campaign
	 * editor). It is read from the current screen, so it is only populated for
	 * the caller that runs on `enqueue_block_editor_assets` (the campaign editor
	 * assets); the embed-block caller runs on `init`, where there is no screen,
	 * so it receives an empty string. That is harmless — the embed block only
	 * consumes `logoUrl`.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function get_campaign_blocks_data() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return [
			'logoUrl'         => esc_url_raw( SUREDONATION_URL . 'images/suredonation-logo.svg' ),
			'currentPostType' => $screen ? (string) $screen->post_type : '',
		];
	}

	/**
	 * Register custom block category for SureDonation blocks.
	 *
	 * The field-block category is limited to the donation form editor; the
	 * campaign display-block category is registered everywhere else.
	 *
	 * @param array<int, array<string, mixed>> $categories Existing block categories.
	 * @param \WP_Block_Editor_Context         $context    Block editor context.
	 * @return array<int, array<string, mixed>> Modified block categories.
	 * @since 0.0.1
	 */
	public function register_block_category( $categories, $context ) {
		// Field-block category on the donation form editor.
		if ( isset( $context->post ) && 'suredonation_form' === $context->post->post_type ) {
			return array_merge(
				[
					[
						'slug'  => 'suredonation',
						'title' => __( 'General Fields', 'suredonation' ),
						'icon'  => null,
					],
				],
				$categories
			);
		}

		// Campaign display-block category on every other editor — including the
		// Site Editor and widget contexts where $context->post is unset — so the
		// campaign blocks always group under SureDonation in the inserter. Only
		// the donation form editor (handled above) is excluded.
		return array_merge(
			[
				[
					'slug'  => 'suredonation-campaign',
					'title' => __( 'SureDonation', 'suredonation' ),
					'icon'  => null,
				],
			],
			$categories
		);
	}

	/**
	 * Enqueue the campaign display blocks editor bundle.
	 *
	 * Loads on every block editor so the campaign blocks can be added to any
	 * page/post/CPT — except the donation form editor, which has its own field
	 * blocks. On a campaign post the blocks auto-bind to that campaign; elsewhere
	 * the block inspector exposes a campaign selector.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_campaign_editor_assets() {
		$screen = get_current_screen();

		// Load everywhere except the donation form editor.
		if ( ! $screen || 'suredonation_form' === $screen->post_type ) {
			return;
		}

		$asset_file = SUREDONATION_DIR . 'assets/build/campaign-blocks.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-data', 'wp-server-side-render' ],
				'version'      => SUREDONATION_VER,
			];

		wp_enqueue_script(
			'suredonation-campaign-blocks',
			SUREDONATION_URL . 'assets/build/campaign-blocks.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'suredonation-campaign-blocks', 'suredonation' );

		// Data for the campaign block editor placeholder (logo).
		wp_localize_script(
			'suredonation-campaign-blocks',
			'suredonationCampaignBlocks',
			$this->get_campaign_blocks_data()
		);

		// Style the server-side-rendered block previews in the editor.
		$style_file    = SUREDONATION_DIR . 'assets/build/blocks/campaign/style-style.css';
		$style_version = file_exists( $style_file )
			? (string) filemtime( $style_file )
			: SUREDONATION_VER;

		wp_enqueue_style(
			'suredonation-campaign-blocks',
			SUREDONATION_URL . 'assets/build/blocks/campaign/style-style.css',
			[],
			$style_version
		);
	}

	/**
	 * Inject the campaign block styles into the editor canvas iframe.
	 *
	 * Styles enqueued via enqueue_block_editor_assets load in the editor's outer
	 * frame only; the block canvas is iframed, so the server-side-rendered campaign
	 * block previews would otherwise render unstyled. Adding the CSS to the editor
	 * settings makes WordPress inject it inside the iframe, matching the frontend.
	 *
	 * @param array<string, mixed>     $settings Block editor settings.
	 * @param \WP_Block_Editor_Context $context  Block editor context.
	 * @return array<string, mixed> Modified settings.
	 * @since 1.0.0
	 */
	public function add_campaign_iframe_styles( $settings, $context ) {
		// Inject wherever the campaign blocks can be used (everywhere except the
		// donation form editor), so their editor previews match the frontend.
		if ( ! isset( $context->post ) || 'suredonation_form' === $context->post->post_type ) {
			return $settings;
		}

		$css = $this->get_campaign_iframe_css();
		if ( '' === $css ) {
			return $settings;
		}

		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = [];
		}

		$settings['styles'][] = [ 'css' => $css ];

		return $settings;
	}

	/**
	 * Read the built campaign stylesheet, cached per request by file mtime so
	 * the filter (which can run more than once per load) reads from disk at most
	 * once until the asset changes.
	 *
	 * @return string The stylesheet contents, or '' when unavailable.
	 * @since 1.0.0
	 */
	private function get_campaign_iframe_css() {
		static $cached_css   = null;
		static $cached_mtime = null;

		$style_file = SUREDONATION_DIR . 'assets/build/blocks/campaign/style-style.css';
		if ( ! file_exists( $style_file ) ) {
			return '';
		}

		$mtime = filemtime( $style_file );
		if ( null === $cached_css || $cached_mtime !== $mtime ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own built stylesheet to inline into the editor iframe.
			$css          = file_get_contents( $style_file );
			$cached_css   = false === $css ? '' : $css;
			$cached_mtime = $mtime;
		}

		return $cached_css;
	}

	/**
	 * Inject the intl-tel-input stylesheet into the editor canvas iframe.
	 *
	 * The phone block renders the real intl-tel-input control in the editor so
	 * its preview (flag + dial code) matches the front end. The library's CSS is
	 * needed inside the canvas, which is iframed, so we add it to the editor
	 * settings (the same mechanism used for the campaign block previews) rather
	 * than enqueuing it in the outer frame where the iframe can't reach it.
	 * Gated to the donation form editor, where the phone block lives.
	 *
	 * @param array<string, mixed>     $settings Block editor settings.
	 * @param \WP_Block_Editor_Context $context  Block editor context.
	 * @return array<string, mixed> Modified settings.
	 * @since 1.1.1
	 */
	public function add_phone_iframe_styles( $settings, $context ) {
		// Only the donation form editor uses the field blocks (incl. phone).
		if ( ! isset( $context->post ) || 'suredonation_form' !== $context->post->post_type ) {
			return $settings;
		}

		$css = $this->get_phone_iframe_css();
		if ( '' === $css ) {
			return $settings;
		}

		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = [];
		}

		$settings['styles'][] = [ 'css' => $css ];

		return $settings;
	}

	/**
	 * Read the vendored intl-tel-input stylesheet, cached per request by file
	 * mtime so the filter (which can run more than once per load) reads from disk
	 * at most once until the asset changes.
	 *
	 * @return string The stylesheet contents, or '' when unavailable.
	 * @since 1.1.1
	 */
	private function get_phone_iframe_css() {
		static $cached_css   = null;
		static $cached_mtime = null;

		$style_file = SUREDONATION_DIR . 'assets/css/vendor/intl/intlTelInput.min.css';
		if ( ! file_exists( $style_file ) ) {
			return '';
		}

		$mtime = filemtime( $style_file );
		if ( null === $cached_css || $cached_mtime !== $mtime ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's vendored stylesheet to inline into the editor iframe.
			$css = file_get_contents( $style_file );

			if ( false === $css ) {
				$cached_css = '';
			} else {
				// The stylesheet references the flag/globe sprites with paths
				// relative to its own location (../intl/img/…). Inlining drops
				// that base, so rewrite them to absolute plugin URLs so the
				// flags resolve inside the iframe.
				$img_url    = SUREDONATION_URL . 'assets/css/vendor/intl/img/';
				$cached_css = str_replace( '../intl/img/', $img_url, $css );
			}

			$cached_mtime = $mtime;
		}

		return $cached_css;
	}

	/**
	 * Enqueue block editor assets.
	 *
	 * Only loads on the donation form editor.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function enqueue_editor_assets() {
		$screen = get_current_screen();

		// Only load on donation form editor.
		if ( ! $screen || 'suredonation_form' !== $screen->post_type ) {
			return;
		}

		// Use the asset.php content hash as the version so rebuilds bust the
		// browser cache. Falls back to SUREDONATION_VER if the asset file
		// is missing.
		$blocks_asset_file = SUREDONATION_DIR . 'assets/build/blocks.asset.php';
		$blocks_asset      = file_exists( $blocks_asset_file )
			? require $blocks_asset_file
			: [
				'dependencies' => [ 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-data' ],
				'version'      => SUREDONATION_VER,
			];

		// Enqueue the blocks script.
		wp_enqueue_script(
			'suredonation-blocks',
			SUREDONATION_URL . 'assets/build/blocks.js',
			$blocks_asset['dependencies'],
			$blocks_asset['version'],
			true
		);

		// Load JS translations for blocks.
		wp_set_script_translations( 'suredonation-blocks', 'suredonation' );

		// Localize script with admin data for blocks.
		$global_currency = Payment_Helper::get_currency();

		wp_localize_script(
			'suredonation-blocks',
			'suredonation_admin',
			[
				'payments'           => [
					'stripe_connected'   => Stripe_Helper::is_stripe_connected(),
					'stripe_connect_url' => Stripe_Helper::get_stripe_connect_url(),
					'settings_url'       => admin_url( 'admin.php?page=suredonation#/settings?tab=payments' ),
					'offline_enabled'    => Offline_Helper::is_offline_enabled(),
					'gateways'           => apply_filters(
						'suredonation_editor_payment_gateways',
						[
							[
								'value'              => 'stripe',
								'label'              => __( 'Stripe', 'suredonation' ),
								'supports_recurring' => true,
							],
							[
								'value'              => 'offline',
								'label'              => __( 'Offline Donations', 'suredonation' ),
								'supports_recurring' => false,
							],
						]
					),
				],
				'fee_recovery'       => Payment_Helper::get_fee_recovery_settings(),
				'currency'           => $global_currency,
				'currencySymbol'     => Payment_Helper::get_currency_symbol( $global_currency ),
				// Resolved default validation messages so the editor can show
				// them as placeholders on each field's Error Message control.
				'validationMessages' => \SureDonation\Inc\Field_Validation::get_resolved_validation_messages(),
			]
		);
	}

	/**
	 * Register all blocks.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_blocks() {
		$blocks = [
			[
				'dir'       => SUREDONATION_DIR . 'inc/blocks/**/*.php',
				'namespace' => 'SureDonation\\Inc\\Blocks',
			],
		];

		/**
		 * Filter to add and register additional blocks.
		 *
		 * @param array<int, array<string, string>> $additional_blocks Additional blocks to register.
		 */
		$additional_blocks = apply_filters( 'suredonation_register_additional_blocks', [] );

		if ( ! empty( $additional_blocks ) && is_array( $additional_blocks ) && count( $additional_blocks ) > 0 ) {
			$blocks = [ ...$blocks, ...$additional_blocks ];
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['dir'] ) || ! isset( $block['namespace'] ) ) {
				continue;
			}
			$block_files = glob( $block['dir'] );
			if ( is_array( $block_files ) ) {
				$this->register_block( $block_files, $block['namespace'], 'Block' );
			}
		}
	}

	/**
	 * Register blocks from directory.
	 *
	 * @param array<int, string> $blocks_dir      Array of block file paths.
	 * @param string             $block_namespace Block namespace.
	 * @param string             $base            Base class name.
	 * @return void
	 * @since 0.0.1
	 */
	public function register_block( $blocks_dir, $block_namespace, $base ) {
		if ( empty( $blocks_dir ) ) {
			return;
		}

		foreach ( $blocks_dir as $filename ) {
			// Skip base.php and register.php.
			$basename = basename( $filename );
			if ( 'base.php' === $basename || 'register.php' === $basename ) {
				continue;
			}

			require_once $filename;

			// Replace hyphens with underscores in directory name.
			$classname = str_replace( '-', '_', basename( dirname( $filename ) ) );

			// Convert to title case.
			$classname = ucwords( $classname, '_' );

			$full_class_name = $block_namespace . '\\' . $classname . '\\' . $base;

			// Check if the class exists.
			if ( class_exists( $full_class_name ) ) {
				$block = new $full_class_name();

				// Call register on the block object.
				if ( method_exists( $block, 'register' ) ) {
					$block->register();
				}
			}
		}
	}
}
