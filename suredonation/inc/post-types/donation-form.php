<?php
/**
 * Donation Form Custom Post Type.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Post_Types;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donation Form Post Type Class.
 *
 * @since 0.0.1
 */
class Donation_Form {
	use Get_Instance;

	/**
	 * Post type slug.
	 *
	 * @since 0.0.1
	 */
	public const POST_TYPE = 'suredonation_form';

	/**
	 * Meta key for linked campaign ID.
	 *
	 * @since 0.0.1
	 */
	public const META_CAMPAIGN_ID = '_suredonation_campaign_id';

	/**
	 * Meta key for the per-form styling settings (JSON blob).
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const META_STYLING = '_suredonation_form_styling';

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_meta' ] );
		add_filter( 'allowed_block_types_all', [ $this, 'restrict_blocks' ], 10, 2 );
		add_filter( 'render_block_data', [ $this, 'alias_legacy_multi_choice_block' ] );
		add_filter( 'surerank_excluded_post_types_from_seo_checks', [ $this, 'exclude_from_surerank_seo_checks' ] );
		add_action( 'load-post-new.php', [ $this, 'set_campaign_on_auto_draft' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'maybe_set_campaign_from_url' ], 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'update_field_slugs' ], 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'store_block_config' ], 10, 2 );
	}

	/**
	 * Exclude the donation form post type from SureRank's SEO checks.
	 *
	 * Stops SureRank from injecting its SEO meta box / "Optimize" button into
	 * the donation form editor (and its column on the list table), where SEO
	 * is not relevant — mirroring how SureRank excludes sureforms_form.
	 *
	 * @param array<string> $post_types Post types excluded from SEO checks.
	 * @return array<string> Filtered list of excluded post types.
	 * @since 1.0.0
	 */
	public function exclude_from_surerank_seo_checks( $post_types ) {
		$post_types   = is_array( $post_types ) ? $post_types : [];
		$post_types[] = self::POST_TYPE;

		return $post_types;
	}

