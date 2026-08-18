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
	 * Setting key gating abilities that create or modify records.
	 *
	 * @since 1.5.0
	 */
	public const GATE_UPDATE = 'allow_updates';

	/**
	 * Setting key gating abilities that destroy records.
	 *
	 * @since 1.5.0
	 */
	public const GATE_DELETE = 'allow_delete';

	/**
	 * Cached abilities.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $abilities = null;

	/**
	 * Whether a write/delete gate is open.
	 *
	 * These gates are opt-IN: an absent key is closed, exactly as an explicit
	 * `false` is. That has to match two other places or the product lies to the
	 * admin — `Settings_API::get_ai_settings()` reports an absent key as `false`
	 * via its defaults, and the settings screen renders the switch off. An
	 * earlier revision treated absent as OPEN for parity with SureForms, which
	 * meant a site whose `ai_settings` was written programmatically with only
	 * `enable_abilities` (WP-CLI, a migration, another plugin) enforced deletes
	 * and gateway refunds as enabled while the UI stated they were disabled.
	 *
	 * SureForms can afford absent-means-open because its toggles are flat
	 * options that always exist once its settings page has been saved. Ours live
	 * inside a serialized array that other code paths write, so the absent state
	 * is reachable and must fail closed.
	 *
	 * Agents are not shown tools they cannot call: Runtime::register() skips a
	 * gated-off ability entirely rather than registering one whose permission
	 * callback always fails.
	 *
	 * @param string $gate One of the GATE_* constants.
	 * @return bool True when abilities behind this gate may register and run.
	 * @since 1.5.0
	 */
	public static function is_gate_open( $gate ) {
		if ( '' === $gate ) {
			return true;
		}

		$ai_option   = Helper::get_suredonation_option( 'ai_settings', [] );
		$ai_settings = is_array( $ai_option ) ? $ai_option : [];

		// Absent means closed. Settings_API::get_ai_settings() reports both gates
		// as false by default and the settings screen renders them off, so
		// treating an absent key as open would let the plugin enforce writes and
		// permanent deletes it is telling the admin are disabled. Reachable on a
		// partial PATCH of just {enable_abilities:true}, or a site that toggled
		// abilities before these sub-keys shipped. Deny is the only safe
		// fallthrough for a gate whose whole purpose is a deliberate second
		// decision about destructive or money-moving operations.
		return ! empty( $ai_settings[ $gate ] );
	}

	/**
	 * Get all ability configurations.
	 *
	 * @return array<string, array<string, mixed>> Ability definitions.
	 */
	public static function get_abilities() {
		if ( null !== self::$abilities ) {
			return self::$abilities;
		}

		$runtime = new Runtime();

		$perm_read = static function () use ( $runtime ) {
			return $runtime->permission_callback( 'manage_options' );
		};

		$perm_edit = static function () use ( $runtime ) {
			return self::is_gate_open( self::GATE_UPDATE ) && $runtime->permission_callback( 'manage_options' );
		};

		$perm_delete = static function () use ( $runtime ) {
			return self::is_gate_open( self::GATE_DELETE ) && $runtime->permission_callback( 'manage_options' );
		};

		$abilities = array_merge(
			self::get_campaign_abilities( $runtime, $perm_read, $perm_edit, $perm_delete ),
			self::get_donation_abilities( $runtime, $perm_read, $perm_edit, $perm_delete ),
			self::get_donor_abilities( $runtime, $perm_read, $perm_edit ),
			self::get_form_abilities( $runtime, $perm_read, $perm_edit, $perm_delete ),
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
	 * Three consumers read this block and each needs a different key:
	 *
	 * - `show_in_rest` gates the core `wp-abilities/v1` REST controllers. It
	 *   defaults to false in WP_Ability, so omitting it makes an ability
	 *   invisible to `GET /abilities` and unrunnable via `/run`.
	 * - `annotations` must use core's key names (`readonly`, `destructive`,
	 *   `idempotent`). The MCP-spec spellings (`readOnlyHint` and friends) are
	 *   not recognised by core, which leaves its own keys null.
	 * - `tool_type` is a TOP-LEVEL key read by MCP clients to classify the
	 *   operation. Without it a client has to guess from the tool name, and a
	 *   mutating ability whose name starts with a read-ish verb can slip past
	 *   an approval gate.
	 *
	 * Public rather than private: this is the single place that encodes the meta
	 * contract, and SureDonation Pro registers its own abilities through the
	 * `suredonation_config_abilities` filter. Pro hand-rolling this block would
	 * guarantee drift the moment any consumer's expectations change.
	 *
	 * @param string $tool_type   One of read|write|list|search|action|delete.
	 * @param float  $priority    Priority level (1.0 read, 2.0 write, 3.0 destructive).
	 * @param bool   $read_only   Whether the ability only reads data.
	 * @param bool   $destructive Whether the ability destroys data.
	 * @param bool   $idempotent  Whether repeated calls produce the same result.
	 * @param string $instructions Optional guidance for the calling model.
	 * @return array<string, mixed> Meta configuration.
	 */
	public static function build_meta( $tool_type = 'read', $priority = 1.0, $read_only = true, $destructive = false, $idempotent = true, $instructions = '' ) {
		$annotations = [
			'readonly'      => $read_only,
			'destructive'   => $destructive,
			'idempotent'    => $idempotent,
			'priority'      => $priority,
			// Deliberate MCP-spec spelling among core's snake_case annotation keys:
			// core does not define this one, and clients read the camelCase name.
			'openWorldHint' => false,
		];

		if ( '' !== $instructions ) {
			$annotations['instructions'] = $instructions;
		}

		return [
			'show_in_rest' => true,
			'tool_type'    => $tool_type,
			'annotations'  => $annotations,
			'mcp'          => [
				'public' => false,
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
							'enum'        => [ 'all', 'publish', 'draft', 'trash', 'paused' ],
							'default'     => 'all',
							'description' => __( 'Filter by status. "publish", "draft" and "trash" are WordPress post statuses; "paused" matches published campaigns whose campaign status is paused.', 'suredonation' ),
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
									'id'                 => [ 'type' => 'integer' ],
									'title'              => [ 'type' => 'string' ],
									'status'             => [ 'type' => 'string' ],
									'goal_type'          => [ 'type' => 'string' ],
									'goal'               => [ 'type' => 'number' ],
									'raised'             => [ 'type' => 'number' ],
									'donors'             => [ 'type' => 'integer' ],
									'progress'           => [ 'type' => 'number' ],
									'created_at'         => [ 'type' => 'string' ],
									'modified_at'        => [ 'type' => 'string' ],
									'post_status'        => [ 'type' => 'string' ],
									'currency'           => [ 'type' => 'string' ],
									'terms_text'         => [ 'type' => 'string' ],
									'thank_you_message'  => [ 'type' => 'string' ],
									'featured_image'     => [ 'type' => 'integer' ],
									'featured_image_url' => [ 'type' => 'string' ],
									'has_page'           => [ 'type' => 'boolean' ],
									'permalink'          => [ 'type' => 'string' ],
									'author'             => [ 'type' => 'string' ],
									'edit_url'           => [ 'type' => 'string' ],
									'default_form_id'    => [ 'type' => 'integer' ],
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
				'meta'                => self::build_meta( 'list', 1.0, true, false, true ),
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
						'id'                 => [ 'type' => 'integer' ],
						'title'              => [ 'type' => 'string' ],
						'description'        => [ 'type' => 'string' ],
						'status'             => [ 'type' => 'string' ],
						'goal_type'          => [ 'type' => 'string' ],
						'goal'               => [ 'type' => 'number' ],
						'raised'             => [ 'type' => 'number' ],
						'donors'             => [ 'type' => 'integer' ],
						'progress'           => [ 'type' => 'number' ],
						'donation_count'     => [ 'type' => 'integer' ],
						'average_donation'   => [ 'type' => 'number' ],
						'largest_donation'   => [ 'type' => 'number' ],
						'is_goal_reached'    => [ 'type' => 'boolean' ],
						'require_terms'      => [ 'type' => 'boolean' ],
						'created_at'         => [ 'type' => 'string' ],
						'modified_at'        => [ 'type' => 'string' ],
						'post_status'        => [ 'type' => 'string' ],
						'currency'           => [ 'type' => 'string' ],
						'terms_text'         => [ 'type' => 'string' ],
						'thank_you_message'  => [ 'type' => 'string' ],
						'featured_image'     => [ 'type' => 'integer' ],
						'featured_image_url' => [ 'type' => 'string' ],
						'has_page'           => [ 'type' => 'boolean' ],
						'permalink'          => [ 'type' => 'string' ],
						'author'             => [ 'type' => 'string' ],
						'edit_url'           => [ 'type' => 'string' ],
						'default_form_id'    => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_campaign( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
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
						'title'             => [
							'type'        => 'string',
							'description' => __( 'Campaign title.', 'suredonation' ),
						],
						'description'       => [
							'type'        => 'string',
							'format'      => 'html',
							'description' => __( 'Campaign description (HTML allowed).', 'suredonation' ),
							'default'     => '',
						],
						'goal_type'         => [
							'type'        => 'string',
							'enum'        => [ 'raised_amount', 'donation_count' ],
							'default'     => 'raised_amount',
							'description' => __( 'Goal type: track by amount raised or donation count.', 'suredonation' ),
						],
						'goal_amount'       => [
							'type'        => 'number',
							'description' => __( 'Goal amount (0 for no goal).', 'suredonation' ),
							'default'     => 0,
						],
						'campaign_status'   => [
							'type'        => 'string',
							'enum'        => [ 'active', 'paused', 'completed' ],
							'default'     => 'active',
							'description' => __( 'Campaign status.', 'suredonation' ),
						],
						'require_terms'     => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Require terms acceptance before donating.', 'suredonation' ),
						],
						'terms_text'        => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Terms and conditions text.', 'suredonation' ),
						],
						'thank_you_message' => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Message shown to the donor after a successful donation.', 'suredonation' ),
						],
						'featured_image'    => [
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Attachment ID to use as the campaign featured image (0 for none).', 'suredonation' ),
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
				'meta'                => self::build_meta( 'write', 2.0, false, false, false ),
				'gate'                => self::GATE_UPDATE,
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
						'id'                => [
							'type'        => 'integer',
							'description' => __( 'Campaign ID to update.', 'suredonation' ),
						],
						'title'             => [
							'type'        => 'string',
							'description' => __( 'Campaign title.', 'suredonation' ),
						],
						'description'       => [
							'type'        => 'string',
							'format'      => 'html',
							'description' => __( 'Campaign description (HTML allowed).', 'suredonation' ),
						],
						'goal_type'         => [
							'type'        => 'string',
							'enum'        => [ 'raised_amount', 'donation_count' ],
							'description' => __( 'Goal type.', 'suredonation' ),
						],
						'goal_amount'       => [
							'type'        => 'number',
							'description' => __( 'Goal amount.', 'suredonation' ),
						],
						'campaign_status'   => [
							'type'        => 'string',
							'enum'        => [ 'active', 'paused', 'completed' ],
							'description' => __( 'Campaign status.', 'suredonation' ),
						],
						'require_terms'     => [
							'type'        => 'boolean',
							'description' => __( 'Require terms acceptance.', 'suredonation' ),
						],
						'terms_text'        => [
							'type'        => 'string',
							'description' => __( 'Terms and conditions text.', 'suredonation' ),
						],
						'thank_you_message' => [
							'type'        => 'string',
							'description' => __( 'Message shown to the donor after a successful donation.', 'suredonation' ),
						],
						'featured_image'    => [
							'type'        => 'integer',
							'description' => __( 'Attachment ID to use as the campaign featured image (0 clears it).', 'suredonation' ),
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
				'meta'                => self::build_meta( 'write', 2.0, false, false, false ),
				'gate'                => self::GATE_UPDATE,
			],

			$ns . 'delete-campaign'             => [
				'label'               => __( 'Delete campaign', 'suredonation' ),
				'description'         => __( 'Permanently deletes a campaign by ID, along with its donation forms. Refused when the campaign has donations recorded against it, since those are financial records. This action cannot be undone.', 'suredonation' ),
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
						'id'            => [ 'type' => 'integer' ],
						'deleted_forms' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => __( 'IDs of the campaign donation forms deleted alongside it.', 'suredonation' ),
						],
						'kept_forms'    => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => __( 'Forms left in place because they still have donations recorded against them.', 'suredonation' ),
						],
						'message'       => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->delete_campaign( $input );
				},
				'meta'                => self::build_meta( 'delete', 3.0, false, true, false, __( 'Permanent and not undoable. It also deletes the campaign\'s donation forms. Confirm with the user before executing.', 'suredonation' ) ),
				'gate'                => self::GATE_DELETE,
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
				'meta'                => self::build_meta( 'write', 2.0, false, false, false ),
				'gate'                => self::GATE_UPDATE,
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
									'id'          => [ 'type' => 'integer' ],
									'title'       => [ 'type' => 'string' ],
									'type'        => [ 'type' => 'string' ],
									'status'      => [ 'type' => 'string' ],
									'modified_at' => [ 'type' => 'string' ],
									'edit_url'    => [ 'type' => 'string' ],
									'view_url'    => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_campaign_form_locations( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'update-campaign-status'      => [
				'label'               => __( 'Publish, draft or trash a campaign', 'suredonation' ),
				'description'         => __( 'Changes a campaign\'s WordPress post status: publish makes it live, draft hides it, trash removes it from listings without deleting it. This is different from the campaign business status (active/paused/completed), which update-campaign sets via campaign_status.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id', 'status' ],
					'properties' => [
						'id'     => [
							'type'        => 'integer',
							'description' => __( 'Campaign ID.', 'suredonation' ),
						],
						'status' => [
							'type'        => 'string',
							'enum'        => [ 'publish', 'draft', 'trash' ],
							'description' => __( 'The new post status.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'              => [ 'type' => 'integer' ],
						'post_status'     => [ 'type' => 'string' ],
						'previous_status' => [ 'type' => 'string' ],
						'changed'         => [ 'type' => 'boolean' ],
						'message'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->update_campaign_status( $input );
				},
				'meta'                => self::build_meta( 'write', 2.0, false, false, true ),
			],
		];
	}

	/**
	 * Get donation ability configurations.
	 *
	 * @param Runtime  $runtime     Runtime instance.
	 * @param callable $perm_read   Read permission closure.
	 * @param callable $perm_edit   Edit permission closure.
	 * @param callable $perm_delete Delete permission closure.
	 * @return array<string, array<string, mixed>> Donation abilities.
	 */
	private static function get_donation_abilities( $runtime, $perm_read, $perm_edit, $perm_delete ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		return [
			$ns . 'list-donations'         => [
				'label'               => __( 'List donations', 'suredonation' ),
				'description'         => __( 'Returns a paginated list of donations with optional search, status filter, campaign filter, and sorting.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search'      => [
							'type'        => 'string',
							'description' => __( 'Search donations by donor name, donor email, or transaction ID.', 'suredonation' ),
							'default'     => '',
						],
						'status'      => [
							'type'        => 'string',
							'enum'        => [ 'all', 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled', 'suspicious' ],
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
							'enum'        => [ 'id', 'created_at', 'updated_at', 'amount', 'donor_name', 'donor_email', 'payment_status', 'campaign_id', 'subscription_status' ],
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
									'id'                  => [ 'type' => 'integer' ],
									'campaign_id'         => [ 'type' => 'integer' ],
									'campaign_title'      => [ 'type' => 'string' ],
									'donor_name'          => [ 'type' => 'string' ],
									'donor_email'         => [ 'type' => 'string' ],
									'amount'              => [ 'type' => 'number' ],
									'currency'            => [ 'type' => 'string' ],
									'payment_status'      => [ 'type' => 'string' ],
									'donation_type'       => [ 'type' => 'string' ],
									'gateway'             => [ 'type' => 'string' ],
									'form_id'             => [ 'type' => 'integer' ],
									'form_title'          => [ 'type' => 'string' ],
									'subscription_id'     => [ 'type' => 'string' ],
									'subscription_status' => [ 'type' => 'string' ],
									'created_at'          => [ 'type' => 'string' ],
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
				'meta'                => self::build_meta( 'list', 1.0, true, false, true ),
			],

			$ns . 'get-donation'           => [
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
						'id'                     => [ 'type' => 'integer' ],
						'campaign_id'            => [ 'type' => 'integer' ],
						'campaign_title'         => [ 'type' => 'string' ],
						'donor_id'               => [ 'type' => 'integer' ],
						'donor_name'             => [ 'type' => 'string' ],
						'donor_email'            => [ 'type' => 'string' ],
						'donor_phone'            => [ 'type' => 'string' ],
						'amount'                 => [ 'type' => 'number' ],
						'fees_covered'           => [ 'type' => 'number' ],
						'refunded_amount'        => [ 'type' => 'number' ],
						'currency'               => [ 'type' => 'string' ],
						'donation_type'          => [ 'type' => 'string' ],
						'is_anonymous'           => [ 'type' => 'boolean' ],
						'donor_comment'          => [ 'type' => 'string' ],
						'payment_status'         => [ 'type' => 'string' ],
						'payment_mode'           => [ 'type' => 'string' ],
						'gateway'                => [ 'type' => 'string' ],
						'transaction_id'         => [ 'type' => 'string' ],
						'form_id'                => [ 'type' => 'integer' ],
						'form_title'             => [ 'type' => 'string' ],
						'stripe_customer_id'     => [ 'type' => 'string' ],
						'stripe_account_id'      => [
							'type'        => 'string',
							'description' => __( 'Connected Stripe account that processed this donation.', 'suredonation' ),
						],
						'subscription_id'        => [ 'type' => 'string' ],
						'subscription_status'    => [ 'type' => 'string' ],
						'parent_subscription_id' => [ 'type' => 'integer' ],
						'subscription_interval'  => [ 'type' => 'string' ],
						'billing_cycles'         => [ 'type' => 'string' ],
						'receipt_sent'           => [ 'type' => 'boolean' ],
						'receipt_pdf_url'        => [ 'type' => 'string' ],
						'import_source'          => [ 'type' => 'string' ],
						'fields'                 => [
							'type'        => 'array',
							'description' => __( 'Field values the donor submitted, as label/value/group triples.', 'suredonation' ),
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'label' => [ 'type' => 'string' ],
									'value' => [ 'type' => 'string' ],
									'group' => [ 'type' => 'string' ],
								],
							],
						],
						'created_at'             => [ 'type' => 'string' ],
						'updated_at'             => [ 'type' => 'string' ],
						'logs'                   => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donation( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'get-donation-notes'     => [
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
									'id'          => [ 'type' => 'string' ],
									'content'     => [ 'type' => 'string' ],
									'author_id'   => [ 'type' => 'integer' ],
									'author_name' => [ 'type' => 'string' ],
									'created_at'  => [ 'type' => 'string' ],
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
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'add-donation-note'      => [
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
				'meta'                => self::build_meta( 'write', 2.0, false, false, false ),
				'gate'                => self::GATE_UPDATE,
			],

			$ns . 'update-donation-status' => [
				'label'               => __( 'Update donation status', 'suredonation' ),
				'description'         => __( 'Changes a donation\'s payment status. Use refund-donation instead when money should actually move: this only changes the record.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id', 'status' ],
					'properties' => [
						'id'     => [
							'type'        => 'integer',
							'description' => __( 'The donation ID.', 'suredonation' ),
						],
						'status' => [
							'type'        => 'string',
							'enum'        => [ 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled' ],
							'description' => __( 'The new payment status.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'              => [ 'type' => 'integer' ],
						'payment_status'  => [ 'type' => 'string' ],
						'previous_status' => [ 'type' => 'string' ],
						'changed'         => [
							'type'        => 'boolean',
							'description' => __( 'False when the donation already had that status.', 'suredonation' ),
						],
						'message'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->update_donation_status( $input );
				},
				'meta'                => self::build_meta( 'write', 2.0, false, false, true ),
			],

			$ns . 'refund-donation'        => [
				'label'               => __( 'Refund donation', 'suredonation' ),
				'description'         => __( 'Refunds a donation through the gateway that processed it (Stripe or PayPal), fully or partially. Moves real money and cannot be undone. Amounts are in the donation currency (for example 25.50), not cents. Omit the amount to refund everything still refundable. This also emails the donor a refund notification and fires any connected refund automations. For a recurring donation it refunds the one charge only and does not cancel the subscription.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'             => [
							'type'        => 'integer',
							'description' => __( 'The donation ID.', 'suredonation' ),
						],
						'amount'         => [
							'type'        => 'number',
							'default'     => 0,
							'description' => __( 'Amount to refund in the donation currency. 0 or omitted refunds the full remaining balance.', 'suredonation' ),
						],
						'transaction_id' => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Optional safety check. When supplied it must match the donation\'s gateway transaction ID.', 'suredonation' ),
						],
						'notes'          => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Internal note recorded against the refund.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'             => [ 'type' => 'integer' ],
						'refunded'       => [
							'type'        => 'number',
							'description' => __( 'Amount refunded by this call, in the donation currency.', 'suredonation' ),
						],
						'currency'       => [ 'type' => 'string' ],
						'refunded_total' => [
							'type'        => 'number',
							'description' => __( 'Total refunded against this donation so far.', 'suredonation' ),
						],
						'payment_status' => [ 'type' => 'string' ],
						'message'        => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->refund_donation( $input );
				},
				'meta'                => self::build_meta( 'action', 3.0, false, true, false, __( 'Moves real money through the payment gateway and cannot be undone, emails the donor a refund notification, and does not cancel a subscription. Always confirm the donation and the amount with the user before executing.', 'suredonation' ) ),
			],

			$ns . 'create-donation'        => [
				'label'               => __( 'Record a donation', 'suredonation' ),
				'description'         => __( 'Records a donation taken outside the online checkout — a cheque, cash, or bank transfer. This does NOT charge anyone: it only creates the record. Never use it to take a card payment.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'campaign_id', 'amount' ],
					'properties' => [
						'campaign_id'    => [
							'type'        => 'integer',
							'description' => __( 'Campaign the donation belongs to.', 'suredonation' ),
						],
						'amount'         => [
							'type'        => 'number',
							'description' => __( 'Donation amount in the store currency.', 'suredonation' ),
						],
						'donor_name'     => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Donor name.', 'suredonation' ),
						],
						'donor_email'    => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Donor email address.', 'suredonation' ),
						],
						'donor_phone'    => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Donor phone number.', 'suredonation' ),
						],
						'donor_comment'  => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Comment left by the donor.', 'suredonation' ),
						],
						'payment_status' => [
							'type'        => 'string',
							'enum'        => [ 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled' ],
							'default'     => 'pending',
							'description' => __( 'Status to record. Defaults to "pending" so recording a donation does not send donor receipts or fire completion automations; pass "completed" explicitly for a gift that has already cleared.', 'suredonation' ),
						],
						'donation_type'  => [
							'type'        => 'string',
							'enum'        => [ 'one-time', 'recurring', 'renewal' ],
							'default'     => 'one-time',
							'description' => __( 'Donation type.', 'suredonation' ),
						],
						'gateway'        => [
							'type'        => 'string',
							'default'     => 'offline',
							'description' => __( 'How the donation was taken (for example "offline").', 'suredonation' ),
						],
						'transaction_id' => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'External reference, such as a cheque number.', 'suredonation' ),
						],
						'fees_covered'   => [
							'type'        => 'number',
							'default'     => 0,
							'description' => __( 'Amount the donor added to cover processing fees.', 'suredonation' ),
						],
						'is_anonymous'   => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether the donation should be shown anonymously.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'             => [ 'type' => 'integer' ],
						'campaign_id'    => [ 'type' => 'integer' ],
						'amount'         => [ 'type' => 'number' ],
						'payment_status' => [ 'type' => 'string' ],
						'message'        => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->create_donation( $input );
				},
				'meta'                => self::build_meta( 'write', 2.0, false, false, false ),
			],

			$ns . 'delete-donation-note'   => [
				'label'               => __( 'Delete donation note', 'suredonation' ),
				'description'         => __( 'Permanently removes an internal note from a donation. Get the note ID from get-donation-notes first.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_delete,
				'gate'                => self::GATE_DELETE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id', 'note_id' ],
					'properties' => [
						'id'      => [
							'type'        => 'integer',
							'description' => __( 'The donation ID.', 'suredonation' ),
						],
						'note_id' => [
							'type'        => 'string',
							'description' => __( 'The note ID, as returned by get-donation-notes.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'      => [ 'type' => 'integer' ],
						'note_id' => [ 'type' => 'string' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->delete_donation_note( $input );
				},
				'meta'                => self::build_meta( 'delete', 3.0, false, true, false, __( 'Permanently removes the note. Confirm with the user before executing.', 'suredonation' ) ),
			],
		];
	}

	/**
	 * Get donor ability configurations.
	 *
	 * @param Runtime  $runtime   Runtime instance.
	 * @param callable $perm_read Read permission closure.
	 * @param callable $perm_edit Edit permission closure.
	 * @return array<string, array<string, mixed>> Donor abilities.
	 */
	private static function get_donor_abilities( $runtime, $perm_read, $perm_edit ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		$donor_detail_schema = [
			'type'       => 'object',
			'properties' => [
				'id'                  => [ 'type' => 'integer' ],
				'name'                => [ 'type' => 'string' ],
				'email'               => [ 'type' => 'string' ],
				'phone'               => [ 'type' => 'string' ],
				'company'             => [ 'type' => 'string' ],
				'address'             => [ 'type' => 'string' ],
				'stripe_customer_id'  => [ 'type' => 'string' ],
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
			$ns . 'list-donors'         => [
				'label'               => __( 'List donors', 'suredonation' ),
				'description'         => __( 'Returns a paginated list of donors with optional search, status filter, campaign filter, date range, and sorting.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'search'      => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Search donors by name or email.', 'suredonation' ),
						],
						'campaign_id' => [
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Only donors who gave to this campaign (0 for all campaigns).', 'suredonation' ),
						],
						'after'       => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Only donors whose last donation was on or after this date (YYYY-MM-DD).', 'suredonation' ),
						],
						'before'      => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Only donors whose last donation was on or before this date (YYYY-MM-DD).', 'suredonation' ),
						],
						'status'      => [
							'type'        => 'string',
							'enum'        => [ 'all', 'active', 'inactive', 'blocked' ],
							'default'     => 'all',
							'description' => __( 'Filter by donor status.', 'suredonation' ),
						],
						'sort_by'     => [
							'type'        => 'string',
							'enum'        => [ 'id', 'created_at', 'updated_at', 'name', 'email', 'total_donated', 'donation_count', 'last_donation_date' ],
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
						'donors'      => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'                  => [ 'type' => 'integer' ],
									'name'                => [ 'type' => 'string' ],
									'email'               => [ 'type' => 'string' ],
									'phone'               => [ 'type' => 'string' ],
									'company'             => [ 'type' => 'string' ],
									'address'             => [ 'type' => 'string' ],
									'stripe_customer_id'  => [ 'type' => 'string' ],
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
				'meta'                => self::build_meta( 'list', 1.0, true, false, true ),
			],

			$ns . 'get-donor'           => [
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
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'get-donor-by-email'  => [
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
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'get-top-donors'      => [
				'label'               => __( 'Get top donors', 'suredonation' ),
				'description'         => __( 'Returns the top active donors ranked by total donated amount. Blocked and inactive donors are excluded. Useful for identifying major supporters and generating donor reports.', 'suredonation' ),
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
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'get-donor-donations' => [
				'label'               => __( 'Get donor donation history', 'suredonation' ),
				'description'         => __( 'Returns a paginated donation history for one donor, newest first.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'       => [
							'type'        => 'integer',
							'description' => __( 'The donor ID.', 'suredonation' ),
						],
						'page'     => [
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number (1-based).', 'suredonation' ),
						],
						'per_page' => [
							'type'        => 'integer',
							'default'     => 10,
							'description' => __( 'Results per page (max 100).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'donor_id'    => [ 'type' => 'integer' ],
						'donations'   => [
							'type'  => 'array',
							'items' => [
								'type'                 => 'object',
								'properties'           => [
									'id'             => [ 'type' => 'integer' ],
									'campaign_id'    => [ 'type' => 'integer' ],
									'campaign_title' => [ 'type' => 'string' ],
									'donor_name'     => [ 'type' => 'string' ],
									'donor_email'    => [ 'type' => 'string' ],
									'amount'         => [ 'type' => 'number' ],
									'currency'       => [ 'type' => 'string' ],
									'payment_status' => [ 'type' => 'string' ],
									'created_at'     => [ 'type' => 'string' ],
								],
								'additionalProperties' => false,
							],
						],
						'total'       => [ 'type' => 'integer' ],
						'total_pages' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donor_donations( $input );
				},
				'meta'                => self::build_meta( 'list', 1.0, true, false, true ),
			],

			$ns . 'update-donor'        => [
				'label'               => __( 'Update donor', 'suredonation' ),
				'description'         => __( 'Updates a donor\'s contact details, status or tags. Only the fields you send are changed; everything else is left as-is. Donation totals are derived from donations and cannot be set here.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'           => [
							'type'        => 'integer',
							'description' => __( 'The donor ID.', 'suredonation' ),
						],
						'name'         => [
							'type'        => 'string',
							'description' => __( 'Donor name.', 'suredonation' ),
						],
						'email'        => [
							'type'        => 'string',
							'description' => __( 'Donor email address.', 'suredonation' ),
						],
						'phone'        => [
							'type'        => 'string',
							'description' => __( 'Donor phone number.', 'suredonation' ),
						],
						'company'      => [
							'type'        => 'string',
							'description' => __( 'Donor company.', 'suredonation' ),
						],
						'address'      => [
							'type'        => 'string',
							'description' => __( 'Donor address.', 'suredonation' ),
						],
						'donor_status' => [
							'type'        => 'string',
							'enum'        => [ 'active', 'inactive', 'blocked' ],
							'description' => __( 'Donor status. Blocked donors are excluded from top-donor reports.', 'suredonation' ),
						],
						'donor_tags'   => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Replaces the donor\'s tags with this list.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'      => [ 'type' => 'integer' ],
						'updated' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Names of the fields this call changed.', 'suredonation' ),
						],
						'donor'   => [ 'type' => 'object' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->update_donor( $input );
				},
				'meta'                => self::build_meta( 'write', 2.0, false, false, true ),
			],
		];
	}

	/**
	 * Get form ability configurations.
	 *
	 * @param Runtime  $runtime     Runtime instance.
	 * @param callable $perm_read   Read permission closure.
	 * @param callable $perm_edit   Edit permission closure.
	 * @param callable $perm_delete Delete permission closure.
	 * @return array<string, array<string, mixed>> Form abilities.
	 */
	private static function get_form_abilities( $runtime, $perm_read, $perm_edit, $perm_delete ) {
		$ns = SUREDONATION_ABILITY_API_NAMESPACE;

		return [
			$ns . 'list-forms'       => [
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
						'page'        => [
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number (1-based).', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total matching forms.', 'suredonation' ),
						],
						'total_pages' => [
							'type'        => 'integer',
							'description' => __( 'Total pages.', 'suredonation' ),
						],
						'forms'       => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'            => [ 'type' => 'integer' ],
									'title'         => [ 'type' => 'string' ],
									'status'        => [ 'type' => 'string' ],
									'campaign_id'   => [ 'type' => 'integer' ],
									'campaign_name' => [ 'type' => 'string' ],
									'entries'       => [ 'type' => 'integer' ],
									'revenue'       => [ 'type' => 'number' ],
									'is_default'    => [ 'type' => 'boolean' ],
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
				'meta'                => self::build_meta( 'list', 1.0, true, false, true ),
			],

			$ns . 'get-form'         => [
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
						'entries'       => [ 'type' => 'integer' ],
						'revenue'       => [ 'type' => 'number' ],
						'is_default'    => [ 'type' => 'boolean' ],
						'created_at'    => [ 'type' => 'string' ],
						'modified_at'   => [ 'type' => 'string' ],
						'edit_url'      => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_form( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'update-form'      => [
				'label'               => __( 'Move a form to another campaign', 'suredonation' ),
				'description'         => __( 'Reassigns a donation form to a different campaign. Donations already recorded through the form keep their original campaign.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id', 'campaign_id' ],
					'properties' => [
						'id'          => [
							'type'        => 'integer',
							'description' => __( 'The donation form ID.', 'suredonation' ),
						],
						'campaign_id' => [
							'type'        => 'integer',
							'description' => __( 'Campaign to attach the form to.', 'suredonation' ),
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
						'entries'       => [ 'type' => 'integer' ],
						'revenue'       => [ 'type' => 'number' ],
						'is_default'    => [ 'type' => 'boolean' ],
						'created_at'    => [ 'type' => 'string' ],
						'modified_at'   => [ 'type' => 'string' ],
						'edit_url'      => [ 'type' => 'string' ],
						'message'       => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->update_form( $input );
				},
				'meta'                => self::build_meta( 'write', 2.0, false, false, true ),
			],

			$ns . 'duplicate-form'   => [
				'label'               => __( 'Duplicate donation form', 'suredonation' ),
				'description'         => __( 'Creates a copy of a donation form, including its fields and settings.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'The donation form ID to copy.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'        => [
							'type'        => 'integer',
							'description' => __( 'The new form ID.', 'suredonation' ),
						],
						'source_id' => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'message'   => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->duplicate_form( $input );
				},
				'meta'                => self::build_meta( 'write', 2.0, false, false, false ),
			],

			$ns . 'set-default-form' => [
				'label'               => __( 'Set a campaign\'s default form', 'suredonation' ),
				'description'         => __( 'Chooses which donation form a campaign renders. A campaign can have several forms attached but renders only its default.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_edit,
				'gate'                => self::GATE_UPDATE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'form_id', 'campaign_id' ],
					'properties' => [
						'form_id'     => [
							'type'        => 'integer',
							'description' => __( 'Form to make the default.', 'suredonation' ),
						],
						'campaign_id' => [
							'type'        => 'integer',
							'description' => __( 'Campaign to set it on.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'campaign_id'     => [ 'type' => 'integer' ],
						'default_form_id' => [ 'type' => 'integer' ],
						'message'         => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->set_default_form( $input );
				},
				'meta'                => self::build_meta( 'write', 2.0, false, false, true ),
			],

			$ns . 'manage-form'      => [
				'label'               => __( 'Trash, restore or delete a form', 'suredonation' ),
				'description'         => __( 'Moves a donation form to the trash, restores it, or deletes it permanently. Trashing is reversible; deleting is not. Donations already recorded through the form are never removed.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_delete,
				'gate'                => self::GATE_DELETE,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id', 'action' ],
					'properties' => [
						'id'     => [
							'type'        => 'integer',
							'description' => __( 'The donation form ID.', 'suredonation' ),
						],
						'action' => [
							'type'        => 'string',
							'enum'        => [ 'trash', 'restore', 'delete' ],
							'description' => __( 'What to do with the form. "delete" is permanent.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'          => [ 'type' => 'integer' ],
						'action'      => [ 'type' => 'string' ],
						'post_status' => [ 'type' => 'string' ],
						'message'     => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->manage_form( $input );
				},
				'meta'                => self::build_meta( 'delete', 3.0, false, true, false, 'The "delete" action is permanent and not undoable. Prefer "trash", and confirm with the user before deleting.' ),
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
			$ns . 'get-donation-trends'  => [
				'label'               => __( 'Get donation trends', 'suredonation' ),
				'description'         => __( 'Returns donation trend data grouped by day, week, or month for a single currency. Supports date-range and campaign filtering; defaults to the last 30 days in the store currency. Useful for charts and analytics.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'after'       => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Start date (YYYY-MM-DD). Empty defaults to 30 days ago.', 'suredonation' ),
						],
						'before'      => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'End date (YYYY-MM-DD). Empty defaults to today.', 'suredonation' ),
						],
						'group'       => [
							'type'        => 'string',
							'enum'        => [ 'day', 'week', 'month' ],
							'default'     => 'day',
							'description' => __( 'Group results by time period.', 'suredonation' ),
						],
						'currency'    => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Three-letter currency code to report on. Defaults to the store currency. Amounts across currencies are never summed together.', 'suredonation' ),
						],
						'campaign_id' => [
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Limit to one campaign (0 for all campaigns).', 'suredonation' ),
						],
						'payment_mode' => [
							'type'        => 'string',
							'enum'        => [ 'test', 'live' ],
							'default'     => '',
							'description' => __( 'Report on test or live donations. Defaults to the store\'s current mode.', 'suredonation' ),
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
						'after'    => [
							'type'        => 'string',
							'description' => __( 'Start of the window actually queried.', 'suredonation' ),
						],
						'before'   => [
							'type'        => 'string',
							'description' => __( 'End of the window actually queried.', 'suredonation' ),
						],
						'payment_mode' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_donation_trends( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'get-dashboard-stats'  => [
				'label'               => __( 'Get donation dashboard stats', 'suredonation' ),
				'description'         => __( 'Returns the site-wide donation totals: number of donations, amount raised, unique donors, average and largest donation, and how many campaigns are published. The single best call for answering "how are we doing?".', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'currency'     => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Three-letter currency code to report on. Defaults to the store currency. Amounts across currencies are never summed together.', 'suredonation' ),
						],
						'payment_mode' => [
							'type'        => 'string',
							'enum'        => [ 'test', 'live' ],
							'default'     => '',
							'description' => __( 'Report on test or live donations. Defaults to the store\'s current mode. Test and live figures are never summed together.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total_donations'  => [ 'type' => 'integer' ],
						'total_raised'     => [ 'type' => 'number' ],
						'unique_donors'    => [ 'type' => 'integer' ],
						'average_donation' => [ 'type' => 'number' ],
						'largest_donation' => [ 'type' => 'number' ],
						'published_campaigns' => [ 'type' => 'integer' ],
						'currency'         => [ 'type' => 'string' ],
						'payment_mode'     => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_dashboard_stats( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'get-recent-donations' => [
				'label'               => __( 'Get recent donations', 'suredonation' ),
				'description'         => __( 'Returns the most recent donations across all campaigns, newest first.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'limit' => [
							'type'        => 'integer',
							'default'     => 5,
							'description' => __( 'How many donations to return (max 100).', 'suredonation' ),
						],
						'currency'     => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Three-letter currency code to report on. Defaults to the store currency. Amounts across currencies are never summed together.', 'suredonation' ),
						],
						'payment_mode' => [
							'type'        => 'string',
							'enum'        => [ 'test', 'live' ],
							'default'     => '',
							'description' => __( 'Report on test or live donations. Defaults to the store\'s current mode. Test and live figures are never summed together.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'donations' => [
							'type'  => 'array',
							'items' => [
								'type'                 => 'object',
								'properties'           => [
									'id'             => [ 'type' => 'integer' ],
									'campaign_id'    => [ 'type' => 'integer' ],
									'campaign_title' => [ 'type' => 'string' ],
									'donor_name'     => [ 'type' => 'string' ],
									'donor_email'    => [ 'type' => 'string' ],
									'amount'         => [ 'type' => 'number' ],
									'currency'       => [ 'type' => 'string' ],
									'payment_status' => [ 'type' => 'string' ],
									'created_at'     => [ 'type' => 'string' ],
								],
								'additionalProperties' => false,
							],
						],
						'currency'  => [ 'type' => 'string' ],
						'payment_mode' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_recent_donations( $input );
				},
				'meta'                => self::build_meta( 'list', 1.0, true, false, true ),
			],

			$ns . 'get-top-campaigns'    => [
				'label'               => __( 'Get top campaigns', 'suredonation' ),
				'description'         => __( 'Returns the campaigns that have raised the most, ranked by amount raised.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'limit' => [
							'type'        => 'integer',
							'default'     => 5,
							'description' => __( 'How many campaigns to return (max 100).', 'suredonation' ),
						],
						'currency'     => [
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Three-letter currency code to report on. Defaults to the store currency. Amounts across currencies are never summed together.', 'suredonation' ),
						],
						'payment_mode' => [
							'type'        => 'string',
							'enum'        => [ 'test', 'live' ],
							'default'     => '',
							'description' => __( 'Report on test or live donations. Defaults to the store\'s current mode. Test and live figures are never summed together.', 'suredonation' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'campaigns' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'             => [ 'type' => 'integer' ],
									'title'          => [ 'type' => 'string' ],
									'total_raised'   => [ 'type' => 'number' ],
									'donation_count' => [ 'type' => 'integer' ],
								],
							],
						],
						'currency'  => [ 'type' => 'string' ],
						'payment_mode' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_top_campaigns( $input );
				},
				'meta'                => self::build_meta( 'list', 1.0, true, false, true ),
			],

			$ns . 'get-settings'         => [
				'label'               => __( 'Get donation settings', 'suredonation' ),
				'description'         => __( 'Returns the non-sensitive store settings: currency and how its symbol is positioned, whether payments are in test or live mode, and the donor/spam options. Payment credentials and the AI settings that gate these abilities are never returned.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'currency'               => [ 'type' => 'string' ],
						'currency_symbol'        => [ 'type' => 'string' ],
						'currency_sign_position' => [ 'type' => 'string' ],
						'payment_mode'           => [
							'type'        => 'string',
							'description' => __( 'test or live. Global, not per gateway.', 'suredonation' ),
						],
						'honeypot_enabled'       => [ 'type' => 'boolean' ],
						'create_wp_user'         => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_settings( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],

			$ns . 'get-payment-gateways' => [
				'label'               => __( 'Get payment gateway status', 'suredonation' ),
				'description'         => __( 'Returns which payment gateways are connected and whether the store is in test or live mode. Connection state only, never credentials. Useful for diagnosing why a donation form offers no payment options.', 'suredonation' ),
				'category'            => 'suredonation',
				'permission_callback' => $perm_read,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'payment_mode' => [ 'type' => 'string' ],
						'gateways'     => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'        => [ 'type' => 'string' ],
									'connected' => [ 'type' => 'boolean' ],
								],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $runtime ) {
					return $runtime->get_payment_gateways( $input );
				},
				'meta'                => self::build_meta( 'read', 1.0, true, false, true ),
			],
		];
	}
}
