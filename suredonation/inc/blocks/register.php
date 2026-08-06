<?php
/**
 * Blocks Register
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Blocks;

use SureDonation\Inc\Assets\Register as Assets_Register;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Offline\Offline_Helper;
use SureDonation\Inc\Payments\PayPal\PayPal_Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
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
		add_filter( 'block_editor_settings_all', [ $this, 'add_donation_form_iframe_styles' ], 10, 2 );
		add_filter( 'block_editor_settings_all', [ $this, 'add_phone_iframe_styles' ], 10, 2 );
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_preview_field_scripts' ] );
	}

	/**
	 * Load the dropdown and phone field libraries into the block editor canvas.
	 *
	 * The donation form embed block previews the real form through the block's PHP
	 * render_callback (ServerSideRender), which returns markup only — a REST render
	 * emits no wp_footer(), so nothing the render callback enqueues ever reaches the
	 * page. Without their libraries the dropdown stays an unstyled native <select>
	 * and the phone field renders with no country flag or dial code.
	 *
	 * `enqueue_block_assets` is the hook WordPress replays when it collects assets
	 * for the iframed canvas: _wp_get_iframed_editor_assets() fires it and returns
	 * both the printed styles AND scripts, which the canvas injects into its own
	 * document. It is explicitly the hook for front-end assets that need to run
	 * against editor content.
	 *
	 * Only the two field libraries are loaded. The payment gateways are excluded on
	 * purpose: mounting Stripe Elements or the PayPal SDK would pull third-party
	 * scripts into wp-admin on every editor load and open live gateway connections
	 * for a preview the author cannot interact with. Those keep their static
	 * placeholders (see _editor-preview.scss).
	 *
	 * Both initialisers are safe here — each is ready-state aware, guards against
	 * double-initialising via a dataset flag, and exposes a re-init hook the editor
	 * calls once ServerSideRender has injected the markup (see the block's edit
	 * component).
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function enqueue_preview_field_scripts() {
		// Front end already enqueues these per-block from the field render; this is
		// the editor-only path.
		if ( ! is_admin() ) {
			return;
		}

		// The form builder mounts its own React controls for these fields.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'suredonation_form' === $screen->post_type ) {
			return;
		}

		// The handles are registered on wp_enqueue_scripts, which never fires in
		// admin. Registration is side-effect free (wp_register_* only), so reuse it
		// rather than duplicating the definitions.
		Assets_Register::get_instance()->register_frontend_assets();

		// Each script handle already depends on its vendor library, so enqueuing the
		// initialiser pulls the library in, in the right order.
		wp_enqueue_style( 'suredonation-tom-select' );
		wp_enqueue_script( 'suredonation-dropdown' );
		wp_enqueue_style( 'suredonation-intl-tel-input' );
		wp_enqueue_script( 'suredonation-phone' );

		// The payment bundle mounts Stripe Elements and the PayPal buttons. Both are
		// client-only on mount: Stripe's elements()/mount() builds an iframe, and
		// PayPal's createOrder does not run until the button is clicked, so nothing
		// here reaches the server or creates a PaymentIntent.
		wp_enqueue_script( 'suredonation-form-frontend' );

		$this->enqueue_preview_gateway_assets();
	}

	/**
	 * Enqueue gateway assets for each donation form embedded in the current post.
	 *
	 * The PayPal SDK is enqueued by gateway code hooked to
	 * `suredonation_enqueue_form_frontend_scripts`, which the render callback fires
	 * with the form's id and content — that hook is how a gateway decides whether it
	 * is even used by the form. A REST render throws the enqueue away, so fire it
	 * here instead, for the forms this post actually embeds.
	 *
	 * Resolving the forms (rather than loading every gateway unconditionally) keeps
	 * the gateway's own `form_has_paypal()` style gating intact, so a post with no
	 * PayPal-enabled form does not pull the SDK into wp-admin.
	 *
	 * Also localises the payment settings the bundle reads, per form.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function enqueue_preview_gateway_assets() {
		$post = get_post();

		if ( ! $post instanceof \WP_Post || ! has_block( 'suredonation/donation-form', $post ) ) {
			return;
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'suredonation/donation-form' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$form_id = absint( $block['attrs']['formId'] ?? 0 );
			$form    = $form_id ? get_post( $form_id ) : null;

			if ( ! $form instanceof \WP_Post || Donation_Form::POST_TYPE !== $form->post_type ) {
				continue;
			}

			/** This action is documented in inc/blocks/donation-form/block.php */
			do_action( 'suredonation_enqueue_form_frontend_scripts', $form_id, $form->post_content );

			wp_localize_script(
				'suredonation-form-frontend',
				'suredonationPayment',
				Helper::get_form_payment_settings( $form_id )
			);
		}
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
	 * Append an inline stylesheet to the block-editor iframe settings.
	 *
	 * Styles enqueued via enqueue_block_editor_assets load in the editor's outer
	 * frame only; the block canvas is iframed, so server-side-rendered previews
	 * would otherwise render unstyled. Adding CSS here makes WordPress inject it
	 * inside the iframe, matching the frontend.
	 *
	 * @param array<string, mixed> $settings Block editor settings (by reference).
	 * @param string               $css      Stylesheet contents to inline.
	 * @return void
	 * @since 1.4.0
	 */
	private function append_iframe_style( &$settings, $css ) {
		if ( '' === $css ) {
			return;
		}

		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = [];
		}

		$settings['styles'][] = [ 'css' => $css ];
	}

	/**
	 * Read a stylesheet for iframe inlining, cached per request by file mtime so
	 * the filter (which can run more than once per load) reads each file from disk
	 * at most once until it changes.
	 *
	 * @param string                $style_file   Absolute path to the stylesheet.
	 * @param array<string, string> $replacements Optional search => replace pairs
	 *                                            applied to the CSS, e.g. to rewrite
	 *                                            relative asset URLs to absolute
	 *                                            plugin URLs so they resolve inside
	 *                                            the iframe.
	 * @return string The stylesheet contents, or '' when unavailable.
	 * @since 1.4.0
	 */
	private function read_iframe_css( $style_file, $replacements = [] ) {
		// Keyed by path so the aggregate + vendor stylesheets do not evict each
		// other's cache entry.
		static $cache = [];

		if ( ! file_exists( $style_file ) ) {
			return '';
		}

		$mtime = filemtime( $style_file );
		if ( ! isset( $cache[ $style_file ] ) || $cache[ $style_file ]['mtime'] !== $mtime ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own/vendored stylesheet to inline into the editor iframe.
			$css = file_get_contents( $style_file );
			$css = false === $css ? '' : $css;

			if ( '' !== $css && ! empty( $replacements ) ) {
				$css = str_replace( array_keys( $replacements ), array_values( $replacements ), $css );
			}

			$cache[ $style_file ] = [
				'mtime' => $mtime,
				'css'   => $css,
			];
		}

		return $cache[ $style_file ]['css'];
	}

	/**
	 * Inject the campaign block styles into the editor canvas iframe.
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

		$this->append_iframe_style(
			$settings,
			$this->read_iframe_css( SUREDONATION_DIR . 'assets/build/blocks/campaign/style-style.css' )
		);

		return $settings;
	}

	/**
	 * Inject the donation form styles into the editor canvas iframe.
	 *
	 * The donation form embed block previews the real form via ServerSideRender,
	 * and the canvas is iframed, so styles enqueued on the outer frame never reach
	 * it. Three stylesheets are inlined:
	 *
	 * - the aggregate donation-form CSS, which also carries every field block's
	 *   styles and the editor-preview reconciliation (see _editor-preview.scss);
	 * - the tom-select vendor CSS, which paints both the dropdown field's
	 *   server-rendered `.ts-wrapper` placeholder and the real control tom-select
	 *   mounts over it; and
	 * - the intl-tel-input vendor CSS, for the `.iti` wrapper that library builds
	 *   around the phone input. Its flag sprites are referenced relative to the
	 *   stylesheet, so those paths are rewritten to absolute plugin URLs — inlining
	 *   drops the base they resolve against.
	 *
	 * Both libraries genuinely run in the canvas: they are enqueued on
	 * enqueue_block_assets (see enqueue_preview_field_scripts) and re-initialised by
	 * the block's edit component once ServerSideRender has injected the markup. The
	 * payment gateways are not, so their placeholders stay static.
	 *
	 * @param array<string, mixed>     $settings Block editor settings.
	 * @param \WP_Block_Editor_Context $context  Block editor context.
	 * @return array<string, mixed> Modified settings.
	 * @since 1.4.0
	 */
	public function add_donation_form_iframe_styles( $settings, $context ) {
		// Inject wherever the embed block can be used, including the Site Editor and
		// widget contexts where $context->post is unset. Only the donation form
		// builder is excluded; it styles its own field blocks separately (see
		// add_phone_iframe_styles + form-editor).
		if ( isset( $context->post ) && 'suredonation_form' === $context->post->post_type ) {
			return $settings;
		}

		$this->append_iframe_style(
			$settings,
			$this->read_iframe_css( SUREDONATION_DIR . 'assets/build/blocks/donation-form/style-style.css' )
		);
		$this->append_iframe_style(
			$settings,
			$this->read_iframe_css( SUREDONATION_DIR . 'assets/css/vendor/tom-select.css' )
		);
		$this->append_iframe_style(
			$settings,
			$this->read_iframe_css(
				SUREDONATION_DIR . 'assets/css/vendor/intl/intlTelInput.min.css',
				[ '../intl/img/' => SUREDONATION_URL . 'assets/css/vendor/intl/img/' ]
			)
		);

		return $settings;
	}

	/**
	 * Inject the intl-tel-input stylesheet into the editor canvas iframe.
	 *
	 * The phone block renders the real intl-tel-input control in the form builder
	 * editor so its preview (flag + dial code) matches the front end. The canvas
	 * is iframed, so the library CSS is added to the editor settings rather than
	 * enqueued on the outer frame. Gated to the donation form editor, where the
	 * phone block lives. (The relative flag sprite paths are rewritten to absolute
	 * plugin URLs so they resolve inside the iframe.)
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

		$this->append_iframe_style(
			$settings,
			$this->read_iframe_css(
				SUREDONATION_DIR . 'assets/css/vendor/intl/intlTelInput.min.css',
				[ '../intl/img/' => SUREDONATION_URL . 'assets/css/vendor/intl/img/' ]
			)
		);

		return $settings;
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
					'paypal_connected'   => PayPal_Helper::is_paypal_connected(),
					'stripe_connect_url' => Stripe_Helper::get_stripe_connect_url(),
					// Base payments-settings URL; the editor's "Configure Payment
					// Account" CTA appends the block's selected gateway subpage.
					'settings_url'       => Payment_Helper::get_settings_url(),
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
