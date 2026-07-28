<?php
/**
 * Abilities API Runtime
 *
 * Contains execute callbacks and helpers for SureDonation abilities.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Abilities;

use Exception;
use SureDonation\Inc\Campaigns\Campaign_Stats;
use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use WP_Query;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime class.
 *
 * @since 0.0.1
 */
class Runtime {
	/**
	 * Sentinel value indicating no default was provided to input_get().
	 */
	private const NO_DEFAULT = '__NO_DEFAULT__';

	/**
	 * Parsed input data.
	 *
	 * @var array<string, mixed>|false
	 */
	protected $input = false;

	// ============================================
	// Category & Ability Registration
	// ============================================

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public function register_categories() {
		wp_register_ability_category(
			'suredonation',
			[
				'label'       => __( 'SureDonation', 'suredonation' ),
				'description' => __( 'Abilities for the SureDonation donation management plugin.', 'suredonation' ),
			]
		);
	}

	/**
	 * Register a dedicated SureDonation MCP server with the MCP adapter.
	 *
	 * Creates endpoint: {site_url}/wp-json/suredonation/v1/mcp
	 *
	 * @param \WP\MCP\Adapter\Adapter $adapter The MCP adapter instance.
	 * @return void
	 * @since 1.0.0
	 */
	public function register_mcp_server( $adapter ) {
		$abilities = wp_get_abilities();
		$tools     = [];

		foreach ( $abilities as $ability ) {
			if ( 0 === strpos( $ability->get_name(), 'suredonation/' ) ) {
				$tools[] = $ability->get_name();
			}
		}

		$transport_class = class_exists( '\WP\MCP\Transport\HttpTransport' )
			? \WP\MCP\Transport\HttpTransport::class
			: \WP\MCP\Transport\Http\RestTransport::class;

		$adapter->create_server(
			'suredonation',
			'suredonation/v1',
			'mcp',
			__( 'SureDonation MCP Server', 'suredonation' ),
			__( 'SureDonation MCP Server for donation management.', 'suredonation' ),
			SUREDONATION_VER,
			[ $transport_class ],
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$tools,
			[],
			[]
		);
	}

	/**
	 * Register all abilities.
	 *
	 * @return void
	 */
	public function register() {
		$abilities = Config_Ability::get_abilities();

		foreach ( $abilities as $ability_name => $ability ) {
			wp_register_ability(
				$ability_name,
				[
					'label'               => $ability['label'],
					'description'         => $ability['description'],
					'category'            => $ability['category'],
					'input_schema'        => $ability['input_schema'],
					'output_schema'       => $ability['output_schema'],
					'execute_callback'    => $ability['execute_callback'],
					'permission_callback' => $ability['permission_callback'],
					'meta'                => $ability['meta'],
				]
			);
		}
	}

	// ============================================
	// Campaign Execute Callbacks
	// ============================================

