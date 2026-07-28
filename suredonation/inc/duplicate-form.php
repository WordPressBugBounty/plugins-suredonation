<?php
/**
 * SureDonation form duplication.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc;

use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicate Form Class.
 *
 * @since 0.0.1
 */
class Duplicate_Form {
	use Get_Instance;

	/**
	 * Duplicate a form with all its metadata.
	 *
	 * @param int    $form_id      Form ID to duplicate.
	 * @param string $title_suffix Suffix to append to title. Default ' (Copy)'.
	 * @return array<string, mixed>|\WP_Error Result with new form ID or error.
	 * @since 0.0.1
	 */
	public function duplicate_form( $form_id, $title_suffix = ' (Copy)' ) {
		$form_id = intval( $form_id );
		if ( $form_id <= 0 ) {
			return new \WP_Error(
				'invalid_form_id',
				__( 'Invalid form ID provided.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$source_form = get_post( $form_id );

		if ( ! $source_form ) {
			return new \WP_Error(
				'form_not_found',
				__( 'Source form not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		if ( Donation_Form::POST_TYPE !== $source_form->post_type ) {
			return new \WP_Error(
				'invalid_post_type',
				__( 'The specified post is not a donation form.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$post_meta = get_post_meta( $form_id );
		$new_title = $this->generate_unique_title( $source_form->post_title, $title_suffix );

		// wp_insert_post() internally calls wp_unslash() which removes backslashes.
		// Use wp_slash() to preserve unicode escapes in block attributes.
		$new_post_args = [
			'post_title'   => $new_title,
			'post_content' => wp_slash( $source_form->post_content ),
			'post_status'  => 'draft',
			'post_type'    => Donation_Form::POST_TYPE,
			'post_author'  => get_current_user_id(),
		];

		$new_form_id = wp_insert_post( $new_post_args );

		if ( ! is_int( $new_form_id ) || $new_form_id <= 0 ) {
			return new \WP_Error(
				'duplication_failed',
				__( 'Failed to create duplicate form.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		// Update formId in Gutenberg blocks.
		$updated_content = $this->update_block_form_ids( $source_form->post_content, $form_id, $new_form_id );

		wp_update_post(
			[
				'ID'           => $new_form_id,
				'post_content' => wp_slash( $updated_content ),
			]
		);

		// Copy all post meta.
		if ( is_array( $post_meta ) ) {
			foreach ( $post_meta as $meta_key => $meta_values ) {
				if ( ! is_string( $meta_key ) ) {
					continue;
				}

				// Skip WordPress internal meta keys.
				if ( '_edit_lock' === $meta_key || '_edit_last' === $meta_key ) {
					continue;
				}

				if ( is_array( $meta_values ) && isset( $meta_values[0] ) ) {
					$raw_meta_value = $meta_values[0];
					// The is_serialized() guard preserves maybe_unserialize()'s pass-through for non-serialized values.
					// Serialized objects are intentionally NOT rehydrated (allowed_classes=false): object-valued meta would
					// copy as __PHP_Incomplete_Class, but suredonation_form meta is only arrays/scalars/JSON, so none exists.
					if ( is_serialized( $raw_meta_value ) ) {
						// allowed_classes=false blocks PHP object injection (CWE-502) when rehydrating copied meta; @ suppresses notices on malformed input.
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- hardened native unserialize with class instantiation disabled.
						$meta_value = @unserialize( $raw_meta_value, [ 'allowed_classes' => false ] );
					} else {
						$meta_value = $raw_meta_value;
					}
					add_post_meta( $new_form_id, $meta_key, $meta_value );
				}
			}
		}

		/**
		 * Fires after a donation form has been duplicated.
		 *
		 * @param int $new_form_id New form ID.
		 * @param int $form_id     Original form ID.
		 * @since 0.0.1
		 */
		do_action( 'suredonation_after_form_duplicated', $new_form_id, $form_id );

		$edit_url = admin_url( 'post.php?post=' . $new_form_id . '&action=edit' );

		return [
			'success'          => true,
			'original_form_id' => $form_id,
			'new_form_id'      => $new_form_id,
			'new_form_title'   => $new_title,
			'edit_url'         => $edit_url,
		];
	}

	/**
	 * Handle duplicate form REST API request.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error on failure.
	 * @since 0.0.1
	 */
	public function handle_duplicate_form_rest( $request ) {
		$form_id      = absint( $request->get_param( 'form_id' ) );
		$title_suffix = sanitize_text_field( $request->get_param( 'title_suffix' ) );

		$result = $this->duplicate_form( $form_id, $title_suffix );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Generate unique title by appending suffix.
	 *
	 * @param string $base_title Original form title.
	 * @param string $suffix     Suffix to append.
	 * @return string Unique title.
	 * @since 0.0.1
	 */
	private function generate_unique_title( $base_title, $suffix = ' (Copy)' ) {
		$new_title = $base_title . $suffix;
		$counter   = 2;

		while ( $this->title_exists( $new_title ) ) {
			$new_title = $base_title . $suffix . ' ' . $counter;
			++$counter;
		}

		return $new_title;
	}

	/**
	 * Check if a form title already exists.
	 *
	 * @param string $title Title to check.
	 * @return bool True if title exists.
	 * @since 0.0.1
	 */
	private function title_exists( $title ) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status != 'trash' LIMIT 1",
			$title,
			Donation_Form::POST_TYPE
		);

		$existing = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return ! empty( $existing );
	}

	/**
	 * Update formId in Gutenberg blocks.
	 *
	 * @param string $content Post content with blocks.
	 * @param int    $old_id  Original form ID.
	 * @param int    $new_id  New form ID.
	 * @return string Updated content.
	 * @since 0.0.1
	 */
	private function update_block_form_ids( $content, $old_id, $new_id ) {
		return str_replace(
			'"formId":' . intval( $old_id ),
			'"formId":' . intval( $new_id ),
			$content
		);
	}
}
