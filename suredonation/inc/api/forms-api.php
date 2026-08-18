<?php
/**
 * Forms REST API endpoints.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Duplicate_Form;
use SureDonation\Inc\Post_Types\Donation_Form;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forms API class.
 *
 * @since 0.0.1
 */
class Forms_API {
	/**
	 * Get forms endpoints.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public function get_endpoints() {
		return [
			// Get forms list.
			'/forms'             => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_forms' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'campaign_id' => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'per_page'    => [
							'type'              => 'integer',
							'default'           => 100,
							'sanitize_callback' => 'absint',
						],
						'status'      => [
							'type'              => 'string',
							'default'           => 'any',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			],

			// Manage form lifecycle (trash/restore/delete).
			'/forms/manage'      => [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'manage_form_lifecycle' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'form_ids' => [
							'required'          => true,
							'type'              => [ 'array', 'integer' ],
							'sanitize_callback' => static function ( $value ) {
								if ( is_array( $value ) ) {
									return array_map( 'intval', $value );
								}
								return [ intval( $value ) ];
							},
						],
						'action'   => [
							'required'          => true,
							'type'              => 'string',
							'enum'              => [ 'trash', 'restore', 'delete' ],
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			],

			// Set the campaign's default form.
			'/forms/set-default' => [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'set_default_form' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'form_id'     => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'campaign_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			],

			// Duplicate a form.
			'/forms/duplicate'   => [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ Duplicate_Form::get_instance(), 'handle_duplicate_form_rest' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'form_id'      => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => static function ( $value ) {
								return $value > 0;
							},
						],
						'title_suffix' => [
							'required'          => false,
							'type'              => 'string',
							'default'           => __( ' (Copy)', 'suredonation' ),
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			],

			// Get/update single form.
			'/forms/(?P<id>\d+)' => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_form' ],
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
					'callback'            => [ $this, 'update_form' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id'          => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
						'campaign_id' => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
			],
		];
	}

	/**
	 * Get forms list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_forms( $request ) {
		$campaign_id = $request->get_param( 'campaign_id' );
		$per_page    = $request->get_param( 'per_page' ) ?? 100;
		$status      = $request->get_param( 'status' ) ?? 'any';

		// Map status parameter to post_status values.
		$post_status = 'any' === $status ? [ 'publish', 'draft', 'trash' ] : $status;

		$args = [
			'posts_per_page' => $per_page,
			'post_status'    => $post_status,
		];

		// Filter by campaign if provided.
		if ( $campaign_id ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => Donation_Form::META_CAMPAIGN_ID,
					'value'   => $campaign_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				],
			];
		}

		$forms = Donation_Form::get_forms( $args );

		// Flag the campaign's default form so the UI can mark it.
		$default_form_id = $campaign_id ? Campaign_Cpt::get_default_form_id( $campaign_id ) : 0;

		$formatted_forms = [];
		foreach ( $forms as $form ) {
			$formatted               = $this->format_form( $form );
			$formatted['is_default'] = ( (int) $form->ID === $default_form_id );
			$formatted_forms[]       = $formatted;
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'forms'   => $formatted_forms,
			],
			200
		);
	}

	/**
	 * Set the default form for a campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function set_default_form( $request ) {
		$form_id     = absint( $request->get_param( 'form_id' ) );
		$campaign_id = absint( $request->get_param( 'campaign_id' ) );

		$campaign = get_post( $campaign_id );
		if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
			return new WP_Error(
				'invalid_campaign',
				__( 'Invalid campaign.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$form = get_post( $form_id );
		if ( ! $form || Donation_Form::POST_TYPE !== $form->post_type ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'publish' !== $form->post_status ) {
			return new WP_Error(
				'form_not_published',
				__( 'Only a published form can be set as the default.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		if ( absint( Donation_Form::get_form_campaign_id( $form_id ) ) !== $campaign_id ) {
			return new WP_Error(
				'form_campaign_mismatch',
				__( 'This form does not belong to the selected campaign.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		update_post_meta( $campaign_id, Campaign_Cpt::META_DEFAULT_FORM_ID, $form_id );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Default form updated.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Get a single form.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_form( $request ) {
		$form_id = absint( $request->get_param( 'id' ) );
		$form    = get_post( $form_id );

		if ( ! $form || Donation_Form::POST_TYPE !== $form->post_type ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'form'    => $this->format_form( $form, true ),
			],
			200
		);
	}

	/**
	 * Update form (primarily for updating campaign association).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function update_form( $request ) {
		$form_id     = absint( $request->get_param( 'id' ) );
		$campaign_id = $request->get_param( 'campaign_id' );

		$form = get_post( $form_id );

		if ( ! $form || Donation_Form::POST_TYPE !== $form->post_type ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Update campaign association.
		if ( ! is_null( $campaign_id ) ) {
			Donation_Form::set_form_campaign_id( $form_id, $campaign_id );
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Form updated successfully.', 'suredonation' ),
				'form'    => $this->format_form( $form ),
			],
			200
		);
	}

	/**
	 * Manage form lifecycle (trash, restore, permanent delete).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function manage_form_lifecycle( $request ) {
		$form_ids = $request->get_param( 'form_ids' );
		$action   = $request->get_param( 'action' );

		if ( empty( $form_ids ) || empty( $action ) ) {
			return new WP_Error(
				'missing_parameters',
				__( 'Missing required parameters.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$results = [];
		$errors  = [];

		foreach ( $form_ids as $form_id ) {
			$post = get_post( $form_id );

			if ( ! $post || Donation_Form::POST_TYPE !== $post->post_type ) {
				$errors[] = [
					'form_id' => $form_id,
					'error'   => __( 'Form not found.', 'suredonation' ),
				];
				continue;
			}

			$result = false;

			switch ( $action ) {
				case 'trash':
					if ( 'trash' === $post->post_status ) {
						$errors[] = [
							'form_id' => $form_id,
							'error'   => __( 'Form is already in trash.', 'suredonation' ),
						];
						continue 2;
					}
					$result = wp_trash_post( $form_id );
					break;

				case 'restore':
					if ( 'trash' !== $post->post_status ) {
						$errors[] = [
							'form_id' => $form_id,
							'error'   => __( 'Form is not in trash.', 'suredonation' ),
						];
						continue 2;
					}
					$result = wp_untrash_post( $form_id );
					break;

				case 'delete':
					$result = wp_delete_post( $form_id, true );
					break;
			}

			if ( $result ) {
				$results[] = [
					'form_id' => $form_id,
					'action'  => $action,
					'success' => true,
				];
			} else {
				$errors[] = [
					'form_id' => $form_id,
					'error'   => __( 'Operation failed.', 'suredonation' ),
				];
			}
		}

		return new WP_REST_Response(
			[
				'success'       => ! empty( $results ),
				'action'        => $action,
				'processed_ids' => array_column( $results, 'form_id' ),
				'success_count' => count( $results ),
				'error_count'   => count( $errors ),
				'results'       => $results,
				'errors'        => $errors,
			],
			200
		);
	}

	/**
	 * Check if user has read permissions.
	 *
	 * @return bool True if user has permission.
	 * @since 0.0.1
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Format form data for API response.
	 *
	 * @param \WP_Post $form         Form post object.
	 * @param bool     $include_html Whether to include rendered HTML.
	 * @return array<string, mixed> Formatted form data.
	 * @since 0.0.1
	 */
	private function format_form( $form, $include_html = false ) {
		$campaign_id = Donation_Form::get_form_campaign_id( $form->ID );
		$campaign    = $campaign_id ? get_post( $campaign_id ) : null;
		$form_stats  = $this->get_form_stats( $form->ID );

		$data = [
			'id'            => $form->ID,
			'title'         => $form->post_title,
			'status'        => $form->post_status,
			'campaign_id'   => $campaign_id,
			'campaign_name' => $campaign ? $campaign->post_title : '',
			'entries'       => $form_stats['entries'],
			'revenue'       => $form_stats['revenue'],
			'created_at'    => $form->post_date,
			'modified_at'   => $form->post_modified,
			'edit_url'      => admin_url( 'post.php?post=' . $form->ID . '&action=edit' ),
		];

		if ( $include_html ) {
			// Parse and render the form blocks.
			$blocks      = parse_blocks( $form->post_content );
			$blocks_html = '';

			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$block['attrs']['formId'] = $form->ID;
					$blocks_html             .= render_block( $block );
				}
			}

			$data['content']       = $form->post_content;
			$data['rendered_html'] = $blocks_html;
		}

		return $data;
	}

	/**
	 * Get donation stats for a specific form.
	 *
	 * @param int $form_id Form ID.
	 * @return array{entries: int, revenue: float} Form stats.
	 * @since 1.0.0
	 */
	private function get_form_stats( $form_id ) {
		// Delegates to the donations table so this surface and the abilities
		// report the same figures from one query.
		return \SureDonation\Inc\Database\Tables\Donations::get_form_stats( $form_id );
	}
}
