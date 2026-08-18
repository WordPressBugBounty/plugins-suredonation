<?php
/**
 * Per-form Custom CSS helper for donation forms.
 *
 * Reads the per-form Custom CSS meta and wraps it in a `<style>` block scoped to
 * the form's `.sd-form-container` wrapper, so the rules only ever affect the
 * donation form they were written for. Mirrors SureForms' per-form Custom CSS
 * behaviour (`_srfm_form_custom_css`).
 *
 * @package SureDonation
 * @since 1.5.0
 */

namespace SureDonation\Inc\Fields;

use SureDonation\Inc\Post_Types\Donation_Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Form_Custom_CSS class.
 *
 * @since 1.5.0
 */
class Form_Custom_CSS {

	/**
	 * Form IDs whose Custom CSS has already been printed in this request.
	 *
	 * A form can be rendered more than once on a page (two blocks, a block plus
	 * a shortcode, …). The style block is scoped by form ID rather than by the
	 * per-render wrapper ID, so printing it once is enough for every instance.
	 *
	 * @var array<int, bool>
	 * @since 1.5.0
	 */
	private static $emitted = [];

	/**
	 * Sanitize the Custom CSS, both on save and on output.
	 *
	 * Registered as the meta's `sanitize_callback`. Two things have to hold: the
	 * value must not close the `<style>` element it is printed inside, and it
	 * must not close the container rule inside that element. The first is this
	 * function's job, the second is {@see self::balance_braces()}'s.
	 *
	 * The element is RAWTEXT, so its only exit is the end-tag sequence, which
	 * begins with `</`. That sequence is escaped rather than deleted: `<\/` can
	 * never match an end tag for the HTML parser, while CSS reads `\/` as an
	 * escaped solidus and gives back the `/`. So the guarantee holds and the
	 * author's content survives — an inline SVG data URI keeps its `</svg>`,
	 * which deleting the `</` outright would have silently corrupted.
	 *
	 * A bare `<` is left alone. It cannot leave text context: verified in a
	 * browser, a `<script>` inside a style element creates no script element and
	 * runs nothing, and only a real `</style>` ends the element.
	 *
	 * That makes the result safe *for this context specifically*. A stored value
	 * may still contain a literal `<script`, harmless between `<style>` tags and
	 * not harmless outside them, so anything that renders this meta anywhere
	 * else has to escape it for wherever it is going. The editor preview does:
	 * it assigns through `style.textContent`, which never parses markup.
	 *
	 * Escaping also avoids the splice that deletion invites. `str_replace()`
	 * makes one pass and does not re-examine what it joins, so removing `</`
	 * from `<<//` would leave a fresh `</` behind; inserting a backslash cannot
	 * bring a `<` and a `/` together.
	 *
	 * This deliberately no longer runs `wp_kses_post()` and
	 * `html_entity_decode()`. Neither contributed to the guarantee — the strip
	 * carried it alone — and the pair actively cost three things: kses is the
	 * expensive half of a function that runs on every render; it HTML-encodes
	 * `<`, `>` and `&`, which then had to be decoded back so child combinators
	 * (`.a > .b`) and the nesting parent selector (`&:hover`) survived; and that
	 * round trip peeled one entity layer per pass, so `&amp;lt;` degraded to
	 * `&lt;` and then to nothing, making repeated saves lossy.
	 *
	 * Stripping `<` outright, as this did before, was also overbroad: it breaks
	 * Media Queries L4 range syntax (`@media (400px < width < 700px)`),
	 * container queries and inline SVG data URIs, none of which can close
	 * anything.
	 *
	 * @param mixed $value Raw CSS.
	 * @return string Sanitized CSS ('' when empty/invalid).
	 * @since 1.5.0
	 */
	public static function sanitize( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		// Neither pass can be run last and trusted, because each one invalidates
		// the other's analysis:
		//
		// - Escaping first, balancing second: balancing *deletes* a `}`, which
		//   can bring a `<` and a `/` together that were not adjacent when the
		//   escape looked at them, splicing a live `</style>`.
		// - Balancing first, escaping second: escaping *inserts* a backslash,
		//   and `\/` is no longer a comment opener — so `</*}body{…}` is one
		//   unterminated comment to the counter and an open block plus a
		//   top-level rule to the browser, which is a breakout the counter
		//   already decided was safe.
		//
		// So run the pair to a fixed point instead. It converges: every escape
		// a later cycle adds was paid for by a character the previous balance
		// removed, so the total work is bounded by the input length.
		$css = $value;

		for ( $guard = strlen( $value ) + 2; $guard > 0; --$guard ) {
			$next = self::balance_braces( self::escape_html_starts( $css ) );

			if ( $next === $css ) {
				break;
			}

			$css = $next;
		}

		// A no-op at a real fixed point, since reaching one means the value
		// already carries its escapes. It only does anything if the guard above
		// ran out, where failing closed on the HTML boundary is what matters.
		return trim( self::escape_html_starts( $css ) );
	}

