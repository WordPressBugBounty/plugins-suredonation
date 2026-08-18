<?php
/**
 * Per-form styling helper for donation forms.
 *
 * Reads the per-form styling meta and converts it into CSS custom properties
 * applied inline on the `.sd-form-container` wrapper. Only values the user set
 * are emitted; everything else falls back to the defaults in
 * src/blocks/styles/_variables.scss.
 *
 * @package SureDonation
 * @since 1.0.0
 */

namespace SureDonation\Inc\Fields;

use SureDonation\Inc\Post_Types\Donation_Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Form_Styling class.
 *
 * @since 1.0.0
 */
class Form_Styling {

	/**
	 * Field-spacing presets. Each sets the full density variable set (matching
	 * SureForms' field-spacing scaling). "medium" mirrors the :root defaults in
	 * src/blocks/styles/_variables.scss, so it is never emitted (defaults apply).
	 *
	 * Kept in sync with SPACING_MAP in src/editor/form-style-vars.js (editor
	 * preview) — update both together.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SPACING_MAP = [
		'small' => [
			'--sd-row-gap-between-blocks'              => '16px',
			'--sd-column-gap-between-blocks'           => '12px',
			'--sd-col-gap-between-fields'              => '12px',
			'--sd-input-height'                        => '40px',
			'--sd-input-field-padding'                 => '10px 12px',
			'--sd-input-field-font-size'               => '14px',
			'--sd-input-field-line-height'             => '20px',
			'--sd-input-field-margin-top'              => '4px',
			'--sd-input-field-margin-bottom'           => '4px',
			'--sd-label-font-size'                     => '14px',
			'--sd-label-line-height'                   => '20px',
			'--sd-description-font-size'               => '12px',
			'--sd-description-line-height'             => '16px',
			'--sd-btn-padding'                         => '8px 14px',
			'--sd-btn-font-size'                       => '14px',
			'--sd-btn-line-height'                     => '20px',
			'--sd-donation-amount-vertical-padding'    => '16px',
			'--sd-donation-amount-internal-option-gap' => '8px',
			'--sd-donation-amount-outer-padding'       => '0',
			'--sd-checkbox-size'                       => '16px',
		],
		'large' => [
			'--sd-row-gap-between-blocks'              => '20px',
			'--sd-column-gap-between-blocks'           => '16px',
			'--sd-col-gap-between-fields'              => '16px',
			'--sd-input-height'                        => '48px',
			'--sd-input-field-padding'                 => '10px 14px',
			'--sd-input-field-font-size'               => '18px',
			'--sd-input-field-line-height'             => '28px',
			'--sd-input-field-margin-top'              => '8px',
			'--sd-input-field-margin-bottom'           => '8px',
			'--sd-label-font-size'                     => '18px',
			'--sd-label-line-height'                   => '28px',
			'--sd-description-font-size'               => '16px',
			'--sd-description-line-height'             => '24px',
			'--sd-btn-padding'                         => '10px 14px',
			'--sd-btn-font-size'                       => '18px',
			'--sd-btn-line-height'                     => '28px',
			'--sd-donation-amount-vertical-padding'    => '24px',
			'--sd-donation-amount-internal-option-gap' => '12px',
			'--sd-donation-amount-outer-padding'       => '4px',
			'--sd-checkbox-size'                       => '20px',
		],
	];

	/**
	 * Default settings (also the editor panel defaults).
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function get_defaults() {
		return [
			'bgType'                 => 'color',
			'bgColor'                => '',
			'bgGradient'             => 'linear-gradient(90deg,#FFC9B2 0%,#C7CBFF 100%)',
			'bgImage'                => '',
			'bgImageId'              => 0,
			'bgImageSize'            => 'cover',
			'bgImagePosition'        => 'center center',
			'bgImageRepeat'          => 'no-repeat',
			// Colors default to empty here so unset values fall through to the
			// :root defaults in _variables.scss (the editor's STYLE_DEFAULTS seeds
			// the actual hex values instead, only to populate the panel swatches).
			'primaryColor'           => '',
			'textColor'              => '',
			'textOnPrimaryColor'     => '',
			'padding'                => [
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
			],
			'borderRadius'           => [
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
			],
			'fieldSpacing'           => 'medium',
			'buttonAlignment'        => 'justify',
			// When true the form renders without the SureDonation stylesheet and
			// inline CSS variables so the site's own CSS fully controls its
			// appearance (mirrors SureForms' disable_default_styles).
			'disable_default_styles' => false,
		];
	}

	/**
	 * Read + merge the per-form styling settings.
	 *
	 * @param int $form_id Form post ID.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function get_settings( $form_id ) {
		$defaults = self::get_defaults();
		$raw      = get_post_meta( (int) $form_id, Donation_Form::META_STYLING, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return $defaults;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $defaults;
		}

		$settings                 = array_merge( $defaults, $decoded );
		$settings['padding']      = array_merge( $defaults['padding'], is_array( $decoded['padding'] ?? null ) ? $decoded['padding'] : [] );
		$settings['borderRadius'] = array_merge( $defaults['borderRadius'], is_array( $decoded['borderRadius'] ?? null ) ? $decoded['borderRadius'] : [] );

		return $settings;
	}

	/**
	 * Sanitize the styling meta JSON on save.
	 *
	 * @param mixed $value Raw meta value (JSON string).
	 * @return string Sanitized JSON string ('' when invalid/empty).
	 * @since 1.0.0
	 */
	public static function sanitize_json( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$defaults = self::get_defaults();
		$clean    = [];

		$clean['bgType']     = in_array( $decoded['bgType'] ?? '', [ 'color', 'gradient', 'image' ], true ) ? $decoded['bgType'] : 'color';
		$clean['bgColor']    = self::sanitize_color( $decoded['bgColor'] ?? '' );
		$clean['bgGradient'] = self::sanitize_gradient( $decoded['bgGradient'] ?? '' );
		// Strip quotes/parens so the URL can't break out of the url('...') wrap.
		// The front-end render resolves the image from bgImageId instead.
		$clean['bgImage']       = str_replace( [ "'", '"', '(', ')' ], '', esc_url_raw( (string) ( $decoded['bgImage'] ?? '' ) ) );
		$clean['bgImageId']     = absint( $decoded['bgImageId'] ?? 0 );
		$clean['bgImageSize']   = in_array( $decoded['bgImageSize'] ?? '', [ 'cover', 'contain', 'auto' ], true ) ? $decoded['bgImageSize'] : 'cover';
		$clean['bgImageRepeat'] = in_array( $decoded['bgImageRepeat'] ?? '', [ 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ], true ) ? $decoded['bgImageRepeat'] : 'no-repeat';
		// Position has no UI control; allow safe keyword/percentage values only.
		$clean['bgImagePosition']    = preg_match( '/^[a-z0-9%.\s]+$/i', (string) ( $decoded['bgImagePosition'] ?? '' ) ) ? trim( (string) $decoded['bgImagePosition'] ) : 'center center';
		$clean['primaryColor']       = self::sanitize_color( $decoded['primaryColor'] ?? '' );
		$clean['textColor']          = self::sanitize_color( $decoded['textColor'] ?? '' );
		$clean['textOnPrimaryColor'] = self::sanitize_color( $decoded['textOnPrimaryColor'] ?? '' );
		$clean['padding']            = self::sanitize_box( $decoded['padding'] ?? [], $defaults['padding'] );
		$clean['borderRadius']       = self::sanitize_box( $decoded['borderRadius'] ?? [], $defaults['borderRadius'] );
		$clean['fieldSpacing']       = in_array( $decoded['fieldSpacing'] ?? '', [ 'small', 'medium', 'large' ], true ) ? $decoded['fieldSpacing'] : 'medium';
		$clean['buttonAlignment']    = in_array( $decoded['buttonAlignment'] ?? '', [ 'left', 'center', 'right', 'justify' ], true ) ? $decoded['buttonAlignment'] : 'justify';

		// Boolean flag, not a style value — must survive sanitization or an
		// editor save silently re-enables the default styling.
		$clean['disable_default_styles'] = ! empty( $decoded['disable_default_styles'] );

		$encoded = wp_json_encode( $clean );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Check whether the form renders without SureDonation's default styling.
	 *
	 * When enabled the frontend stylesheet is not enqueued for the form and the
	 * inline CSS-variable style attribute is omitted, so the site's own CSS
	 * fully controls the form's appearance. The container is stamped with an
	 * `sd-styling-none` marker class so custom CSS can target the state.
	 *
	 * @param int $form_id Form post ID.
	 * @return bool True when default styling is disabled for the form.
	 * @since 1.4.0
	 */
	public static function is_default_styling_disabled( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return false;
		}

		$settings = self::get_settings( $form_id );
		$disabled = ! empty( $settings['disable_default_styles'] );

		/**
		 * Filters whether SureDonation's default frontend styling is disabled for a form.
		 *
		 * Lets themes/plugins toggle the unstyled mode programmatically, overriding
		 * the stored per-form meta. Return true to render the form without the
		 * SureDonation stylesheet and inline CSS variables.
		 *
		 * @param bool $disabled Whether default styling is disabled (from meta).
		 * @param int  $form_id  Form post ID.
		 * @since 1.4.0
		 */
		return (bool) apply_filters( 'suredonation_disable_default_styles', $disabled, $form_id );
	}

