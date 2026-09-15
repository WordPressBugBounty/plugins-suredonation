<?php
/**
 * PHP render for the Donor Comments block.
 *
 * @package SureDonation
 * @since 1.6.0
 */

namespace SureDonation\Inc\Blocks\Campaign_Donor_Comments;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Campaigns\Campaign_Stats;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donor Comments block.
 *
 * @since 1.6.0
 */
class Block extends Base {
	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 1.6.0
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content );

		$campaign_id = Campaign_Page::resolve_campaign_id( $attributes );
		if ( ! $campaign_id ) {
			return '';
		}

		wp_enqueue_style( 'suredonation-campaign-blocks' );

		$limit     = isset( $attributes['commentsToShow'] ) ? absint( Helper::get_string_value( $attributes['commentsToShow'] ) ) : 5;
		$limit     = max( 1, $limit );
		$show_anon = ! isset( $attributes['showAnonymous'] ) || $attributes['showAnonymous'];

		// Anonymity filtering is done by the cached reader, across the whole
		// cached window, before it slices to $limit — see the note there. Doing
		// it here instead meant a run of anonymous comments at the top could
		// swallow the slice and render "No comments yet." while non-anonymous
		// approved comments sat just past the cut.
		$comments = Campaign_Stats::get_cached_donor_comments( $campaign_id, $limit, 300, ! $show_anon );

		if ( ! is_array( $comments ) ) {
			$comments = [];
		}

		$show_avatar = ! isset( $attributes['showAvatar'] ) || $attributes['showAvatar'];
		$show_amount = ! isset( $attributes['showAmount'] ) || $attributes['showAmount'];
		$show_date   = ! isset( $attributes['showDate'] ) || $attributes['showDate'];

		// 0 disables truncation entirely — the whole comment always renders.
		$max_chars      = isset( $attributes['commentLength'] ) ? absint( Helper::get_string_value( $attributes['commentLength'] ) ) : 150;
		$read_more_text = ! empty( $attributes['readMoreText'] ) ? Helper::get_string_value( $attributes['readMoreText'] ) : __( 'Read more', 'suredonation' );

		$wrapper = get_block_wrapper_attributes( [ 'class' => 'suredonation-campaign-donor-comments' ] );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
			<div class="suredonation-campaign-donor-comments__header">
				<h2 class="suredonation-campaign-donor-comments__title"><?php esc_html_e( 'Donor Comments', 'suredonation' ); ?></h2>
			</div>
			<?php if ( empty( $comments ) ) : ?>
				<p class="suredonation-campaign-donor-comments__empty"><?php esc_html_e( 'No comments yet.', 'suredonation' ); ?></p>
			<?php else : ?>
				<ul class="suredonation-campaign-donor-comments__list">
					<?php foreach ( $comments as $comment ) : ?>
						<?php
						$is_anon = ! empty( $comment['is_anonymous'] );
						// An anonymous donation masks the donor's name but keeps
						// the comment — it is the donor's own message, and the
						// showAnonymous toggle above is how an author drops them
						// entirely. Matches Charitable; GiveWP hides both.
						$name       = $is_anon ? __( 'Anonymous', 'suredonation' ) : Helper::get_string_value( $comment['donor_name'] ?? __( 'Anonymous', 'suredonation' ) );
						$time       = ! empty( $comment['created_at'] ) ? strtotime( Helper::get_string_value( $comment['created_at'] ) ) : false;
						$avatar_url = $show_avatar ? Campaign_Page::donor_avatar_url( Helper::get_string_value( $comment['donor_email'] ?? '' ), $is_anon ) : '';
						// Decoded, then trimmed, then measured and cut — in that order,
						// and all before the escaping at each sink below.
						//
						// Decode first: sanitize_textarea_field() runs
						// wp_pre_kses_less_than at write time, so a bare '<' is stored
						// as '&lt;'. That matters for the CUT, not for the rendering —
						// esc_html() does not double-encode, so an undecoded entity
						// would still display correctly. What breaks undecoded is
						// truncate(): the stored form is longer than the visible one,
						// so the limit is measured against the wrong length and the
						// cut can land inside '&lt;', rendering as a literal 'a &lt'.
						//
						// Then trim: decoding can expose leading whitespace that is not
						// content, and the moderator edit path does not trim the way
						// the donor-facing capture path does.
						$text = trim( wp_specialchars_decode( Helper::get_string_value( $comment['donor_comment'] ?? '' ) ) );
						// Whether to truncate is decided here, not inferred from the
						// excerpt being empty — see the note on truncate().
						$needs_excerpt = $max_chars > 0 && mb_strlen( $text ) > $max_chars;
						$excerpt       = $needs_excerpt ? self::truncate( $text, $max_chars ) : '';
						// Unique per render, not just per donation: the same campaign
						// can be rendered twice on one page (the block plus the
						// Elementor widget or Bricks element), and a duplicated id
						// makes both cards' labels drive the first card's checkbox.
						$toggle_id = wp_unique_id( 'sd-donor-comment-more-' );
						?>
						<li class="suredonation-campaign-donor-comments__item">
							<?php if ( $avatar_url ) : ?>
								<div class="suredonation-campaign-donor-comments__avatar">
									<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" loading="lazy" />
								</div>
							<?php endif; ?>
							<div class="suredonation-campaign-donor-comments__info">
								<div class="suredonation-campaign-donor-comments__meta">
									<span class="suredonation-campaign-donor-comments__name"><?php echo esc_html( $name ); ?></span>
									<?php if ( $show_amount ) : ?>
										<span class="suredonation-campaign-donor-comments__amount"><?php echo esc_html( Payment_Helper::format_amount( Helper::get_float_value( $comment['amount'] ?? 0 ) ) ); ?></span>
									<?php endif; ?>
								</div>
								<?php if ( $needs_excerpt ) : ?>
									<?php
									// CSS-only "read more": the hidden checkbox swaps the
									// excerpt for the full text, so the block needs no
									// script of its own. Escaped first, then nl2br so the
									// donor's own line breaks survive.
									?>
									<div class="suredonation-campaign-donor-comments__body">
										<?php
										// aria-label outranks the two <label for> elements
										// that drive this checkbox; without it a screen
										// reader concatenates both into the accessible
										// name ("Read more Show less, checkbox"), because
										// the display:none one still folds in. The visible
										// labels stay as click targets.
										?>
										<input type="checkbox" id="<?php echo esc_attr( $toggle_id ); ?>" class="suredonation-campaign-donor-comments__toggle" aria-label="<?php esc_attr_e( 'Show the full comment', 'suredonation' ); ?>" />
										<p class="suredonation-campaign-donor-comments__excerpt">
											<?php echo nl2br( esc_html( $excerpt ) ); ?>&hellip;
										</p>
										<p class="suredonation-campaign-donor-comments__text">
											<?php echo nl2br( esc_html( $text ) ); ?>
										</p>
										<label class="suredonation-campaign-donor-comments__read-more" for="<?php echo esc_attr( $toggle_id ); ?>"><?php echo esc_html( $read_more_text ); ?></label>
										<?php
										// The expanded state needs its own control: hiding the
										// read-more label on :checked would otherwise destroy the
										// element the keyboard user just activated, dropping focus
										// to the top of the document.
										?>
										<label class="suredonation-campaign-donor-comments__read-less" for="<?php echo esc_attr( $toggle_id ); ?>"><?php esc_html_e( 'Show less', 'suredonation' ); ?></label>
									</div>
								<?php else : ?>
									<p class="suredonation-campaign-donor-comments__text">
										<?php echo nl2br( esc_html( $text ) ); ?>
									</p>
								<?php endif; ?>
								<?php if ( $show_date && $time ) : ?>
									<span class="suredonation-campaign-donor-comments__date">
										<?php
										/* translators: %s: human-readable time difference, e.g. "3 weeks". */
										printf( esc_html__( '%s ago', 'suredonation' ), esc_html( human_time_diff( $time ) ) );
										?>
									</span>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}

	/**
	 * Build a truncated excerpt of a comment.
	 *
	 * Cuts at the last space before the limit so the excerpt never ends
	 * mid-word, falling back to the hard character cut when there is no space in
	 * range (e.g. CJK, or one long token).
	 *
	 * The caller decides *whether* to truncate; this only builds the excerpt, and
	 * never returns '' for a comment that needs one. An earlier version signalled
	 * "already fits" by returning '', which the trailing rtrim() could also
	 * produce for a genuinely over-long comment — a run of punctuation ("-----")
	 * or leading whitespace then rendered in full with no read-more control,
	 * silently defeating the configured limit.
	 *
	 * @param string $text      The full comment, already known to exceed $max_chars.
	 * @param int    $max_chars Maximum characters.
	 * @return string The excerpt. Never '' unless $text itself is ''.
	 * @since 1.6.0
	 */
	private static function truncate( $text, $max_chars ) {
		// Character-accurate cut for the limit itself.
		$hard = mb_substr( $text, 0, absint( $max_chars ) );

		// strrpos/substr (byte functions) rather than the mb_* pair: the needle is
		// an ASCII space, which can never occur inside a UTF-8 multibyte sequence,
		// so cutting at that byte offset always yields valid UTF-8. WordPress core
		// polyfills mb_substr() and mb_strlen() but NOT mb_strrpos(), so the mb_*
		// form would fatal on a host without the mbstring extension.
		$last_space = strrpos( $hard, ' ' );

		if ( false !== $last_space && $last_space > 0 ) {
			$word_cut = rtrim( substr( $hard, 0, $last_space ), " \t\n\r\0\x0B.,;:!?-" );

			if ( '' !== $word_cut ) {
				return $word_cut;
			}
		}

		// The word-boundary cut left nothing usable, so keep the hard cut: the
		// limit still has to be enforced.
		return rtrim( $hard, " \t\n\r\0\x0B" );
	}
}
