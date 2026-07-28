<?php
/**
 * Campaign Meta Tags
 *
 * Outputs Open Graph + Twitter Card meta tags on singular campaign pages so a
 * shared campaign URL renders a rich social preview (title, description, image).
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Campaigns;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign_Meta_Tags class.
 *
 * @since 1.2.0
 */
class Campaign_Meta_Tags {
	use Get_Instance;

	/**
	 * Constructor — register the head output.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		// Priority 5 so the tags sit early in <head>, before most theme output.
		add_action( 'wp_head', [ $this, 'print_meta_tags' ], 5 );
	}

	/**
	 * Print Open Graph + Twitter Card tags for the current campaign.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function print_meta_tags() {
		if ( is_admin() || ! is_singular( Campaign_Cpt::POST_TYPE ) ) {
			return;
		}

		$campaign_id = get_queried_object_id();
		if ( ! $campaign_id || 'publish' !== get_post_status( $campaign_id ) ) {
			return;
		}

		// A password-protected post keeps 'publish' status — the password gates the
		// content, not the status. get_description() reads the raw excerpt/body, so
		// emitting tags here would leak protected copy to anonymous visitors/crawlers.
		if ( post_password_required( $campaign_id ) ) {
			return;
		}

		// Skip when an SEO plugin already emits Open Graph tags, to avoid duplicates.
		if ( self::seo_plugin_active() ) {
			return;
		}

		// Same for themes that emit their own Open Graph set (Bricks prints
		// og:* on wp_head at priority 99999 — after us, so we can't inspect
		// its output; we mirror its own emit condition instead).
		if ( self::theme_emits_og() ) {
			return;
		}

		/**
		 * Filter whether SureDonation outputs social meta tags for this campaign.
		 *
		 * @since 1.2.0
		 * @param bool $enabled     Whether to output the tags.
		 * @param int  $campaign_id Campaign post ID.
		 */
		if ( ! apply_filters( 'suredonation_output_campaign_meta_tags', true, $campaign_id ) ) {
			return;
		}

		$title       = wp_strip_all_tags( (string) get_the_title( $campaign_id ) );
		$url         = (string) get_permalink( $campaign_id );
		$description = self::get_description( $campaign_id );
		$site_name   = (string) get_bloginfo( 'name' );
		$image       = self::get_image( $campaign_id );

		echo "\n<!-- SureDonation campaign social preview -->\n";

		self::tag( 'property', 'og:type', 'article' );
		if ( '' !== $site_name ) {
			self::tag( 'property', 'og:site_name', $site_name );
		}
		self::tag( 'property', 'og:title', $title );
		if ( '' !== $description ) {
			self::tag( 'property', 'og:description', $description );
		}
		self::tag( 'property', 'og:url', $url, true );

		if ( '' !== $image['url'] ) {
			self::tag( 'property', 'og:image', $image['url'], true );
			// Only emit dimensions when they're meaningful. SVGs (and some
			// scalable sources) report 1x1 in WordPress, and advertising a 1x1
			// image makes scrapers skip it.
			if ( $image['width'] > 1 && $image['height'] > 1 ) {
				self::tag( 'property', 'og:image:width', (string) $image['width'] );
				self::tag( 'property', 'og:image:height', (string) $image['height'] );
			}
			self::tag( 'property', 'og:image:alt', $title );
		}

		self::tag( 'name', 'twitter:card', '' !== $image['url'] ? 'summary_large_image' : 'summary' );
		self::tag( 'name', 'twitter:title', $title );
		if ( '' !== $description ) {
			self::tag( 'name', 'twitter:description', $description );
		}
		if ( '' !== $image['url'] ) {
			self::tag( 'name', 'twitter:image', $image['url'], true );
		}