	/**
	 * Build the inline CSS custom-property string for the form wrapper.
	 *
	 * Returns the CSS declarations only (no surrounding style attribute). The
	 * caller is expected to output the result via esc_attr() inside a style
	 * attribute, which the browser HTML-decodes before the CSS parser runs.
	 *
	 * @param int $form_id Form post ID.
	 * @return string CSS declarations, or '' when nothing is customized.
	 * @since 1.0.0
	 */
	public static function get_style_attr( $form_id ) {
		// Unstyled mode: no inline CSS variables either — an inline style on the
		// container would override any site/custom CSS that themes the form.
		if ( self::is_default_styling_disabled( $form_id ) ) {
			return '';
		}

		$settings = self::get_settings( $form_id );
		$vars     = [];

		// Colors. Color-derived tints are emitted per-form so they track the
		// chosen color: the static :root @supports defaults in _variables.scss are
		// computed from the default brand/text colors and cannot see a per-form
		// override (they live on :root, the override on the form container), so
		// without this the button hover and field tints stay default. Ratios
		// mirror that @supports block and SureForms' inc/generate-form-markup.php.
		// Keep in sync with buildStyleVars() in src/editor/form-style-vars.js.
		if ( '' !== $settings['primaryColor'] ) {
			$primary                                    = $settings['primaryColor'];
			$vars['--sd-color-scheme-primary']          = $primary;
			$vars['--sd-color-scheme-primary-hover']    = "hsl(from {$primary} h s l / 0.9)";
			$vars['--sd-color-input-border-hover']      = "hsl(from {$primary} h s l / 0.65)";
			$vars['--sd-color-input-border-focus-glow'] = "hsl(from {$primary} h s l / 0.15)";
			$vars['--sd-color-input-selected']          = "hsl(from {$primary} h s l / 0.1)";
		}
		if ( '' !== $settings['textColor'] ) {
			$text                                      = $settings['textColor'];
			$vars['--sd-color-input-text']             = $text;
			$vars['--sd-color-input-label']            = $text;
			$vars['--sd-color-input-description']      = "hsl(from {$text} h s l / 0.65)";
			$vars['--sd-color-input-placeholder']      = "hsl(from {$text} h s l / 0.5)";
			$vars['--sd-color-input-background']       = "hsl(from {$text} h s l / 0.02)";
			$vars['--sd-color-input-background-hover'] = "hsl(from {$text} h s l / 0.05)";
			$vars['--sd-color-input-border']           = "hsl(from {$text} h s l / 0.25)";
			$vars['--sd-color-donation-amount-svg']    = "hsl(from {$text} h s l / 0.7)";
			$vars['--sd-color-input-prefix']           = "hsl(from {$text} h s l / 0.65)";
			$vars['--sd-disabled-color']               = "hsl(from {$text} h s l / 0.5)";
			$vars['--sd-disabled-background-color']    = "hsl(from {$text} h s l / 0.07)";
			$vars['--sd-disabled-border']              = "hsl(from {$text} h s l / 0.15)";
		}
		if ( '' !== $settings['textOnPrimaryColor'] ) {
			$vars['--sd-btn-text-color'] = $settings['textOnPrimaryColor'];
		}

		// Background.
		$background = self::background_value( $settings );
		if ( '' !== $background ) {
			$vars['--sd-form-background'] = $background;
		}

		// Padding / border radius.
		$padding = self::box_value( $settings['padding'] );
		if ( '' !== $padding ) {
			$vars['--sd-form-padding'] = $padding;
		}
		$radius = self::box_value( $settings['borderRadius'] );
		if ( '' !== $radius ) {
			$vars['--sd-form-border-radius'] = $radius;
		}

		// Field spacing — the full density set; "medium" matches _variables.scss
		// defaults, so it is skipped.
		if ( 'medium' !== $settings['fieldSpacing'] && isset( self::SPACING_MAP[ $settings['fieldSpacing'] ] ) ) {
			foreach ( self::SPACING_MAP[ $settings['fieldSpacing'] ] as $name => $value ) {
				$vars[ $name ] = $value;
			}
		}

		// Button alignment — skip the default ('justify'); CSS fallback applies.
		$align_map = [
			'left'   => 'flex-start',
			'center' => 'center',
			'right'  => 'flex-end',
		];
		if ( isset( $align_map[ $settings['buttonAlignment'] ] ) ) {
			$vars['--sd-btn-align-items'] = $align_map[ $settings['buttonAlignment'] ];
			$vars['--sd-btn-width']       = 'auto';
		}

		/**
		 * Filter the form style CSS custom properties before they are serialized
		 * onto the `.sd-form-container` wrapper.
		 *
		 * Add-ons (e.g. SureDonation Pro) use this to contribute additional
		 * `--sd-*` variables. Runs before the empty-check so an add-on can style a
		 * form even when the free panel set nothing. Values must be pre-sanitized
		 * CSS tokens — they are emitted verbatim inside the inline style attribute.
		 *
		 * @param array<string, string> $vars     Map of `--sd-*` variable => value.
		 * @param int                   $form_id  Form post ID.
		 * @param array<string, mixed>  $settings Merged free style settings.
		 * @since 1.5.0
		 */
		$vars = apply_filters( 'suredonation_form_style_vars', $vars, (int) $form_id, $settings );

		if ( ! is_array( $vars ) || empty( $vars ) ) {
			return '';
		}

		$declarations = [];
		foreach ( $vars as $name => $value ) {
			// Defense-in-depth for the public filter above: only emit custom
			// properties with scalar, declaration-safe values, so a
			// non-sanitizing add-on callback cannot append arbitrary
			// declarations or trigger array-to-string notices. Values are
			// additionally escaped by the caller via esc_attr().
			if (
				! is_scalar( $value )
				|| ! preg_match( '/^--[A-Za-z0-9_-]+$/', (string) $name )
				|| preg_match( '/[;{}]/', (string) $value )
			) {
				continue;
			}
			$declarations[] = $name . ':' . $value;
		}

		return implode( ';', $declarations ) . ';';
	}

