<?php
/**
 * Campaign template registry.
 *
 * Discovers bundled campaign templates (each a folder under templates/ with a
 * template.php returning metadata + block builders, plus an assets/ folder) and
 * exposes them to the seeding hooks and the REST picker. Ships a built-in
 * `general` template whose builders delegate to the existing default generators,
 * so "Start from scratch" stays byte-for-byte identical to today's output.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Campaign_Templates;

use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of cause-based campaign templates.
 *
 * @since 1.5.0
 */
class Campaign_Templates {
	use Get_Instance;

	/**
	 * The built-in template id used when a campaign carries no explicit template.
	 *
	 * @since 1.5.0
	 */
	public const GENERAL = 'general';

	/**
	 * Resolved templates, keyed by id. Null until first discovery.
	 *
	 * @var array<string, array<string, mixed>>|null
	 * @since 1.5.0
	 */
	private $templates = null;

	/**
	 * Get every template's metadata for the picker (no builders or file paths),
	 * excluding the built-in `general` fallback (the UI shows that as a dedicated
	 * "Start from scratch" card, not a gallery card).
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.5.0
	 */
	public function get_all() {
		$out = [];

		foreach ( $this->all() as $id => $template ) {
			if ( self::GENERAL === $id ) {
				continue;
			}

			$out[] = [
				'id'                   => $id,
				'name'                 => $template['name'] ?? $id,
				'category'             => $template['category'] ?? '',
				'description'          => $template['description'] ?? '',
				'thumbnail'            => $template['thumbnail'] ?? '',
				'goal_type'            => $template['goal_type'] ?? 'raised_amount',
				'goal_amount'          => $template['goal_amount'] ?? 0,
				'campaign_title'       => $template['campaign_title'] ?? '',
				'campaign_description' => $template['campaign_description'] ?? '',
			];
		}

		return $out;
	}