	/**
	 * List campaigns with pagination, search, status filter, and sorting.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function list_campaigns( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'list-campaigns' );

			$page     = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$search   = Helper::get_string_value( $this->input_get( 'search' ) );
			$status   = Helper::get_string_value( $this->input_get( 'status' ) );
			$sort_by  = Helper::get_string_value( $this->input_get( 'sort_by' ) );
			$order    = Helper::get_string_value( $this->input_get( 'order' ) );

			$orderby_map = [
				'date'   => 'date',
				'title'  => 'title',
				'status' => 'post_status',
			];

			$args = [
				'post_type'      => SUREDONATION_POST_TYPE,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => $orderby_map[ $sort_by ] ?? 'date',
				'order'          => $order,
			];

			if ( 'all' !== $status ) {
				$args['post_status'] = $status;
			} else {
				$args['post_status'] = [ 'publish', 'draft' ];
			}

			if ( ! empty( $search ) ) {
				$args['s'] = $search;
			}

			$query = new WP_Query( $args );

			$campaigns = [];
			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post ) {
					$post_obj = $post instanceof \WP_Post ? $post : get_post( $post );
					if ( $post_obj instanceof \WP_Post ) {
						$campaigns[] = $this->format_campaign( $post_obj );
					}
				}
			}

			return [
				'campaigns'   => $campaigns,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single campaign by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function get_campaign( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-campaign' );
			$post = $this->require_campaign( $this->input_get( 'id' ) );

			$stats = Campaign_Stats::get_stats( $post->ID );
			$meta  = Helper::get_campaign_meta( $post->ID );

			return [
				'id'               => $post->ID,
				'title'            => $post->post_title,
				'description'      => $post->post_excerpt,
				'status'           => $stats['campaign_status'],
				'goal_type'        => $meta['goal_type'],
				'goal'             => $stats['goal_amount'],
				'raised'           => $stats['total_raised'],
				'donors'           => $stats['donor_count'],
				'progress'         => $stats['progress_percentage'],
				'donation_count'   => $stats['donation_count'],
				'average_donation' => $stats['average_donation'],
				'largest_donation' => $stats['largest_donation'],
				'is_goal_reached'  => $stats['is_goal_reached'],
				'require_terms'    => (bool) ( $meta['require_terms'] ?? false ),
				'created_at'       => $post->post_date,
				'modified_at'      => $post->post_modified,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Create a new campaign.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 * @throws Exception If validation or creation fails.
	 */
	public function create_campaign( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'create-campaign' );

			$title       = Helper::get_string_value( $this->input_get( 'title' ) );
			$description = Helper::get_string_value( $this->input_get( 'description', '' ) );

			// The description is stored as the excerpt so post_content stays
			// reserved for the campaign page layout (matching the REST handler).
			$post_id = wp_insert_post(
				[
					'post_type'    => SUREDONATION_POST_TYPE,
					'post_title'   => sanitize_text_field( $title ),
					'post_excerpt' => wp_kses_post( $description ),
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
				],
				true
			);

			if ( is_wp_error( $post_id ) ) {
				throw new Exception( esc_html__( 'Failed to create campaign.', 'suredonation' ) );
			}

			$meta_values = [
				'goal_type'       => $this->input_get( 'goal_type' ),
				'goal_amount'     => $this->input_get( 'goal_amount' ),
				'campaign_status' => $this->input_get( 'campaign_status' ),
				'require_terms'   => $this->input_get( 'require_terms' ),
				'terms_text'      => $this->input_get( 'terms_text', '' ),
			];

			Helper::update_campaign_meta( $post_id, $meta_values );