		echo "<!-- /SureDonation campaign social preview -->\n";
	}

	/**
	 * Print a single escaped meta tag.
	 *
	 * @since 1.2.0
	 * @param string $attr    Attribute name: 'property' (Open Graph) or 'name' (Twitter).
	 * @param string $key     Meta key (e.g. 'og:title').
	 * @param string $content Tag content.
	 * @param bool   $is_url  Whether $content is a URL (uses esc_url).
	 * @return void
	 */
	private static function tag( $attr, $key, $content, $is_url = false ) {
		if ( '' === $content ) {
			return;
		}

		printf(
			'<meta %1$s="%2$s" content="%3$s" />' . "\n",
			'name' === $attr ? 'name' : 'property',
			esc_attr( $key ),
			$is_url ? esc_url( $content ) : esc_attr( $content )
		);
	}

	/**
	 * Resolve the preview description: excerpt, then a trimmed content fallback,
	 * then the site tagline.
	 *
	 * @since 1.2.0
	 * @param int $campaign_id Campaign post ID.
	 * @return string
	 */
	private static function get_description( $campaign_id ) {
		$excerpt = trim( wp_strip_all_tags( Helper::get_string_value( get_post_field( 'post_excerpt', $campaign_id ) ) ) );
		if ( '' !== $excerpt ) {
			return $excerpt;
		}

		// Campaign content is mostly dynamic blocks, so stripped content is often
		// empty; only use it when it yields real text.
		$content = trim( wp_strip_all_tags( Helper::get_string_value( get_post_field( 'post_content', $campaign_id ) ) ) );
		if ( '' !== $content ) {
			return wp_trim_words( $content, 30 );
		}

		$tagline = trim( (string) get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			return $tagline;
		}

		// Guaranteed non-empty default so the preview card is never bare.
		return __( 'Support this fundraising campaign and help make a difference.', 'suredonation' );
	}

	/**
	 * Resolve the preview image: the campaign's featured image, else the site icon.
	 *
	 * @since 1.2.0
	 * @param int $campaign_id Campaign post ID.
	 * @return array{url:string,width:int,height:int}
	 */
	private static function get_image( $campaign_id ) {
		$thumbnail_id = get_post_thumbnail_id( $campaign_id );
		if ( $thumbnail_id ) {
			$src = wp_get_attachment_image_src( (int) $thumbnail_id, 'large' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return [
					'url'    => Helper::get_string_value( $src[0] ),
					'width'  => isset( $src[1] ) ? absint( Helper::get_string_value( $src[1] ) ) : 0,
					'height' => isset( $src[2] ) ? absint( Helper::get_string_value( $src[2] ) ) : 0,
				];
			}
		}

		$icon = (string) get_site_icon_url( 512 );
		return [
			'url'    => $icon,
			'width'  => '' !== $icon ? 512 : 0,
			'height' => '' !== $icon ? 512 : 0,
		];
	}

	/**
	 * Whether the active theme will emit its own Open Graph tags.
	 *
	 * Currently detects Bricks, which prints a full og:* set on wp_head at
	 * priority 99999 unless its disableOpenGraph setting (or the
	 * bricks/frontend/disable_opengraph filter) turns it off, or maintenance
	 * mode is active. We defer only when Bricks will actually emit — a Bricks
	 * site with OG output disabled still gets our tags. Deferral covers our
	 * twitter:* tags too: X falls back to og:*, and emitting only twitter
	 * cards would risk a conflicting title/image against the theme's og set.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function theme_emits_og() {
		if ( ! class_exists( '\Bricks\Database' ) ) {
			return false;
		}

		if ( class_exists( '\Bricks\Maintenance' ) && \Bricks\Maintenance::get_mode() ) {
			return false;
		}

		// Mirror Bricks' own gate expression (themes/bricks/includes/frontend.php).
		$disabled = apply_filters( 'bricks/frontend/disable_opengraph', ! empty( \Bricks\Database::$global_settings['disableOpenGraph'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Third-party (Bricks) hook name, evaluated read-only.

		return ! $disabled;
	}

	/**
	 * Whether a known SEO plugin that emits Open Graph tags is active.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )                 // Yoast SEO.
			|| class_exists( 'RankMath' )                 // Rank Math.
			|| function_exists( 'aioseo' )                // All in One SEO.
			|| defined( 'SEOPRESS_VERSION' )              // SEOPress.
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' )     // The SEO Framework.
			|| defined( 'SLIM_SEO_VER' )                  // Slim SEO.
			|| class_exists( 'Jetpack' );                 // Jetpack (Open Graph module).
	}
}