	/**
	 * Render-time alias: any saved suredonation/multi-choice block (from before the
	 * rename) renders as suredonation/donation-amount. Keeps existing forms working
	 * without a content migration.
	 *
	 * Intentionally registered globally rather than gated on the
	 * suredonation_form post type: forms are embedded on regular pages via
	 * the donation-form block / shortcode, where the queried post is the
	 * page, not the form CPT. The early string compare is cheap, and the
	 * slug is plugin-specific so it only ever matches our own blocks.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block data.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function alias_legacy_multi_choice_block( $parsed_block ) {
		if ( isset( $parsed_block['blockName'] ) && 'suredonation/multi-choice' === $parsed_block['blockName'] ) {
			$parsed_block['blockName'] = 'suredonation/donation-amount';
		}
		return $parsed_block;
	}

	/**
	 * Register the donation form post type.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_post_type() {
		$labels = [
			'name'                  => _x( 'Donation Forms', 'Post type general name', 'suredonation' ),
			'singular_name'         => _x( 'Donation Form', 'Post type singular name', 'suredonation' ),
			'menu_name'             => _x( 'Donation Forms', 'Admin Menu text', 'suredonation' ),
			'name_admin_bar'        => _x( 'Donation Form', 'Add New on Toolbar', 'suredonation' ),
			'add_new'               => __( 'Add New', 'suredonation' ),
			'add_new_item'          => __( 'Add New Form', 'suredonation' ),
			'new_item'              => __( 'New Form', 'suredonation' ),
			'edit_item'             => __( 'Edit Form', 'suredonation' ),
			'view_item'             => __( 'View Form', 'suredonation' ),
			'all_items'             => __( 'All Forms', 'suredonation' ),
			'search_items'          => __( 'Search Forms', 'suredonation' ),
			'parent_item_colon'     => __( 'Parent Forms:', 'suredonation' ),
			'not_found'             => __( 'No forms found.', 'suredonation' ),
			'not_found_in_trash'    => __( 'No forms found in Trash.', 'suredonation' ),
			'archives'              => _x( 'Form archives', 'The post type archive label used in nav menus.', 'suredonation' ),
			'insert_into_item'      => _x( 'Insert into form', 'Overrides the "Insert into post" phrase.', 'suredonation' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this form', 'Overrides the "Uploaded to this post" phrase.', 'suredonation' ),
			'filter_items_list'     => _x( 'Filter forms list', 'Screen reader text for the filter links heading.', 'suredonation' ),
			'items_list_navigation' => _x( 'Forms list navigation', 'Screen reader text for the pagination heading.', 'suredonation' ),
			'items_list'            => _x( 'Forms list', 'Screen reader text for the items list heading.', 'suredonation' ),
		];

		$args = [
			'labels'             => $labels,
			'description'        => __( 'Donation forms for SureDonation.', 'suredonation' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'suredonation',
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => [ 'title', 'editor', 'custom-fields' ],
			'show_in_rest'       => true, // Required for Gutenberg.
			'template'           => $this->get_default_template(),
			'template_lock'      => false,
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register post meta for the donation form.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_meta() {
		register_post_meta(
			self::POST_TYPE,
			self::META_CAMPAIGN_ID,
			[
				'type'              => 'integer',
				'description'       => __( 'The ID of the linked campaign.', 'suredonation' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STYLING,
			[
				'type'              => 'string',
				'description'       => __( 'Per-form styling settings (JSON).', 'suredonation' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => [ \SureDonation\Inc\Fields\Form_Styling::class, 'sanitize_json' ],
				'auth_callback'     => static function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Restrict allowed blocks in the donation form editor.
	 *
	 * @param bool|array<string>       $allowed_block_types Array of allowed block types or true for all.
	 * @param \WP_Block_Editor_Context $context             Block editor context.
	 * @return bool|array<string> Array of allowed block types.
	 * @since 0.0.1
	 */
	public function restrict_blocks( $allowed_block_types, $context ) {
		if ( ! isset( $context->post ) || self::POST_TYPE !== $context->post->post_type ) {
			return $allowed_block_types;
		}

		// SureDonation form blocks.
		$blocks = [
			'suredonation/input',
			'suredonation/email',
			'suredonation/number',
			'suredonation/dropdown',
			'suredonation/address',
			'suredonation/phone',
			'suredonation/url',
			'suredonation/heading',
			'suredonation/html',
			'suredonation/donation-amount',
			'suredonation/anonymous-donation',
			'suredonation/payment',
			'suredonation/donate-button',
			'suredonation/cover-fees',
		];

		/**
		 * Filter the blocks allowed in the donation form editor.
		 *
		 * Lets extensions (e.g. SureDonation Pro) register additional field
		 * blocks — such as the date/time pickers — so they appear in the form
		 * editor's inserter.
		 *
		 * @since 1.1.1
		 * @param array<string> $blocks Allowed block names.
		 */
		return apply_filters( 'suredonation_allowed_form_blocks', $blocks );
	}

