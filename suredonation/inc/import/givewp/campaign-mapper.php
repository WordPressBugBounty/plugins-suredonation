<?php
/**
 * Campaign mapper for the GiveWP migration tool.
 *
 * Translates GiveWP forms (give_forms CPT) into SureDonation campaigns
 * (suredonation_cmpgn CPT) and creates a default donation form for each.
 * Imports campaigns as `publish` (post_status) with `campaign_status =
 * active` so the donor-facing campaign page is immediately reachable
 * after migration — admins can still flip an individual campaign back
 * to draft from the editor if they want to review before going live.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Import\Givewp;

use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Campaign_Mapper class.
 *
 * @since 1.0.0
 */
class Campaign_Mapper {
	use Get_Instance;

	/**
	 * Post meta key that stores the GiveWP source ID on imported campaigns.
	 * Used both for duplicate detection and for Pro rollback.
	 */
	const META_SOURCE_ID = '_suredonation_givewp_source_id';

	/**
	 * Post meta key that ties an imported campaign to its migration session.
	 * Used by Pro rollback to scope deletion to a single import.
	 */
	const META_IMPORT_ID = '_suredonation_givewp_import_id';

	/**
	 * Post meta key on the GiveWP form recording the SureDonation campaign ID.
	 * Used by Pro rollback to clear the link if the campaign gets removed.
	 */
	const META_GIVEWP_MARKER = '_suredonation_imported_to_campaign_id';

	/**
	 * Process a batch of GiveWP forms.
	 *
	 * @param  array<string,mixed> $progress Session progress (passed by reference).
	 * @param  int                 $offset   Current offset within this phase.
	 * @return int Number of source rows processed in this batch.
	 * @since  1.0.0
	 */
	public function process_batch( &$progress, $offset ) {
		$source   = Source::get_instance();
		$form_ids = isset( $progress['options']['campaign_ids'] ) && is_array( $progress['options']['campaign_ids'] )
			? $progress['options']['campaign_ids']
			: [];
		$forms    = $source->get_forms_batch( (int) $offset, Importer::BATCH_SIZE, $form_ids );

		if ( empty( $forms ) ) {
			return 0;
		}

		foreach ( $forms as $form ) {
			$give_form_id = isset( $form->ID ) ? (int) $form->ID : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP $wpdb->posts row.
			if ( $give_form_id <= 0 ) {
				++$progress['results']['campaigns']['errors'];
				continue;
			}

			$existing_id = $this->find_existing_campaign( $give_form_id );

			if ( $existing_id > 0 ) {
				++$progress['results']['campaigns']['skipped'];
				$progress['campaign_map'][ $give_form_id ] = $existing_id;
				continue;
			}

			try {
				$result = $this->insert_campaign( $form, $progress );
			} catch ( \Throwable $t ) {
				++$progress['results']['campaigns']['errors'];
				$progress['results']['campaigns']['error_log'] = $this->append_error_log(
					$progress['results']['campaigns']['error_log'],
					$give_form_id,
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
					$give_form_id,
					$result->get_error_message()
				);
				continue;
			}

			$progress['campaign_map'][ $give_form_id ] = (int) $result;
			++$progress['results']['campaigns']['imported'];
		}

		return count( $forms );
	}

