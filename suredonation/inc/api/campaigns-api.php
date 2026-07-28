<?php
/**
 * Campaigns REST API endpoints.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Helper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaigns API class.
 *
 * @since 0.0.1
 */
class Campaigns_API {
	/**
	 * Get campaign endpoints.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public function get_endpoints() {
		return [
			// Get campaigns list & create campaign.
			'/campaigns'                            => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_campaigns' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_campaign' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'title'           => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description'     => [
							'sanitize_callback' => 'wp_kses_post',
						],
						'goal_type'       => [
							'default'           => 'raised_amount',
							'enum'              => [ 'raised_amount', 'donation_count' ],
							'sanitize_callback' => 'sanitize_text_field',
						],
						'goal_amount'     => [
							'sanitize_callback' => static function ( $value ) {
								return '' !== $value ? floatval( $value ) : '';
							},
							'validate_callback' => static function ( $value ) {
								if ( '' === $value ) {
									return true;
								}
								$amount = floatval( $value );
								return $amount >= 0 && $amount <= 999999999.99;
							},
						],
						'campaign_status' => [
							'default' => 'active',
							'enum'    => [ 'active', 'paused', 'completed' ],
						],
						'featured_image'  => [
							'sanitize_callback' => 'absint',
						],
						'email_settings'  => [
							'type'              => 'object',
							'sanitize_callback' => [ $this, 'sanitize_email_settings' ],
						],
						'require_terms'   => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'terms_text'      => [
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			],

			// Update campaign.
			'/campaigns/(?P<id>\d+)'                => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_campaign' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
					],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_campaign' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id'              => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
						'title'           => [
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description'     => [
							'sanitize_callback' => 'wp_kses_post',
						],
						'goal_type'       => [
							'enum'              => [ 'raised_amount', 'donation_count' ],
							'sanitize_callback' => 'sanitize_text_field',
						],
						'goal_amount'     => [
							'sanitize_callback' => static function ( $value ) {
								return '' !== $value ? floatval( $value ) : '';
							},
							'validate_callback' => static function ( $value ) {
								if ( '' === $value ) {
									return true;
								}
								$amount = floatval( $value );
								return $amount >= 0 && $amount <= 999999999.99;
							},
						],
						'campaign_status' => [
							'enum' => [ 'active', 'paused', 'completed' ],
						],
						'featured_image'  => [
							'sanitize_callback' => 'absint',
						],
						'email_settings'  => [
							'type'              => 'object',
							'sanitize_callback' => [ $this, 'sanitize_email_settings' ],
						],
						'require_terms'   => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'terms_text'      => [
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_campaign' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
					],
				],
			],

			// Update campaign status.
			'/campaigns/(?P<id>\d+)/status'         => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_campaign_status' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'     => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
					'status' => [
						'required' => true,
						'enum'     => [ 'publish', 'draft', 'trash' ],
					],
				],
			],

			// Duplicate campaign.
			'/campaigns/(?P<id>\d+)/duplicate'      => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'duplicate_campaign' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			],

			// Bulk actions.
			'/campaigns/bulk'                       => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'bulk_action' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'action' => [
						'required' => true,
						'enum'     => [ 'delete', 'publish', 'draft', 'trash' ],
					],
					'ids'    => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_array( $param ) && ! empty( $param );
						},
					],
				],
			],

			// Get form locations for a campaign.
			'/campaigns/(?P<id>\d+)/form-locations' => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_form_locations' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			],

			// Create (seed) the campaign page layout.
			'/campaigns/(?P<id>\d+)/page'           => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_campaign_page' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			],
		];
	}

	/**
	 * Get a single campaign by ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_campaign( $request ) {
		$campaign_id = absint( $request->get_param( 'id' ) );

		// Get the campaign post.
		$post = get_post( $campaign_id );

		// Check if campaign exists and is of correct type.
		if ( ! $post || SUREDONATION_POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'campaign_not_found',
				__( 'Campaign not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Format and return the campaign data.
		$campaign = $this->format_campaign( $post );

		return new WP_REST_Response(
			[
				'success'  => true,
				'campaign' => $campaign,
			],
			200
		);
	}

	/**
	 * Create (seed) the default campaign page layout for a campaign.
	 *
	 * Idempotent: seeding only runs when the campaign has no page content yet, so
	 * an existing, user-edited page is never overwritten. Returns the campaign in
	 * its post-seed state so the admin can flip the button to "View Campaign".
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function create_campaign_page( $request ) {
		$campaign_id = absint( $request->get_param( 'id' ) );
		$post        = get_post( $campaign_id );

		if ( ! $post || SUREDONATION_POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'campaign_not_found',
				__( 'Campaign not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		\SureDonation\Inc\Campaigns\Campaign_Page::seed_if_empty( $campaign_id );

		// Refresh the post so the response reflects the seeded content.
		$post = get_post( $campaign_id );

		return new WP_REST_Response(
			[
				'success'  => true,
				'campaign' => $this->format_campaign( $post ),
			],
			200
		);
	}

	/**
	 * Get campaigns list with filters, sorting, and pagination.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_campaigns( $request ) {
		$page     = $request->get_param( 'page' ) ?? 1;
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$per_page = -1 === $per_page ? -1 : absint( $per_page );
		if ( 0 === $per_page ) {
			$per_page = 20;
		}
		$search  = $request->get_param( 'search' ) ?? '';
		$status  = $request->get_param( 'status' ) ?? 'all';
		$sort_by = $request->get_param( 'sort_by' ) ?? 'date';
		$order   = $request->get_param( 'order' ) ?? 'desc';

		// Build query args.
		$args = [
			'post_type'      => SUREDONATION_POST_TYPE,
			'posts_per_page' => $per_page,
			'paged'          => -1 === $per_page ? 1 : absint( $page ),
			'orderby'        => $this->get_orderby_field( $sort_by ),
			'order'          => strtoupper( $order ),
		];

		// Add status filter.
		if ( 'paused' === $status ) {
			// "Paused" is a campaign business status stored inside the campaign
			// meta JSON (not a WP post status), so match published campaigns whose
			// meta marks them paused.
			$args['post_status'] = 'publish';
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only campaign list filter.
			$args['meta_query'] = [
				[
					'key'     => Helper::SUREDONATION_CAMPAIGN_META_KEY,
					'value'   => '"campaign_status":"paused"',
					'compare' => 'LIKE',
				],
			];
		} elseif ( 'all' !== $status ) {
			$args['post_status'] = $status;
		} else {
			$args['post_status'] = [ 'publish', 'draft' ];
		}

		// Add search.
		if ( ! empty( $search ) ) {
			$args['s'] = sanitize_text_field( $search );
		}

		// Execute query.
		$query = new \WP_Query( $args );

		// Format campaigns data.
		$campaigns = [];
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$post_obj = $post instanceof \WP_Post ? $post : get_post( $post );
				if ( $post_obj instanceof \WP_Post ) {
					$campaigns[] = $this->format_campaign( $post_obj );
				}
			}
		}

		// Prepare response.
		return new WP_REST_Response(
			[
				'campaigns'  => $campaigns,
				'pagination' => [
					'total'       => (int) $query->found_posts,
					'total_pages' => (int) $query->max_num_pages,
					'per_page'    => (int) $per_page,
					'current'     => (int) $page,
				],
			]
		);
	}

	/**
	 * Create a new campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function create_campaign( $request ) {
		$title           = $request->get_param( 'title' );
		$description     = $request->get_param( 'description' );
		$goal_type       = $request->get_param( 'goal_type' ) ?? 'raised_amount';
		$goal_amount     = $request->get_param( 'goal_amount' );
		$campaign_status = $request->get_param( 'campaign_status' ) ?? 'active';
		$featured_image  = $request->get_param( 'featured_image' );

		// Create the campaign post. The description is stored as the excerpt so
		// post_content stays reserved for the campaign page layout.
		$post_id = wp_insert_post(
			[
				'post_type'    => SUREDONATION_POST_TYPE,
				'post_title'   => $title,
				'post_excerpt' => $description,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create campaign.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		// Save campaign meta as single consolidated meta key.
		$meta_values = [
			'goal_type'       => $goal_type,
			'goal_amount'     => isset( $goal_amount ) && '' !== $goal_amount ? floatval( $goal_amount ) : 0,
			'campaign_status' => $campaign_status,
		];

		$email_settings = $request->get_param( 'email_settings' );
		if ( ! empty( $email_settings ) ) {
			$meta_values['email_settings'] = $email_settings;
		}

		$require_terms = $request->get_param( 'require_terms' );
		if ( ! is_null( $require_terms ) ) {
			$meta_values['require_terms'] = (bool) $require_terms;
		}

		$terms_text = $request->get_param( 'terms_text' );
		if ( ! is_null( $terms_text ) ) {
			$meta_values['terms_text'] = $terms_text;
		}

		Helper::update_campaign_meta( $post_id, $meta_values );

		// Set featured image if provided.
		if ( ! empty( $featured_image ) ) {
			set_post_thumbnail( $post_id, $featured_image );
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Campaign created successfully.', 'suredonation' ),
				'campaign' => $this->format_campaign( get_post( $post_id ) ),
			],
			201
		);
	}

	/**
	 * Update an existing campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function update_campaign( $request ) {
		$campaign_id = $request->get_param( 'id' );

		// Check if campaign exists.
		$campaign = get_post( $campaign_id );
		if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
			return new WP_Error(
				'campaign_not_found',
				__( 'Campaign not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Prepare post data.
		$post_data = [
			'ID' => $campaign_id,
		];

		$title       = $request->get_param( 'title' );
		$description = $request->get_param( 'description' );

		if ( ! empty( $title ) ) {
			$post_data['post_title'] = $title;
		}

		// Use is_null (not empty) so an empty string can clear the description;
		// it feeds the seeded campaign page paragraph.
		if ( ! is_null( $description ) ) {
			$post_data['post_excerpt'] = $description;
		}

		// Update post if there are changes.
		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'update_failed',
					__( 'Failed to update campaign.', 'suredonation' ),
					[ 'status' => 500 ]
				);
			}
		}

		// Update campaign meta (consolidated single key).
		$meta_values    = [];
		$featured_image = $request->get_param( 'featured_image' );

		$goal_type = $request->get_param( 'goal_type' );
		if ( ! is_null( $goal_type ) ) {
			$meta_values['goal_type'] = $goal_type;
		}

		$goal_amount = $request->get_param( 'goal_amount' );
		if ( ! is_null( $goal_amount ) ) {
			$meta_values['goal_amount'] = '' !== $goal_amount ? floatval( $goal_amount ) : 0;
		}

		$campaign_status = $request->get_param( 'campaign_status' );
		if ( ! empty( $campaign_status ) ) {
			$meta_values['campaign_status'] = $campaign_status;
		}

		$email_settings = $request->get_param( 'email_settings' );
		if ( ! is_null( $email_settings ) ) {
			$meta_values['email_settings'] = $email_settings;
		}

		$require_terms = $request->get_param( 'require_terms' );
		if ( ! is_null( $require_terms ) ) {
			$meta_values['require_terms'] = (bool) $require_terms;
		}

		$terms_text = $request->get_param( 'terms_text' );
		if ( ! is_null( $terms_text ) ) {
			$meta_values['terms_text'] = $terms_text;
		}

		if ( ! empty( $meta_values ) ) {
			Helper::update_campaign_meta( $campaign_id, $meta_values );
		}

		// Update featured image if provided.
		if ( ! is_null( $featured_image ) ) {
			if ( empty( $featured_image ) ) {
				delete_post_thumbnail( $campaign_id );
			} else {
				set_post_thumbnail( $campaign_id, $featured_image );
			}
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Campaign updated successfully.', 'suredonation' ),
				'campaign' => $this->format_campaign( get_post( $campaign_id ) ),
			],
			200
		);
	}

	/**
	 * Update campaign status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function update_campaign_status( $request ) {
		$campaign_id = absint( $request->get_param( 'id' ) );
		$status      = $request->get_param( 'status' );

		// Verify the post exists and belongs to our post type.
		$campaign = get_post( $campaign_id );
		if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
			return new WP_Error(
				'campaign_not_found',
				__( 'Campaign not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$result = wp_update_post(
			[
				'ID'          => $campaign_id,
				'post_status' => $status,
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update campaign status.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Campaign status updated successfully.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Duplicate campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function duplicate_campaign( $request ) {
		$campaign_id = $request->get_param( 'id' );

		// Get original campaign.
		$original = get_post( $campaign_id );
		if ( ! $original || SUREDONATION_POST_TYPE !== $original->post_type ) {
			return new WP_Error(
				'campaign_not_found',
				__( 'Campaign not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Create duplicate. The page (post_content) is intentionally NOT copied:
		// its blocks carry the original campaign's id, so a fresh page is seeded
		// for the duplicate on first publish (or via the Create Campaign Page CTA),
		// guaranteeing the correct id and its own default form. The description
		// (post_excerpt) is carried over.
		$duplicate_id = wp_insert_post(
			[
				'post_type'    => $original->post_type,
				'post_title'   => $original->post_title . ' (Copy)',
				'post_excerpt' => $original->post_excerpt,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $duplicate_id ) ) {
			return new WP_Error(
				'duplicate_failed',
				__( 'Failed to duplicate campaign.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		// Copy consolidated campaign meta.
		$meta = get_post_meta( $campaign_id, Helper::SUREDONATION_CAMPAIGN_META_KEY, true );
		if ( ! empty( $meta ) ) {
			update_post_meta( $duplicate_id, Helper::SUREDONATION_CAMPAIGN_META_KEY, $meta );
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Campaign duplicated successfully.', 'suredonation' ),
				'campaign' => $this->format_campaign( get_post( $duplicate_id ) ),
			],
			201
		);
	}

	/**
	 * Delete campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function delete_campaign( $request ) {
		$campaign_id = $request->get_param( 'id' );

		$campaign = get_post( $campaign_id );

		if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
			return new WP_Error(
				'invalid_campaign',
				__( 'Invalid campaign ID.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$result = wp_delete_post( $campaign_id, true );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete campaign.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Campaign deleted successfully.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Bulk action on campaigns.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function bulk_action( $request ) {
		$action = $request->get_param( 'action' );
		$ids    = $request->get_param( 'ids' );

		$success_count = 0;
		$error_count   = 0;

		foreach ( $ids as $id ) {
			$id = absint( $id );

			// Verify each post belongs to our post type before operating on it.
			$post = get_post( $id );
			if ( ! $post || SUREDONATION_POST_TYPE !== $post->post_type ) {
				++$error_count;
				continue;
			}

			if ( 'delete' === $action ) {
				$result = wp_delete_post( $id, true );
				if ( $result ) {
					++$success_count;
				} else {
					++$error_count;
				}
			} else {
				$result = wp_update_post(
					[
						'ID'          => $id,
						'post_status' => $action,
					],
					true
				);
				if ( ! is_wp_error( $result ) ) {
					++$success_count;
				} else {
					++$error_count;
				}
			}
		}

		return new WP_REST_Response(
			[
				'success'       => true,
				'message'       => sprintf(
					// translators: %1$d: success count, %2$d: error count.
					__( 'Bulk action completed. Success: %1$d, Failed: %2$d', 'suredonation' ),
					$success_count,
					$error_count
				),
				'success_count' => $success_count,
				'error_count'   => $error_count,
			],
			200
		);
	}

	/**
	 * Sanitize email settings.
	 *
	 * @param array<string, mixed> $settings Email settings to sanitize.
	 * @return array<string, mixed> Sanitized email settings.
	 * @since 0.0.1
	 */
	public function sanitize_email_settings( $settings ) {
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return [];
		}

