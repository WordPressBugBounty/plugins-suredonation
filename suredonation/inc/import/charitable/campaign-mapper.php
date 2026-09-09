<?php
/**
 * Maps Charitable campaigns onto SureDonation campaigns.
 *
 * One SureDonation campaign per selected Charitable campaign post, with
 * source/import provenance meta for idempotency and rollback, the goal
 * written into SureDonation's canonical campaign-meta blob, the raw
 * Charitable settings preserved in a JSON payload, and a default donation
 * form auto-created (mirrors the GiveWP campaign mapper).
 *
 * @package SureDonation
 * @since 1.5.1
 */

namespace SureDonation\Inc\Import\Charitable;

use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Campaign_Mapper class.
 *
 * @phpstan-import-type ImportProgress from Session
 *
 * @since 1.5.1
 */
class Campaign_Mapper {
	use Get_Instance;

	/**
	 * Post meta on the SD campaign holding the Charitable campaign post ID.
	 *
	 * @since 1.5.1
	 */
	public const META_SOURCE_ID = '_suredonation_charitable_source_id';

	/**
	 * Post meta on the SD campaign holding the import session UUID (rollback scope).
	 *
	 * @since 1.5.1
	 */
	public const META_IMPORT_ID = '_suredonation_charitable_import_id';

	/**
	 * Reverse pointer written onto the Charitable campaign post.
	 *
	 * @since 1.5.1
	 */
	public const META_CHARITABLE_MARKER = '_suredonation_imported_to_campaign_id';

	/**
	 * Process a batch of Charitable campaigns.
	 *
	 * @param  ImportProgress $progress Session progress (passed by reference).
	 * @param  int            $offset   Current offset within this phase.
	 * @return int Number of source rows processed in this batch.
	 * @since  1.5.1
	 */
	public function process_batch( &$progress, $offset ) {
		$source       = Source::get_instance();
		$campaign_ids = isset( $progress['options']['campaign_ids'] ) && is_array( $progress['options']['campaign_ids'] )
			? $progress['options']['campaign_ids']
			: [];
		$campaigns    = $source->get_campaigns_batch( (int) $offset, Importer::BATCH_SIZE, $campaign_ids );

		if ( empty( $campaigns ) ) {
			return 0;
		}

		foreach ( $campaigns as $campaign ) {
			$charitable_id = isset( $campaign->ID ) ? (int) $campaign->ID : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP $wpdb->posts row.
			if ( $charitable_id <= 0 ) {
				++$progress['results']['campaigns']['errors'];
				continue;
			}

			$existing_id = $this->find_existing_campaign( $charitable_id );

			if ( $existing_id > 0 ) {
				++$progress['results']['campaigns']['skipped'];
				$progress['campaign_map'][ $charitable_id ] = $existing_id;
				continue;
			}

			try {
				$result = $this->insert_campaign( $campaign, $progress );
			} catch ( \Throwable $t ) {
				++$progress['results']['campaigns']['errors'];
				$progress['results']['campaigns']['error_log'] = $this->append_error_log(
					$progress['results']['campaigns']['error_log'],
					$charitable_id,
					sprintf(
						/* translators: %s: exception message */
						__( 'Unhandled exception while importing campaign: %s', 'suredonation' ),
						$t->getMessage()
					)
				);
				continue;
			}

			if ( is_wp_error( $result ) ) {
				++$progress['results']['campaigns']['errors'];
				$progress['results']['campaigns']['error_log'] = $this->append_error_log(
					$progress['results']['campaigns']['error_log'],
					$charitable_id,
					$result->get_error_message()
				);
				continue;
			}

			$progress['campaign_map'][ $charitable_id ] = (int) $result;
			++$progress['results']['campaigns']['imported'];
		}

		return count( $campaigns );
	}