	/**
	 * Set campaign ID on the auto-draft when creating a new form from a campaign page.
	 *
	 * Hooks into load-post-new.php so the meta is set before the block editor
	 * renders, ensuring the campaign link is stored even if the URL parameter
	 * is lost after the first save/redirect.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function set_campaign_on_auto_draft() {
		// Only for our post type.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check on admin page load.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; validated below via capability check.
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( $campaign_id <= 0 ) {
			return;
		}

		// Validate campaign exists and user can access it.
		$campaign = get_post( $campaign_id );
		if ( ! $campaign instanceof \WP_Post || SUREDONATION_POST_TYPE !== $campaign->post_type || ! current_user_can( 'edit_post', $campaign_id ) ) {
			return;
		}

		// WordPress creates the auto-draft via get_default_post_to_edit() which runs
		// before our hook. We can get the post ID from the global $post or from the
		// auto-draft that will be created. Use a filter on wp_insert_post_data to
		// capture it, or simply hook into wp_insert_post to set meta right after.
		//
		// The closure stays attached for the rest of the request, but its condition
		// (post_type + auto-draft) is narrow enough that subsequent wp_insert_post
		// calls for other types are no-ops. Only one auto-draft is created per
		// load-post-new.php request, so a one-shot removal adds complexity without benefit.
		add_action(
			'wp_insert_post',
			static function ( $post_id, $post ) use ( $campaign_id ) {
				if ( self::POST_TYPE === $post->post_type && 'auto-draft' === $post->post_status ) {
					update_post_meta( $post_id, self::META_CAMPAIGN_ID, $campaign_id );
				}
			},
			10,
			2
		);
	}

	/**
	 * Set campaign ID from URL parameter when creating a new form.
	 *
	 * This handles the case when a form is created via the "Add Form" button
	 * from the campaign page, which passes campaign_id as a URL parameter.
	 *
	 * SECURITY: This method implements defense-in-depth with multiple checks:
	 * 1. Nonce verification via verify_save_post_nonce() (WordPress REST nonce or classic editor nonce)
	 * 2. Capability check: current_user_can('edit_post', $post_id) for the form
	 * 3. Capability check: current_user_can('edit_post', $campaign_id) for the campaign
	 * 4. Validation: Campaign must exist and be the correct post type
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 * @since 0.0.1
	 */
	public function maybe_set_campaign_from_url( $post_id, $post ) {
		unset( $post ); // Unused parameter.

		// Skip autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Security check 1: Verify user has permission to edit this form.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Security check 2: Verify nonce - handles both block editor (REST API) and classic editor.
		if ( ! self::verify_save_post_nonce( $post_id ) ) {
			return;
		}

		// Only process if campaign_id is not already set.
		$existing_campaign_id = self::get_form_campaign_id( $post_id );
		if ( $existing_campaign_id > 0 ) {
			return;
		}

		// Get campaign_id from URL parameter (from "Add Form" button on campaign pages).
		// Security: Nonce verified above via verify_save_post_nonce(). Authorization verified
		// via capability checks on both form (above) and campaign (below).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above via verify_save_post_nonce().
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;

		if ( $campaign_id > 0 ) {
			// Security check 3: Verify the campaign exists, is the correct type, and user can edit it.
			$campaign = get_post( $campaign_id );
			if ( $campaign instanceof \WP_Post && SUREDONATION_POST_TYPE === $campaign->post_type && current_user_can( 'edit_post', $campaign_id ) ) {
				self::set_form_campaign_id( $post_id, $campaign_id );
			}
		}
	}

	/**
	 * Store block configuration for server-side validation.
	 *
	 * This method extracts and stores payment block configuration (amount type,
	 * fixed amount, minimum amount, etc.) in post meta. This stored configuration
	 * is used during payment processing to validate that the submitted amount
	 * matches the form's configured values, preventing payment manipulation attacks.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 * @since 0.0.1
	 */
	public function store_block_config( $post_id, $post ) {
		// Skip autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Verify user has permission to edit this post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Verify nonce - handles both block editor (REST API) and classic editor.
		if ( ! self::verify_save_post_nonce( $post_id ) ) {
			return;
		}

		// Re-fetch the post content fresh. update_field_slugs() runs on the same
		// save_post hook and rewrites post_content with generated field slugs via
		// a nested wp_update_post(); the $post handed to this callback is the
		// pre-update copy, so reading $post->post_content directly would miss the
		// slug for a newly added field and the config would be stored without it
		// (skipping that field in server-side validation until the next save).
		$fresh   = get_post( $post_id );
		$content = $fresh instanceof \WP_Post ? $fresh->post_content : $post->post_content;
		$blocks  = parse_blocks( $content );

		if ( empty( $blocks ) ) {
			return;
		}

		// Store block configuration using Field_Validation class.
		\SureDonation\Inc\Field_Validation::add_block_config( $blocks, $post_id );
	}