	/**
	 * Find an existing SureDonation campaign for a given GiveWP form ID.
	 *
	 * @param  int $give_form_id GiveWP form ID.
	 * @return int Campaign ID or 0 if none.
	 * @since  1.0.0
	 */
	private function find_existing_campaign( $give_form_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scope, one-shot lookup per form.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_SOURCE_ID,
				(string) (int) $give_form_id
			)
		);

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}

	/**
	 * Insert a single SureDonation campaign from a GiveWP form row.
	 *
	 * Migrates title, description (short + long), goal amount, goal type,
	 * dates, brand colors, hero image, and campaign-page URL.
	 *
	 * GiveWP 4 stores rich campaign data in the `wp_give_campaigns`
	 * custom table keyed by `form_id`. GiveWP 3.x and earlier kept the
	 * goal in `_give_set_goal` post meta. Both paths are handled.
	 *
	 * @param  object $form     GiveWP wp_posts row.
	 * @param  array  $progress Current progress (used for the import_id).
	 * @return int|\WP_Error New campaign ID on success.
	 * @since  1.0.0
	 */
	private function insert_campaign( $form, $progress ) {
		$source = Source::get_instance();

		$form_id       = isset( $form->ID ) ? (int) $form->ID : 0;
		$post_title    = isset( $form->post_title ) ? (string) $form->post_title : '';
		$post_content  = isset( $form->post_content ) ? (string) $form->post_content : '';
		$post_author   = isset( $form->post_author ) ? (int) $form->post_author : get_current_user_id();
		$post_date     = isset( $form->post_date ) ? (string) $form->post_date : current_time( 'mysql' );
		$post_date_gmt = isset( $form->post_date_gmt ) ? (string) $form->post_date_gmt : current_time( 'mysql', true );

		// Prefer the GiveWP 4 campaign row when it exists.
		$campaign = $source->get_campaign_for_form( $form_id );

		$long_desc  = '';
		$short_desc = '';
		if ( is_array( $campaign ) ) {
			if ( ! empty( $campaign['campaign_title'] ) ) {
				$post_title = (string) $campaign['campaign_title'];
			}
			$long_desc  = self::clean_description(
				isset( $campaign['long_desc'] ) ? $campaign['long_desc'] : ''
			);
			$short_desc = self::clean_description(
				isset( $campaign['short_desc'] ) ? $campaign['short_desc'] : ''
			);
			if ( ! empty( $campaign['date_created'] ) ) {
				$post_date = (string) $campaign['date_created'];
			}
		}

		// The campaign description is stored as the excerpt so post_content stays
		// reserved for the seeded SureDonation page layout — imported campaigns
		// then auto-seed the goal/stats/donate blocks on first publish (the
		// description appears as a paragraph within that layout). GiveWP 4 keeps
		// the body in `short_desc`; prefer a populated `long_desc` (legacy/v3),
		// fall back to `short_desc`, then to the original form body.
		$post_excerpt = $post_content;
		if ( '' !== $long_desc ) {
			$post_excerpt = $long_desc;
		} elseif ( '' !== $short_desc ) {
			$post_excerpt = $short_desc;
		}
		$post_content = '';

		// GiveWP descriptions are rich HTML, but the excerpt is consumed as
		// plain text (the seeded layout escapes it into a paragraph, the admin
		// drawer edits it in a textarea) — strip the markup so it doesn't
		// render as escaped entity soup. This also keeps unfiltered GiveWP
		// markup from being stored under the importing admin's
		// unfiltered_html context.
		$post_excerpt = trim( wp_strip_all_tags( $post_excerpt ) );

		if ( '' === $post_title ) {
			$post_title = sprintf(
				/* translators: %d: GiveWP form ID */
				__( 'Imported campaign %d', 'suredonation' ),
				$form_id
			);
		}

		// The imported HTML was authored inside GiveWP (possibly by
		// lower-privileged roles), so sanitize it regardless of the
		// importing user's unfiltered_html capability — wp_insert_post()
		// would otherwise store it raw for admins.
		$post_content = wp_kses_post( $post_content );
		$post_excerpt = wp_kses_post( $post_excerpt );

		$campaign_id = wp_insert_post(
			[
				'post_title'    => $post_title,
				'post_content'  => $post_content,
				'post_excerpt'  => $post_excerpt,
				'post_status'   => 'publish',
				'post_type'     => Campaign_Cpt::POST_TYPE,
				'post_author'   => $post_author,
				'post_date'     => $post_date,
				'post_date_gmt' => $post_date_gmt,
			],
			true
		);

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$campaign_id = (int) $campaign_id;

		// Provenance meta for duplicate detection + rollback scoping.
		update_post_meta( $campaign_id, self::META_SOURCE_ID, $form_id );
		if ( ! empty( $progress['import_id'] ) ) {
			update_post_meta( $campaign_id, self::META_IMPORT_ID, (string) $progress['import_id'] );
		}

		// Reverse pointer on the GiveWP form so a rollback can clear it cleanly.
		update_post_meta( $form_id, self::META_GIVEWP_MARKER, $campaign_id );

		// Resolve goal amount + type. Prefer GiveWP 4 campaign row, fall
		// back to legacy `_give_set_goal` post meta.
		$goal_amount = 0;
		$goal_type   = 'raised_amount';
		if ( is_array( $campaign ) ) {
			if ( isset( $campaign['campaign_goal'] ) && is_numeric( $campaign['campaign_goal'] ) ) {
				$goal_amount = (int) $campaign['campaign_goal'];
			}
			if ( ! empty( $campaign['goal_type'] ) ) {
				$goal_type = $this->map_goal_type( (string) $campaign['goal_type'] );
			}
		} else {
			$legacy_goal = get_post_meta( $form_id, '_give_set_goal', true );
			if ( '' !== $legacy_goal && is_numeric( $legacy_goal ) ) {
				$goal_amount = (int) $legacy_goal;
			}
		}

		// Write to SureDonation's canonical _suredonation_campaign_meta
		// JSON blob via Helper::update_campaign_meta. This is the key the
		// All Campaigns UI reads (raised vs. goal display, status checks,
		// fee-recovery toggle). Writing to a bare _campaign_goal post
		// meta is a no-op as far as SureDonation is concerned.
		Helper::update_campaign_meta(
			$campaign_id,
			[
				'goal_amount'     => $goal_amount,
				'goal_type'       => $goal_type,
				'campaign_status' => 'active',
			]
		);

		// Preserve the rest of the GiveWP 4 campaign row in JSON meta so
		// admin UI can surface dates, colors, hero image, etc. (we don't
		// have first-class columns for all of them yet).
		if ( is_array( $campaign ) ) {
			$payload = [];
			foreach ( [ 'goal_type', 'campaign_type', 'primary_color', 'secondary_color', 'start_date', 'end_date', 'campaign_logo', 'campaign_image', 'campaign_url', 'campaign_page_id', 'status' ] as $field ) {
				if ( isset( $campaign[ $field ] ) && '' !== (string) $campaign[ $field ] ) {
					$payload[ $field ] = is_numeric( $campaign[ $field ] )
						? (int) $campaign[ $field ]
						: sanitize_text_field( (string) $campaign[ $field ] );
				}
			}
			if ( ! empty( $payload ) ) {
				update_post_meta( $campaign_id, '_suredonation_givewp_campaign', wp_json_encode( $payload ) );
			}
		}

		// Default form auto-creation. Restored in response to user feedback —
		// having no form is worse than a basic SureDonation template the
		// admin can edit. The template doesn't carry GiveWP's donation
		// levels / custom fields / branding (migrating GiveWP form
		// structure into a SureDonation Gutenberg block tree is a separate
		// piece of work) — admins should expect to revise it.
		if ( ! Campaign_Cpt::get_default_form_id( $campaign_id ) ) {
			$new_form_id = Donation_Form::create_default_form_for_campaign( $campaign_id, $post_title );
			if ( $new_form_id ) {
				update_post_meta( $campaign_id, Campaign_Cpt::META_DEFAULT_FORM_ID, $new_form_id );
			}
		}

		return $campaign_id;
	}

	/**
	 * Map a GiveWP goal_type to a SureDonation goal_type.
	 *
	 * GiveWP supports: amount / donations / donors / amountFromSubscriptions.
	 * SureDonation supports: raised_amount / donation_count.
	 *
	 * @param  string $give_goal_type GiveWP goal_type column value.
	 * @return string SureDonation goal_type enum value.
	 * @since  1.0.0
	 */
	private function map_goal_type( $give_goal_type ) {
		switch ( strtolower( $give_goal_type ) ) {
			case 'donations':
			case 'donors':
				return 'donation_count';
			case 'amount':
			case 'amountfromsubscriptions':
			default:
				return 'raised_amount';
		}
	}

	/**
	 * Normalise a GiveWP campaign description value before writing it
	 * to a SureDonation post field.
	 *
	 * On GiveWP 4 the `short_desc` column on `give_campaigns` holds the
	 * actual campaign body (rich-text block-editor output) while
	 * `long_desc` is typically empty. Empty fields are stored as the
	 * JSON-empty-array literal `"[]"` (and occasionally `"{}"`), which
	 * would land in `post_content` / `post_excerpt` verbatim if we
	 * passed it through. Treat such placeholders (along with
	 * whitespace-only and non-strings) as an empty value so the
	 * caller's fallback (e.g. the give_forms post_content) applies
	 * instead.
	 *
	 * @param  mixed $value Raw column value.
	 * @return string Cleaned description, or empty string when the
	 *                source held a placeholder.
	 * @since  1.0.0
	 */
	private static function clean_description( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$trimmed = trim( (string) $value );
		if ( '' === $trimmed || '[]' === $trimmed || '{}' === $trimmed ) {
			return '';
		}
		return $trimmed;
	}

	/**
	 * Append an entry to the error log array, capping at 50 entries to keep the transient bounded.
	 *
	 * @param  array  $log         Existing error log.
	 * @param  int    $source_id   GiveWP source ID that failed.
	 * @param  string $message     Error message.
	 * @return array Updated log.
	 * @since  1.0.0
	 */
	private function append_error_log( $log, $source_id, $message ) {
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		$log[] = [
			'source_id' => (int) $source_id,
			'message'   => (string) $message,
		];
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}
		return $log;
	}
}
