<?php
/**
 * Abilities API Configuration
 *
 * Defines all ability configurations for the SureDonation plugin.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Abilities;

use SureDonation\Inc\Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Config_Ability class.
 *
 * @since 0.0.1
 */
class Config_Ability {
	/**
	 * Cached abilities.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $abilities = null;

	/**
	 * Get all ability configurations.
	 *
	 * @return array<string, array<string, mixed>> Ability definitions.
	 */
	public static function get_abilities() {
		if ( null !== self::$abilities ) {
			return self::$abilities;
		}

		$runtime     = new Runtime();
		$ai_option   = Helper::get_suredonation_option( 'ai_settings', [] );
		$ai_settings = is_array( $ai_option ) ? $ai_option : [];

		$perm_read = static function () use ( $runtime ) {
			return $runtime->permission_callback( 'manage_options' );
		};

		$perm_edit = static function () use ( $runtime, $ai_settings ) {
			return ! empty( $ai_settings['allow_updates'] ) && $runtime->permission_callback( 'manage_options' );
		};

		$perm_delete = static function () use ( $runtime, $ai_settings ) {
			return ! empty( $ai_settings['allow_delete'] ) && $runtime->permission_callback( 'manage_options' );
		};

		$abilities = array_merge(
			self::get_campaign_abilities( $runtime, $perm_read, $perm_edit, $perm_delete ),
			self::get_donation_abilities( $runtime, $perm_read, $perm_edit ),
			self::get_donor_abilities( $runtime, $perm_read ),
			self::get_form_abilities( $runtime, $perm_read ),
			self::get_analytics_abilities( $runtime, $perm_read )
		);

		/**
		 * Filter SureDonation ability configurations.
		 *
		 * @param array $abilities Ability definitions.
		 */
		$abilities = apply_filters( 'suredonation_config_abilities', $abilities );
		if ( ! is_array( $abilities ) ) {
			$abilities = [];
		}

		self::$abilities = $abilities;

		return $abilities;
	}

	/**
	 * Get a single ability config by name.
	 *
	 * @param string $ability_name Ability identifier.
	 * @return array<string, mixed>|false Ability config or false.
	 */
	public static function get_ability( $ability_name ) {
		if ( null === self::$abilities ) {
			self::$abilities = self::get_abilities();
		}
		return self::$abilities[ $ability_name ] ?? false;
	}

	/**
	 * Get ability input schema.
	 *
	 * @param string $ability_name Ability identifier.
	 * @return array<string, mixed>|false Input schema or false.
	 */
	public static function get_ability_input_schema( $ability_name ) {
		$ability = self::get_ability( $ability_name );
		if ( false === $ability ) {
			return false;
		}
		$schema = $ability['input_schema'] ?? false;
		return is_array( $schema ) ? $schema : false;
	}

	/**
	 * Build meta block for an ability.
	 *
	 * @param float $priority        Priority level (1.0 read, 2.0 write, 3.0 destructive).
	 * @param bool  $read_only       Whether the ability only reads data.
	 * @param bool  $destructive     Whether the ability destroys data.
	 * @param bool  $idempotent      Whether repeated calls produce the same result.
	 * @return array<string, mixed> Meta configuration.
	 */
	private static function build_meta( $priority = 1.0, $read_only = true, $destructive = false, $idempotent = true ) {
		return [
			'annotations' => [
				'priority'        => $priority,
				'readOnlyHint'    => $read_only,
				'destructiveHint' => $destructive,
				'idempotentHint'  => $idempotent,
				'openWorldHint'   => false,
			],
			'mcp'         => [
				'public' => true,
				'type'   => 'tool',
			],
		];
	}