			return [
				'id'      => $post_id,
				'title'   => $title,
				'status'  => 'active',
				'message' => esc_html__( 'Campaign created successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Update an existing campaign.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 * @throws Exception If validation or update fails.
	 */
	public function update_campaign( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'update-campaign' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$this->require_campaign( $id );

			$parsed_inputs = is_array( $this->input ) ? $this->input : [];

			// Update post fields if provided.
			$post_data = [ 'ID' => $id ];
			$title     = Helper::get_string_value( $this->input_get( 'title', '' ) );
			if ( ! empty( $title ) ) {
				$post_data['post_title'] = sanitize_text_field( $title );
			}

			// The description is stored as the excerpt so post_content stays
			// reserved for the campaign page layout. An empty string clears it.
			if ( array_key_exists( 'description', $parsed_inputs ) ) {
				$post_data['post_excerpt'] = wp_kses_post(
					Helper::get_string_value( $this->input_get( 'description', '' ) )
				);
			}

			if ( count( $post_data ) > 1 ) {
				$result = wp_update_post( $post_data, true );
				if ( is_wp_error( $result ) ) {
					throw new Exception( esc_html__( 'Failed to update campaign.', 'suredonation' ) );
				}
			}

			// Update meta fields if provided.
			$meta_fields = [ 'goal_type', 'goal_amount', 'campaign_status', 'require_terms', 'terms_text' ];
			$meta_values = [];

			foreach ( $meta_fields as $field ) {
				if ( array_key_exists( $field, $parsed_inputs ) ) {
					$value = $parsed_inputs[ $field ];
					// Only include non-default/non-empty values for optional fields.
					if ( '' !== $value && null !== $value ) {
						$meta_values[ $field ] = $value;
					}
				}
			}

			if ( ! empty( $meta_values ) ) {
				Helper::update_campaign_meta( $id, $meta_values );
			}

			$updated_post = get_post( $id );
			$meta         = Helper::get_campaign_meta( $id );

			return [
				'id'      => $id,
				'title'   => $updated_post ? $updated_post->post_title : '',
				'status'  => $meta['campaign_status'],
				'message' => esc_html__( 'Campaign updated successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Delete a campaign permanently.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 * @throws Exception If validation or deletion fails.
	 */
	public function delete_campaign( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'delete-campaign' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$this->require_campaign( $id );

			$result = wp_delete_post( $id, true );
			if ( ! $result ) {
				throw new Exception( esc_html__( 'Failed to delete campaign.', 'suredonation' ) );
			}

			return [
				'id'      => $id,
				'message' => esc_html__( 'Campaign permanently deleted.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Duplicate a campaign.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 * @throws Exception If validation or duplication fails.
	 */
	public function duplicate_campaign( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'duplicate-campaign' );
			$original = $this->require_campaign( $this->input_get( 'id' ) );

			// The page (post_content) is intentionally NOT copied: its blocks carry
			// the original campaign's id, so a fresh page is seeded for the duplicate
			// on first publish (or via the Create Campaign Page CTA). The description
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
				throw new Exception( esc_html__( 'Failed to duplicate campaign.', 'suredonation' ) );
			}

			// Copy campaign meta.
			$meta = get_post_meta( $original->ID, Helper::SUREDONATION_CAMPAIGN_META_KEY, true );
			if ( ! empty( $meta ) ) {
				update_post_meta( $duplicate_id, Helper::SUREDONATION_CAMPAIGN_META_KEY, $meta );
			}

			return [
				'id'      => $duplicate_id,
				'title'   => $original->post_title . ' (Copy)',
				'message' => esc_html__( 'Campaign duplicated successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get pages/posts where a campaign's form block is embedded.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function get_campaign_form_locations( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-campaign-form-locations' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$this->require_campaign( $id );

			global $wpdb;

			$search_pattern = '%"campaignId":%' . $id . '%';

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
				foreach ( $posts as $found_post ) {
					$locations[] = [
						'id'       => (int) $found_post->ID,
						'title'    => $found_post->post_title,
						'type'     => $found_post->post_type,
						'status'   => $found_post->post_status,
						'edit_url' => admin_url( 'post.php?post=' . $found_post->ID . '&action=edit' ),
						'view_url' => 'publish' === $found_post->post_status ? get_permalink( $found_post->ID ) : '',
					];
				}
			}

			return [
				'locations' => $locations,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Donation Execute Callbacks
	// ============================================

	/**
	 * List donations with pagination, search, status/campaign filter, and sorting.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function list_donations( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'list-donations' );

			$page        = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page    = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$search      = Helper::get_string_value( $this->input_get( 'search' ) );
			$status      = Helper::get_string_value( $this->input_get( 'status' ) );
			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );
			$sort_by     = Helper::get_string_value( $this->input_get( 'sort_by' ) );
			$order       = Helper::get_string_value( $this->input_get( 'order' ) );

			$offset = ( $page - 1 ) * $per_page;

			$results = Donations::get_admin_list(
				$status,
				$campaign_id,
				$search,
				$per_page,
				$offset,
				$sort_by,
				strtoupper( $order )
			);

			$total = Donations::get_total_donations_by_status( $status, $campaign_id );

			$donations = [];
			foreach ( $results as $donation ) {
				if ( is_array( $donation ) ) {
					$donations[] = $this->format_donation( $donation );
				}
			}

			return [
				'donations'   => $donations,
				'total'       => (int) $total,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single donation by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function get_donation( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donation' );
			$donation = $this->require_donation( $this->input_get( 'id' ) );

			return $this->format_donation( $donation );
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get paginated notes for a donation.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function get_donation_notes( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donation-notes' );
			$id       = Helper::get_integer_value( $this->input_get( 'id' ) );
			$page     = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$this->require_donation( $id );

			$notes_data = Donations::get_notes( $id, $page, $per_page );

			return [
				'notes'       => $notes_data['notes'],
				'total'       => (int) $notes_data['total'],
				'total_pages' => (int) $notes_data['total_pages'],
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Add a note to a donation.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 * @throws Exception If validation fails.
	 */
	public function add_donation_note( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'add-donation-note' );
			$id   = Helper::get_integer_value( $this->input_get( 'id' ) );
			$note = Helper::get_string_value( $this->input_get( 'note' ) );
			$this->require_donation( $id );

			$result = Donations::add_note( $id, $note, get_current_user_id() );

			if ( ! $result['success'] ) {
				throw new Exception( esc_html__( 'Failed to add note.', 'suredonation' ) );
			}

			return [
				'note_id' => $result['note_id'],
				'message' => esc_html__( 'Note added successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Donor Execute Callbacks
	// ============================================

	/**
	 * List donors with pagination, status filter, and sorting.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function list_donors( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'list-donors' );

			$page     = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$status   = Helper::get_string_value( $this->input_get( 'status' ) );
			$sort_by  = Helper::get_string_value( $this->input_get( 'sort_by' ) );
			$order    = Helper::get_string_value( $this->input_get( 'order' ) );
			$offset   = ( $page - 1 ) * $per_page;

			if ( 'all' === $status ) {
				$results = Donors::get_all( $per_page, $offset, $sort_by, $order );
			} else {
				$results = Donors::get_by_status( $status, $per_page, $offset, $sort_by, $order );
			}

			$total = Donors::get_total_donors( $status );

			$donors = [];
			foreach ( $results as $donor ) {
				if ( is_array( $donor ) ) {
					$donors[] = $this->format_donor( $donor );
				}
			}

			return [
				'donors'      => $donors,
				'total'       => (int) $total,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single donor by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function get_donor( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donor' );
			$donor = $this->require_donor( $this->input_get( 'id' ) );

			return $this->format_donor( $donor );
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a donor by email address.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 * @throws Exception If validation fails.
	 */
	public function get_donor_by_email( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donor-by-email' );
			$email = sanitize_email( Helper::get_string_value( $this->input_get( 'email' ) ) );

			if ( empty( $email ) || ! is_email( $email ) ) {
				throw new Exception( esc_html__( 'A valid email address is required.', 'suredonation' ) );
			}

			$donor = Donors::get_by_email( $email );
			if ( ! $donor ) {
				throw new Exception( esc_html__( 'Donor not found.', 'suredonation' ) );
			}

			return $this->format_donor( $donor );
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get top donors ranked by total donated.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function get_top_donors( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-top-donors' );
			$limit = $this->clamp_per_page( $this->input_get( 'limit' ) );

			$results = Donors::get_top_donors( $limit );

			$donors = [];
			foreach ( $results as $donor ) {
				if ( is_array( $donor ) ) {
					$id_val      = $donor['id'] ?? 0;
					$donated_val = $donor['total_donated'] ?? 0;
					$count_val   = $donor['donation_count'] ?? 0;

					$donors[] = [
						'id'             => is_numeric( $id_val ) ? (int) $id_val : 0,
						'name'           => $donor['name'] ?? '',
						'email'          => $donor['email'] ?? '',
						'total_donated'  => is_numeric( $donated_val ) ? (float) $donated_val : 0.0,
						'donation_count' => is_numeric( $count_val ) ? (int) $count_val : 0,
					];
				}
			}

			return [
				'donors' => $donors,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Form Execute Callbacks
	// ============================================

	/**
	 * List donation forms with optional campaign and status filter.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function list_forms( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'list-forms' );

			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );
			$status      = Helper::get_string_value( $this->input_get( 'status' ) );
			$per_page    = $this->clamp_per_page( $this->input_get( 'per_page' ) );

			$post_status = 'any' === $status ? [ 'publish', 'draft', 'trash' ] : $status;

			$args = [
				'posts_per_page' => $per_page,
				'post_status'    => $post_status,
			];

			if ( $campaign_id > 0 ) {
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

			$formatted = [];
			foreach ( $forms as $form ) {
				$formatted[] = $this->format_form( $form );
			}

			return [
				'forms' => $formatted,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single donation form by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 * @throws Exception If validation fails.
	 */
	public function get_form( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-form' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );

			if ( 0 === $id ) {
				throw new Exception( esc_html__( 'Invalid form ID.', 'suredonation' ) );
			}

			$form = get_post( $id );
			if ( ! $form || Donation_Form::POST_TYPE !== $form->post_type ) {
				throw new Exception( esc_html__( 'Form not found.', 'suredonation' ) );
			}

			return $this->format_form( $form );
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Dashboard & Analytics Execute Callbacks
	// ============================================

	/**
	 * Get donation trends for time-series analysis.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed> Response.
	 */
	public function get_donation_trends( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donation-trends' );

			$after  = Helper::get_string_value( $this->input_get( 'after' ) );
			$before = Helper::get_string_value( $this->input_get( 'before' ) );
			$group  = Helper::get_string_value( $this->input_get( 'group' ) );

			$date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
			if ( ! empty( $after ) && ! preg_match( $date_pattern, $after ) ) {
				$after = '';
			}
			if ( ! empty( $before ) && ! preg_match( $date_pattern, $before ) ) {
				$before = '';
			}

			$trends   = Donations::get_donation_trends( $after, $before, $group );
			$currency = Payment_Helper::get_currency();

			$formatted = [];
			foreach ( $trends as $trend ) {
				$formatted[] = [
					'period'         => $trend['period'] ?? '',
					'donation_count' => isset( $trend['donation_count'] ) ? (int) $trend['donation_count'] : 0,
					'total_amount'   => isset( $trend['total_amount'] ) ? (float) $trend['total_amount'] : 0.0,
				];
			}

			return [
				'trends'   => $formatted,
				'currency' => $currency,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Check user capabilities.
	 *
	 * @param string|array<string> $caps Single capability or array of capabilities (AND logic).
	 * @return bool True if user has required capabilities.
	 */
	public function permission_callback( $caps ) {
		if ( empty( $caps ) ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return false;
		}

		if ( is_string( $caps ) ) {
			return $user->has_cap( $caps );
		}

		if ( is_array( $caps ) ) {
			foreach ( $caps as $cap ) {
				if ( ! $user->has_cap( $cap ) ) {
					return false;
				}
			}
			return true;
		}

		return false;
	}

	/**
	 * Get a parsed input value.
	 *
	 * @param string $name     Property name.
	 * @param mixed  $fallback Fallback value if property not found.
	 * @return mixed
	 * @throws Exception If inputs not parsed or property not found and no fallback.
	 */
	public function input_get( $name, $fallback = self::NO_DEFAULT ) {
		if ( false === $this->input ) {
			throw new Exception( esc_html__( 'Inputs not parsed.', 'suredonation' ) );
		}

		if ( ! array_key_exists( $name, $this->input ) ) {
			if ( self::NO_DEFAULT !== $fallback ) {
				return $fallback;
			}
			throw new Exception(
				sprintf(
					/* translators: %s: property name */
					esc_html__( 'Property %s not found.', 'suredonation' ),
					esc_html( $name )
				)
			);
		}

		return $this->input[ $name ];
	}

	// ============================================
	// Helper Methods
	// ============================================

	/**
	 * Initialize input parsing.
	 *
	 * @param mixed  $input        Raw input.
	 * @param string $ability_name Ability identifier.
	 * @return void
	 */
	protected function init( $input, $ability_name ) {
		$this->input_parse( $input, $ability_name );
	}

	/**
	 * Parse and validate input against schema.
	 *
	 * @param mixed  $input        Raw input.
	 * @param string $ability_name Ability identifier.
	 * @return array<string, mixed> Parsed input.
	 * @throws Exception If required field is missing or invalid value.
	 */
	protected function input_parse( $input, $ability_name ) {
		$this->input = [];

		if ( is_object( $input ) && is_a( $input, 'WP_REST_Request' ) ) {
			$input = $input->get_json_params();
			if ( ! is_array( $input ) ) {
				$input = [];
			}
		}

		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$input_schema = Config_Ability::get_ability_input_schema( $ability_name );
		if ( ! is_array( $input_schema ) || empty( $input_schema ) ) {
			return [];
		}

		if ( ! isset( $input_schema['properties'] ) || ! is_array( $input_schema['properties'] ) ) {
			return [];
		}

		$required_fields = isset( $input_schema['required'] ) && is_array( $input_schema['required'] )
			? $input_schema['required']
			: [];

		foreach ( $input_schema['properties'] as $name => $prop ) {
			$type      = isset( $prop['type'] ) ? strtolower( $prop['type'] ) : 'string';
			$raw_value = array_key_exists( $name, $input ) ? $input[ $name ] : null;

			$is_required = in_array( $name, $required_fields, true );
			if ( $is_required && ( null === $raw_value || '' === $raw_value ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: field name */
						esc_html__( 'Required field %s is missing.', 'suredonation' ),
						esc_html( $name )
					)
				);
			}

			if ( null === $raw_value && isset( $prop['default'] ) ) {
				$raw_value = $prop['default'];
			}

			if ( null === $raw_value ) {
				switch ( $type ) {
					case 'integer':
						$raw_value = 0;
						break;
					case 'number':
						$raw_value = 0.0;
						break;
					case 'boolean':
						$raw_value = false;
						break;
					case 'array':
					case 'object':
						$raw_value = [];
						break;
					default:
						$raw_value = '';
						break;
				}
			}

			$value = $raw_value;

			switch ( $type ) {
				case 'integer':
					$value = intval( $value );
					break;
				case 'number':
					$value = floatval( $value );
					break;
				case 'boolean':
					$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
					break;
				case 'string':
					$str_value = is_string( $value ) ? $value : strval( $value );
					// Use wp_kses_post for fields with format 'html', sanitize_text_field for all others.
					$value = isset( $prop['format'] ) && 'html' === $prop['format']
						? wp_kses_post( $str_value )
						: sanitize_text_field( $str_value );
					break;
				case 'array':
				case 'object':
					if ( ! is_array( $value ) ) {
						$value = [];
					}
					$value = $this->sanitize_recursive( $value );
					break;
			}

			if ( isset( $prop['enum'] ) && is_array( $prop['enum'] ) ) {
				if ( ! in_array( $value, $prop['enum'], true ) ) {
					throw new Exception(
						sprintf(
							/* translators: %s: field name */
							esc_html__( 'Invalid value for %s.', 'suredonation' ),
							esc_html( $name )
						)
					);
				}
			}

			$this->input[ $name ] = $value;
		}

		return $this->input;
	}

	/**
	 * Recursively sanitize array/object values.
	 *
	 * @param array<mixed> $data Data to sanitize.
	 * @return array<mixed> Sanitized data.
	 */
	protected function sanitize_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sanitized = [];
		foreach ( $data as $key => $value ) {
			$key = sanitize_text_field( strval( $key ) );
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_recursive( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) ) {
				$sanitized[ $key ] = intval( $value );
			} elseif ( is_float( $value ) ) {
				$sanitized[ $key ] = floatval( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $key ] = (bool) $value;
			} else {
				$sanitized[ $key ] = sanitize_text_field( strval( $value ) );
			}
		}
		return $sanitized;
	}

	/**
	 * Format error response.
	 *
	 * @param Exception $e The exception.
	 * @return array<string, mixed> Error response.
	 */
	protected function error( $e ) {
		return [
			'error' => [
				'code'    => 'suredonation_error',
				'message' => $e->getMessage(),
			],
		];
	}

	/**
	 * Require a valid campaign post by ID.
	 *
	 * @param mixed $id Campaign ID.
	 * @return \WP_Post The campaign post.
	 * @throws Exception If ID is zero, post not found, or wrong post type.
	 */
	protected function require_campaign( $id ) {
		$id = Helper::get_integer_value( $id );
		if ( 0 === $id ) {
			throw new Exception( esc_html__( 'Invalid campaign ID.', 'suredonation' ) );
		}

		$post = get_post( $id );
		if ( ! $post || SUREDONATION_POST_TYPE !== $post->post_type ) {
			throw new Exception( esc_html__( 'Campaign not found.', 'suredonation' ) );
		}

		return $post;
	}

	/**
	 * Require a valid donation by ID.
	 *
	 * @param mixed $id Donation ID.
	 * @return array<string, mixed> The donation record.
	 * @throws Exception If ID is zero or donation not found.
	 */
	protected function require_donation( $id ) {
		$id = Helper::get_integer_value( $id );
		if ( 0 === $id ) {
			throw new Exception( esc_html__( 'Invalid donation ID.', 'suredonation' ) );
		}

		$donation = Donations::get( $id );
		if ( ! $donation ) {
			throw new Exception( esc_html__( 'Donation not found.', 'suredonation' ) );
		}

		return $donation;
	}

	/**
	 * Require a valid donor by ID.
	 *
	 * @param mixed $id Donor ID.
	 * @return array<string, mixed> The donor record.
	 * @throws Exception If ID is zero or donor not found.
	 */
	protected function require_donor( $id ) {
		$id = Helper::get_integer_value( $id );
		if ( 0 === $id ) {
			throw new Exception( esc_html__( 'Invalid donor ID.', 'suredonation' ) );
		}

		$donor = Donors::get( $id );
		if ( ! $donor ) {
			throw new Exception( esc_html__( 'Donor not found.', 'suredonation' ) );
		}

		return $donor;
	}

	/**
	 * Clamp per_page to safe bounds.
	 *
	 * @param mixed $per_page Raw per_page value.
	 * @param int   $max      Maximum allowed.
	 * @return int Clamped value (minimum 1).
	 */
	protected function clamp_per_page( $per_page, $max = 100 ) {
		return max( 1, min( Helper::get_integer_value( $per_page ), $max ) );
	}

	/**
	 * Clamp page to minimum 1.
	 *
	 * @param mixed $page Raw page value.
	 * @return int Clamped value (minimum 1).
	 */
	protected function clamp_page( $page ) {
		return max( 1, Helper::get_integer_value( $page ) );
	}

	/**
	 * Format a donation record for ability output.
	 *
	 * @param array<string, mixed> $donation Raw donation data from database.
	 * @return array<string, mixed> Formatted donation data.
	 */
	private function format_donation( $donation ) {
		$campaign_id = isset( $donation['campaign_id'] ) ? Helper::get_integer_value( $donation['campaign_id'] ) : 0;
		$donation_id = isset( $donation['id'] ) ? Helper::get_integer_value( $donation['id'] ) : 0;

		$logs = $donation_id ? Donations::get_log( $donation_id ) : [];

		return [
			'id'              => $donation_id,
			'campaign_id'     => $campaign_id,
			'campaign_title'  => $campaign_id ? wp_kses_post( get_the_title( $campaign_id ) ) : '',
			'donor_id'        => isset( $donation['donor_id'] ) ? Helper::get_integer_value( $donation['donor_id'] ) : 0,
			'donor_name'      => $donation['donor_name'] ?? '',
			'donor_email'     => $donation['donor_email'] ?? '',
			'donor_phone'     => $donation['donor_phone'] ?? '',
			'amount'          => Helper::get_float_value( $donation['amount'] ?? 0 ),
			'fees_covered'    => Helper::get_float_value( $donation['fees_covered'] ?? 0 ),
			'refunded_amount' => Helper::get_float_value( $donation['refunded_amount'] ?? 0 ),
			'currency'        => $donation['currency'] ?? 'USD',
			'donation_type'   => $donation['donation_type'] ?? 'one-time',
			'is_anonymous'    => ! empty( $donation['is_anonymous'] ),
			'donor_comment'   => $donation['donor_comment'] ?? '',
			'payment_status'  => $donation['payment_status'] ?? 'pending',
			'payment_mode'    => $donation['payment_mode'] ?? 'test',
			'gateway'         => $donation['gateway'] ?? '',
			'transaction_id'  => $donation['transaction_id'] ?? '',
			'created_at'      => $donation['created_at'] ?? '',
			'updated_at'      => $donation['updated_at'] ?? '',
			'logs'            => $logs,
		];
	}

	/**
	 * Format a donation form for ability output.
	 *
	 * @param \WP_Post $form Form post object.
	 * @return array<string, mixed> Formatted form data.
	 */
	private function format_form( $form ) {
		$campaign_id = Donation_Form::get_form_campaign_id( $form->ID );
		$campaign    = $campaign_id ? get_post( $campaign_id ) : null;

		return [
			'id'            => $form->ID,
			'title'         => $form->post_title,
			'status'        => $form->post_status,
			'campaign_id'   => $campaign_id,
			'campaign_name' => $campaign ? $campaign->post_title : '',
			'created_at'    => $form->post_date,
			'modified_at'   => $form->post_modified,
			'edit_url'      => admin_url( 'post.php?post=' . $form->ID . '&action=edit' ),
		];
	}

	/**
	 * Format a donor record for ability output.
	 *
	 * @param array<string, mixed> $donor Raw donor data from database.
	 * @return array<string, mixed> Formatted donor data.
	 */
	private function format_donor( $donor ) {
		$id_val      = $donor['id'] ?? 0;
		$user_val    = $donor['user_id'] ?? 0;
		$donated_val = $donor['total_donated'] ?? 0;
		$count_val   = $donor['donation_count'] ?? 0;
		$largest_val = $donor['largest_donation'] ?? 0;

		return [
			'id'                  => is_numeric( $id_val ) ? (int) $id_val : 0,
			'name'                => $donor['name'] ?? '',
			'email'               => $donor['email'] ?? '',
			'phone'               => $donor['phone'] ?? '',
			'user_id'             => is_numeric( $user_val ) ? (int) $user_val : 0,
			'donor_status'        => $donor['donor_status'] ?? 'active',
			'total_donated'       => is_numeric( $donated_val ) ? (float) $donated_val : 0.0,
			'donation_count'      => is_numeric( $count_val ) ? (int) $count_val : 0,
			'largest_donation'    => is_numeric( $largest_val ) ? (float) $largest_val : 0.0,
			'first_donation_date' => $donor['first_donation_date'] ?? '',
			'last_donation_date'  => $donor['last_donation_date'] ?? '',
			'donor_tags'          => is_array( $donor['donor_tags'] ?? null ) ? $donor['donor_tags'] : [],
			'created_at'          => $donor['created_at'] ?? '',
			'updated_at'          => $donor['updated_at'] ?? '',
		];
	}

	/**
	 * Format a campaign post for ability output.
	 *
	 * @param \WP_Post $post Campaign post.
	 * @return array<string, mixed> Formatted campaign data.
	 */
	private function format_campaign( $post ) {
		$stats = Campaign_Stats::get_stats( $post->ID );
		$meta  = Helper::get_campaign_meta( $post->ID );

		return [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'status'      => $stats['campaign_status'],
			'goal_type'   => $meta['goal_type'],
			'goal'        => $stats['goal_amount'],
			'raised'      => $stats['total_raised'],
			'donors'      => $stats['donor_count'],
			'progress'    => $stats['progress_percentage'],
			'created_at'  => $post->post_date,
			'modified_at' => $post->post_modified,
		];
	}
}