	/**
	 * Generate unique slugs for SureDonation blocks on form save.
	 *
	 * Parses the form content, generates slugs for blocks that don't have one,
	 * ensures uniqueness, and updates the post content if needed.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 * @since 0.0.1
	 */
	public function update_field_slugs( $post_id, $post ) {
		// Skip autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Verify user has permission to edit this post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Verify nonce - handles both block editor (REST API) and classic editor.
		if ( ! self::verify_save_post_nonce( $post_id ) ) {
			return;
		}

		$blocks = parse_blocks( $post->post_content );

		if ( empty( $blocks ) ) {
			return;
		}

		// Sanitize untrusted authors' raw HTML-block markup at save so the stored
		// value cannot contain markup the front end would strip on render
		// (defense-in-depth). Authors with unfiltered_html keep their raw markup,
		// mirroring how WordPress treats post_content.
		$html_sanitized = false;
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$html_sanitized = self::sanitize_html_block_content( $blocks );
		}

		// Process blocks to generate slugs.
		[ $blocks, , $updated ] = \SureDonation\Inc\Helper::process_blocks( $blocks );

		// Only update if blocks were modified (slugs generated or HTML sanitized).
		if ( ! $updated && ! $html_sanitized ) {
			return;
		}

		// Serialize blocks and update post.
		$post_content = serialize_blocks( $blocks ); // @phpstan-ignore argument.type

		// Remove save action to prevent infinite loop.
		remove_action( 'save_post_' . self::POST_TYPE, [ $this, 'update_field_slugs' ], 10 );