		$sanitized = [];

		// Sanitize boolean.
		$sanitized['enabled'] = ! empty( $settings['enabled'] );

		// Sanitize text fields.
		if ( isset( $settings['subject'] ) ) {
			$sanitized['subject'] = sanitize_text_field( Helper::get_string_value( $settings['subject'] ) );
		}

		if ( isset( $settings['from_name'] ) ) {
			$sanitized['from_name'] = sanitize_text_field( Helper::get_string_value( $settings['from_name'] ) );
		}

		// Sanitize email fields.
		if ( isset( $settings['from_email'] ) ) {
			$sanitized['from_email'] = sanitize_email( Helper::get_string_value( $settings['from_email'] ) );
		}

		if ( isset( $settings['reply_to'] ) ) {
			$sanitized['reply_to'] = sanitize_email( Helper::get_string_value( $settings['reply_to'] ) );
		}

		// Sanitize email body (allow HTML).
		if ( isset( $settings['email_body'] ) ) {
			$sanitized['email_body'] = wp_kses_post( Helper::get_string_value( $settings['email_body'] ) );
		}

		return $sanitized;
	}

	/**
	 * Get pages/posts where donation form block is used for this campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_form_locations( $request ) {
		$campaign_id = absint( $request->get_param( 'id' ) );

		// Check if campaign exists.
		$campaign = get_post( $campaign_id );
		if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
			return new WP_Error(
				'campaign_not_found',
				__( 'Campaign not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		global $wpdb;

		// Search for posts containing the donation form block for this campaign.
		// The block stores campaignId in its attributes like: "campaignId":123.
		$search_pattern = '%"campaignId":' . $campaign_id . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status, post_modified
				FROM %i
				WHERE post_content LIKE %s
				AND post_status IN ('publish', 'draft', 'pending', 'private')
				AND post_type IN ('page', 'post', 'suredonation_cmpgn')
				ORDER BY post_modified DESC",
				$wpdb->posts,
				$search_pattern
			)
		);

		$locations = [];
		if ( $posts ) {
			foreach ( $posts as $post ) {
				$edit_url = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
				$view_url = get_permalink( $post->ID );

				$locations[] = [
					'id'          => (int) $post->ID,
					'title'       => $post->post_title,
					'type'        => $post->post_type,
					'status'      => $post->post_status,
					'modified_at' => $post->post_modified,
					'edit_url'    => $edit_url,
					'view_url'    => 'publish' === $post->post_status ? $view_url : null,
				];
			}
		}

		return new WP_REST_Response(
			[
				'success'   => true,
				'locations' => $locations,
			],
			200
		);
	}

	/**
	 * Check if user has permission to manage campaigns.
	 *
	 * @return bool True if user has permission.
	 * @since 0.0.1
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Format campaign data for API response.
	 *
	 * @param \WP_Post|null $post Post object.
	 * @return array<string,mixed> Formatted campaign data.
	 * @since 0.0.1
	 */
	private function format_campaign( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		// Get real-time stats from donations database table.
		$stats         = \SureDonation\Inc\Campaigns\Campaign_Stats::get_stats( $post->ID );
		$meta          = Helper::get_campaign_meta( $post->ID );
		$thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'medium' );

		return [
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'description'        => $post->post_excerpt,
			'status'             => $stats['campaign_status'],
			'goal_type'          => $meta['goal_type'],
			'goal'               => $stats['goal_amount'],
			'raised'             => $stats['total_raised'],
			'donors'             => $stats['donor_count'],
			'donations'          => $stats['donation_count'],
			'donation_count'     => $stats['donation_count'],
			'progress'           => $stats['progress_percentage'],
			'email_settings'     => $meta['email_settings'],
			'require_terms'      => $meta['require_terms'],
			'terms_text'         => $meta['terms_text'],
			'created_at'         => $post->post_date,
			'modified_at'        => $post->post_modified,
			'author'             => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'has_page'           => \SureDonation\Inc\Campaigns\Campaign_Page::has_page( $post->ID ),
			'permalink'          => 'publish' === $post->post_status ? get_permalink( $post->ID ) : null,
			'edit_link'          => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'featured_image'     => (int) get_post_thumbnail_id( $post->ID ),
			'featured_image_url' => $thumbnail_url ? $thumbnail_url : '',
		];
	}

	/**
	 * Get orderby field based on sort parameter.
	 *
	 * @param string $sort_by Sort parameter.
	 * @return string WordPress orderby field.
	 * @since 0.0.1
	 */
	private function get_orderby_field( $sort_by ) {
		$orderby_map = [
			'date'   => 'date',
			'title'  => 'title',
			'status' => 'post_status',
		];

		return $orderby_map[ $sort_by ] ?? 'date';
	}
}
