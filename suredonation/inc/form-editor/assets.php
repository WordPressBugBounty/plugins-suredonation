<?php
/**
 * Form Editor Assets.
 *
 * Handles asset registration and enqueuing for the donation form editor.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\FormEditor;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;
use SureDonation\Inc\Post_Types\Donation_Form;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assets Class.
 *
 * @since 0.0.1
 */
class Assets {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		// The stylesheet is registered separately, on the hook WordPress replays
		// inside the editor canvas iframe. See enqueue_editor_styles().
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_editor_styles' ] );
		add_action( 'init', [ $this, 'register_form_settings_meta' ] );
		add_action( 'init', [ $this, 'register_email_notifications_meta' ] );
	}

	/**
	 * Enqueue editor assets for the donation form editor.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function enqueue_editor_assets() {
		// Only load on donation form editor.
		if ( ! $this->is_form_editor_screen() ) {
			return;
		}

		// Core's bundled CodeMirror (CSS mode) backs the Custom CSS tab in the form
		// settings dialog. Returns false when the user turned syntax highlighting
		// off in their profile; the tab falls back to a plain textarea then.
		wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
		wp_enqueue_script( 'wp-theme-plugin-editor' );
		wp_enqueue_style( 'wp-codemirror' );

		$editor_asset = $this->get_editor_asset();

		// Editor plugin JS.
		wp_enqueue_script(
			'suredonation-form-editor',
			SUREDONATION_URL . 'assets/build/editor/editor.js',
			$editor_asset['dependencies'],
			$editor_asset['version'],
			true
		);

		// Editor styles are enqueued from enqueue_editor_styles(), not here.

		// The OttoKit embed script (defines window.SureTriggers) is NOT enqueued
		// here — it is remote executable JS and would load on every form-editor
		// session even when OttoKit is absent. It is lazy-injected from the
		// OttoKit tab only when the builder opens (see OttoKitSettings.js), using
		// the URL passed below.

		// Localized data.
		$global_currency = Payment_Helper::get_global_setting( 'currency', 'USD' );
		$global_currency = is_string( $global_currency ) ? $global_currency : 'USD';

		$editor_data = [
			'ajaxUrl'                   => admin_url( 'admin-ajax.php' ),
			'nonce'                     => wp_create_nonce( 'suredonation_editor_nonce' ),
			'postType'                  => Donation_Form::POST_TYPE,
			'smartTags'                 => Helper::get_smart_tags()['confirmation'],
			'emailSmartTags'            => Helper::get_smart_tags()['email_grouped'],
			'defaultEmailNotifications' => $this->get_default_email_notifications(),
			// The recurring payment type is a Pro control, but the block attribute
			// it sets is registered in free and persists in post content. Without
			// this, deactivating Pro leaves the recurring notifications on screen
			// and editable while nothing can send them.
			'isProActive'               => defined( 'SUREDONATION_PRO_VER' ),
			'currency'                  => $global_currency,
			'currencySymbol'            => Payment_Helper::get_currency_symbol( $global_currency ),
			// OttoKit integration: embed config + lazy-loaded builder script.
			'suretriggersNonce'         => wp_create_nonce( 'suredonation_suretriggers_nonce' ),
			'embedScriptUrl'            => SUREDONATION_SURETRIGGERS_INTEGRATION_BASE_URL . 'js/v2/embed.js',
			'integrations'              => [
				'sure_triggers' => Helper::get_ottokit_integration(),
			],
		];

		// Plugin install/activate nonces are a plugin-management capability;
		// only expose them to users who can actually install plugins. The AJAX
		// handlers enforce this server-side too — this keeps the nonces out of
		// the page source for sub-admins who reach the editor via post.php.
		if ( current_user_can( 'install_plugins' ) ) {
			$editor_data['pluginInstallerNonce'] = wp_create_nonce( 'updates' );
			$editor_data['pluginManagerNonce']   = wp_create_nonce( 'suredonation_plugin_manager' );
		}

		wp_localize_script(
			'suredonation-form-editor',
			'suredonationFormEditor',
			$editor_data
		);

		// Set script translations.
		wp_set_script_translations( 'suredonation-form-editor', 'suredonation' );
	}

	/**
	 * Enqueue the form editor stylesheet.
	 *
	 * Split out from enqueue_editor_assets() because the two need different
	 * hooks. `enqueue_block_editor_assets` only reaches the admin document, but
	 * most of this stylesheet targets `.editor-styles-wrapper` — the block
	 * canvas, which is an iframe. WordPress used to paper over that with a
	 * compatibility pass that clones any outer stylesheet mentioning
	 * `.editor-styles-wrapper` into the canvas, logging "<handle> was added to
	 * the iframe incorrectly" for each one (see the block editor's `Iframe`
	 * component). `enqueue_block_assets` is the supported hook instead:
	 * core replays it inside _wp_get_iframed_editor_assets() to build the
	 * canvas document, so the styles land there directly and the compatibility
	 * pass skips the handle instead of cloning it.
	 *
	 * The hook fires for the admin document as well, so a single enqueue here
	 * covers the editor chrome too and the handle stays `suredonation-form-editor`
	 * — the id core matches on when deciding whether a clone is still needed.
	 *
	 * One consequence of the move: `enqueue_block_assets` reaches the admin
	 * document only through wp_common_block_scripts_and_styles(), which bails when
	 * `should_load_block_editor_scripts_and_styles` is filtered false in wp-admin.
	 * Anything doing that also strips `wp-block-library` and visibly breaks core's
	 * own editor, so it is not a case worth defending against here.
	 *
	 * @return void
	 * @since x.x.x
	 */
	public function enqueue_editor_styles() {
		// `enqueue_block_assets` also fires on the front end, where there is no
		// editor to style.
		if ( ! is_admin() || ! $this->is_form_editor_screen() ) {
			return;
		}

		/*
		 * No `wp-components` dependency. It is already in both documents ahead of
		 * this sheet without being asked for: the admin page loads it as editor
		 * chrome, and _wp_get_iframed_editor_assets() enqueues `wp-edit-blocks`
		 * — whose dependencies include `wp-components` — before it fires
		 * `enqueue_block_assets`. Declaring it would neither change the cascade nor
		 * keep anything out of the canvas.
		 */
		wp_enqueue_style(
			'suredonation-form-editor',
			SUREDONATION_URL . 'assets/build/editor/editor.css',
			[],
			$this->get_editor_asset()['version']
		);
	}

	/**
	 * Whether the current admin screen is the donation form editor.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	private function is_form_editor_screen() {
		// get_current_screen() lives in an admin include, so it is missing on the
		// front end — where enqueue_block_assets also fires. Checked here rather
		// than at each call site so the helper is safe for any caller.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen && Donation_Form::POST_TYPE === $screen->post_type;
	}

	/**
	 * Build metadata (dependencies and version) for the editor bundle.
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 * @since x.x.x
	 */
	private function get_editor_asset() {
		$editor_asset_file = SUREDONATION_DIR . 'assets/build/editor/editor.asset.php';

		return file_exists( $editor_asset_file )
			? include $editor_asset_file
			: [
				'dependencies' => [
					'wp-plugins',
					'wp-editor',
					'wp-components',
					'wp-data',
					'wp-element',
					'wp-i18n',
				],
				'version'      => SUREDONATION_VER,
			];
	}

	/**
	 * Register form settings meta fields.
	 *
	 * Stores form confirmation settings as a single JSON string.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_form_settings_meta() {
		$post_type = Donation_Form::POST_TYPE;

		// Default confirmation message HTML (receipt layout with smart tags).
		$default_message = Helper::get_default_confirmation_message();

		$default_confirmation = wp_json_encode(
			[
				'confirmation_type' => 'same page',
				'message'           => $default_message,
				'submission_action' => 'hide form',
				'custom_url'        => '',
				'page_url'          => '',
			]
		);

		// Form confirmation settings (JSON string).
		register_post_meta(
			$post_type,
			'_suredonation_form_confirmation',
			[
				'type'              => 'string',
				'description'       => __( 'Form confirmation settings.', 'suredonation' ),
				'single'            => true,
				'default'           => $default_confirmation,
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'string',
						'context' => [ 'edit' ],
					],
				],
				'sanitize_callback' => [ $this, 'sanitize_confirmation_settings' ],
				'auth_callback'     => static function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Email notification meta key.
	 *
	 * No migration from global `suredonation_options['email_notifications']` is needed:
	 * the plugin is pre-release (v0.0.1) with no production installs carrying customized
	 * global settings. New forms are seeded with defaults via the editor JS useEffect.
	 *
	 * @since 1.0.0
	 */
	public const EMAIL_NOTIFICATIONS_META_KEY = '_suredonation_form_email_notifications';

	/**
	 * Get default email notifications for new forms.
	 *
	 * Provides the initial set of notifications (donation receipt + admin) that
	 * are seeded when a form has no email notifications configured.
	 *
	 * @return array<int, array<string, mixed>> Default notifications array.
	 * @since 1.0.0
	 */
	public function get_default_email_notifications() {
		$sig = '<p>' . esc_html__( 'We truly appreciate your support', 'suredonation' ) . '</p>'
			. '<p>— {site_title}</p>';

		// phpcs:disable Generic.Strings.UnnecessaryStringConcat.Found -- Readability.
		$defaults = [
			// --- Donor Emails ---
			[
				'key'        => 'donation_receipt',
				'id'         => 1,
				'status'     => true,
				'name'       => __( 'Donation Receipt', 'suredonation' ),
				'email_to'   => '{donor_email}',
				'subject'    => __( 'Thank you for your donation to {campaign_name}', 'suredonation' ),
				'email_body' => '<p>' . esc_html__( 'Hi {donor_name},', 'suredonation' ) . '</p>'
					. '<p>' . esc_html__( 'Thank you for supporting {campaign_name}. Your contribution means a lot.', 'suredonation' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Donation Details:', 'suredonation' ) . '</strong></p>'
					. '<ul>'
					. '<li>' . esc_html__( 'Amount:', 'suredonation' ) . ' {amount}</li>'
					. '<li>' . esc_html__( 'Date:', 'suredonation' ) . ' {donation_date}</li>'
					. '<li>' . esc_html__( 'Transaction ID:', 'suredonation' ) . ' {transaction_id}</li>'
					. '</ul>'
					. $sig,
				'from_name'  => '{site_title}',
				'from_email' => '{admin_email}',
				'reply_to'   => '',
				'trigger'    => 'donation_completed',
			],
			[
				'key'        => 'donation_processing',
				'id'         => 2,
				'status'     => true,
				'name'       => __( 'Donation Processing', 'suredonation' ),
				'email_to'   => '{donor_email}',
				'subject'    => __( 'Your donation is being processed', 'suredonation' ),
				'email_body' => '<p>' . esc_html__( 'Hi {donor_name},', 'suredonation' ) . '</p>'
					. '<p>' . esc_html__( 'Your donation to {campaign_name} is currently being processed.', 'suredonation' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( 'Amount:', 'suredonation' ) . ' {amount}</li>'
					. '<li>' . esc_html__( 'Date:', 'suredonation' ) . ' {donation_date}</li>'
					. '</ul>'
					. '<p>' . esc_html__( "We'll notify you once it's confirmed.", 'suredonation' ) . '</p>'
					. '<p>— {site_title}</p>',
				'from_name'  => '{site_title}',
				'from_email' => '{admin_email}',
				'reply_to'   => '',
				'trigger'    => 'donation_processing',
			],
			[
				'key'        => 'donation_failed',
				'id'         => 3,
				'status'     => true,
				'name'       => __( 'Donation Failed', 'suredonation' ),
				'email_to'   => '{donor_email}',
				'subject'    => __( "We couldn't process your donation", 'suredonation' ),
				'email_body' => '<p>' . esc_html__( 'Hi {donor_name},', 'suredonation' ) . '</p>'
					. '<p>' . esc_html__( 'Unfortunately, your donation to {campaign_name} could not be completed.', 'suredonation' ) . '</p>'
					. '<p>' . esc_html__( 'If you need help, feel free to contact us.', 'suredonation' ) . '</p>'
					. '<p>— {site_title}</p>',
				'from_name'  => '{site_title}',
				'from_email' => '{admin_email}',
				'reply_to'   => '',
				'trigger'    => 'donation_failed',
			],
			[
				'key'        => 'refund_processed',
				'id'         => 4,
				'status'     => true,
				'name'       => __( 'Refund Processed', 'suredonation' ),
				'email_to'   => '{donor_email}',
				'subject'    => __( 'Your donation has been refunded', 'suredonation' ),
				'email_body' => '<p>' . esc_html__( 'Hi {donor_name},', 'suredonation' ) . '</p>'
					. '<p>' . esc_html__( 'Your donation has been refunded.', 'suredonation' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( 'Campaign:', 'suredonation' ) . ' {campaign_name}</li>'
					. '<li>' . esc_html__( 'Amount:', 'suredonation' ) . ' {refund_amount}</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'If you have any questions, feel free to reach out.', 'suredonation' ) . '</p>'
					. '<p>— {site_title}</p>',
				'from_name'  => '{site_title}',
				'from_email' => '{admin_email}',
				'reply_to'   => '',
				'trigger'    => 'refund_processed',
			],

			// --- Admin Emails ---
			[
				'key'        => 'donation_receipt_admin',
				'id'         => 9,
				'status'     => true,
				'name'       => __( 'New Donation (Admin)', 'suredonation' ),
				'email_to'   => '{admin_email}',
				'subject'    => __( 'New donation received', 'suredonation' ),
				'email_body' => '<p>' . esc_html__( 'A new donation has been received.', 'suredonation' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( 'Donor:', 'suredonation' ) . ' {donor_name}</li>'
					. '<li>' . esc_html__( 'Email:', 'suredonation' ) . ' {donor_email}</li>'
					. '<li>' . esc_html__( 'Campaign:', 'suredonation' ) . ' {campaign_name}</li>'
					. '<li>' . esc_html__( 'Amount:', 'suredonation' ) . ' {amount}</li>'
					. '<li>' . esc_html__( 'Date:', 'suredonation' ) . ' {donation_date}</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'View details:', 'suredonation' ) . '<br />{admin_url}</p>',
				'from_name'  => '{site_title}',
				'from_email' => '{admin_email}',
				'reply_to'   => '',
				'trigger'    => 'donation_completed',
			],
			[
				'key'        => 'donation_failed_admin',
				'id'         => 10,
				'status'     => true,
				'name'       => __( 'Donation Failed (Admin)', 'suredonation' ),
				'email_to'   => '{admin_email}',
				'subject'    => __( 'Donation failed', 'suredonation' ),
				'email_body' => '<p>' . esc_html__( 'A donation attempt has failed.', 'suredonation' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( 'Donor:', 'suredonation' ) . ' {donor_name}</li>'
					. '<li>' . esc_html__( 'Campaign:', 'suredonation' ) . ' {campaign_name}</li>'
					. '<li>' . esc_html__( 'Amount:', 'suredonation' ) . ' {amount}</li>'
					. '<li>' . esc_html__( 'Date:', 'suredonation' ) . ' {donation_date}</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'Check details:', 'suredonation' ) . '<br />{admin_url}</p>',
				'from_name'  => '{site_title}',
				'from_email' => '{admin_email}',
				'reply_to'   => '',
				'trigger'    => 'donation_failed',
			],
			[
				'key'        => 'refund_processed_admin',
				'id'         => 11,
				'status'     => true,
				'name'       => __( 'Refund Processed (Admin)', 'suredonation' ),
				'email_to'   => '{admin_email}',
				'subject'    => __( 'Refund issued', 'suredonation' ),
				'email_body' => '<p>' . esc_html__( 'A refund has been processed.', 'suredonation' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( 'Donor:', 'suredonation' ) . ' {donor_name}</li>'
					. '<li>' . esc_html__( 'Campaign:', 'suredonation' ) . ' {campaign_name}</li>'
					. '<li>' . esc_html__( 'Amount:', 'suredonation' ) . ' {refund_amount}</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'View details:', 'suredonation' ) . '<br />{admin_url}</p>',
				'from_name'  => '{site_title}',
				'from_email' => '{admin_email}',
				'reply_to'   => '',
				'trigger'    => 'refund_processed',
			],
		];
		// phpcs:enable Generic.Strings.UnnecessaryStringConcat.Found

		/**
		 * Filter default email notifications.
		 * Pro uses this to add subscription email notification templates.
		 *
		 * @param array<int, array<string, mixed>> $defaults Default notification configs.
		 * @since 1.0.0
		 */
		return apply_filters( 'suredonation_default_email_notifications', $defaults );
	}

	/**
	 * Register email notification meta for donation forms.
	 *
	 * Stores per-form email notification settings as a JSON string.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_email_notifications_meta() {
		register_post_meta(
			Donation_Form::POST_TYPE,
			self::EMAIL_NOTIFICATIONS_META_KEY,
			[
				'type'              => 'string',
				'description'       => __( 'Form email notification settings.', 'suredonation' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'string',
						'context' => [ 'edit' ],
					],
				],
				'sanitize_callback' => [ $this, 'sanitize_email_notifications' ],
				'auth_callback'     => static function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Sanitize email notification settings.
	 *
	 * @param string $value JSON string of email notification settings.
	 * @return string Sanitized JSON string.
	 * @since 1.0.0
	 */
	public function sanitize_email_notifications( $value ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		$data = json_decode( $value, true );

		if ( ! is_array( $data ) ) {
			return '';
		}

		$sanitized = [];
		foreach ( $data as $notification ) {
			if ( ! is_array( $notification ) ) {
				continue;
			}

			$trigger = isset( $notification['trigger'] ) && is_string( $notification['trigger'] ) ? $notification['trigger'] : '';

			// Preserve the trigger verbatim rather than validating against the
			// registered list. 'all' means "send on every event", so coercing an
			// unrecognised trigger to it silently rewires that notification to
			// fire on every donation event. Saving a form while Pro is inactive
			// did exactly that to the recurring templates: the trigger was not
			// registered, so a customised "Subscription Created" became a message
			// sent on completed, failed, processing and refunded donations, and
			// the editor stopped recognising it as recurring and appended a
			// duplicate set.
			//
			// An unregistered trigger is already inert: dispatch is an equality
			// match against an event name, and the code that fires the recurring
			// events does not load while Pro is inactive. Preserving the value
			// costs nothing and lets the notification resume working, with its
			// customisations, as soon as Pro is active again.
			$sanitized[] = [
				// Stable machine identity. `id` is reassigned when a set is
				// re-seeded and `name` is user-editable and translated, so neither
				// survives as a way to recognise a notification later.
				'key'        => isset( $notification['key'] ) && is_string( $notification['key'] ) ? sanitize_key( $notification['key'] ) : '',
				'id'         => isset( $notification['id'] ) ? absint( $notification['id'] ) : 0,
				'status'     => isset( $notification['status'] ) ? (bool) $notification['status'] : true,
				'name'       => isset( $notification['name'] ) ? sanitize_text_field( $notification['name'] ) : '',
				'email_to'   => isset( $notification['email_to'] ) ? sanitize_text_field( $notification['email_to'] ) : '',
				'subject'    => isset( $notification['subject'] ) ? sanitize_text_field( $notification['subject'] ) : '',
				'email_body' => isset( $notification['email_body'] ) ? wp_kses_post( $notification['email_body'] ) : '',
				'from_name'  => isset( $notification['from_name'] ) ? sanitize_text_field( $notification['from_name'] ) : '',
				'from_email' => isset( $notification['from_email'] ) ? sanitize_text_field( $notification['from_email'] ) : '',
				'reply_to'   => isset( $notification['reply_to'] ) ? sanitize_text_field( $notification['reply_to'] ) : '',
				// A missing trigger must stay inert. Dispatch skips an empty
				// trigger but treats 'all' as "fire on every event", so falling
				// back to 'all' routes the unknown case to the most permissive
				// outcome — the opposite of what a missing value should mean.
				'trigger'    => '' !== $trigger ? sanitize_key( $trigger ) : '',
			];
		}

		$encoded = wp_json_encode( $sanitized );
		return is_string( $encoded ) ? $encoded : '';
	}


	/**
	 * Sanitize form confirmation settings.
	 *
	 * @param string $value JSON string of confirmation settings.
	 * @return string Sanitized JSON string.
	 * @since 1.0.0
	 */
	public function sanitize_confirmation_settings( $value ) {
		$fallback_data = [
			'confirmation_type' => 'same page',
			'message'           => '',
			'submission_action' => 'hide form',
			'custom_url'        => '',
			'page_url'          => '',
		];
		$fallback      = (string) wp_json_encode( $fallback_data );

		if ( empty( $value ) || ! is_string( $value ) ) {
			return $fallback;
		}

		$data = json_decode( $value, true );

		if ( ! is_array( $data ) ) {
			return $fallback;
		}

		$allowed_types   = [ 'same page', 'different page', 'custom url' ];
		$allowed_actions = [ 'hide form', 'reset form' ];

		$message = isset( $data['message'] ) ? wp_kses_post( $data['message'] ) : '';

		$sanitized = [
			'confirmation_type' => isset( $data['confirmation_type'] ) && in_array( $data['confirmation_type'], $allowed_types, true )
				? $data['confirmation_type']
				: 'same page',
			'message'           => $message,
			'submission_action' => isset( $data['submission_action'] ) && in_array( $data['submission_action'], $allowed_actions, true )
				? $data['submission_action']
				: 'hide form',
			'custom_url'        => isset( $data['custom_url'] ) ? esc_url_raw( $data['custom_url'] ) : '',
			'page_url'          => isset( $data['page_url'] ) ? esc_url_raw( $data['page_url'] ) : '',
		];

		$encoded = wp_json_encode( $sanitized );
		return is_string( $encoded ) ? $encoded : $fallback;
	}
}
