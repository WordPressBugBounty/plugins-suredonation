<?php
/**
 * Campaign Custom Post Type
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Campaigns;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign_Cpt class.
 *
 * @since 0.0.1
 */
class Campaign_Cpt {
	use Get_Instance;

	/**
	 * Post type slug.
	 *
	 * @since 0.0.1
	 */
	public const POST_TYPE = 'suredonation_cmpgn';

	/**
	 * Meta key for the linked default form ID.
	 *
	 * @since 0.0.1
	 */
	public const META_DEFAULT_FORM_ID = '_suredonation_default_form_id';

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_campaign_meta' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'maybe_create_default_form' ], 20, 2 );
	}

	/**
	 * Register Campaign post type.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_post_type() {
		$labels = [
			'name'                  => _x( 'Campaigns', 'Post Type General Name', 'suredonation' ),
			'singular_name'         => _x( 'Campaign', 'Post Type Singular Name', 'suredonation' ),
			'menu_name'             => __( 'Campaigns', 'suredonation' ),
			'name_admin_bar'        => __( 'Campaign', 'suredonation' ),
			'archives'              => __( 'Campaign Archives', 'suredonation' ),
			'attributes'            => __( 'Campaign Attributes', 'suredonation' ),
			'parent_item_colon'     => __( 'Parent Campaign:', 'suredonation' ),
			'all_items'             => __( 'All Campaigns', 'suredonation' ),
			'add_new_item'          => __( 'Add New Campaign', 'suredonation' ),
			'add_new'               => __( 'Add New', 'suredonation' ),
			'new_item'              => __( 'New Campaign', 'suredonation' ),
			'edit_item'             => __( 'Edit Campaign', 'suredonation' ),
			'update_item'           => __( 'Update Campaign', 'suredonation' ),
			'view_item'             => __( 'View Campaign', 'suredonation' ),
			'view_items'            => __( 'View Campaigns', 'suredonation' ),
			'search_items'          => __( 'Search Campaign', 'suredonation' ),
			'not_found'             => __( 'Not found', 'suredonation' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'suredonation' ),
			'featured_image'        => __( 'Campaign Image', 'suredonation' ),
			'set_featured_image'    => __( 'Set campaign image', 'suredonation' ),
			'remove_featured_image' => __( 'Remove campaign image', 'suredonation' ),
			'use_featured_image'    => __( 'Use as campaign image', 'suredonation' ),
			'insert_into_item'      => __( 'Insert into campaign', 'suredonation' ),
			'uploaded_to_this_item' => __( 'Uploaded to this campaign', 'suredonation' ),
			'items_list'            => __( 'Campaigns list', 'suredonation' ),
			'items_list_navigation' => __( 'Campaigns list navigation', 'suredonation' ),
			'filter_items_list'     => __( 'Filter campaigns list', 'suredonation' ),
		];

		$args = [
			'label'               => __( 'Campaign', 'suredonation' ),
			'description'         => __( 'Fundraising Campaigns', 'suredonation' ),
			'labels'              => $labels,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => false, // We'll add it to custom menu.
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-heart',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'post',
			'show_in_rest'        => true,
			'rewrite'             => [
				'slug'       => 'suredonation-campaigns',
				'with_front' => false,
			],
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register campaign meta for the campaign post type.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_campaign_meta() {
		register_post_meta(
			self::POST_TYPE,
			Helper::SUREDONATION_CAMPAIGN_META_KEY,
			[
				'type'          => 'string',
				'description'   => __( 'Consolidated campaign settings.', 'suredonation' ),
				'single'        => true,
				'show_in_rest'  => [
					'schema' => [
						'type'    => 'string',
						'context' => [ 'edit' ],
					],
				],
				'auth_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Create a default donation form when a campaign is first published.
	 *
	 * Only creates a form if:
	 * - The campaign is being published (not draft/pending)
	 * - No default form has been created yet for this campaign
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 * @since 0.0.1
	 */
	public function maybe_create_default_form( $post_id, $post ) {
		// Skip autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Only proceed for published campaigns.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Check if a default form already exists for this campaign.
		$existing_form_id = get_post_meta( $post_id, self::META_DEFAULT_FORM_ID, true );
		if ( ! empty( $existing_form_id ) ) {
			$existing_form_id = absint( Helper::get_string_value( $existing_form_id ) );
			if ( 0 >= $existing_form_id ) {
				return;
			}
			// Verify the form still exists.
			$existing_form = get_post( $existing_form_id );
			if ( $existing_form instanceof \WP_Post && Donation_Form::POST_TYPE === $existing_form->post_type ) {
				return; // Form already exists, don't create another.
			}
		}

		// Create the default form.
		$form_id = Donation_Form::create_default_form_for_campaign( $post_id, $post->post_title );

		if ( $form_id ) {
			// Store the form ID in campaign meta.
			update_post_meta( $post_id, self::META_DEFAULT_FORM_ID, $form_id );
		}
	}

	/**
	 * Get the default form ID for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int Form ID or 0 if not found.
	 * @since 0.0.1
	 */
	public static function get_default_form_id( $campaign_id ) {
		return absint( Helper::get_string_value( get_post_meta( $campaign_id, self::META_DEFAULT_FORM_ID, true ) ) );
	}
}