	/**
	 * Build the `background` shorthand value from the settings.
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return string
	 * @since 1.0.0
	 */
	private static function background_value( $settings ) {
		switch ( $settings['bgType'] ) {
			case 'gradient':
				return '' !== $settings['bgGradient'] ? $settings['bgGradient'] : '';
			case 'image':
				// Resolve the URL from the attachment ID (trusted, from the media
				// library) rather than the stored URL string; fall back to the
				// sanitized stored URL only if the attachment can't be resolved.
				$image_url = ! empty( $settings['bgImageId'] )
					? wp_get_attachment_image_url( (int) $settings['bgImageId'], 'full' )
					: '';
				if ( empty( $image_url ) ) {
					$image_url = $settings['bgImage'];
				}
				if ( '' === $image_url ) {
					return '';
				}
				// Guard the shorthand parts so an empty value can't make it invalid.
				$position = '' !== $settings['bgImagePosition'] ? $settings['bgImagePosition'] : 'center center';
				$size     = '' !== $settings['bgImageSize'] ? $settings['bgImageSize'] : 'cover';
				$repeat   = '' !== $settings['bgImageRepeat'] ? $settings['bgImageRepeat'] : 'no-repeat';
				return sprintf(
					"url('%s') %s / %s %s",
					esc_url_raw( $image_url ),
					$position,
					$size,
					$repeat
				);
			case 'color':
			default:
				return '' !== $settings['bgColor'] ? $settings['bgColor'] : '';
		}
	}