	/**
	 * Get a full template (metadata + builders + hero path) by id.
	 *
	 * The id is treated as untrusted: it is sanitized and resolved only against
	 * the discovered whitelist, never concatenated into a filesystem path.
	 *
	 * @param string $id Template id.
	 * @return array<string, mixed>|null The template, or null when unknown.
	 * @since 1.5.0
	 */
	public function get( $id ) {
		$id = sanitize_key( (string) $id );

		if ( '' === $id ) {
			return null;
		}

		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Whether a template id resolves to a known template.
	 *
	 * @param string $id Template id.
	 * @return bool
	 * @since 1.5.0
	 */
	public function exists( $id ) {
		return null !== $this->get( $id );
	}

	/**
	 * Import a bundled image file into the Media Library as an attachment.
	 *
	 * Fail-soft: returns 0 on any failure so seeding never breaks campaign
	 * creation.
	 *
	 * @param string $file_path Absolute path to a bundled image file.
	 * @param int    $post_id   Post to attach the image to.
	 * @return int Attachment id, or 0 on failure.
	 * @since 1.5.0
	 */
	public static function import_image( $file_path, $post_id ) {
		if ( ! is_string( $file_path ) || ! is_readable( $file_path ) ) {
			return 0;
		}

		// Load the admin media stack unconditionally — on a REST/front-end create
		// request none of these are loaded, and media_handle_sideload() depends on
		// wp_handle_sideload() (file.php) and image functions (image.php). Guarding
		// only on media_handle_sideload() can leave those undefined and fatal. All
		// three are require_once, so this is cheap and idempotent.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( basename( $file_path ) );
		if ( ! $tmp ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- copying a bundled plugin asset into a temp file for sideloading.
		if ( ! copy( $file_path, $tmp ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		$file_array = [
			'name'     => basename( $file_path ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, (int) $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		return (int) $attachment_id;
	}

	/**
	 * Build donation-form block markup for a template.
	 *
	 * Shared by the bundled templates so each only varies its amounts/labels.
	 * Produces: name, email, donation-amount (given tiers), payment, donate button.
	 *
	 * Keep the block names/attribute shapes here in sync with
	 * Donation_Form::get_default_form_blocks_content() — the two must stay
	 * equivalent so template forms behave identically to the default form.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Form configuration.
	 *
	 *     @type int[]  $amounts      Amount tier values. Default 25/50/100/250.
	 *     @type string $amount_label Label for the donation-amount field.
	 *     @type string $button_text  Donate button text.
	 * }
	 * @return string Serialized block markup.
	 * @since 1.5.0
	 */
	public static function build_form_blocks( $args = [] ) {
		$amounts      = isset( $args['amounts'] ) && is_array( $args['amounts'] ) ? $args['amounts'] : [ 25, 50, 100, 250 ];
		$amount_label = $args['amount_label'] ?? __( 'Select Donation Amount', 'suredonation' );
		$button_text  = $args['button_text'] ?? __( 'Donate', 'suredonation' );

		$options = array_map(
			static function ( $value ) {
				$value = Helper::get_string_value( $value );
				return [
					'label' => $value,
					'value' => $value,
				];
			},
			$amounts
		);

		$blocks = [
			'<!-- wp:suredonation/input ' . wp_json_encode(
				[
					'label'       => __( 'Full Name', 'suredonation' ),
					'required'    => true,
					'placeholder' => __( 'Enter your full name', 'suredonation' ),
					'slug'        => 'donor-name',
					'fieldWidth'  => 50,
				]
			) . ' /-->',
			'<!-- wp:suredonation/email ' . wp_json_encode(
				[
					'label'       => __( 'Email Address', 'suredonation' ),
					'required'    => true,
					'placeholder' => __( 'Enter your email', 'suredonation' ),
					'slug'        => 'donor-email',
					'fieldWidth'  => 50,
				]
			) . ' /-->',
			'<!-- wp:suredonation/donation-amount ' . wp_json_encode(
				[
					'label'      => $amount_label,
					'required'   => true,
					'choiceType' => 'radio',
					'layout'     => 'horizontal',
					'slug'       => 'donation-amount',
					'options'    => $options,
				]
			) . ' /-->',
			'<!-- wp:suredonation/payment ' . wp_json_encode(
				[
					'gateway'             => 'stripe',
					'paymentType'         => 'one-time',
					'amountType'          => 'variable',
					'minimumAmount'       => 0,
					'variableAmountField' => 'donation-amount',
					'customerEmailField'  => 'donor-email',
					'customerNameField'   => 'donor-name',
				]
			) . ' /-->',
			'<!-- wp:suredonation/donate-button ' . wp_json_encode(
				[
					'buttonText' => $button_text,
					'slug'       => 'donate-button',
				]
			) . ' /-->',
		];

		return implode( "\n\n", $blocks );
	}

	/**
	 * Resolve and cache all templates (built-in + discovered + filtered).
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.5.0
	 */
	private function all() {
		if ( null === $this->templates ) {
			$this->templates = $this->discover();
		}

		return $this->templates;
	}

	/**
	 * Discover bundled templates from the templates/ directory, plus the built-in
	 * `general` template.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.5.0
	 */
	private function discover() {
		$templates = [ self::GENERAL => $this->general_template() ];

		$base_dir = __DIR__ . '/templates';

		if ( is_dir( $base_dir ) ) {
			$dirs = glob( $base_dir . '/*', GLOB_ONLYDIR );

			foreach ( (array) $dirs as $path ) {
				$file = $path . '/template.php';

				if ( ! is_readable( $file ) ) {
					continue;
				}

				// Safe: these are first-party template files bundled inside the
				// plugin (same trust boundary as any plugin file). Third-party or
				// remote templates must be injected as arrays via the
				// `suredonation_campaign_templates` filter below — never written
				// into this directory — so this require stays first-party only.
				$template = require $file;

				if ( ! is_array( $template ) || empty( $template['id'] ) ) {
					continue;
				}

				$id = sanitize_key( (string) $template['id'] );

				// Skip invalid ids and never let a bundled folder override `general`.
				if ( '' === $id || self::GENERAL === $id || isset( $templates[ $id ] ) ) {
					continue;
				}

				// Resolve assets by convention so templates need not hardcode paths.
				// Hero: prefer WebP (smaller), fall back to JPEG.
				$hero_file = '';
				foreach ( [ 'hero.webp', 'hero.jpg' ] as $candidate ) {
					if ( is_readable( $path . '/assets/' . $candidate ) ) {
						$hero_file = $candidate;
						break;
					}
				}
				$template['hero_path'] = '' !== $hero_file ? $path . '/assets/' . $hero_file : '';

				// Card thumbnail: prefer a dedicated thumbnail.jpg when provided,
				// otherwise fall back to the hero image so a single bundled image
				// serves both the featured image and the picker card.
				$asset_url = SUREDONATION_URL . 'inc/campaign-templates/templates/' . $id . '/assets/';
				if ( file_exists( $path . '/assets/thumbnail.jpg' ) ) {
					$template['thumbnail'] = esc_url_raw( $asset_url . 'thumbnail.jpg' );
				} elseif ( '' !== $hero_file ) {
					$template['thumbnail'] = esc_url_raw( $asset_url . $hero_file );
				} else {
					$template['thumbnail'] = '';
				}

				$templates[ $id ] = $template;
			}
		}

		/**
		 * Filter the registered campaign templates.
		 *
		 * Lets Pro / remote sources inject additional templates. v1 bundles only.
		 *
		 * @param array<string, array<string, mixed>> $templates Templates keyed by id.
		 * @since 1.5.0
		 */
		return apply_filters( 'suredonation_campaign_templates', $templates );
	}

	/**
	 * The built-in `general` template — the scratch baseline. Its builders
	 * delegate to the existing default generators so output is unchanged.
	 *
	 * @return array<string, mixed>
	 * @since 1.5.0
	 */
	private function general_template() {
		return [
			'id'                   => self::GENERAL,
			'name'                 => __( 'Start from scratch', 'suredonation' ),
			'category'             => __( 'General', 'suredonation' ),
			'description'          => __( 'A blank donation campaign with the standard form.', 'suredonation' ),
			'campaign_title'       => '',
			'campaign_description' => '',
			'goal_type'            => 'raised_amount',
			'goal_amount'          => 0,
			'hero_path'            => '',
			'thumbnail'            => '',
			'get_form_blocks'      => static function () {
				return Donation_Form::get_default_form_blocks_content();
			},
			'get_page_blocks'      => static function ( $ctx ) {
				return Campaign_Page::get_default_layout( (int) ( $ctx['campaign_id'] ?? 0 ) );
			},
		];
	}
}