	/**
	 * Get campaign ability configurations.
	 *
	 * @param Runtime  $runtime     Runtime instance.
	 * @param callable $perm_read   Read permission closure.
	 * @param callable $perm_edit   Edit permission closure.
	 * @param callable $perm_delete Delete permission closure.
	 * @return array<string, array<string, mixed>> Campaign abilities.
	 */
	private static function get_campaign_abilities( $runtime, $perm_read, $perm_edit, $perm_delete ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		return [
			$ns . 'list-campaigns'              => [
				'label'               => __( 'List campaigns', 'suredonation' ),
				'description'         => __( 'Returns a paginated list of fundraising campaigns with optional search, status filter, and sorting.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search'   => [
							'type'        => 'string',
							'description' => __( 'Search campaigns by title.', 'suredonation' ),
							'default'     => '',
						],
						'status'   => [
							'type'        => 'string',
							'enum'        => [ 'all', 'publish', 'draft' ],
							'default'     => 'all',
							'description' => __( 'Filter by post status.', 'suredonation' ),
						],
						'sort_by'  => [
							'type'        => 'string',
							'enum'        => [ 'date', 'title', 'status' ],
							'default'     => 'date',
							'description' => __( 'Column to sort by.', 'suredonation' ),
						],
						'order'    => [
							'type'        => 'string',
							'enum'        => [ 'ASC', 'DESC' ],
							'default'     => 'DESC',
							'description' => __( 'Sort direction.', 'suredonation' ),
						],
						'page'     => [
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number (1-based).', 'suredonation' ),
						],
						'per_page' => [
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Results per page (max 100).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'campaigns'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'          => [ 'type' => 'integer' ],
									'title'       => [ 'type' => 'string' ],
									'status'      => [ 'type' => 'string' ],
									'goal_type'   => [ 'type' => 'string' ],
									'goal'        => [ 'type' => 'number' ],
									'raised'      => [ 'type' => 'number' ],
									'donors'      => [ 'type' => 'integer' ],
									'progress'    => [ 'type' => 'number' ],
									'created_at'  => [ 'type' => 'string' ],
									'modified_at' => [ 'type' => 'string' ],
								],
							],
						],
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total matching campaigns.', 'suredonation' ),
						],
						'total_pages' => [
							'type'        => 'integer',
							'description' => __( 'Total pages.', 'suredonation' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->list_campaigns( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'get-campaign'                => [
				'label'               => __( 'Get campaign', 'suredonation' ),
				'description'         => __( 'Returns a single fundraising campaign by ID with real-time stats including total raised, donor count, and progress.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'The campaign ID.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'               => [ 'type' => 'integer' ],
						'title'            => [ 'type' => 'string' ],
						'description'      => [ 'type' => 'string' ],
						'status'           => [ 'type' => 'string' ],
						'goal_type'        => [ 'type' => 'string' ],
						'goal'             => [ 'type' => 'number' ],
						'raised'           => [ 'type' => 'number' ],
						'donors'           => [ 'type' => 'integer' ],
						'progress'         => [ 'type' => 'number' ],
						'donation_count'   => [ 'type' => 'integer' ],
						'average_donation' => [ 'type' => 'number' ],
						'largest_donation' => [ 'type' => 'number' ],
						'is_goal_reached'  => [ 'type' => 'boolean' ],
						'require_terms'    => [ 'type' => 'boolean' ],
						'created_at'       => [ 'type' => 'string' ],
						'modified_at'      => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_campaign( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'create-campaign'             => [
				'label'               => __( 'Create campaign', 'suredonation' ),
				'description'         => __( 'Creates a new fundraising campaign with title, description, goal settings, and optional fee coverage/terms configuration.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'title' ],
					'properties' => [
						'title'           => [
							'type'        => 'string',
							'description' => __( 'Campaign title.', 'suredonation' ),
						],
						'description'     => [
							'type'        => 'string',
							'format'      => 'html',
							'description' => __( 'Campaign description (HTML allowed).', 'suredonation' ),
							'default'     => '',
						],
						'goal_type'       => [
							'type'        => 'string',
							'enum'        => [ 'raised_amount', 'donation_count' ],
							'default'     => 'raised_amount',
							'description' => __( 'Goal type: track by amount raised or donation count.', 'suredonation' ),
						],
						'goal_amount'     => [
							'type'        => 'number',
							'description' => __( 'Goal amount (0 for no goal).', 'suredonation' ),
							'default'     => 0,
						],
						'campaign_status' => [
							'type'        => 'string',
							'enum'        => [ 'active', 'paused', 'completed' ],
							'default'     => 'active',
							'description' => __( 'Campaign status.', 'suredonation' ),
						],
						'require_terms'   => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Require terms acceptance before donating.', 'suredonation' ),
						],
						'terms_text'      => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Terms and conditions text.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'      => [
							'type'        => 'integer',
							'description' => __( 'New campaign ID.', 'suredonation' ),
						],
						'title'   => [ 'type' => 'string' ],
						'status'  => [ 'type' => 'string' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->create_campaign( $input );
				},
				'meta'                => self::build_meta( 2.0, false, false, false ),
			],

			$ns . 'update-campaign'             => [
				'label'               => __( 'Update campaign', 'suredonation' ),
				'description'         => __( 'Updates an existing campaign. All fields except ID are optional — only provided fields are updated.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'              => [
							'type'        => 'integer',
							'description' => __( 'Campaign ID to update.', 'suredonation' ),
						],
						'title'           => [
							'type'        => 'string',
							'description' => __( 'Campaign title.', 'suredonation' ),
						],
						'description'     => [
							'type'        => 'string',
							'format'      => 'html',
							'description' => __( 'Campaign description (HTML allowed).', 'suredonation' ),
						],
						'goal_type'       => [
							'type'        => 'string',
							'enum'        => [ 'raised_amount', 'donation_count' ],
							'description' => __( 'Goal type.', 'suredonation' ),
						],
						'goal_amount'     => [
							'type'        => 'number',
							'description' => __( 'Goal amount.', 'suredonation' ),
						],
						'campaign_status' => [
							'type'        => 'string',
							'enum'        => [ 'active', 'paused', 'completed' ],
							'description' => __( 'Campaign status.', 'suredonation' ),
						],
						'require_terms'   => [
							'type'        => 'boolean',
							'description' => __( 'Require terms acceptance.', 'suredonation' ),
						],
						'terms_text'      => [
							'type'        => 'string',
							'description' => __( 'Terms and conditions text.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'      => [ 'type' => 'integer' ],
						'title'   => [ 'type' => 'string' ],
						'status'  => [ 'type' => 'string' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->update_campaign( $input );
				},
				'meta'                => self::build_meta( 2.0, false, false, false ),
			],

			$ns . 'delete-campaign'             => [
				'label'               => __( 'Delete campaign', 'suredonation' ),
				'description'         => __( 'Permanently deletes a campaign by ID. This action cannot be undone.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_delete,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'Campaign ID to delete.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'      => [ 'type' => 'integer' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->delete_campaign( $input );
				},
				'meta'                => self::build_meta( 3.0, false, true, false ),
			],

			$ns . 'duplicate-campaign'          => [
				'label'               => __( 'Duplicate campaign', 'suredonation' ),
				'description'         => __( 'Creates a copy of an existing campaign as a draft. Copies title (with " (Copy)" suffix), description, and settings.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'Campaign ID to duplicate.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'      => [
							'type'        => 'integer',
							'description' => __( 'New campaign ID.', 'suredonation' ),
						],
						'title'   => [ 'type' => 'string' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->duplicate_campaign( $input );
				},
				'meta'                => self::build_meta( 2.0, false, false, false ),
			],

			$ns . 'get-campaign-form-locations' => [
				'label'               => __( 'Get campaign form locations', 'suredonation' ),
				'description'         => __( 'Finds all pages and posts where a campaign donation form block is embedded. Returns page IDs, titles, and edit/view URLs.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'Campaign ID.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'locations' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'       => [ 'type' => 'integer' ],
									'title'    => [ 'type' => 'string' ],
									'type'     => [ 'type' => 'string' ],
									'status'   => [ 'type' => 'string' ],
									'edit_url' => [ 'type' => 'string' ],
									'view_url' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_campaign_form_locations( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],
		];
	}

	/**
	 * Get donation ability configurations.
	 *
	 * @param Runtime  $runtime   Runtime instance.
	 * @param callable $perm_read Read permission closure.
	 * @param callable $perm_edit Edit permission closure.
	 * @return array<string, array<string, mixed>> Donation abilities.
	 */
	private static function get_donation_abilities( $runtime, $perm_read, $perm_edit ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		return [
			$ns . 'list-donations'     => [
				'label'               => __( 'List donations', 'suredonation' ),
				'description'         => __( 'Returns a paginated list of donations with optional search, status filter, campaign filter, and sorting.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search'      => [
							'type'        => 'string',
							'description' => __( 'Search donations by donor name or email.', 'suredonation' ),
							'default'     => '',
						],
						'status'      => [
							'type'        => 'string',
							'enum'        => [ 'all', 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled' ],
							'default'     => 'all',
							'description' => __( 'Filter by payment status.', 'suredonation' ),
						],
						'campaign_id' => [
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Filter by campaign ID (0 for all campaigns).', 'suredonation' ),
						],
						'sort_by'     => [
							'type'        => 'string',
							'enum'        => [ 'created_at', 'amount', 'donor_name', 'payment_status' ],
							'default'     => 'created_at',
							'description' => __( 'Column to sort by.', 'suredonation' ),
						],
						'order'       => [
							'type'        => 'string',
							'enum'        => [ 'ASC', 'DESC' ],
							'default'     => 'DESC',
							'description' => __( 'Sort direction.', 'suredonation' ),
						],
						'page'        => [
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number (1-based).', 'suredonation' ),
						],
						'per_page'    => [
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Results per page (max 100).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'donations'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'             => [ 'type' => 'integer' ],
									'campaign_id'    => [ 'type' => 'integer' ],
									'campaign_title' => [ 'type' => 'string' ],
									'donor_name'     => [ 'type' => 'string' ],
									'donor_email'    => [ 'type' => 'string' ],
									'amount'         => [ 'type' => 'number' ],
									'currency'       => [ 'type' => 'string' ],
									'payment_status' => [ 'type' => 'string' ],
									'donation_type'  => [ 'type' => 'string' ],
									'gateway'        => [ 'type' => 'string' ],
									'created_at'     => [ 'type' => 'string' ],
								],
							],
						],
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total matching donations.', 'suredonation' ),
						],
						'total_pages' => [
							'type'        => 'integer',
							'description' => __( 'Total pages.', 'suredonation' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->list_donations( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'get-donation'       => [
				'label'               => __( 'Get donation', 'suredonation' ),
				'description'         => __( 'Returns a single donation by ID with full details including donor info, payment data, transaction ID, and activity logs.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'The donation ID.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'              => [ 'type' => 'integer' ],
						'campaign_id'     => [ 'type' => 'integer' ],
						'campaign_title'  => [ 'type' => 'string' ],
						'donor_id'        => [ 'type' => 'integer' ],
						'donor_name'      => [ 'type' => 'string' ],
						'donor_email'     => [ 'type' => 'string' ],
						'donor_phone'     => [ 'type' => 'string' ],
						'amount'          => [ 'type' => 'number' ],
						'fees_covered'    => [ 'type' => 'number' ],
						'refunded_amount' => [ 'type' => 'number' ],
						'currency'        => [ 'type' => 'string' ],
						'donation_type'   => [ 'type' => 'string' ],
						'is_anonymous'    => [ 'type' => 'boolean' ],
						'donor_comment'   => [ 'type' => 'string' ],
						'payment_status'  => [ 'type' => 'string' ],
						'payment_mode'    => [ 'type' => 'string' ],
						'gateway'         => [ 'type' => 'string' ],
						'transaction_id'  => [ 'type' => 'string' ],
						'created_at'      => [ 'type' => 'string' ],
						'updated_at'      => [ 'type' => 'string' ],
						'logs'            => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donation( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'get-donation-notes' => [
				'label'               => __( 'Get donation notes', 'suredonation' ),
				'description'         => __( 'Returns paginated notes for a donation. Notes are admin-added comments for internal tracking.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'       => [
							'type'        => 'integer',
							'description' => __( 'The donation ID.', 'suredonation' ),
						],
						'page'     => [
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number.', 'suredonation' ),
						],
						'per_page' => [
							'type'        => 'integer',
							'default'     => 10,
							'description' => __( 'Notes per page (max 100).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'notes'       => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'         => [ 'type' => 'string' ],
									'content'    => [ 'type' => 'string' ],
									'author_id'  => [ 'type' => 'integer' ],
									'created_at' => [ 'type' => 'string' ],
								],
							],
						],
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total notes.', 'suredonation' ),
						],
						'total_pages' => [
							'type'        => 'integer',
							'description' => __( 'Total pages.', 'suredonation' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donation_notes( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'add-donation-note'  => [
				'label'               => __( 'Add donation note', 'suredonation' ),
				'description'         => __( 'Adds an internal note to a donation for admin tracking purposes.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id', 'note' ],
					'properties' => [
						'id'   => [
							'type'        => 'integer',
							'description' => __( 'The donation ID.', 'suredonation' ),
						],
						'note' => [
							'type'        => 'string',
							'description' => __( 'The note content.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'note_id' => [
							'type'        => 'string',
							'description' => __( 'The new note ID.', 'suredonation' ),
						],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->add_donation_note( $input );
				},
				'meta'                => self::build_meta( 2.0, false, false, false ),
			],
		];
	}

	/**
	 * Get donor ability configurations.
	 *
	 * @param Runtime  $runtime   Runtime instance.
	 * @param callable $perm_read Read permission closure.
	 * @return array<string, array<string, mixed>> Donor abilities.
	 */
	private static function get_donor_abilities( $runtime, $perm_read ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		$donor_detail_schema = [
			'type'       => 'object',
			'properties' => [
				'id'                  => [ 'type' => 'integer' ],
				'name'                => [ 'type' => 'string' ],
				'email'               => [ 'type' => 'string' ],
				'phone'               => [ 'type' => 'string' ],
				'user_id'             => [ 'type' => 'integer' ],
				'donor_status'        => [ 'type' => 'string' ],
				'total_donated'       => [ 'type' => 'number' ],
				'donation_count'      => [ 'type' => 'integer' ],
				'largest_donation'    => [ 'type' => 'number' ],
				'first_donation_date' => [ 'type' => 'string' ],
				'last_donation_date'  => [ 'type' => 'string' ],
				'donor_tags'          => [ 'type' => 'array' ],
				'created_at'          => [ 'type' => 'string' ],
				'updated_at'          => [ 'type' => 'string' ],
			],
		];

		return [
			$ns . 'list-donors'        => [
				'label'               => __( 'List donors', 'suredonation' ),
				'description'         => __( 'Returns a paginated list of donors with optional status filter and sorting by name, total donated, donation count, or date.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'status'   => [
							'type'        => 'string',
							'enum'        => [ 'all', 'active', 'inactive', 'blocked' ],
							'default'     => 'all',
							'description' => __( 'Filter by donor status.', 'suredonation' ),
						],
						'sort_by'  => [
							'type'        => 'string',
							'enum'        => [ 'created_at', 'name', 'email', 'total_donated', 'donation_count', 'last_donation_date' ],
							'default'     => 'created_at',
							'description' => __( 'Column to sort by.', 'suredonation' ),
						],
						'order'    => [
							'type'        => 'string',
							'enum'        => [ 'ASC', 'DESC' ],
							'default'     => 'DESC',
							'description' => __( 'Sort direction.', 'suredonation' ),
						],
						'page'     => [
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number (1-based).', 'suredonation' ),
						],
						'per_page' => [
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Results per page (max 100).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'donors'      => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'                  => [ 'type' => 'integer' ],
									'name'                => [ 'type' => 'string' ],
									'email'               => [ 'type' => 'string' ],
									'phone'               => [ 'type' => 'string' ],
									'donor_status'        => [ 'type' => 'string' ],
									'total_donated'       => [ 'type' => 'number' ],
									'donation_count'      => [ 'type' => 'integer' ],
									'largest_donation'    => [ 'type' => 'number' ],
									'first_donation_date' => [ 'type' => 'string' ],
									'last_donation_date'  => [ 'type' => 'string' ],
									'created_at'          => [ 'type' => 'string' ],
								],
							],
						],
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total matching donors.', 'suredonation' ),
						],
						'total_pages' => [
							'type'        => 'integer',
							'description' => __( 'Total pages.', 'suredonation' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->list_donors( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'get-donor'          => [
				'label'               => __( 'Get donor', 'suredonation' ),
				'description'         => __( 'Returns a single donor by ID with full stats including total donated, donation count, largest donation, and donation dates.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'The donor ID.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => $donor_detail_schema,
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donor( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'get-donor-by-email' => [
				'label'               => __( 'Get donor by email', 'suredonation' ),
				'description'         => __( 'Looks up a donor by email address. Returns full donor details if found, or an error if no donor exists with that email.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'email' ],
					'properties' => [
						'email' => [
							'type'        => 'string',
							'description' => __( 'The donor email address.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => $donor_detail_schema,
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donor_by_email( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'get-top-donors'     => [
				'label'               => __( 'Get top donors', 'suredonation' ),
				'description'         => __( 'Returns top donors ranked by total donated amount. Useful for identifying major supporters and generating donor reports.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'limit' => [
							'type'        => 'integer',
							'default'     => 10,
							'description' => __( 'Number of top donors to return (max 100).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'donors' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'             => [ 'type' => 'integer' ],
									'name'           => [ 'type' => 'string' ],
									'email'          => [ 'type' => 'string' ],
									'total_donated'  => [ 'type' => 'number' ],
									'donation_count' => [ 'type' => 'integer' ],
								],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_top_donors( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],
		];
	}

	/**
	 * Get form ability configurations.
	 *
	 * @param Runtime  $runtime   Runtime instance.
	 * @param callable $perm_read Read permission closure.
	 * @return array<string, array<string, mixed>> Form abilities.
	 */
	private static function get_form_abilities( $runtime, $perm_read ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		return [
			$ns . 'list-forms' => [
				'label'               => __( 'List donation forms', 'suredonation' ),
				'description'         => __( 'Returns donation forms with optional campaign filter and status filter. Forms are the front-end donation widgets linked to campaigns.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'campaign_id' => [
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Filter by campaign ID (0 for all campaigns).', 'suredonation' ),
						],
						'status'      => [
							'type'        => 'string',
							'enum'        => [ 'any', 'publish', 'draft', 'trash' ],
							'default'     => 'any',
							'description' => __( 'Filter by form status.', 'suredonation' ),
						],
						'per_page'    => [
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Results per page (max 100).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'forms' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'            => [ 'type' => 'integer' ],
									'title'         => [ 'type' => 'string' ],
									'status'        => [ 'type' => 'string' ],
									'campaign_id'   => [ 'type' => 'integer' ],
									'campaign_name' => [ 'type' => 'string' ],
									'created_at'    => [ 'type' => 'string' ],
									'modified_at'   => [ 'type' => 'string' ],
									'edit_url'      => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->list_forms( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],

			$ns . 'get-form'   => [
				'label'               => __( 'Get donation form', 'suredonation' ),
				'description'         => __( 'Returns a single donation form by ID with campaign association, status, and edit URL.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'The donation form ID.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'            => [ 'type' => 'integer' ],
						'title'         => [ 'type' => 'string' ],
						'status'        => [ 'type' => 'string' ],
						'campaign_id'   => [ 'type' => 'integer' ],
						'campaign_name' => [ 'type' => 'string' ],
						'created_at'    => [ 'type' => 'string' ],
						'modified_at'   => [ 'type' => 'string' ],
						'edit_url'      => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_form( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],
		];
	}

	/**
	 * Get analytics ability configurations.
	 *
	 * @param Runtime  $runtime   Runtime instance.
	 * @param callable $perm_read Read permission closure.
	 * @return array<string, array<string, mixed>> Analytics abilities.
	 */
	private static function get_analytics_abilities( $runtime, $perm_read ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		return [
			$ns . 'get-donation-trends' => [
				'label'               => __( 'Get donation trends', 'suredonation' ),
				'description'         => __( 'Returns donation trend data grouped by day, week, or month. Supports date range filtering. Useful for charts and analytics.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'after'  => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Start date (YYYY-MM-DD). Empty for no lower bound.', 'suredonation' ),
						],
						'before' => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'End date (YYYY-MM-DD). Empty for no upper bound.', 'suredonation' ),
						],
						'group'  => [
							'type'        => 'string',
							'enum'        => [ 'day', 'week', 'month' ],
							'default'     => 'day',
							'description' => __( 'Group results by time period.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'trends'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'period'         => [ 'type' => 'string' ],
									'donation_count' => [ 'type' => 'integer' ],
									'total_amount'   => [ 'type' => 'number' ],
								],
							],
						],
						'currency' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donation_trends( $input );
				},
				'meta'                => self::build_meta( 1.0, true, false, true ),
			],
		];
	}
}