		// wp_slash() the content to preserve the JSON unicode escapes that
		// serialize_blocks() writes into block attributes for characters such as the
		// angle brackets in raw HTML. wp_update_post() runs wp_unslash() internally,
		// so without re-slashing those escape sequences lose their leading backslash
		// and the HTML block's stored markup is corrupted.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( $post_content ),
			]
		);

		// Re-add save action.
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'update_field_slugs' ], 10, 2 );
	}

	/**
	 * Sanitize the raw markup stored in HTML blocks at save time.
	 *
	 * Runs wp_kses_post() over each suredonation/html block's htmlContent so the
	 * stored value cannot hold markup the front end would strip on render. Applied
	 * to authors without the unfiltered_html capability. Inner blocks (columns,
	 * groups) are walked recursively.
	 *
	 * @param array<mixed> $blocks Parsed blocks to process, by reference.
	 * @return bool True if any block's content was modified.
	 * @since 1.1.1
	 */
	private static function sanitize_html_block_content( &$blocks ) {
		$changed = false;

		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if (
				isset( $block['blockName'], $block['attrs']['htmlContent'] )
				&& 'suredonation/html' === $block['blockName']
				&& is_string( $block['attrs']['htmlContent'] )
			) {
				$sanitized = wp_kses_post( $block['attrs']['htmlContent'] );
				if ( $sanitized !== $block['attrs']['htmlContent'] ) {
					$block['attrs']['htmlContent'] = $sanitized;
					$changed                       = true;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				if ( self::sanitize_html_block_content( $block['innerBlocks'] ) ) {
					$changed = true;
				}
			}
		}
		unset( $block );

		return $changed;
	}

	/**
	 * Check if a post is a donation form.
	 *
	 * @param int|\WP_Post $post Post ID or post object.
	 * @return bool
	 * @since 0.0.1
	 */
	public static function is_donation_form( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return false;
		}

		return self::POST_TYPE === $post->post_type;
	}

	/**
	 * Get all donation forms.
	 *
	 * @param array<string, mixed> $args Additional WP_Query arguments.
	 * @return array<\WP_Post> Array of donation form posts.
	 * @since 0.0.1
	 */
	public static function get_forms( $args = [] ) {
		$defaults = [
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		$query_args = wp_parse_args( $args, $defaults );

		return get_posts( $query_args ); // @phpstan-ignore return.type
	}

	/**
	 * Get forms linked to a specific campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<\WP_Post> Array of donation form posts.
	 * @since 0.0.1
	 */
	public static function get_forms_by_campaign( $campaign_id ) {
		return self::get_forms(
			[
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => self::META_CAMPAIGN_ID,
						'value'   => $campaign_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
	}

	/**
	 * Get the campaign ID linked to a form.
	 *
	 * @param int $form_id Form ID.
	 * @return int Campaign ID or 0 if not linked.
	 * @since 0.0.1
	 */
	public static function get_form_campaign_id( $form_id ) {
		$campaign_id = get_post_meta( $form_id, self::META_CAMPAIGN_ID, true );
		return is_numeric( $campaign_id ) ? (int) $campaign_id : 0;
	}

	/**
	 * Set the campaign ID for a form.
	 *
	 * @param int $form_id     Form ID.
	 * @param int $campaign_id Campaign ID.
	 * @return bool True on success, false on failure.
	 * @since 0.0.1
	 */
	public static function set_form_campaign_id( $form_id, $campaign_id ) {
		return (bool) update_post_meta( $form_id, self::META_CAMPAIGN_ID, absint( $campaign_id ) );
	}

	/**
	 * Create a default donation form for a campaign.
	 *
	 * Creates a single-page form with all the essential fields matching
	 * the hardcoded template structure.
	 *
	 * @param int    $campaign_id   Campaign ID to link the form to.
	 * @param string $campaign_name Campaign name for the form title.
	 * @return int|false Form ID on success, false on failure.
	 * @since 0.0.1
	 */
	public static function create_default_form_for_campaign( $campaign_id, $campaign_name = '' ) {
		if ( ! $campaign_id ) {
			return false;
		}

		// Generate form title.
		$form_title = $campaign_name
			? sprintf(
				/* translators: %s: campaign name */
				__( '%s - Donation Form', 'suredonation' ),
				$campaign_name
			)
			: __( 'Donation Form', 'suredonation' );

		// Build the block content.
		$blocks_content = self::get_default_form_blocks_content();

		// Create the form post.
		$form_id = wp_insert_post(
			[
				'post_title'   => $form_title,
				'post_content' => $blocks_content,
				'post_status'  => 'publish',
				'post_type'    => self::POST_TYPE,
				'meta_input'   => [
					self::META_CAMPAIGN_ID => $campaign_id,
				],
			],
			true
		);

		if ( is_wp_error( $form_id ) ) {
			return false;
		}

		return $form_id;
	}

	/**
	 * Get the default block template for new forms.
	 *
	 * @return array<int, array<int, mixed>>
	 * @since 0.0.1
	 */
	private function get_default_template() {
		return [
			[
				'suredonation/input',
				[
					'label'       => __( 'Full Name', 'suredonation' ),
					'required'    => true,
					'placeholder' => __( 'Enter your full name', 'suredonation' ),
					'slug'        => 'donor-name',
					'fieldWidth'  => 50,
				],
			],
			[
				'suredonation/email',
				[
					'label'       => __( 'Email Address', 'suredonation' ),
					'required'    => true,
					'placeholder' => __( 'Enter your email', 'suredonation' ),
					'slug'        => 'donor-email',
					'fieldWidth'  => 50,
				],
			],
			[
				'suredonation/donation-amount',
				[
					'label'      => __( 'Select Donation Amount', 'suredonation' ),
					'required'   => true,
					'choiceType' => 'radio',
					'layout'     => 'horizontal',
					'slug'       => 'donation-amount',
					'options'    => [
						[
							'label' => '25',
							'value' => '25',
						],
						[
							'label' => '50',
							'value' => '50',
						],
						[
							'label' => '100',
							'value' => '100',
						],
						[
							'label' => '250',
							'value' => '250',
						],
					],
				],
			],
			[
				'suredonation/payment',
				[
					'gateway'             => 'stripe',
					'paymentType'         => 'one-time',
					'amountType'          => 'variable',
					'minimumAmount'       => 0,
					'variableAmountField' => 'donation-amount',
					'customerEmailField'  => 'donor-email',
					'customerNameField'   => 'donor-name',
				],
			],
			[
				'suredonation/donate-button',
				[
					'buttonText' => __( 'Donate', 'suredonation' ),
					'slug'       => 'donate-button',
				],
			],
		];
	}

	/**
	 * Get the default form blocks content as serialized block markup.
	 *
	 * Creates a single-page donation form with:
	 * - Multi-choice for preset amounts (radio buttons)
	 * - Input for donor name
	 * - Email for donor email
	 * - Payment block configured for donation-amount variable amount
	 *
	 * @return string Serialized block content.
	 * @since 0.0.1
	 */
	private static function get_default_form_blocks_content() {
		$blocks = [];

		// Donor name.
		$blocks[] = '<!-- wp:suredonation/input ' . wp_json_encode(
			[
				'block_id'    => \SureDonation\Inc\Helper::generate_block_id(),
				'label'       => __( 'Full Name', 'suredonation' ),
				'required'    => true,
				'placeholder' => __( 'Enter your full name', 'suredonation' ),
				'slug'        => 'donor-name',
				'fieldWidth'  => 50,
			]
		) . ' /-->';

		// Donor email.
		$blocks[] = '<!-- wp:suredonation/email ' . wp_json_encode(
			[
				'block_id'    => \SureDonation\Inc\Helper::generate_block_id(),
				'label'       => __( 'Email Address', 'suredonation' ),
				'required'    => true,
				'placeholder' => __( 'Enter your email', 'suredonation' ),
				'slug'        => 'donor-email',
				'fieldWidth'  => 50,
			]
		) . ' /-->';

		// Preset donation amounts using donation-amount (radio buttons).
		$blocks[] = '<!-- wp:suredonation/donation-amount ' . wp_json_encode(
			[
				'block_id'   => \SureDonation\Inc\Helper::generate_block_id(),
				'label'      => __( 'Select Donation Amount', 'suredonation' ),
				'required'   => true,
				'choiceType' => 'radio',
				'layout'     => 'horizontal',
				'slug'       => 'donation-amount',
				'options'    => [
					[
						'label' => '25',
						'value' => '25',
					],
					[
						'label' => '50',
						'value' => '50',
					],
					[
						'label' => '100',
						'value' => '100',
					],
					[
						'label' => '250',
						'value' => '250',
					],
				],
			]
		) . ' /-->';

		// Payment block configured for donation-amount variable amount.
		$blocks[] = '<!-- wp:suredonation/payment ' . wp_json_encode(
			[
				'block_id'            => \SureDonation\Inc\Helper::generate_block_id(),
				'gateway'             => 'stripe',
				'paymentType'         => 'one-time',
				'amountType'          => 'variable',
				'minimumAmount'       => 0,
				'variableAmountField' => 'donation-amount',
				'customerEmailField'  => 'donor-email',
				'customerNameField'   => 'donor-name',
			]
		) . ' /-->';

		// Donate button.
		$blocks[] = '<!-- wp:suredonation/donate-button ' . wp_json_encode(
			[
				'block_id'   => \SureDonation\Inc\Helper::generate_block_id(),
				'buttonText' => __( 'Donate', 'suredonation' ),
				'slug'       => 'donate-button',
			]
		) . ' /-->';

		return implode( "\n\n", $blocks );
	}

	/**
	 * Verify nonce for save_post hooks.
	 *
	 * Handles both block editor (REST API) and classic editor nonce verification.
	 * - Block Editor: Verifies the wp_rest nonce via REST_REQUEST constant
	 * - Classic Editor: Verifies _wpnonce with update-post_{$post_id} action
	 *
	 * @param int $post_id Post ID being saved.
	 * @return int|bool 1 if nonce is valid and generated between 0-12 hours (classic editor), 2 if valid and between 12-24 hours (classic editor), true for block editor, false otherwise.
	 * @since 0.0.1
	 */
	private static function verify_save_post_nonce( $post_id ) {
		// Block editor saves via REST API - nonce already verified by WordPress REST authentication.
		// The REST_REQUEST constant is only defined after successful authentication.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// Classic editor - verify _wpnonce with update-post action.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}

		return wp_verify_nonce( $nonce, 'update-post_' . $post_id );
	}
}