	/**
	 * Keep the CSS inside the container rule it is printed in.
	 *
	 * The value is emitted between the braces of the wrapper selector in
	 * {@see self::get_style_block()}, so it starts at brace depth 1. A `}` that
	 * arrives at depth 0 closes the wrapper rather than a rule of the author's,
	 * and everything after it applies page-wide — so those are dropped.
	 *
	 * The counter has to agree with the browser's tokenizer about which braces
	 * are structural, and only one direction of disagreement is dangerous: a
	 * depth *higher* than the browser's means a `}` the browser spends on the
	 * wrapper is kept here as if it closed a rule of the author's.
	 *
	 * Four constructs consume raw text, so a `{`, `}`, `/*` or `"` inside them
	 * is inert and must be copied without counting. Together they are the whole
	 * set — every other CSS token has its contents tokenized normally, so a
	 * brace inside one is structural for the browser too, and plain counting
	 * already matches:
	 *
	 * - escapes (4.3.7): `\{` is a literal. Up to six hex digits plus one
	 *   trailing whitespace belong to the escape, so `\61 ` is one unit and the
	 *   whitespace it swallows is not a string terminator.
	 * - comments: an unterminated one runs to the end, as it does for a browser.
	 * - strings: terminated by their quote, or by a newline (4.3.4 bad-string).
	 * - url-tokens (4.3.6): once `url(` is followed by a non-quote, everything to
	 *   the first `)` is one token. This is the one that reads least like a
	 *   special case and bites hardest — `url(/*` would otherwise take the
	 *   comment branch and switch counting off for the rest of the value.
	 *
	 * Rules the author left *open* are left open. The wrapper's own closing
	 * brace then closes the innermost one and the stylesheet ends at `</style>`,
	 * which browsers close the rest of the way; every declaration still sits
	 * inside the wrapper, so nothing escapes the form. Appending the missing
	 * braces instead would be wrong: after an unterminated comment or string
	 * they would land inside it, closing nothing, and the value would grow by a
	 * brace on every render — sanitize() runs on output as well as on save.
	 *
	 * Idempotent, because it only ever removes: a second pass finds no `}` at
	 * depth 0 and no trailing lone backslash left to remove.
	 *
	 * @param string $css CSS with `<` already stripped.
	 * @return string CSS that cannot close the container rule.
	 * @since 1.5.0
	 */
	private static function balance_braces( $css ) {
		$length = strlen( $css );
		$out    = '';
		$depth  = 0;
		$i      = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			// url-token. Tested first because the ident may itself be written
			// with escapes (`\75 rl(` is `url(` to a browser), and only at an
			// ident boundary, so `myurl(` — an ordinary function token, whose
			// contents *are* tokenized normally — keeps plain counting.
			$prev = $i > 0 ? $css[ $i - 1 ] : '';
			if ( '\\' !== $prev && ! self::is_ident_byte( $prev ) ) {
				$url_end = self::url_token_end( $css, $i, $length );

				if ( null !== $url_end ) {
					$out .= substr( $css, $i, $url_end - $i );
					$i    = $url_end;
					continue;
				}
			}

			// Escape. Must come before the comment and string branches, so `\/*`
			// does not read as a comment opening and `\"` does not read as a
			// string opening — the latter would otherwise switch counting off
			// for the rest of the value.
			if ( '\\' === $char ) {
				$escape = self::escape_length( $css, $i, $length );

				if ( 0 === $escape ) {
					// A lone trailing backslash would escape the wrapper's own
					// closing brace, leaving `…\}</style>` with the rule never
					// closed. Dropping it keeps this function idempotent, which
					// appending a space would not.
					++$i;
					continue;
				}

				$out .= substr( $css, $i, $escape );
				$i   += $escape;
				continue;
			}

			// Comment: copy verbatim. An unterminated one runs to the end of the
			// value, which is how a browser reads it too.
			if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					$out .= substr( $css, $i );
					break;
				}
				$out .= substr( $css, $i, $end + 2 - $i );
				$i    = $end + 2;
				continue;
			}

			// Quoted string: copy verbatim, honouring escapes so an escaped
			// quote does not read as the closing one.
			if ( '"' === $char || "'" === $char ) {
				$out .= $char;
				++$i;

				while ( $i < $length ) {
					if ( '\\' === $css[ $i ] ) {
						$escape = self::escape_length( $css, $i, $length );

						if ( 0 === $escape ) {
							++$i;
							continue;
						}

						$out .= substr( $css, $i, $escape );
						$i   += $escape;
						continue;
					}

					$out .= $css[ $i ];

					// Closing quote, or a newline ending an unterminated string.
					// A newline reached *through* an escape never gets here, so
					// the string stays open exactly as long as it does for a
					// browser.
					if ( $css[ $i ] === $char || self::is_newline_byte( $css[ $i ] ) ) {
						++$i;
						break;
					}

					++$i;
				}

				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				if ( 0 === $depth ) {
					// Would close the wrapper rule — drop it.
					++$i;
					continue;
				}
				--$depth;
			}

			$out .= $char;
			++$i;
		}

		return $out;
	}

	/**
	 * Neutralize the byte sequences that mean something to the HTML parser.
	 *
	 * Runs last, on the value as it will actually be emitted. Order matters:
	 * {@see self::balance_braces()} *removes* characters, and a removal can bring
	 * a `<` and a `/` together that were not adjacent when this ran — so escaping
	 * first left `<}/style>` to become a live `</style>` once the stray brace was
	 * dropped, which is a script-executing breakout rather than a CSS one.
	 *
	 * Both sequences are escaped rather than deleted, for the reason deletion
	 * failed above: `str_replace()` makes one pass and does not re-examine what it
	 * joins, so stripping `<script` would turn `<scr<scriptipt>` back into
	 * `<script`, and stripping `</` would turn `<<//` back into `</`. Inserting a
	 * backslash cannot splice.
	 *
	 * CSS reads `\/` and `\s` as escaped literals, so the author's `</svg>` and
	 * `<script` survive into the rendered value; the HTML parser sees a `<`
	 * followed by a backslash, which can begin neither an end tag nor a tag name.
	 *
	 * `<script` is handled even though it is inert between `<style>` tags, because
	 * the meta is `show_in_rest` and the stored value therefore travels to places
	 * that are not this element — and a `<script>` needs no closing tag to run.
	 *
	 * @param string $css Balanced CSS.
	 * @return string CSS that cannot start an HTML tag.
	 * @since 1.5.0
	 */
	private static function escape_html_starts( $css ) {
		// A `<` immediately before either sequence; the sequence itself is left
		// alone so its original casing survives.
		return (string) preg_replace( '#<(?=/|script)#i', '<\\\\', $css );
	}

	/**
	 * Length in bytes of the CSS escape starting at a backslash.
	 *
	 * Per CSS Syntax 4.3.7 an escape is a backslash followed by either one code
	 * point, or up to six hex digits and then one optional whitespace which
	 * belongs to the escape rather than to whatever follows. Getting that
	 * whitespace wrong is what lets `content:"\61 <LF>` read as a terminated
	 * string here while the browser is still inside it.
	 *
	 * @param string $css    Value being scanned.
	 * @param int    $i      Offset of the backslash.
	 * @param int    $length Total length.
	 * @return int Bytes consumed, or 0 for a trailing lone backslash.
	 * @since 1.5.0
	 */
	private static function escape_length( $css, $i, $length ) {
		if ( $i + 1 >= $length ) {
			return 0;
		}

		// A backslash before a newline is not an escape at all (4.3.7). Inside a
		// string it is a line continuation that consumes the newline and leaves
		// the string open, so it has to be consumed whole here — leaving half of
		// a CRLF behind would hand the string branch a bare newline and end a
		// string the browser is still inside. CRLF is one newline (3.3).
		if ( self::is_newline_byte( $css[ $i + 1 ] ) ) {
			return "\r" === $css[ $i + 1 ] && $i + 2 < $length && "\n" === $css[ $i + 2 ] ? 3 : 2;
		}

		$j   = $i + 1;
		$hex = 0;
		while ( $j < $length && $hex < 6 && ctype_xdigit( $css[ $j ] ) ) {
			++$j;
			++$hex;
		}

		if ( 0 === $hex ) {
			// A single escaped code point. Consuming one byte of a multi-byte
			// character is harmless: its continuation bytes are not delimiters.
			return 2;
		}

		// CRLF counts as the one permitted whitespace, not two.
		if ( $j + 1 < $length && "\r" === $css[ $j ] && "\n" === $css[ $j + 1 ] ) {
			return $j + 2 - $i;
		}

		if ( $j < $length && self::is_space_byte( $css[ $j ] ) ) {
			++$j;
		}

		return $j - $i;
	}

	/**
	 * Offset just past a url-token starting at the given position, or null.
	 *
	 * Returns null for the quoted form (`url("…")`), which is an ordinary string
	 * token and is better handled by the string branch, and for any ident that
	 * is not `url`.
	 *
	 * @param string $css    Value being scanned.
	 * @param int    $i      Offset to test.
	 * @param int    $length Total length.
	 * @return int|null Offset after the closing `)`, or null when this is not a url-token.
	 * @since 1.5.0
	 */
	private static function url_token_end( $css, $i, $length ) {
		$j = $i;

		// Match u, r, l — each of which may be written literally or as an escape.
		foreach ( [ 'u', 'r', 'l' ] as $expected ) {
			if ( $j >= $length ) {
				return null;
			}

			if ( '\\' === $css[ $j ] ) {
				$escape = self::escape_length( $css, $j, $length );

				if ( $escape < 2 || strtolower( self::decoded_escape( $css, $j, $escape ) ) !== $expected ) {
					return null;
				}

				$j += $escape;
				continue;
			}

			if ( strtolower( $css[ $j ] ) !== $expected ) {
				return null;
			}

			++$j;
		}

		if ( $j >= $length || '(' !== $css[ $j ] ) {
			return null;
		}

		++$j;

		while ( $j < $length && self::is_space_byte( $css[ $j ] ) ) {
			++$j;
		}

		if ( $j < $length && ( '"' === $css[ $j ] || "'" === $css[ $j ] ) ) {
			return null;
		}

		while ( $j < $length && ')' !== $css[ $j ] ) {
			$j += '\\' === $css[ $j ] ? max( 1, self::escape_length( $css, $j, $length ) ) : 1;
		}

		// Consume the ')'. An unterminated url runs to the end, as it does for a
		// browser, which is also why counting must not resume inside it.
		return min( $j + 1, $length );
	}

	/**
	 * The code point an escape stands for, for the few ASCII cases this needs.
	 *
	 * @param string $css    Value being scanned.
	 * @param int    $i      Offset of the backslash.
	 * @param int    $escape Escape length from {@see self::escape_length()}.
	 * @return string Single character, or '' when it is not a plain ASCII one.
	 * @since 1.5.0
	 */
	private static function decoded_escape( $css, $i, $escape ) {
		$body = rtrim( substr( $css, $i + 1, $escape - 1 ) );

		if ( '' !== $body && ctype_xdigit( $body ) ) {
			$code = hexdec( $body );

			return $code > 0 && $code < 0x80 ? chr( $code ) : '';
		}

		return substr( $css, $i + 1, 1 );
	}

	/**
	 * Whether a byte can appear in a CSS identifier.
	 *
	 * @param string $byte Single byte, or '' at the start of the value.
	 * @return bool
	 * @since 1.5.0
	 */
	private static function is_ident_byte( $byte ) {
		if ( '' === $byte ) {
			return false;
		}

		return 1 === preg_match( '/[A-Za-z0-9_-]/', $byte ) || ord( $byte ) >= 0x80;
	}

	/**
	 * Whether a byte is CSS whitespace (4.2), which includes the form feed.
	 *
	 * @param string $byte Single byte.
	 * @return bool
	 * @since 1.5.0
	 */
	private static function is_space_byte( $byte ) {
		return ' ' === $byte || "\t" === $byte || "\n" === $byte || "\r" === $byte || "\f" === $byte;
	}

	/**
	 * Whether a byte ends a string as a newline does (4.3.4).
	 *
	 * Includes the form feed. It is currently unreachable because
	 * wp_kses_no_null() strips it upstream, but relying on that would make this
	 * function's correctness depend on a caller its docblock does not name.
	 *
	 * @param string $byte Single byte.
	 * @return bool
	 * @since 1.5.0
	 */
	private static function is_newline_byte( $byte ) {
		return "\n" === $byte || "\r" === $byte || "\f" === $byte;
	}

	/**
	 * Read the saved Custom CSS for a form.
	 *
	 * @param int $form_id Form post ID.
	 * @return string Saved CSS ('' when unset).
	 * @since 1.5.0
	 */
	public static function get_css( $form_id ) {
		$raw = get_post_meta( (int) $form_id, Donation_Form::META_CUSTOM_CSS, true );

		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * Build the scoped `<style>` block for a form's Custom CSS.
	 *
	 * The user's CSS is nested inside the container rule (native CSS nesting),
	 * the same approach SureForms uses, so selectors resolve relative to the
	 * form and cannot leak page-wide. Returns '' the second time it is called
	 * for the same form so a page with repeated forms gets one style block.
	 *
	 * @param int $form_id Form post ID.
	 * @return string Style block markup, or '' when there is nothing to print.
	 * @since 1.5.0
	 */
	public static function get_style_block( $form_id ) {
		$form_id = (int) $form_id;

		if ( isset( self::$emitted[ $form_id ] ) ) {
			return '';
		}

		$css = self::get_css( $form_id );
		if ( '' === $css ) {
			return '';
		}

		self::$emitted[ $form_id ] = true;

		// Sanitized again on output: the meta may have been written by something
		// that bypassed the registered sanitize_callback (direct SQL, an older
		// value, a filtered import).
		//
		// self::sanitize() is idempotent, so re-running it here cannot degrade a
		// value that was already sanitized on save. Both halves only ever
		// remove: `</` and the braces that would close the wrapper.
		return sprintf(
			'<style id="sd-form-custom-css-%1$d">.sd-form-container[data-form-id="%1$d"]{%2$s}</style>',
			$form_id,
			self::sanitize( $css )
		);
	}

	/**
	 * Forget which forms have already printed their Custom CSS.
	 *
	 * Only needed so tests can exercise the per-request de-duplication without
	 * leaking state between cases.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function reset_emitted() {
		self::$emitted = [];
	}
}