	/**
	 * Build a 4-side CSS shorthand (e.g. padding) from a box setting.
	 *
	 * @param array<string, mixed> $box Box setting (top/right/bottom/left/unit).
	 * @return string Shorthand value, or '' when no side is set.
	 * @since 1.0.0
	 */
	private static function box_value( $box ) {
		$any   = false;
		$parts = [];

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$length = self::sanitize_length( $box[ $side ] ?? '' );
			if ( '' !== $length ) {
				$any = true;
			}
			$parts[] = '' !== $length ? $length : '0';
		}

		return $any ? implode( ' ', $parts ) : '';
	}

	/**
	 * Sanitize a box setting for storage (per-side CSS lengths).
	 *
	 * @param mixed                $box      Incoming box value.
	 * @param array<string, mixed> $fallback Default box.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function sanitize_box( $box, $fallback ) {
		if ( ! is_array( $box ) ) {
			return $fallback;
		}

		$clean = [];
		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$clean[ $side ] = self::sanitize_length( $box[ $side ] ?? '' );
		}

		return $clean;
	}

	/**
	 * Validate a CSS length (e.g. "10px", "1.5rem"); bare numbers become px.
	 *
	 * Negative values are rejected: every consumer here (padding, border
	 * radius) is invalid with a negative length, which the browser would
	 * silently drop.
	 *
	 * @param mixed $value Incoming value.
	 * @return string Valid length, or '' when invalid/empty/negative.
	 * @since 1.0.0
	 */
	public static function sanitize_length( $value ) {
		if ( is_numeric( $value ) ) {
			return $value < 0 ? '' : ( 0 + $value ) . 'px';
		}
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		return preg_match( '/^\d*\.?\d+(px|em|rem|%|vw|vh)$/', $value ) ? $value : '';
	}

	/**
	 * Sanitize a color value via a strict allowlist.
	 *
	 * Accepts hex, rgb()/rgba()/hsl()/hsla() with numeric arguments only, or a
	 * bare named color. Anything else (e.g. "red url(https://…)") is rejected so
	 * a color field cannot smuggle an external resource into the inline style.
	 *
	 * @param mixed $value Incoming color.
	 * @return string Valid color, or '' when invalid/empty.
	 * @since 1.0.0
	 */
	public static function sanitize_color( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		// Hex: #rgb / #rgba / #rrggbb / #rrggbbaa.
		if ( preg_match( '/^#([A-Fa-f0-9]{8}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{4}|[A-Fa-f0-9]{3})$/', $value ) ) {
			return $value;
		}
		// Functional notation with numeric arguments only (no nested functions).
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.,%\/\s]+\)$/i', $value ) ) {
			return $value;
		}
		// Named color — letters only, so it cannot contain parens/url()/escapes.
		if ( preg_match( '/^[a-z]+$/i', $value ) ) {
			return $value;
		}
		// CSS custom-property reference for theme/global palette colors, e.g.
		// var(--wp--preset--color--primary), with an optional safe fallback
		// (hex / named / numeric rgb()|hsl() / one nested var). The property name
		// is restricted to [A-Za-z0-9_-] and the whole value is anchored, so it
		// cannot contain quotes, semicolons, url() or escapes that would break out
		// of the inline style attribute.
		if ( preg_match( '/^var\(\s*--[A-Za-z0-9_-]+\s*(,\s*(#[A-Fa-f0-9]{3,8}|[A-Za-z]+|(?:rgb|rgba|hsl|hsla)\([0-9.,%\/\s]+\)|var\(\s*--[A-Za-z0-9_-]+\s*\)))?\s*\)$/i', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Sanitize a CSS gradient value.
	 *
	 * Requires a (repeating-)?(linear|radial|conic)-gradient(…) shape and rejects
	 * url(), at-rules, and declaration-breaking characters, so the gradient field
	 * cannot reference an external resource or escape the inline style.
	 *
	 * @param mixed $value Incoming gradient.
	 * @return string Valid gradient, or '' when invalid/empty.
	 * @since 1.0.0
	 */
	public static function sanitize_gradient( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/url\s*\(|@|[;{}<>"\'\\\\]/i', $value ) ) {
			return '';
		}
		if ( ! preg_match( '/^(repeating-)?(linear|radial|conic)-gradient\(.*\)$/i', $value ) ) {
			return '';
		}
		return $value;
	}
}
