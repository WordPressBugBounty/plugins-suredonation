<?php
/**
 * Page Builders bootstrap.
 *
 * Wires up SureDonation's page-builder integrations (Elementor and Bricks) and
 * exposes the shared post-list helpers their controls use.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders;

use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Page_Builders\Bricks\Service_Provider as Bricks_Service_Provider;
use SureDonation\Inc\Page_Builders\Elementor\Service_Provider as Elementor_Service_Provider;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Page_Builders class.
 *
 * @since 1.2.0
 */
class Page_Builders {
	use Get_Instance;

	/**
	 * Constructor — boot each page-builder provider (they self-gate on the
	 * builder being active).
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		Elementor_Service_Provider::get_instance();
		Bricks_Service_Provider::get_instance();
	}

	/**
	 * Published campaigns as a `[ id => "Title #id" ]` map for builder controls.
	 *
	 * @since 1.2.0
	 * @return array<int|string, string>
	 */
	public static function get_campaign_options() {
		return self::get_post_options(
			Campaign_Cpt::POST_TYPE,
			__( 'Select a campaign', 'suredonation' ),
			'suredonation_page_builder_campaign_options'
		);
	}

	/**
	 * Published donation forms as a `[ id => "Title" ]` map for builder controls.
	 *
	 * Labels are the bare form title — no `#id` suffix (an untitled form still
	 * falls back to `(no title) #id` so it stays identifiable).
	 *
	 * @since 1.2.0
	 * @return array<int|string, string>
	 */
	public static function get_donation_form_options() {
		return self::get_post_options(
			Donation_Form::POST_TYPE,
			__( 'Select a donation form', 'suredonation' ),
			'suredonation_page_builder_form_options',
			false
		);
	}

	/**
	 * Published donation forms linked to a single campaign as a
	 * `[ id => "Title" ]` map for builder controls.
	 *
	 * Mirrors the Gutenberg donation-form block, whose form selector is scoped to
	 * the chosen campaign (REST `/forms?campaign_id=`). Builders without a native
	 * dependent-dropdown (Elementor free) register one such control per campaign
	 * and gate it on the campaign selection to reproduce that behaviour.
	 *
	 * @since 1.2.0
	 * @param int $campaign_id Campaign whose forms to list.
	 * @return array<int|string, string>
	 */
	public static function get_campaign_form_options( $campaign_id ) {
		return self::format_post_options(
			Donation_Form::get_forms_by_campaign( $campaign_id ),
			__( 'Select a donation form', 'suredonation' ),
			false
		);
	}

	/**
	 * Build a `[ id => label ]` options map of published posts of a type, with a
	 * leading empty "select" entry.
	 *
	 * @since 1.2.0
	 * @param string           $post_type   Post type slug.
	 * @param string           $placeholder Leading empty-option label.
	 * @param non-empty-string $filter      Filter applied to the query args.
	 * @param bool             $append_id   Whether to append `#id` to each label (disambiguates duplicate titles).
	 * @return array<int|string, string>
	 */
	private static function get_post_options( $post_type, $placeholder, $filter, $append_id = true ) {
		$query_args = [
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		// $filter is one of this plugin's own prefixed hook names (suredonation_page_builder_*).
		$filtered = apply_filters( $filter, $query_args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

		// House convention: never trust a filter's return shape blindly.
		$query_args = is_array( $filtered ) ? $filtered : $query_args;

		return self::format_post_options( get_posts( $query_args ), $placeholder, $append_id );
	}

	/**
	 * Turn a list of posts into a `[ id => label ]` options map with a leading
	 * empty "select" entry.
	 *
	 * @since 1.2.0
	 * @param array<int, \WP_Post|mixed> $posts       Posts to format.
	 * @param string                     $placeholder Leading empty-option label.
	 * @param bool                       $append_id   Whether to append `#id` to each label (disambiguates duplicate titles).
	 * @return array<int|string, string>
	 */
	private static function format_post_options( array $posts, $placeholder, $append_id ) {
		$options = [ '' => $placeholder ];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			// Decode entities (wptexturize turns "-" into &#8211; via get_the_title)
			// — builder controls escape option labels, so entities would render
			// literally in the dropdown.
			$title = trim( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $title ) {
				/* translators: %d: post ID. */
				$title = sprintf( __( '(no title) #%d', 'suredonation' ), $post->ID );

				// The fallback already carries the id — never double it up.
				$options[ $post->ID ] = $title;
				continue;
			}
			$options[ $post->ID ] = $append_id ? sprintf( '%1$s #%2$d', $title, $post->ID ) : $title;
		}

		return $options;
	}
}
