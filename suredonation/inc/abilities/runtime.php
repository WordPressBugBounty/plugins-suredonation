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
use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Campaigns\Campaign_Stats;
use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use WP_Error;
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

	/**
	 * Names of the properties the caller actually sent.
	 *
	 * Parsing materialises every schema property (filling defaults, or a type
	 * zero-value when there is no default), so `$this->input` alone cannot
	 * tell "the caller omitted this" from "the parser supplied it". Partial
	 * updates need that distinction: without it an omitted `goal_amount` arrives
	 * as 0.0 and overwrites the stored goal. Keyed by property name for O(1)
	 * lookups; reset on every parse.
	 *
	 * @var array<string, true>
	 * @since 1.5.0
	 */
	protected $provided = [];

	// ============================================
	// Category & Ability Registration
	// ============================================

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public function register_categories() {
		// Another plugin (notably zipwp-mcp) may already have registered this
		// category; re-registering triggers a _doing_it_wrong notice.
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'suredonation' ) ) {
			return;
		}

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
			// Skip abilities whose write/delete gate is closed rather than
			// registering them with a permission callback that always fails.
			// A registered-but-refusing tool still shows up in MCP/REST
			// listings, so a client discovers it, calls it, and gets a
			// permission error for what is really a settings choice.
			$gate = isset( $ability['gate'] ) ? Helper::get_string_value( $ability['gate'] ) : '';
			if ( '' !== $gate && ! Config_Ability::is_gate_open( $gate ) ) {
				continue;
			}

			// Don't collide with an ability another plugin already registered
			// under the same name (e.g. zipwp-mcp).
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
				continue;
			}

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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
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

			if ( 'paused' === $status ) {
				// "paused" is a campaign business status inside the campaign meta
				// JSON, not a WP post status, so match published campaigns whose
				// meta marks them paused (mirrors the REST handler).
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
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
				'post_status'      => $post->post_status,
				'created_at'       => $post->post_date,
				'modified_at'      => $post->post_modified,
			] + $this->campaign_extras( $post, $meta );
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Create a new campaign.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation or creation fails.
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
				throw new Ability_Exception( 'campaign_create_failed', esc_html__( 'Failed to create campaign.', 'suredonation' ) );
			}

			$meta_values = [
				'goal_type'         => $this->input_get( 'goal_type' ),
				'goal_amount'       => $this->input_get( 'goal_amount' ),
				'campaign_status'   => $this->input_get( 'campaign_status' ),
				'require_terms'     => $this->input_get( 'require_terms' ),
				'terms_text'        => $this->input_get( 'terms_text', '' ),
				'thank_you_message' => $this->input_get( 'thank_you_message', '' ),
			];

			Helper::update_campaign_meta( $post_id, $meta_values );

			$featured_image = Helper::get_integer_value( $this->input_get( 'featured_image', 0 ) );
			if ( $featured_image > 0 ) {
				$this->set_featured_image( $post_id, $featured_image );
			}

			// Read the status back rather than assuming 'active': the caller can
			// pass campaign_status, and update_campaign_meta() is the authority.
			$created_meta = Helper::get_campaign_meta( $post_id );

			return [
				'id'      => $post_id,
				'title'   => $title,
				'status'  => Helper::get_string_value( $created_meta['campaign_status'] ),
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation or update fails.
	 */
	public function update_campaign( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'update-campaign' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$this->require_campaign( $id );

			// Every field except `id` is optional: only what the caller actually
			// sent is written. input_provided() is the only reliable test —
			// $this->input holds every schema property after parsing, so keying
			// off it would rewrite untouched fields with parser-supplied
			// zero-values (an omitted goal_amount would reset the goal to 0).
			$post_data = [ 'ID' => $id ];

			// An explicit empty title is ignored rather than blanking the campaign
			// (matching the REST handler); an empty description is honoured, since
			// it clears the paragraph on the seeded campaign page.
			if ( $this->input_provided( 'title' ) ) {
				// input_parse() already sanitized this; sanitizing again here is
				// deliberate belt-and-braces, and keeps this callback readable
				// standalone and consistent with create_campaign().
				$title = sanitize_text_field( Helper::get_string_value( $this->input_get( 'title' ) ) );
				if ( '' !== $title ) {
					$post_data['post_title'] = $title;
				}
			}

			// The description is stored as the excerpt so post_content stays
			// reserved for the campaign page layout.
			if ( $this->input_provided( 'description' ) ) {
				$post_data['post_excerpt'] = wp_kses_post(
					Helper::get_string_value( $this->input_get( 'description' ) )
				);
			}

			if ( count( $post_data ) > 1 ) {
				$result = wp_update_post( $post_data, true );
				if ( is_wp_error( $result ) ) {
					throw new Ability_Exception( 'campaign_update_failed', esc_html__( 'Failed to update campaign.', 'suredonation' ) );
				}
			}

			// goal_type and campaign_status are enum-constrained, so a provided
			// value is always valid — no empty-value guard is needed here.
			$meta_values = [];

			foreach ( [ 'goal_type', 'goal_amount', 'campaign_status', 'require_terms', 'terms_text', 'thank_you_message' ] as $field ) {
				if ( $this->input_provided( $field ) ) {
					$meta_values[ $field ] = $this->input_get( $field );
				}
			}

			if ( ! empty( $meta_values ) ) {
				Helper::update_campaign_meta( $id, $meta_values );
			}

			// The featured image is a post thumbnail rather than campaign meta.
			// Only touched when sent; an explicit 0 clears it.
			if ( $this->input_provided( 'featured_image' ) ) {
				$featured_image = Helper::get_integer_value( $this->input_get( 'featured_image' ) );
				if ( $featured_image > 0 ) {
					$this->set_featured_image( $id, $featured_image );
				} else {
					delete_post_thumbnail( $id );
				}
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation or deletion fails.
	 */
	public function delete_campaign( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'delete-campaign' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$this->require_campaign( $id );

			// Refuse to delete a campaign that has donations against it. Its rows
			// are financial history: they must not be deleted, and re-pointing
			// them at nothing would silently break revenue reporting. Deleting
			// such a campaign stays a deliberate action in the admin UI.
			$donation_count = Donations::count_by_campaign( $id );
			if ( $donation_count > 0 ) {
				throw new Ability_Exception(
					'campaign_has_donations',
					sprintf(
						/* translators: %d: number of donations recorded against the campaign. */
						esc_html__( 'This campaign has %d donation(s) recorded against it and cannot be deleted through this ability, because the donation records are financial history. Archive the campaign by setting its status to completed instead.', 'suredonation' ),
						(int) $donation_count
					)
				);
			}

			// Creating a campaign auto-creates a donation form for it. Deleting
			// only the campaign left that form behind, pointing at a post that no
			// longer exists, so clean the children up in the same operation.
			// WP_Query's 'any' EXCLUDES statuses registered exclude_from_search,
			// which includes 'trash' — so 'any' would leave a trashed child form
			// orphaned, the exact case this cleanup exists to prevent. Name them.
			$child_forms = Donation_Form::get_forms(
				[
					'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
					'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off cleanup on an admin action.
						[
							'key'     => Donation_Form::META_CAMPAIGN_ID,
							'value'   => $id,
							'compare' => '=',
							'type'    => 'NUMERIC',
						],
					],
				]
			);

			$deleted_forms = [];
			$kept_forms = [];

			foreach ( $child_forms as $form ) {
				// The campaign-level guard above counts donations recorded against
				// the CAMPAIGN. A form can have been reassigned to this campaign
				// while its donations are attributed elsewhere, so check the form
				// too: deleting it would leave those rows with a dangling form_id.
				// This mirrors the guard manage-form applies.
				if ( Donations::count_by_form( (int) $form->ID ) > 0 ) {
					$kept_forms[] = (int) $form->ID;
					continue;
				}

				if ( wp_delete_post( $form->ID, true ) ) {
					$deleted_forms[] = (int) $form->ID;
				}
			}

			$result = wp_delete_post( $id, true );
			if ( ! $result ) {
				throw new Ability_Exception( 'campaign_delete_failed', esc_html__( 'Failed to delete campaign.', 'suredonation' ) );
			}

			return [
				'id'            => $id,
				'deleted_forms' => $deleted_forms,
				// Reported rather than silently skipped, so a caller can see that
				// a form outlived its campaign and why.
				'kept_forms'    => $kept_forms,
				'message'       => esc_html__( 'Campaign permanently deleted.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Duplicate a campaign.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation or duplication fails.
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
				throw new Ability_Exception( 'campaign_duplicate_failed', esc_html__( 'Failed to duplicate campaign.', 'suredonation' ) );
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_campaign_form_locations( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-campaign-form-locations' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$this->require_campaign( $id );

			global $wpdb;

			// Anchor on the value's terminator so campaign 5 cannot match
			// "campaignId":51 and a stray digit elsewhere in the content cannot
			// match at all. Block attributes serialise as JSON, so the id is
			// followed by a comma or the closing brace.
			$escaped_id     = $wpdb->esc_like( '"campaignId":' . $id );
			$search_pattern = '%' . $escaped_id . ',%';
			$alt_pattern    = '%' . $escaped_id . '}%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_status, post_modified
					FROM %i
					WHERE ( post_content LIKE %s OR post_content LIKE %s )
					AND post_status IN ('publish', 'draft', 'pending', 'private')
					AND post_type IN ('page', 'post', 'suredonation_cmpgn')
					ORDER BY post_modified DESC",
					$wpdb->posts,
					$search_pattern,
					$alt_pattern
				)
			);

			$locations = [];
			if ( $posts ) {
				foreach ( $posts as $found_post ) {
					$locations[] = [
						'id'          => (int) $found_post->ID,
						'title'       => $found_post->post_title,
						'type'        => $found_post->post_type,
						'status'      => $found_post->post_status,
						'modified_at' => $found_post->post_modified,
						'edit_url'    => admin_url( 'post.php?post=' . $found_post->ID . '&action=edit' ),
						'view_url'    => 'publish' === $found_post->post_status ? get_permalink( $found_post->ID ) : '',
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
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

			// Must mirror get_admin_list()'s filters, search included, or the
			// totals describe a different result set than the rows returned.
			$total = Donations::count_admin_list( $status, $campaign_id, $search );

			$donations = [];
			foreach ( $results as $donation ) {
				if ( is_array( $donation ) ) {
					$donations[] = $this->format_donation_summary( $donation );
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails.
	 */
	public function add_donation_note( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'add-donation-note' );
			$id   = Helper::get_integer_value( $this->input_get( 'id' ) );
			$note = Helper::get_string_value( $this->input_get( 'note' ) );
			$this->require_donation( $id );

			$result = Donations::add_note( $id, $note, get_current_user_id() );

			if ( ! $result['success'] ) {
				throw new Ability_Exception( 'donation_note_add_failed', esc_html__( 'Failed to add note.', 'suredonation' ) );
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function list_donors( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'list-donors' );

			$page        = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page    = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$status      = Helper::get_string_value( $this->input_get( 'status' ) );
			$sort_by     = Helper::get_string_value( $this->input_get( 'sort_by' ) );
			$order       = Helper::get_string_value( $this->input_get( 'order' ) );
			$search      = Helper::get_string_value( $this->input_get( 'search' ) );
			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );
			$after       = $this->valid_date( $this->input_get( 'after' ) );
			$before      = $this->valid_date( $this->input_get( 'before' ) );
			$offset      = ( $page - 1 ) * $per_page;

			// get_all()/get_by_status() cannot search, filter by campaign, or
			// filter by date. The admin listing uses these instead, so the
			// ability now shares one filter contract with list-donations.
			$results = Donors::get_admin_list( $search, $campaign_id, $status, $per_page, $offset, $sort_by, $order, $after, $before );
			$total   = Donors::get_total_donors_filtered( $search, $campaign_id, $status, $after, $before );

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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails.
	 */
	public function get_donor_by_email( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donor-by-email' );
			$email = sanitize_email( Helper::get_string_value( $this->input_get( 'email' ) ) );

			if ( empty( $email ) || ! is_email( $email ) ) {
				throw new Ability_Exception( 'invalid_donor_email', esc_html__( 'A valid email address is required.', 'suredonation' ) );
			}

			$donor = Donors::get_by_email( $email );
			if ( ! $donor ) {
				throw new Ability_Exception( 'donor_not_found', esc_html__( 'Donor not found.', 'suredonation' ) );
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
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
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
	 */
	public function list_forms( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'list-forms' );

			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );
			$status      = Helper::get_string_value( $this->input_get( 'status' ) );
			$per_page    = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$page        = $this->clamp_page( $this->input_get( 'page' ) );

			$post_status = 'any' === $status ? [ 'publish', 'draft', 'trash' ] : $status;

			$args = [
				'posts_per_page' => $per_page,
				'paged'          => $page,
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
			// One GROUP BY for the page instead of a COUNT/SUM per form.
			$stats = Donations::get_form_stats_bulk( wp_list_pluck( $forms, 'ID' ) );

			foreach ( $forms as $form ) {
				$formatted[] = $this->format_form( $form, $stats[ (int) $form->ID ] ?? null );
			}

			// get_posts() returns no count, so total comes from a matching
			// count-only query. Without it list-forms was the one list ability
			// with no pagination contract.
			// count_forms() reads WP_Query's found_posts rather than loading
			// every form ID into memory to call count() on it, which grew
			// linearly with the number of forms on the site.
			$total = Donation_Form::count_forms( $args );

			return [
				'forms'       => $formatted,
				'total'       => $total,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single donation form by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails.
	 */
	public function get_form( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-form' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );

			if ( 0 === $id ) {
				throw new Ability_Exception( 'invalid_form_id', esc_html__( 'Invalid form ID.', 'suredonation' ) );
			}

			$form = get_post( $id );
			if ( ! $form || Donation_Form::POST_TYPE !== $form->post_type ) {
				throw new Ability_Exception( 'form_not_found', esc_html__( 'Form not found.', 'suredonation' ) );
			}

			return $this->format_form( $form );
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Delete a note from a donation.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
	 */
	public function delete_donation_note( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'delete-donation-note' );
			$id      = Helper::get_integer_value( $this->input_get( 'id' ) );
			$note_id = Helper::get_string_value( $this->input_get( 'note_id' ) );
			$this->require_donation( $id );

			$this->rest_call( 'DELETE', '/donations/' . $id . '/notes/' . rawurlencode( $note_id ) );

			return [
				'id'      => $id,
				'note_id' => $note_id,
				'message' => esc_html__( 'Note deleted successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Change a donation's payment status.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
	 */
	public function update_donation_status( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'update-donation-status' );
			$id       = Helper::get_integer_value( $this->input_get( 'id' ) );
			$status   = Helper::get_string_value( $this->input_get( 'status' ) );
			$donation = $this->require_donation( $id );

			$previous = Helper::get_string_value( $donation['payment_status'] ?? '' );
			if ( $previous === $status ) {
				return [
					'id'              => $id,
					'payment_status'  => $status,
					'previous_status' => $previous,
					'changed'         => false,
					'message'         => esc_html__( 'The donation already has that status.', 'suredonation' ),
				];
			}

			$this->rest_call( 'POST', '/donations/' . $id . '/status', [ 'status' => $status ] );

			return [
				'id'              => $id,
				'payment_status'  => $status,
				'previous_status' => $previous,
				'changed'         => true,
				'message'         => esc_html__( 'Donation status updated.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Refund a donation through its original gateway.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the refund is rejected.
	 */
	public function refund_donation( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'refund-donation' );
			$id       = Helper::get_integer_value( $this->input_get( 'id' ) );
			$donation = $this->require_donation( $id );

			$currency       = Helper::get_string_value( $donation['currency'] ?? 'USD' );
			$total          = Helper::get_float_value( $donation['amount'] ?? 0 );
			$already        = Helper::get_float_value( $donation['refunded_amount'] ?? 0 );
			$transaction_id = Helper::get_string_value( $donation['transaction_id'] ?? '' );

			if ( '' === $transaction_id ) {
				throw new Ability_Exception(
					'no_transaction_id',
					esc_html__( 'This donation has no gateway transaction to refund.', 'suredonation' )
				);
			}

			// An explicit transaction_id is optional. When supplied it is checked,
			// so a caller can guard against refunding the wrong record; when
			// omitted the donation's own id is used, because making a model echo
			// back a value it just read adds a failure mode rather than safety.
			$claimed = Helper::get_string_value( $this->input_get( 'transaction_id', '' ) );
			if ( '' !== $claimed && $claimed !== $transaction_id ) {
				throw new Ability_Exception(
					'transaction_mismatch',
					esc_html__( 'The supplied transaction ID does not match this donation.', 'suredonation' )
				);
			}

			// Callers work in major units (25.50), which is what they read back
			// from every other ability. The REST endpoint expects the gateway's
			// minor unit, so convert here rather than pushing cents onto callers.
			$requested = Helper::get_float_value( $this->input_get( 'amount', 0 ) );
			$remaining = round( $total - $already, 2 );
			if ( $requested <= 0 ) {
				$requested = $remaining;
			}

			if ( $requested > $remaining ) {
				throw new Ability_Exception(
					'exceeds_refundable',
					sprintf(
						/* translators: 1: requested amount, 2: refundable amount, 3: currency code. */
						esc_html__( 'Requested refund of %1$s exceeds the %2$s %3$s still refundable on this donation.', 'suredonation' ),
						esc_html( (string) $requested ),
						esc_html( (string) $remaining ),
						esc_html( strtoupper( $currency ) )
					)
				);
			}

			$minor = Payment_Helper::amount_to_stripe_format( $requested, $currency );

			$this->rest_call(
				'POST',
				'/donations/' . $id . '/refund',
				[
					'transaction_id' => $transaction_id,
					'refund_amount'  => $minor,
					'refund_type'    => $requested >= $remaining ? 'full' : 'partial',
					'refund_notes'   => Helper::get_string_value( $this->input_get( 'notes', '' ) ),
				]
			);

			$refreshed = Donations::get( $id );

			return [
				'id'             => $id,
				'refunded'       => $requested,
				'currency'       => strtoupper( $currency ),
				'refunded_total' => is_array( $refreshed ) ? Helper::get_float_value( $refreshed['refunded_amount'] ?? 0 ) : $already + $requested,
				'payment_status' => is_array( $refreshed ) ? Helper::get_string_value( $refreshed['payment_status'] ?? '' ) : '',
				'message'        => esc_html__( 'Refund processed.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Record a donation that was taken outside the payment flow.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation or creation fails.
	 */
	public function create_donation( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'create-donation' );

			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );
			$this->require_campaign( $campaign_id );

			$amount = Helper::get_float_value( $this->input_get( 'amount' ) );
			if ( $amount <= 0 ) {
				throw new Ability_Exception(
					'invalid_amount',
					esc_html__( 'The donation amount must be greater than zero.', 'suredonation' )
				);
			}

			// Validate before sanitising: sanitize_email() strips a malformed
			// address to '', which would silently pass an "is it empty?" check
			// and record the donation with no donor email at all.
			$raw_email = trim( Helper::get_string_value( $this->input_get( 'donor_email', '' ) ) );
			if ( '' !== $raw_email && ! is_email( $raw_email ) ) {
				throw new Ability_Exception(
					'invalid_donor_email',
					esc_html__( 'A valid donor email address is required.', 'suredonation' )
				);
			}
			$email = '' !== $raw_email ? sanitize_email( $raw_email ) : '';

			$response = $this->rest_call(
				'POST',
				'/donations',
				[
					'campaign_id'    => $campaign_id,
					'amount'         => $amount,
					'donor_name'     => Helper::get_string_value( $this->input_get( 'donor_name', '' ) ),
					'donor_email'    => $email,
					'donor_phone'    => Helper::get_string_value( $this->input_get( 'donor_phone', '' ) ),
					'donor_comment'  => Helper::get_string_value( $this->input_get( 'donor_comment', '' ) ),
					'payment_status' => Helper::get_string_value( $this->input_get( 'payment_status' ) ),
					'donation_type'  => Helper::get_string_value( $this->input_get( 'donation_type' ) ),
					'gateway'        => Helper::get_string_value( $this->input_get( 'gateway' ) ),
					'transaction_id' => Helper::get_string_value( $this->input_get( 'transaction_id', '' ) ),
					'fees_covered'   => Helper::get_float_value( $this->input_get( 'fees_covered', 0 ) ),
					'is_anonymous'   => (bool) $this->input_get( 'is_anonymous', false ),
				]
			);

			$created = isset( $response['donation'] ) && is_array( $response['donation'] ) ? $response['donation'] : [];

			return [
				'id'             => isset( $created['id'] ) ? Helper::get_integer_value( $created['id'] ) : 0,
				'campaign_id'    => $campaign_id,
				'amount'         => $amount,
				'payment_status' => Helper::get_string_value( $created['payment_status'] ?? '' ),
				'message'        => esc_html__( 'Donation recorded successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Change a campaign's WordPress post status.
	 *
	 * Distinct from the campaign's business status (active/paused/completed),
	 * which update-campaign handles via campaign_status.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
	 */
	public function update_campaign_status( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'update-campaign-status' );
			$id     = Helper::get_integer_value( $this->input_get( 'id' ) );
			$status = Helper::get_string_value( $this->input_get( 'status' ) );
			$post   = $this->require_campaign( $id );

			$previous = $post->post_status;
			if ( $previous === $status ) {
				return [
					'id'              => $id,
					'post_status'     => $status,
					'previous_status' => $previous,
					'changed'         => false,
					'message'         => esc_html__( 'The campaign already has that status.', 'suredonation' ),
				];
			}

			$this->rest_call( 'POST', '/campaigns/' . $id . '/status', [ 'status' => $status ] );

			return [
				'id'              => $id,
				'post_status'     => $status,
				'previous_status' => $previous,
				'changed'         => true,
				'message'         => esc_html__( 'Campaign status updated.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Update a donor's contact details, status or tags.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
	 */
	public function update_donor( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'update-donor' );
			$id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$this->require_donor( $id );

			// Unlike the campaign, donation and form endpoints, the donors REST
			// controller additionally requires an X-WP-Nonce on writes. An
			// internal dispatch has no nonce to present, and minting a fake one
			// to satisfy a browser-oriented check would be worse than writing
			// through the table directly — which is all that endpoint does here
			// anyway, with no gateway, email or webhook side effects.
			//
			// Only fields the caller actually sent are written, so a partial
			// update cannot blank the rest of the record (the update-campaign
			// lesson from #326).
			$update_data = [];

			if ( $this->input_provided( 'name' ) ) {
				$update_data['name'] = sanitize_text_field( Helper::get_string_value( $this->input_get( 'name' ) ) );
			}

			if ( $this->input_provided( 'phone' ) ) {
				$update_data['phone'] = sanitize_text_field( Helper::get_string_value( $this->input_get( 'phone' ) ) );
			}

			if ( $this->input_provided( 'company' ) ) {
				$update_data['company'] = sanitize_text_field( Helper::get_string_value( $this->input_get( 'company' ) ) );
			}

			if ( $this->input_provided( 'address' ) ) {
				$update_data['address'] = sanitize_textarea_field( Helper::get_string_value( $this->input_get( 'address' ) ) );
			}

			// Validate before sanitising: sanitize_email() reduces a malformed
			// address to '', which would otherwise overwrite a good one.
			if ( $this->input_provided( 'email' ) ) {
				$email = trim( Helper::get_string_value( $this->input_get( 'email' ) ) );
				if ( '' === $email || ! is_email( $email ) ) {
					throw new Ability_Exception(
						'invalid_donor_email',
						esc_html__( 'A valid donor email address is required.', 'suredonation' )
					);
				}
				$update_data['email'] = sanitize_email( $email );
			}

			if ( $this->input_provided( 'donor_status' ) ) {
				$donor_status = Helper::get_string_value( $this->input_get( 'donor_status' ) );
				if ( ! in_array( $donor_status, Donors::get_valid_statuses(), true ) ) {
					throw new Ability_Exception(
						'invalid_donor_status',
						esc_html__( 'Invalid donor status.', 'suredonation' )
					);
				}
				$update_data['donor_status'] = $donor_status;
			}

			if ( $this->input_provided( 'donor_tags' ) ) {
				$tags                      = $this->input_get( 'donor_tags' );
				$update_data['donor_tags'] = is_array( $tags ) ? array_map( 'sanitize_text_field', array_values( $tags ) ) : [];
			}

			if ( empty( $update_data ) ) {
				throw new Ability_Exception(
					'nothing_to_update',
					esc_html__( 'Provide at least one donor field to update.', 'suredonation' )
				);
			}

			// email is UNIQUE, so a duplicate makes $wpdb->update() return false and
			// the entire write is lost. Reporting success then tells the agent the
			// record holds values it does not.
			if ( false === Donors::update( $id, $update_data ) ) {
				throw new Ability_Exception(
					'donor_update_failed',
					esc_html__( 'The donor could not be updated. If you changed the email address, another donor may already use it.', 'suredonation' )
				);
			}

			$donor = Donors::get( $id );

			return [
				'id'      => $id,
				'updated' => array_keys( $update_data ),
				'donor'   => is_array( $donor ) ? $this->format_donor( $donor ) : [],
				'message' => esc_html__( 'Donor updated successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Reassign a donation form to a different campaign.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or a record is missing.
	 */
	public function update_form( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'update-form' );
			$form_id     = Helper::get_integer_value( $this->input_get( 'id' ) );
			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );

			$form = get_post( $form_id );
			if ( ! $form instanceof \WP_Post || Donation_Form::POST_TYPE !== $form->post_type ) {
				throw new Ability_Exception( 'form_not_found', esc_html__( 'Form not found.', 'suredonation' ) );
			}

			$this->require_campaign( $campaign_id );

			$this->rest_call( 'POST', '/forms/' . $form_id, [ 'campaign_id' => $campaign_id ] );

			$refreshed = get_post( $form_id );

			return array_merge(
				$refreshed instanceof \WP_Post ? $this->format_form( $refreshed ) : [],
				[ 'message' => esc_html__( 'Form updated successfully.', 'suredonation' ) ]
			);
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Duplicate a donation form.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
	 */
	public function duplicate_form( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'duplicate-form' );
			$form_id = Helper::get_integer_value( $this->input_get( 'id' ) );

			$form = get_post( $form_id );
			if ( ! $form instanceof \WP_Post || Donation_Form::POST_TYPE !== $form->post_type ) {
				throw new Ability_Exception( 'form_not_found', esc_html__( 'Form not found.', 'suredonation' ) );
			}

			$response = $this->rest_call( 'POST', '/forms/duplicate', [ 'form_id' => $form_id ] );

			$new_id = 0;
			foreach ( [ 'form_id', 'id', 'new_form_id' ] as $key ) {
				if ( isset( $response[ $key ] ) && is_numeric( $response[ $key ] ) ) {
					$new_id = (int) $response[ $key ];
					break;
				}
			}
			if ( 0 === $new_id && isset( $response['form'] ) && is_array( $response['form'] ) && isset( $response['form']['id'] ) && is_numeric( $response['form']['id'] ) ) {
				$new_id = (int) $response['form']['id'];
			}

			return [
				'id'        => $new_id,
				'source_id' => $form_id,
				'title'     => $new_id ? Helper::get_string_value( get_the_title( $new_id ) ) : '',
				'message'   => esc_html__( 'Form duplicated successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Set which form a campaign renders by default.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or a record is missing.
	 */
	public function set_default_form( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'set-default-form' );
			$form_id     = Helper::get_integer_value( $this->input_get( 'form_id' ) );
			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );

			$form = get_post( $form_id );
			if ( ! $form instanceof \WP_Post || Donation_Form::POST_TYPE !== $form->post_type ) {
				throw new Ability_Exception( 'form_not_found', esc_html__( 'Form not found.', 'suredonation' ) );
			}
			$this->require_campaign( $campaign_id );

			$this->rest_call(
				'POST',
				'/forms/set-default',
				[
					'form_id'     => $form_id,
					'campaign_id' => $campaign_id,
				]
			);

			return [
				'campaign_id'     => $campaign_id,
				'default_form_id' => Campaign_Cpt::get_default_form_id( $campaign_id ),
				'message'         => esc_html__( 'Default form updated.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Trash, restore or permanently delete donation forms.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails.
	 */
	public function manage_form( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'manage-form' );
			$form_id = Helper::get_integer_value( $this->input_get( 'id' ) );
			$action  = Helper::get_string_value( $this->input_get( 'action' ) );

			$form = get_post( $form_id );
			if ( ! $form instanceof \WP_Post || Donation_Form::POST_TYPE !== $form->post_type ) {
				throw new Ability_Exception( 'form_not_found', esc_html__( 'Form not found.', 'suredonation' ) );
			}

			// delete-campaign refuses when donations exist because those rows are
			// financial history. Permanently deleting a form its donations still
			// reference would be the same loss by a shorter route, so apply the
			// same guard here. Trash and restore stay available - they're reversible.
			if ( 'delete' === $action ) {
				$donation_count = Donations::count_by_form( $form_id );
				if ( $donation_count > 0 ) {
					throw new Ability_Exception(
						'form_has_donations',
						sprintf(
							/* translators: %d: number of donations recorded through the form. */
							esc_html__( 'This form has %d donation(s) recorded through it and cannot be permanently deleted through this ability, because those records are financial history. Use the "trash" action instead, which is reversible.', 'suredonation' ),
							(int) $donation_count
						)
					);
				}
			}

			$response = $this->rest_call(
				'POST',
				'/forms/manage',
				[
					'form_ids' => [ $form_id ],
					'action'   => $action,
				]
			);

			// This endpoint returns HTTP 200 even when the operation failed, with
			// the reason in errors[] — so rest_call()'s status check cannot see it
			// and the ability would report a success that never happened.
			if ( ! empty( $response['errors'] ) && is_array( $response['errors'] ) ) {
				$first  = is_array( $response['errors'][0] ?? null ) ? $response['errors'][0] : [];
				$reason = Helper::get_string_value( $first['error'] ?? '' );

				throw new Ability_Exception(
					'form_action_failed',
					'' !== $reason
						? esc_html( $reason )
						: esc_html__( 'The form action could not be completed.', 'suredonation' )
				);
			}

			$refreshed = get_post( $form_id );

			return [
				'id'          => $form_id,
				'action'      => $action,
				'post_status' => $refreshed instanceof \WP_Post ? $refreshed->post_status : 'deleted',
				'message'     => esc_html__( 'Form updated successfully.', 'suredonation' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Dashboard & Analytics Execute Callbacks
	// ============================================

	/**
	 * Attach an attachment as a post's featured image, rejecting non-images.
	 *
	 * Core's set_post_thumbnail() DELETES the existing thumbnail when the given
	 * id is not a renderable image, so passing the id of a PDF — or of an
	 * ordinary post — would silently clear a campaign's hero image and still
	 * report success.
	 *
	 * @param int $post_id       Post to attach to.
	 * @param int $attachment_id Attachment to use.
	 * @return void
	 * @throws Ability_Exception If the attachment is missing or is not an image.
	 * @since 1.5.0
	 */
	protected function set_featured_image( $post_id, $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			throw new Ability_Exception(
				'invalid_featured_image',
				esc_html__( 'The featured image must be the ID of an image in the media library.', 'suredonation' )
			);
		}

		set_post_thumbnail( $post_id, $attachment_id );
	}

	/**
	 * Resolve the currency and payment mode a reporting ability should report on.
	 *
	 * Every monetary aggregate must be scoped to one currency and one payment
	 * mode, or the figure is a meaningless mixed sum. Defaults are the store
	 * currency and the store's current mode, so a caller that supplies neither
	 * still gets a coherent number, and both are echoed back in the payload.
	 *
	 * @return array{currency: string, payment_mode: string}
	 * @since 1.5.0
	 */
	protected function resolve_report_scope() {
		$currency = strtoupper( Helper::get_string_value( $this->input_get( 'currency', '' ) ) );
		if ( '' === $currency ) {
			$currency = Payment_Helper::get_currency();
		}

		$payment_mode = strtolower( Helper::get_string_value( $this->input_get( 'payment_mode', '' ) ) );
		if ( ! in_array( $payment_mode, [ 'test', 'live' ], true ) ) {
			$payment_mode = Payment_Helper::get_payment_mode();
		}

		return [
			'currency'     => $currency,
			'payment_mode' => $payment_mode,
		];
	}
	/**
	 * Get the site-wide donation dashboard figures.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_dashboard_stats( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-dashboard-stats' );

			$scope = $this->resolve_report_scope();
			$stats = Donations::get_dashboard_stats( $scope['currency'], $scope['payment_mode'] );

			// wp_count_posts() is filterable and a filter may return a non-object,
			// where a property read would be a fatal rather than a caught Exception.
			$counts            = wp_count_posts( SUREDONATION_POST_TYPE );
			$published_count   = is_object( $counts ) && isset( $counts->publish ) ? $counts->publish : 0;

			return [
				'total_donations'  => Helper::get_integer_value( $stats['total_donations'] ?? 0 ),
				'total_raised'     => Helper::get_float_value( $stats['total_raised'] ?? 0 ),
				'unique_donors'    => Helper::get_integer_value( $stats['unique_donors'] ?? 0 ),
				'average_donation' => Helper::get_float_value( $stats['average_donation'] ?? 0 ),
				'largest_donation' => Helper::get_float_value( $stats['largest_donation'] ?? 0 ),
				'published_campaigns' => Helper::get_integer_value( $published_count ),
				'currency'         => $scope['currency'],
				'payment_mode'     => $scope['payment_mode'],
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get the most recent donations across all campaigns.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_recent_donations( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-recent-donations' );
			$limit = $this->clamp_per_page( $this->input_get( 'limit' ) );
			$scope = $this->resolve_report_scope();

			$donations = [];
			foreach ( Donations::get_recent_donations_global( $limit, $scope['currency'], $scope['payment_mode'] ) as $donation ) {
				if ( is_array( $donation ) ) {
					$donations[] = $this->format_donation_summary( $donation );
				}
			}

			return [
				'donations'    => $donations,
				'currency'     => $scope['currency'],
				'payment_mode' => $scope['payment_mode'],
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get the campaigns that have raised the most.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_top_campaigns( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-top-campaigns' );
			$limit = $this->clamp_per_page( $this->input_get( 'limit' ) );
			$scope = $this->resolve_report_scope();

			$campaigns = [];
			foreach ( Donations::get_top_campaigns( $limit, $scope['currency'], $scope['payment_mode'] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$campaign_id = Helper::get_integer_value( $row['campaign_id'] ?? 0 );
				if ( $campaign_id <= 0 ) {
					continue;
				}

				// The title comes from the query's join, so a page of results is
				// one query rather than one get_post() per row. The join is also
				// what guarantees the campaign post still exists.
				$campaigns[] = [
					'id'             => $campaign_id,
					'title'          => wp_kses_post( Helper::get_string_value( $row['campaign_title'] ?? '' ) ),
					'total_raised'   => Helper::get_float_value( $row['total_raised'] ?? 0 ),
					'donation_count' => Helper::get_integer_value( $row['donation_count'] ?? 0 ),
				];
			}

			return [
				'campaigns'    => $campaigns,
				'currency'     => $scope['currency'],
				'payment_mode' => $scope['payment_mode'],
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get the non-sensitive store settings.
	 *
	 * Deliberately curated rather than dumping the options: payment credentials
	 * and the `ai_settings` block that gates these abilities are never exposed.
	 * Returning them would put live secrets into an assistant's context, and
	 * `ai_settings` in particular would let a caller reason about disabling its
	 * own guard rails.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_settings( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-settings' );

			$donor_option = Helper::get_suredonation_option( 'donor_settings', [] );
			$donor        = is_array( $donor_option ) ? $donor_option : [];

			return [
				'currency'               => Payment_Helper::get_currency(),
				'currency_symbol'        => Payment_Helper::get_currency_symbol(),
				'currency_sign_position' => Payment_Helper::get_currency_sign_position(),
				'payment_mode'           => Payment_Helper::get_payment_mode(),
				'honeypot_enabled'       => Helper::is_honeypot_enabled(),
				'create_wp_user'         => ! empty( $donor['create_wp_user'] ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get which payment gateways are available and connected.
	 *
	 * Connection state only — never credentials.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_payment_gateways( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-payment-gateways' );

			$mode = Payment_Helper::get_payment_mode();

			$stripe_connected = class_exists( '\SureDonation\Inc\Payments\Stripe\Stripe_Helper' )
				&& \SureDonation\Inc\Payments\Stripe\Stripe_Helper::is_stripe_connected();

			$paypal_connected = class_exists( '\SureDonation\Inc\Payments\PayPal\PayPal_Helper' )
				&& \SureDonation\Inc\Payments\PayPal\PayPal_Helper::is_paypal_connected();

			$offline_enabled = class_exists( '\SureDonation\Inc\Payments\Offline\Offline_Helper' )
				&& \SureDonation\Inc\Payments\Offline\Offline_Helper::is_offline_enabled();

			return [
				// payment_mode is global, not per gateway: switching it changes
				// which credentials every gateway uses.
				'payment_mode' => $mode,
				'gateways'     => [
					[
						'id'        => 'stripe',
						'connected' => $stripe_connected,
					],
					[
						'id'        => 'paypal',
						'connected' => $paypal_connected,
					],
					[
						'id'        => 'offline',
						'connected' => $offline_enabled,
					],
				],
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a donor's donation history.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 * @throws Ability_Exception If validation fails or the record is missing.
	 */
	public function get_donor_donations( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donor-donations' );
			$id       = Helper::get_integer_value( $this->input_get( 'id' ) );
			$page     = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$this->require_donor( $id );

			$offset = ( $page - 1 ) * $per_page;
			$result = Donations::get_by_donor_id( $id, $per_page, $offset );
			$total  = Helper::get_integer_value( $result['total'] ?? 0 );

			$donations = [];
			foreach ( ( $result['donations'] ?? [] ) as $donation ) {
				if ( is_array( $donation ) ) {
					$donations[] = $this->format_donation_summary( $donation );
				}
			}

			return [
				'donor_id'    => $id,
				'donations'   => $donations,
				'total'       => $total,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get donation trends for time-series analysis.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, mixed>|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_donation_trends( $input ) {
		try {
			$this->init( $input, SUREDONATION_ABILITY_API_NAMESPACE . 'get-donation-trends' );

			$after       = $this->valid_date( $this->input_get( 'after' ) );
			$before      = $this->valid_date( $this->input_get( 'before' ) );
			$group       = Helper::get_string_value( $this->input_get( 'group' ) );
			$campaign_id = Helper::get_integer_value( $this->input_get( 'campaign_id' ) );

			// Amounts in different currencies, or across test and live mode, cannot
			// be summed into one figure. Scope to one of each so `total_amount` and
			// the reported currency/mode actually describe the same rows.
			$scope    = $this->resolve_report_scope();
			$currency = $scope['currency'];

			$trends = Donations::get_donation_trends( $after, $before, $group, $currency, $campaign_id, $scope['payment_mode'] );

			$formatted = [];
			foreach ( $trends as $trend ) {
				$formatted[] = [
					'period'         => $trend['period'] ?? '',
					'donation_count' => isset( $trend['donation_count'] ) ? (int) $trend['donation_count'] : 0,
					'total_amount'   => isset( $trend['total_amount'] ) ? (float) $trend['total_amount'] : 0.0,
				];
			}

			return [
				'trends'       => $formatted,
				'currency'     => $currency,
				'payment_mode' => $scope['payment_mode'],
				// The query defaults to the last 30 days when a bound is empty,
				// so echo the window back rather than leaving the caller to guess.
				'after'    => '' !== $after ? $after : gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'before'   => '' !== $before ? $before : gmdate( 'Y-m-d' ),
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
	 * @throws Ability_Exception If inputs not parsed or property not found and no fallback.
	 */
	public function input_get( $name, $fallback = self::NO_DEFAULT ) {
		if ( false === $this->input ) {
			throw new Ability_Exception( 'inputs_not_parsed', esc_html__( 'Inputs not parsed.', 'suredonation' ) );
		}

		if ( ! array_key_exists( $name, $this->input ) ) {
			if ( self::NO_DEFAULT !== $fallback ) {
				return $fallback;
			}
			throw new Ability_Exception(
				'property_not_found',
				sprintf(
					/* translators: %s: property name */
					esc_html__( 'Property %s not found.', 'suredonation' ),
					esc_html( $name )
				)
			);
		}

		return $this->input[ $name ];
	}

	/**
	 * Whether the caller explicitly sent a property.
	 *
	 * Use this — not `isset( $this->input[ $name ] )` — to decide whether a
	 * partial update should touch a field. Every schema property is present in
	 * `$this->input` after parsing, so only this tells you the caller meant it.
	 *
	 * @param string $name Property name.
	 * @return bool True when the property was present in the raw input.
	 * @since 1.5.0
	 */
	public function input_provided( $name ) {
		return isset( $this->provided[ $name ] );
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
	 * @throws Ability_Exception If validation fails or the record is missing.
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
	 * @throws Ability_Exception If required field is missing or invalid value.
	 */
	protected function input_parse( $input, $ability_name ) {
		$this->input    = [];
		$this->provided = [];

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
			$type         = isset( $prop['type'] ) ? strtolower( $prop['type'] ) : 'string';
			$was_provided = array_key_exists( $name, $input );
			$raw_value    = $was_provided ? $input[ $name ] : null;

			if ( $was_provided ) {
				$this->provided[ $name ] = true;
			}

			$is_required = in_array( $name, $required_fields, true );
			if ( $is_required && ( null === $raw_value || '' === $raw_value ) ) {
				throw new Ability_Exception(
					'missing_required_field',
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

			// Only validate an enum the caller actually sent. An omitted optional
			// enum with no schema default is coerced to '' above, which is never a
			// member of the enum — validating it would reject every partial update
			// that leaves the field alone (e.g. update-campaign without goal_type).
			if ( $was_provided && isset( $prop['enum'] ) && is_array( $prop['enum'] ) ) {
				if ( ! in_array( $value, $prop['enum'], true ) ) {
					throw new Ability_Exception(
						'invalid_field_value',
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
	 * Convert a caught exception into a WP_Error.
	 *
	 * Returning a WP_Error is the Abilities API's failure channel: WP_Ability
	 * passes it straight back to the caller. The previous shape — a normal
	 * return value carrying an `error` key — was indistinguishable from success,
	 * so a client acted on records that did not exist, and the key was not in
	 * any declared output_schema.
	 *
	 * @param Exception $e The exception.
	 * @return WP_Error Error response.
	 */
	protected function error( $e ) {
		if ( $e instanceof Ability_Exception ) {
			return new WP_Error( $e->get_error_code(), $e->getMessage() );
		}

		// Anything else is an internal failure whose message was never written
		// for an audience — a DB-layer throw can carry a query or a path. The
		// caller here is an MCP client or a REST consumer, so return a fixed
		// string and keep the real one in the log for the site owner.
		$this->log_internal_failure( $e );

		return new WP_Error(
			Ability_Exception::DEFAULT_CODE,
			__( 'The request could not be completed because of an internal error.', 'suredonation' )
		);
	}

	/**
	 * Log an unexpected exception without surfacing it to the caller.
	 *
	 * @param \Throwable $e The exception to record.
	 * @return void
	 * @since 1.5.0
	 */
	protected function log_internal_failure( $e ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic for an unexpected internal failure.
		error_log(
			sprintf(
				'SureDonation ability failure: %s in %s:%d',
				$e->getMessage(),
				$e->getFile(),
				$e->getLine()
			)
		);
	}

	/**
	 * Require a valid campaign post by ID.
	 *
	 * @param mixed $id Campaign ID.
	 * @return \WP_Post The campaign post.
	 * @throws Ability_Exception If ID is zero, post not found, or wrong post type.
	 */
	protected function require_campaign( $id ) {
		$id = Helper::get_integer_value( $id );
		if ( 0 === $id ) {
			throw new Ability_Exception( 'invalid_campaign_id', esc_html__( 'Invalid campaign ID.', 'suredonation' ) );
		}

		$post = get_post( $id );
		if ( ! $post || SUREDONATION_POST_TYPE !== $post->post_type ) {
			throw new Ability_Exception( 'campaign_not_found', esc_html__( 'Campaign not found.', 'suredonation' ) );
		}

		return $post;
	}

	/**
	 * Require a valid donation by ID.
	 *
	 * @param mixed $id Donation ID.
	 * @return array<string, mixed> The donation record.
	 * @throws Ability_Exception If ID is zero or donation not found.
	 */
	protected function require_donation( $id ) {
		$id = Helper::get_integer_value( $id );
		if ( 0 === $id ) {
			throw new Ability_Exception( 'invalid_donation_id', esc_html__( 'Invalid donation ID.', 'suredonation' ) );
		}

		$donation = Donations::get( $id );
		if ( ! $donation ) {
			throw new Ability_Exception( 'donation_not_found', esc_html__( 'Donation not found.', 'suredonation' ) );
		}

		return $donation;
	}

	/**
	 * Require a valid donor by ID.
	 *
	 * @param mixed $id Donor ID.
	 * @return array<string, mixed> The donor record.
	 * @throws Ability_Exception If ID is zero or donor not found.
	 */
	protected function require_donor( $id ) {
		$id = Helper::get_integer_value( $id );
		if ( 0 === $id ) {
			throw new Ability_Exception( 'invalid_donor_id', esc_html__( 'Invalid donor ID.', 'suredonation' ) );
		}

		$donor = Donors::get( $id );
		if ( ! $donor ) {
			throw new Ability_Exception( 'donor_not_found', esc_html__( 'Donor not found.', 'suredonation' ) );
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
	 * Dispatch an internal REST request to one of the plugin's own endpoints.
	 *
	 * The donation lifecycle operations (refund, status change, manual entry)
	 * are substantial: the refund handler alone orchestrates two gateways,
	 * recomputes the status in minor units, stores the refund for webhook
	 * de-duplication, writes the audit log and sends notifications.
	 * Re-implementing that here would guarantee the two copies drift, and Pro
	 * extends these same endpoints through `suredonation_rest_api_endpoints`,
	 * so delegating keeps Pro's behaviour intact for free.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route relative to the plugin namespace.
	 * @param array<string, mixed> $params Request body parameters.
	 * @return array<string, mixed> The decoded successful response.
	 * @throws Ability_Exception If the endpoint returns an error.
	 * @since 1.5.0
	 */
	protected function rest_call( $method, $route, $params = [] ) {
		$request = new \WP_REST_Request( $method, '/suredonation/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error   = $response->as_error();
			$code    = Ability_Exception::DEFAULT_CODE;
			$message = __( 'The request could not be completed.', 'suredonation' );

			if ( $error instanceof WP_Error ) {
				$code    = Helper::get_string_value( $error->get_error_code() );
				$message = $error->get_error_message();
			}

			// The code is a machine-readable key the caller matches on, not
			// output — escaping belongs at the output boundary, and escaping it
			// here would corrupt any code containing an escapable character.
			throw new Ability_Exception( $code, esc_html( $message ) );
		}

		$data = $response->get_data();

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Normalise a YYYY-MM-DD date bound, discarding anything malformed.
	 *
	 * @param mixed $value Raw date value.
	 * @return string The date, or '' when absent or malformed.
	 * @since 1.5.0
	 */
	protected function valid_date( $value ) {
		$date = Helper::get_string_value( $value );

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Format a donation for a list context.
	 *
	 * The detail formatter is deliberately not reused here. It runs a per-row
	 * Donations::get_log() query — which re-SELECTs a row the list query has
	 * already fetched — and returns the donor's submitted field values, phone,
	 * comment, Stripe customer/account ids and receipt URL. On a page of up to
	 * 100 rows that is 100 redundant queries plus bulk donor PII, for fields the
	 * list output_schema does not declare and the caller never asked for.
	 *
	 * The keys returned here are exactly the ones the list schemas declare.
	 * Callers that need the full record fetch it with get-donation.
	 *
	 * @param array<string, mixed> $donation Raw donation row.
	 * @return array<string, mixed> Formatted donation summary.
	 * @since 1.5.0
	 */
	protected function format_donation_summary( $donation ) {
		$campaign_id = isset( $donation['campaign_id'] ) ? Helper::get_integer_value( $donation['campaign_id'] ) : 0;
		$form_id     = isset( $donation['form_id'] ) ? Helper::get_integer_value( $donation['form_id'] ) : 0;

		return [
			'id'                  => isset( $donation['id'] ) ? Helper::get_integer_value( $donation['id'] ) : 0,
			'campaign_id'         => $campaign_id,
			'campaign_title'      => $campaign_id ? wp_kses_post( get_the_title( $campaign_id ) ) : '',
			'form_id'             => $form_id,
			'form_title'          => $form_id ? wp_kses_post( get_the_title( $form_id ) ) : '',
			'donor_name'          => $donation['donor_name'] ?? '',
			'donor_email'         => $donation['donor_email'] ?? '',
			'amount'              => Helper::get_float_value( $donation['amount'] ?? 0 ),
			'currency'            => $donation['currency'] ?? 'USD',
			'payment_status'      => $donation['payment_status'] ?? '',
			'donation_type'       => $donation['donation_type'] ?? 'one-time',
			'gateway'             => $donation['gateway'] ?? '',
			'subscription_id'     => Helper::get_string_value( $donation['subscription_id'] ?? '' ),
			'subscription_status' => Helper::get_string_value( $donation['subscription_status'] ?? '' ),
			'created_at'          => $donation['created_at'] ?? '',
		];
	}

	/**
	 * Format a donation record for ability output.
	 *
	 * Protected rather than private: SureDonation Pro extends this class to
	 * register its own abilities, and subscriptions and renewals ARE donation
	 * rows. Pro must return the same donation shape as free or the two payloads
	 * diverge on the first change to either. Same reasoning for the other
	 * format_* methods below.
	 *
	 * @param array<string, mixed> $donation Raw donation data from database.
	 * @return array<string, mixed> Formatted donation data.
	 */
	protected function format_donation( $donation ) {
		$campaign_id = isset( $donation['campaign_id'] ) ? Helper::get_integer_value( $donation['campaign_id'] ) : 0;
		$donation_id = isset( $donation['id'] ) ? Helper::get_integer_value( $donation['id'] ) : 0;

		$logs    = $donation_id ? Donations::get_log( $donation_id ) : [];
		$form_id = isset( $donation['form_id'] ) ? Helper::get_integer_value( $donation['form_id'] ) : 0;

		// donation_data holds the submitted field list and the subscription
		// metadata. decode_by_datatype() usually decodes it already; tolerate a
		// raw JSON string for rows fetched outside that path.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && '' !== $donation_data ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			$donation_data = [];
		}

		// The label/value/group triples captured at submission time. This is the
		// only way a caller can answer "what did this donor actually fill in?".
		$submitted_fields = [];
		if ( isset( $donation_data['fields'] ) && is_array( $donation_data['fields'] ) ) {
			foreach ( $donation_data['fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$submitted_fields[] = [
					'label' => sanitize_text_field( Helper::get_string_value( $field['label'] ?? '' ) ),
					'value' => sanitize_text_field( Helper::get_string_value( $field['value'] ?? '' ) ),
					'group' => sanitize_text_field( Helper::get_string_value( $field['group'] ?? '' ) ),
				];
			}
		}

		return [
			'id'                     => $donation_id,
			'campaign_id'            => $campaign_id,
			'campaign_title'         => $campaign_id ? wp_kses_post( get_the_title( $campaign_id ) ) : '',
			'form_id'                => $form_id,
			'form_title'             => $form_id ? wp_kses_post( get_the_title( $form_id ) ) : '',
			'donor_id'               => isset( $donation['donor_id'] ) ? Helper::get_integer_value( $donation['donor_id'] ) : 0,
			'donor_name'             => $donation['donor_name'] ?? '',
			'donor_email'            => $donation['donor_email'] ?? '',
			'donor_phone'            => $donation['donor_phone'] ?? '',
			'amount'                 => Helper::get_float_value( $donation['amount'] ?? 0 ),
			'fees_covered'           => Helper::get_float_value( $donation['fees_covered'] ?? 0 ),
			'refunded_amount'        => Helper::get_float_value( $donation['refunded_amount'] ?? 0 ),
			'currency'               => $donation['currency'] ?? 'USD',
			'donation_type'          => $donation['donation_type'] ?? 'one-time',
			'is_anonymous'           => ! empty( $donation['is_anonymous'] ),
			'donor_comment'          => $donation['donor_comment'] ?? '',
			'payment_status'         => $donation['payment_status'] ?? 'pending',
			'payment_mode'           => $donation['payment_mode'] ?? 'test',
			'gateway'                => $donation['gateway'] ?? '',
			'transaction_id'         => $donation['transaction_id'] ?? '',
			'stripe_customer_id'     => $donation['customer_id'] ?? '',
			'stripe_account_id'      => $donation['stripe_account_id'] ?? '',
			'subscription_id'        => $donation['subscription_id'] ?? '',
			'subscription_status'    => $donation['subscription_status'] ?? '',
			'parent_subscription_id' => isset( $donation['parent_subscription_id'] ) ? Helper::get_integer_value( $donation['parent_subscription_id'] ) : 0,
			'subscription_interval'  => Helper::get_string_value( $donation_data['subscription_interval'] ?? '' ),
			'billing_cycles'         => Helper::get_string_value( $donation_data['billing_cycles'] ?? '' ),
			'receipt_sent'           => ! empty( $donation['receipt_sent'] ),
			'receipt_pdf_url'        => $donation['receipt_pdf_url'] ?? '',
			'import_source'          => $donation['import_source'] ?? '',
			'fields'                 => $submitted_fields,
			'created_at'             => $donation['created_at'] ?? '',
			'updated_at'             => $donation['updated_at'] ?? '',
			'logs'                   => $logs,
		];
	}

	/**
	 * Format a donation form for ability output.
	 *
	 * @param \WP_Post $form Form post object.
	 * @param array{entries: int, revenue: float}|null $stats Pre-computed totals for this form, or null to query them.
	 * @return array<string, mixed> Formatted form data.
	 */
	protected function format_form( $form, $stats = null ) {
		$campaign_id = Donation_Form::get_form_campaign_id( $form->ID );
		$campaign    = $campaign_id ? get_post( $campaign_id ) : null;

		// A list passes stats it has already batched for the whole page; a
		// single-form read falls back to the one-form query.
		if ( ! is_array( $stats ) ) {
			$stats = Donations::get_form_stats( $form->ID );
		}

		return [
			'id'            => $form->ID,
			'title'         => $form->post_title,
			'status'        => $form->post_status,
			'campaign_id'   => $campaign_id,
			'campaign_name' => $campaign ? $campaign->post_title : '',
			'entries'       => $stats['entries'],
			'revenue'       => $stats['revenue'],
			// Which form a campaign actually renders, so a caller can tell the
			// live form apart from the others attached to the same campaign.
			'is_default'    => $campaign_id > 0 && Campaign_Cpt::get_default_form_id( $campaign_id ) === (int) $form->ID,
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
	protected function format_donor( $donor ) {
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
			'company'             => $donor['company'] ?? '',
			'address'             => $donor['address'] ?? '',
			'stripe_customer_id'  => $donor['stripe_customer_id'] ?? '',
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
	protected function format_campaign( $post ) {
		$stats = Campaign_Stats::get_stats( $post->ID );
		$meta  = Helper::get_campaign_meta( $post->ID );

		return array_merge(
			[
				'id'          => $post->ID,
				'title'       => $post->post_title,
				// `status` is the campaign business status (active/paused/
				// completed). `post_status` is the WordPress one — without it a
				// caller cannot tell a draft from a published campaign.
				'status'      => $stats['campaign_status'],
				'post_status' => $post->post_status,
				'goal_type'   => $meta['goal_type'],
				'goal'        => $stats['goal_amount'],
				'raised'      => $stats['total_raised'],
				'donors'      => $stats['donor_count'],
				'progress'    => $stats['progress_percentage'],
				'created_at'  => $post->post_date,
				'modified_at' => $post->post_modified,
			],
			$this->campaign_extras( $post, $meta )
		);
	}

	/**
	 * Fields shared by the campaign list and detail payloads.
	 *
	 * Kept separate so list-campaigns and get-campaign cannot drift apart, and
	 * so the currency travels with every monetary figure.
	 *
	 * @param \WP_Post             $post Campaign post.
	 * @param array<string, mixed> $meta Decoded campaign meta.
	 * @return array<string, mixed> Additional campaign fields.
	 * @since 1.5.0
	 */
	private function campaign_extras( $post, $meta ) {
		$thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'medium' );

		return [
			// goal/raised are amounts; without a currency code they are ambiguous.
			'currency'           => Payment_Helper::get_currency(),
			'terms_text'         => Helper::get_string_value( $meta['terms_text'] ?? '' ),
			'thank_you_message'  => Helper::get_string_value( $meta['thank_you_message'] ?? '' ),
			'featured_image'     => (int) get_post_thumbnail_id( $post->ID ),
			'featured_image_url' => is_string( $thumbnail_url ) ? $thumbnail_url : '',
			'has_page'           => Campaign_Page::has_page( $post->ID ),
			'permalink'          => 'publish' === $post->post_status ? (string) get_permalink( $post->ID ) : '',
			'author'             => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'edit_url'           => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'default_form_id'    => Campaign_Cpt::get_default_form_id( $post->ID ),
		];
	}
}
