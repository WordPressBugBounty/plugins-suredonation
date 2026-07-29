<?php
/**
 * Campaign and settings config import/export (JSON).
 *
 * Handles the one-shot JSON operations of the Import & Export feature — the
 * small, config-shaped data that doesn't warrant the batched CSV path:
 *  - Campaigns (post + `_suredonation_*` meta + linked donation forms), for
 *    site-to-site moves and backups.
 *  - Settings (the `suredonation_options` blob), with credentials stripped.
 *
 * This PR implements the export side; the import side (Merge/Replace, formId
 * rewrite) lands with the import tasks.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Import_Export;

use SureDonation\Inc\API\Settings_API;
use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Database\Base;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use WP_Post;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Config import/export helper.
 *
 * @since 1.3.0
 */
class Config_IO {

	/**
	 * Post fields carried in a campaign/form export — enough to recreate the
	 * post on import without leaking site-specific IDs beyond the reference id.
	 *
	 * @var array<int, string>
	 * @since 1.3.0
	 */
	const POST_FIELDS = [
		'post_title',
		'post_content',
		'post_excerpt',
		'post_status',
		'post_name',
		'post_type',
		'menu_order',
	];

	/**
	 * Export campaigns (with their linked donation forms) as a portable array.
	 *
	 * @param array<int, int> $campaign_ids Specific campaign IDs, or empty for all.
	 * @return array<int, array<string, mixed>> Campaign export objects.
	 * @since 1.3.0
	 */
	public static function export_campaigns( $campaign_ids = [] ) {
		/**
		 * Maximum number of campaigns exported in one pass. Bounds memory/time
		 * on sites with a very large number of campaigns (each campaign also
		 * loads its linked forms + meta).
		 *
		 * @param int $limit Campaign export cap.
		 * @since 1.3.0
		 */
		$limit = (int) apply_filters( 'suredonation_export_campaigns_limit', 10000 );

		$args = [
			'post_type'      => Campaign_Cpt::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		if ( ! empty( $campaign_ids ) ) {
			$args['post__in'] = array_map( 'absint', $campaign_ids );
		}

		$ids       = get_posts( $args );
		$campaigns = [];

		foreach ( $ids as $campaign_id ) {
			$campaign_id = absint( $campaign_id );
			$post        = get_post( $campaign_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$forms = [];
			foreach ( Donation_Form::get_forms_by_campaign( $campaign_id ) as $form ) {
				if ( ! $form instanceof WP_Post ) {
					continue;
				}
				$forms[] = [
					'id'   => $form->ID,
					'post' => self::export_post_fields( $form ),
					'meta' => self::export_suredonation_meta( $form->ID ),
				];
			}

			$campaigns[] = [
				'id'    => $campaign_id,
				'post'  => self::export_post_fields( $post ),
				'meta'  => self::export_suredonation_meta( $campaign_id ),
				'forms' => $forms,
			];
		}

		return $campaigns;
	}

	/**
	 * Export the SureDonation settings blob with credentials removed.
	 *
	 * Strips the option sub-keys that hold secrets (gateway API keys/tokens/
	 * webhook secrets, AI key, captcha secrets) so nothing sensitive ever
	 * leaves the site in a downloadable file. Site-specific / license options
	 * are not included.
	 *
	 * @return array<string, mixed> Settings safe to export.
	 * @since 1.3.0
	 */
	public static function export_settings() {
		$options = get_option( Helper::OPTION_NAME, [] );
		if ( ! is_array( $options ) ) {
			return [];
		}

		$exclude = array_merge(
			self::get_secret_option_keys(),
			self::get_non_portable_option_keys()
		);
		foreach ( $exclude as $key ) {
			unset( $options[ $key ] );
		}

		return $options;
	}

	/**
	 * Option sub-keys that must never be exported (credentials/secrets).
	 *
	 * @return array<int, string> Secret sub-key names.
	 * @since 1.3.0
	 */
	public static function get_secret_option_keys() {
		$keys = [
			Payment_Helper::OPTION_KEY,
			Settings_API::AI_OPTION_KEY,
			Settings_API::SPAM_OPTION_KEY,
		];

		/**
		 * Option sub-keys that hold secrets and must never be exported. The
		 * settings blob is shared with SureDonation Pro and future gateways;
		 * each new secret-bearing key must register here so it is stripped from
		 * every settings export (and preserved on import).
		 *
		 * @param array<int, string> $keys Secret sub-key names.
		 * @since 1.3.0
		 */
		$keys = apply_filters( 'suredonation_export_secret_option_keys', $keys );

		return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : [];
	}

	/**
	 * Option sub-keys excluded from export because they are operational/state,
	 * not user settings, and are unsafe or meaningless to restore (schema
	 * versions, analytics queues, onboarding flags).
	 *
	 * @return array<int, string> Non-portable sub-key names.
	 * @since 1.3.0
	 */
	public static function get_non_portable_option_keys() {
		return [
			Base::VERSION_OPTION_KEY,
			'usage_events_pending',
			'usage_events_pushed',
			'onboarding_completed',
			'onboarding_user_details',
			'onboarding_lead_sent_at',
		];
	}

	/**
	 * Import campaigns (and their linked forms) from an exported payload.
	 *
	 * Campaigns and forms are created as published. Each form's embedded `formId`
	 * is rewritten from the old id to the new one across the form and campaign
	 * block content, the campaign's default-form link is remapped, and only
	 * `_suredonation_*` meta is written back.
	 *
	 * @param array<int, mixed> $campaigns Campaign export objects.
	 * @return array<string, int> Counts: { campaigns, forms }.
	 * @since 1.3.0
	 */
	public static function import_campaigns( $campaigns ) {
		$result = [
			'campaigns' => 0,
			'forms'     => 0,
		];

		if ( ! is_array( $campaigns ) ) {
			return $result;
		}

		/**
		 * Maximum number of campaigns imported in one request. This path runs
		 * synchronously (2x wp_update_post per campaign) with no rollback, so a
		 * very large payload is capped to avoid a mid-import timeout.
		 *
		 * @param int $limit Campaign import cap.
		 * @since 1.3.0
		 */
		$limit     = (int) apply_filters( 'suredonation_import_campaigns_limit', 1000 );
		$campaigns = array_slice( $campaigns, 0, max( 0, $limit ) );

		foreach ( $campaigns as $campaign ) {
			if ( ! is_array( $campaign ) ) {
				continue;
			}

			$post_fields = is_array( $campaign['post'] ?? null ) ? $campaign['post'] : [];

			$campaign_id = wp_insert_post(
				[
					'post_type'    => Campaign_Cpt::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => wp_slash( sanitize_text_field( Helper::get_string_value( $post_fields['post_title'] ?? '' ) ) ),
					'post_content' => wp_slash( wp_kses_post( Helper::get_string_value( $post_fields['post_content'] ?? '' ) ) ),
					'post_excerpt' => wp_slash( sanitize_textarea_field( Helper::get_string_value( $post_fields['post_excerpt'] ?? '' ) ) ),
				],
				true
			);

			if ( is_wp_error( $campaign_id ) || ! $campaign_id ) {
				continue;
			}
			$campaign_id = (int) $campaign_id;
			++$result['campaigns'];

			$campaign_meta = is_array( $campaign['meta'] ?? null ) ? $campaign['meta'] : [];
			$old_default   = absint( Helper::get_string_value( $campaign_meta[ Campaign_Cpt::META_DEFAULT_FORM_ID ] ?? 0 ) );
			$forms         = is_array( $campaign['forms'] ?? null ) ? $campaign['forms'] : [];

			$form_id_map = [];
			$default_new = 0;

			foreach ( $forms as $form ) {
				if ( ! is_array( $form ) ) {
					continue;
				}
				$old_form_id = absint( Helper::get_string_value( $form['id'] ?? 0 ) );
				$form_post   = is_array( $form['post'] ?? null ) ? $form['post'] : [];

				$new_form_id = wp_insert_post(
					[
						'post_type'    => Donation_Form::POST_TYPE,
						'post_status'  => 'publish',
						'post_title'   => wp_slash( sanitize_text_field( Helper::get_string_value( $form_post['post_title'] ?? '' ) ) ),
						'post_content' => wp_slash( wp_kses_post( Helper::get_string_value( $form_post['post_content'] ?? '' ) ) ),
					],
					true
				);

				if ( is_wp_error( $new_form_id ) || ! $new_form_id ) {
					continue;
				}
				$new_form_id = (int) $new_form_id;
				++$result['forms'];

				if ( $old_form_id > 0 ) {
					$form_id_map[ $old_form_id ] = $new_form_id;
				}
				if ( $old_default > 0 && $old_form_id === $old_default ) {
					$default_new = $new_form_id;
				} elseif ( 0 === $default_new ) {
					$default_new = $new_form_id;
				}

				$form_meta                                    = is_array( $form['meta'] ?? null ) ? $form['meta'] : [];
				$form_meta[ Donation_Form::META_CAMPAIGN_ID ] = $campaign_id;
				self::write_suredonation_meta( $new_form_id, $form_meta );
			}

			// Rewrite formId references now that every new id is known.
			foreach ( $form_id_map as $new_id ) {
				self::rewrite_form_ids_in_post( $new_id, $form_id_map );
			}
			self::rewrite_form_ids_in_post( $campaign_id, $form_id_map );

			if ( $default_new > 0 ) {
				$campaign_meta[ Campaign_Cpt::META_DEFAULT_FORM_ID ] = $default_new;
			}
			self::write_suredonation_meta( $campaign_id, $campaign_meta );
		}

		return $result;
	}

	/**
	 * Import the settings blob with a Merge or Replace strategy.
	 *
	 * Never writes credential or operational keys: they are stripped from the
	 * incoming data, and on Replace the current values for those keys are
	 * preserved so a restore can't wipe live gateway credentials.
	 *
	 * @param array<string, mixed> $settings Incoming settings.
	 * @param string               $mode     'merge' or 'replace'.
	 * @return array<string, int> { applied } count of applied keys.
	 * @since 1.3.0
	 */
	public static function import_settings( $settings, $mode ) {
		if ( ! is_array( $settings ) ) {
			return [ 'applied' => 0 ];
		}

		$current = get_option( Helper::OPTION_NAME, [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}

		$excluded = array_merge( self::get_secret_option_keys(), self::get_non_portable_option_keys() );
		foreach ( $excluded as $key ) {
			unset( $settings[ $key ] );
		}

		// Sanitize the uploaded values (untrusted JSON): strip scripts/dangerous
		// markup from string leaves while preserving structure and non-strings.
		$sanitized = self::sanitize_import_values( $settings );
		$settings  = is_array( $sanitized ) ? $sanitized : [];

		if ( 'replace' === $mode ) {
			$preserved = array_intersect_key( $current, array_flip( $excluded ) );
			$new       = array_merge( $preserved, $settings );
		} else {
			$new = array_merge( $current, $settings );
		}

		update_option( Helper::OPTION_NAME, $new );

		return [ 'applied' => count( $settings ) ];
	}

	/**
	 * Recursively sanitize imported setting values.
	 *
	 * The structure and non-string scalars (int/float/bool/null) are preserved;
	 * string leaves are run through wp_kses_post so an uploaded settings file
	 * cannot smuggle scripts/dangerous markup into a value, while still allowing
	 * the safe HTML some settings legitimately contain.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed Sanitized value.
	 * @since 1.3.0
	 */
	private static function sanitize_import_values( $value ) {
		if ( is_array( $value ) ) {
			$clean = [];
			foreach ( $value as $key => $item ) {
				$clean[ $key ] = self::sanitize_import_values( $item );
			}
			return $clean;
		}
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}
		return $value;
	}

	/**
	 * Write a post's `_suredonation_*` meta from an import payload.
	 *
	 * Non-SureDonation keys are ignored. Values are slashed for the meta API so
	 * JSON-string metas round-trip intact.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $meta    Meta key => value.
	 * @return void
	 * @since 1.3.0
	 */
	private static function write_suredonation_meta( $post_id, $meta ) {
		if ( ! is_array( $meta ) ) {
			return;
		}
		foreach ( $meta as $key => $value ) {
			if ( 0 !== strpos( (string) $key, '_suredonation_' ) ) {
				continue;
			}
			$stored = ( is_array( $value ) || is_string( $value ) ) ? wp_slash( $value ) : $value;
			update_post_meta( $post_id, (string) $key, $stored );
		}
	}

	/**
	 * Rewrite embedded `formId` block attributes in a post's content using an
	 * old-id => new-id map (handles both numeric and quoted attribute forms).
	 *
	 * @param int             $post_id Post whose content to rewrite.
	 * @param array<int, int> $map     Old form id => new form id.
	 * @return void
	 * @since 1.3.0
	 */
	private static function rewrite_form_ids_in_post( $post_id, $map ) {
		if ( empty( $map ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Single pass over each "formId":<n> / "formId":"<n>" token. A greedy
		// \d+ consumes the whole number so old id 12 does not match inside 123,
		// and looking each match up once (rather than chained str_replace calls)
		// prevents a freshly-written id from being rewritten again by a later
		// map entry.
		$content = preg_replace_callback(
			'/"formId":("?)(\d+)\1/',
			static function ( $matches ) use ( $map ) {
				$old = (int) $matches[2];
				if ( ! isset( $map[ $old ] ) ) {
					return $matches[0];
				}
				$new = (int) $map[ $old ];
				return '"' === $matches[1] ? '"formId":"' . $new . '"' : '"formId":' . $new;
			},
			$post->post_content
		);

		if ( is_string( $content ) && $content !== $post->post_content ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( $content ),
				]
			);
		}
	}

	/**
	 * Pluck the exportable post fields from a post object.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed> Post fields keyed by field name.
	 * @since 1.3.0
	 */
	private static function export_post_fields( $post ) {
		$fields = [];
		foreach ( self::POST_FIELDS as $field ) {
			$fields[ $field ] = $post->$field ?? '';
		}
		return $fields;
	}

	/**
	 * Collect a post's `_suredonation_*` meta as a key => value map.
	 *
	 * Only SureDonation-owned meta is exported; core/third-party meta is
	 * skipped. Single values are taken as stored (JSON-string metas such as the
	 * campaign meta round-trip verbatim).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Meta values keyed by meta key.
	 * @since 1.3.0
	 */
	private static function export_suredonation_meta( $post_id ) {
		$all  = get_post_meta( $post_id );
		$meta = [];

		if ( ! is_array( $all ) ) {
			return $meta;
		}

		foreach ( $all as $key => $values ) {
			if ( 0 !== strpos( (string) $key, '_suredonation_' ) ) {
				continue;
			}
			$meta[ $key ] = is_array( $values ) && isset( $values[0] )
				? maybe_unserialize( $values[0] )
				: '';
		}

		return $meta;
	}
}
