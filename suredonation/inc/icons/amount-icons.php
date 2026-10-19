<?php
/**
 * SureDonation Donation Amount option icons.
 *
 * @package SureDonation
 * @since 1.7.0
 */

namespace SureDonation\Inc\Icons;

use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The icon set an author can attach to a preset donation amount.
 *
 * One map serves both halves of the feature: this class renders it on the front
 * end, and the form editor receives the same array through the localized
 * `suredonationFormEditor` payload. A duplicated JS copy would let the canvas
 * preview and the rendered form drift apart.
 *
 * The stored geometry carries no presentation attributes. `render()` adds stroke
 * and fill, because `Helper::get_allowed_form_html()` rejects stroke attributes
 * on the `<svg>` root — they have to sit on each child instead.
 *
 * @since 1.7.0
 */
class Amount_Icons {
	/**
	 * Attributes kept per element, mirroring the kses allowlist in
	 * `Helper::get_allowed_form_html()`.
	 *
	 * This is the fail-closed half of the `suredonation_amount_icons` filter: an
	 * extension can add icons, but anything it puts in that kses would strip is
	 * dropped here first, so the markup this class returns always survives both
	 * kses passes intact.
	 *
	 * @since 1.7.0
	 */
	private const ALLOWED_ATTRS = [
		'path'   => [ 'd' ],
		'circle' => [ 'cx', 'cy', 'r' ],
		'rect'   => [ 'x', 'y', 'width', 'height', 'rx' ],
	];

	/**
	 * Memoized icon map, so the data file is read and filtered once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 * @since 1.7.0
	 */
	private static $icons = null;

	/**
	 * The full icon map, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> Slug => [ label, group, nodes ].
	 * @since 1.7.0
	 */
	public static function get_icons() {
		if ( null !== self::$icons ) {
			return self::$icons;
		}

		// require, not require_once: the data file returns an array, and a second
		// load (after the memo is reset, as the tests do) must return it again
		// rather than the `true` that require_once gives for an already-loaded file.
		$icons = require __DIR__ . '/amount-icons-data.php';

		/**
		 * Filter the icons offered for each preset donation amount.
		 *
		 * Each entry is `[ 'label' => string, 'group' => string, 'nodes' => array ]`,
		 * and each node is `[ 'tag' => 'path'|'circle'|'rect', 'attrs' => [...] ]`
		 * holding geometry only — no stroke or fill, which `render()` supplies.
		 *
		 * Tags and attributes outside that set are dropped at render time rather
		 * than reaching `wp_kses()`, so an added icon that uses, say, `<line>`
		 * renders as nothing instead of as a broken shape.
		 *
		 * Resolved once per request and memoized, so register on `init` or
		 * earlier; a callback added after the first read never applies.
		 *
		 * @since 1.7.0
		 * @param array<string, array<string, mixed>> $icons Icon map keyed by slug.
		 */
		$icons = apply_filters( 'suredonation_amount_icons', $icons );

		self::$icons = is_array( $icons ) ? $icons : [];

		return self::$icons;
	}

	/**
	 * Whether a slug names an icon that exists.
	 *
	 * @param string $slug Icon slug.
	 * @return bool
	 * @since 1.7.0
	 */
	public static function has( $slug ) {
		// Deliberately defined as "render() would draw something" rather than
		// "the key exists": a filter can add an entry whose nodes are all
		// unusable, and a caller gating a wrapper on this would then emit an
		// empty element.
		return '' !== self::render( $slug );
	}

	/**
	 * Render an icon as inline SVG.
	 *
	 * Returns '' for an unknown slug, so a form whose icon was removed by a
	 * filter (or saved against an older set) simply shows no icon rather than
	 * breaking the option.
	 *
	 * @param string $slug    Icon slug.
	 * @param string $classes Class attribute for the <svg>.
	 * @return string SVG markup, or '' when there is nothing to draw.
	 * @since 1.7.0
	 */
	public static function render( $slug, $classes = '' ) {
		$slug = Helper::get_string_value( $slug );

		// Answered before get_icons(), because the overwhelmingly common case is
		// a form with no icons at all, and there is no reason for those renders
		// to load and filter the whole data file.
		if ( '' === $slug ) {
			return '';
		}

		$icons = self::get_icons();

		if ( ! isset( $icons[ $slug ]['nodes'] ) || ! is_array( $icons[ $slug ]['nodes'] ) ) {
			return '';
		}

		$children = '';
		foreach ( $icons[ $slug ]['nodes'] as $node ) {
			$children .= self::render_node( $node );
		}

		if ( '' === $children ) {
			return '';
		}

		// `fill="none"` is load-bearing, not cosmetic: `rect` has no `fill` in the
		// kses allowlist, so a rect that had to carry its own would fall back to
		// SVG's default black and paint a solid box. On the root it inherits into
		// every child, and survives kses because `svg` does allow `fill`.
		return sprintf(
			'<svg%1$s width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">%2$s</svg>',
			$classes ? ' class="' . esc_attr( $classes ) . '"' : '',
			$children
		);
	}

	/**
	 * Render one geometry node.
	 *
	 * @param mixed $node Node entry from the icon map.
	 * @return string Element markup, or '' when the node is unusable.
	 * @since 1.7.0
	 */
	private static function render_node( $node ) {
		if ( ! is_array( $node ) ) {
			return '';
		}

		$tag = Helper::get_string_value( $node['tag'] ?? '' );
		if ( ! isset( self::ALLOWED_ATTRS[ $tag ] ) || ! is_array( $node['attrs'] ?? null ) ) {
			return '';
		}

		$markup = '<' . $tag;
		$drawn  = false;

		foreach ( self::ALLOWED_ATTRS[ $tag ] as $name ) {
			if ( ! isset( $node['attrs'][ $name ] ) ) {
				continue;
			}

			$value = Helper::get_string_value( $node['attrs'][ $name ] );
			if ( '' === $value ) {
				continue;
			}

			$markup .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			$drawn   = true;
		}

		if ( ! $drawn ) {
			return '';
		}

		// Presentation lives here rather than in the data so the whole set can be
		// restyled in one place, and so the generated file stays pure geometry.
		$markup .= ' stroke="currentColor" stroke-width="2"';

		// `stroke-linecap` and `stroke-linejoin` are allowed on `path` only — kses
		// drops them from `circle` and `rect`, where they would mean nothing anyway.
		if ( 'path' === $tag ) {
			$markup .= ' stroke-linecap="round" stroke-linejoin="round"';
		}

		return $markup . ' />';
	}
}