	/**
	 * Find an existing SureDonation campaign for a given Charitable campaign ID.
	 *
	 * @param  int $charitable_id Charitable campaign post ID.
	 * @return int Campaign ID or 0 if none.
	 * @since  1.5.1
	 */
	private function find_existing_campaign( $charitable_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scope, one-shot lookup per campaign.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_SOURCE_ID,
				(string) (int) $charitable_id
			)
		);

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}

	/**
	 * Insert a single SureDonation campaign from a Charitable campaign row.
	 *
	 * Migrates title, description, author, dates, and the fundraising goal;
	 * preserves the remaining Charitable settings (suggested amounts, end
	 * date, minimum amount, button text, display toggles) in a JSON payload.
	 * Draft Charitable campaigns import as published/active — same deliberate
	 * decision as the GiveWP importer.
	 *
	 * @param  object               $campaign Charitable wp_posts row.
	 * @param  array<string, mixed> $progress Current progress (used for the import_id).
	 * @return int|\WP_Error New campaign ID on success.
	 * @since  1.5.1
	 */
	private function insert_campaign( $campaign, $progress ) {
		$source = Source::get_instance();

		$charitable_id = isset( $campaign->ID ) ? (int) $campaign->ID : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP $wpdb->posts row.
		$post_title    = isset( $campaign->post_title ) ? (string) $campaign->post_title : '';
		if ( '' === trim( $post_title ) ) {
			/* translators: %d: Charitable campaign ID. */
			$post_title = sprintf( __( 'Imported campaign %d', 'suredonation' ), $charitable_id );
		}

		$meta = $source->get_campaign_meta( $charitable_id );

		$description = isset( $meta['_campaign_description'] ) ? (string) $meta['_campaign_description'] : '';
		if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
			$description = isset( $campaign->post_content ) ? (string) $campaign->post_content : '';
		}
		$post_excerpt = wp_kses_post( wp_strip_all_tags( $description ) );

		$campaign_id = wp_insert_post(
			[
				'post_type'     => Campaign_Cpt::POST_TYPE,
				'post_status'   => 'publish',
				'post_title'    => sanitize_text_field( $post_title ),
				'post_content'  => '',
				'post_excerpt'  => $post_excerpt,
				'post_author'   => isset( $campaign->post_author ) ? (int) $campaign->post_author : 0,
				'post_date'     => isset( $campaign->post_date ) ? (string) $campaign->post_date : '',
				'post_date_gmt' => isset( $campaign->post_date_gmt ) ? (string) $campaign->post_date_gmt : '',
			],
			true
		);

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		update_post_meta( $campaign_id, self::META_SOURCE_ID, $charitable_id );
		if ( ! empty( $progress['import_id'] ) ) {
			update_post_meta( $campaign_id, self::META_IMPORT_ID, is_scalar( $progress['import_id'] ) ? (string) $progress['import_id'] : '' );
		}

		// Reverse pointer so the Charitable side records where it went.
		update_post_meta( $charitable_id, self::META_CHARITABLE_MARKER, $campaign_id );

		// Write to SureDonation's canonical _suredonation_campaign_meta blob —
		// the key the All Campaigns UI reads. Charitable goals are always
		// amount-based, so goal_type is fixed to raised_amount.
		$goal_amount = isset( $meta['_campaign_goal'] ) && is_numeric( $meta['_campaign_goal'] ) ? (float) $meta['_campaign_goal'] : 0;
		Helper::update_campaign_meta(
			$campaign_id,
			[
				'goal_amount'     => $goal_amount,
				'goal_type'       => 'raised_amount',
				'campaign_status' => 'active',
			]
		);

		// Preserve the migration-relevant Charitable settings in JSON meta so
		// the admin UI can surface them later (no first-class SD columns yet).
		$payload = $this->build_raw_payload( $meta );
		if ( ! empty( $payload ) ) {
			update_post_meta( $campaign_id, '_suredonation_charitable_campaign', wp_json_encode( $payload ) );
		}

		// Default form auto-creation — having no form is worse than a basic
		// SureDonation template the admin can edit. The template doesn't carry
		// Charitable's suggested amounts / fields (that structure is preserved
		// in the raw payload above for a future enhancement).
		if ( ! Campaign_Cpt::get_default_form_id( $campaign_id ) ) {
			$new_form_id = Donation_Form::create_default_form_for_campaign( $campaign_id, $post_title );
			if ( $new_form_id ) {
				update_post_meta( $campaign_id, Campaign_Cpt::META_DEFAULT_FORM_ID, $new_form_id );
			}
		}

		return $campaign_id;
	}

	/**
	 * Build the preserved raw payload from a Charitable campaign's meta.
	 *
	 * @param  array<string, string> $meta Flat campaign meta map.
	 * @return array<string, mixed>
	 * @since  1.5.1
	 */
	private function build_raw_payload( $meta ) {
		$payload = [];

		// End date — '0'/empty means the campaign never ends.
		if ( ! empty( $meta['_campaign_end_date'] ) && '0' !== (string) $meta['_campaign_end_date'] ) {
			$payload['end_date'] = sanitize_text_field( (string) $meta['_campaign_end_date'] );
		}

		// Suggested donation amounts — a serialized list of amount/description pairs.
		if ( ! empty( $meta['_campaign_suggested_donations'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- hardened native unserialize with class instantiation disabled.
			$suggested = @unserialize( (string) $meta['_campaign_suggested_donations'], [ 'allowed_classes' => false ] );
			if ( is_array( $suggested ) ) {
				$clean = [];
				foreach ( $suggested as $entry ) {
					if ( ! is_array( $entry ) || ! isset( $entry['amount'] ) || ! is_numeric( $entry['amount'] ) ) {
						continue;
					}
					$clean[] = [
						'amount'      => (float) $entry['amount'],
						'description' => isset( $entry['description'] ) ? sanitize_text_field( (string) $entry['description'] ) : '',
					];
				}
				if ( ! empty( $clean ) ) {
					$payload['suggested_donations'] = $clean;
				}
			}
		}

		if ( isset( $meta['_campaign_suggested_donations_default'] ) && is_numeric( $meta['_campaign_suggested_donations_default'] ) ) {
			$payload['suggested_donations_default'] = (float) $meta['_campaign_suggested_donations_default'];
		}

		if ( isset( $meta['_campaign_allow_custom_donations'] ) ) {
			$payload['allow_custom_donations'] = ! empty( $meta['_campaign_allow_custom_donations'] );
		}

		// NOTE: the stored key is _campaign_minimum_donation_amount — the
		// Charitable class getter reads a different (never-written) key.
		if ( isset( $meta['_campaign_minimum_donation_amount'] ) && is_numeric( $meta['_campaign_minimum_donation_amount'] ) ) {
			$payload['minimum_donation_amount'] = (float) $meta['_campaign_minimum_donation_amount'];
		}

		if ( ! empty( $meta['_campaign_donate_button_text'] ) ) {
			$payload['donate_button_text'] = sanitize_text_field( (string) $meta['_campaign_donate_button_text'] );
		}

		foreach ( $meta as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_campaign_hide_' ) ) {
				$payload[ sanitize_key( substr( (string) $key, 10 ) ) ] = ! empty( $value );
			}
		}

		$payload['has_settings_v2'] = ! empty( $meta['campaign_settings_v2'] );

		return $payload;
	}

	/**
	 * Append an error entry, capped so a pathological source can't bloat the session.
	 *
	 * @param  array<int, array<string, mixed>> $error_log Existing log.
	 * @param  int                              $source_id Source row ID.
	 * @param  string                           $message   Error message.
	 * @return array<int, array<string, mixed>>
	 * @since  1.5.1
	 */
	private function append_error_log( $error_log, $source_id, $message ) {
		$error_log = is_array( $error_log ) ? $error_log : [];
		if ( count( $error_log ) >= 50 ) {
			return $error_log;
		}

		$error_log[] = [
			'source_id' => (int) $source_id,
			'message'   => sanitize_text_field( $message ),
		];

		return $error_log;
	}
}
