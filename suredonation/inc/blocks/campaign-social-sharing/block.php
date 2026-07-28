<?php
/**
 * PHP render for the Campaign Social Sharing block.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Blocks\Campaign_Social_Sharing;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Campaigns\Campaign_Page;
use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Campaign Social Sharing block.
 *
 * @since 1.2.0
 */
class Block extends Base {
	/**
	 * Whether the Mastodon click handler has already been printed on this page.
	 *
	 * @var bool
	 * @since 1.2.0
	 */
	private static $mastodon_script_printed = false;

	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 1.2.0
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content );

		$campaign_id = Campaign_Page::resolve_campaign_id( $attributes );
		if ( ! $campaign_id ) {
			return '';
		}

		wp_enqueue_style( 'suredonation-campaign-blocks' );

		$networks = self::get_networks();
		$enabled  = isset( $attributes['networks'] ) && is_array( $attributes['networks'] ) ? $attributes['networks'] : [];
		$enabled  = array_values( array_intersect( array_keys( $networks ), array_map( 'strval', $enabled ) ) );
		if ( empty( $enabled ) ) {
			return '';
		}

		// No block.json default (static JSON isn't localizable) — absent attribute
		// means "never edited", which falls back to the translated default; an
		// explicitly cleared headline is stored as '' and renders no headline.
		$headline     = isset( $attributes['headline'] ) ? Helper::get_string_value( $attributes['headline'] ) : __( 'Share:', 'suredonation' );
		$new_tab      = ! isset( $attributes['openInNewTab'] ) || ! empty( $attributes['openInNewTab'] );
		$align        = isset( $attributes['contentAlign'] ) ? Helper::get_string_value( $attributes['contentAlign'] ) : 'left';
		$align        = in_array( $align, [ 'left', 'center', 'right' ], true ) ? $align : 'left';
		$target_attrs = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

		// Share tokens: the campaign title and its permalink, url-encoded.
		$text = rawurlencode( wp_strip_all_tags( (string) get_the_title( $campaign_id ) ) );
		$url  = rawurlencode( (string) get_permalink( $campaign_id ) );

		$wrapper = get_block_wrapper_attributes(
			[
				'class' => 'suredonation-campaign-social suredonation-campaign-social--align-' . sanitize_html_class( $align ),
			]
		);

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped markup. ?>>
			<?php if ( '' !== trim( $headline ) ) { ?>
				<h5 class="suredonation-campaign-social__headline"><?php echo esc_html( $headline ); ?></h5>
			<?php } ?>
			<div class="suredonation-campaign-social__list">
				<?php
				foreach ( $enabled as $key ) {
					$network = $networks[ $key ];

					if ( 'mastodon' === $key ) {
						// Mastodon has no central share endpoint; the instance is asked
						// for (and remembered) by the click handler below.
						printf(
							'<a class="suredonation-campaign-social__link suredonation-campaign-social__link--mastodon" href="#" data-share-text="%1$s" data-share-url="%2$s" data-new-tab="%3$s" aria-label="%4$s">%5$s</a>',
							esc_attr( $text ),
							esc_attr( $url ),
							esc_attr( $new_tab ? '1' : '0' ),
							esc_attr(
								sprintf(
									/* translators: %s: social network name. */
									__( 'Share on %s', 'suredonation' ),
									$network['label']
								)
							),
							self::get_icon( $key ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, developer-defined inline SVG markup.
						);
						continue;
					}

					$share_url = strtr(
						$network['share_url'],
						[
							'{text}' => $text,
							'{url}'  => $url,
						]
					);

					printf(
						'<a class="suredonation-campaign-social__link suredonation-campaign-social__link--%1$s" href="%2$s"%3$s aria-label="%4$s">%5$s</a>',
						esc_attr( $key ),
						esc_url( $share_url ),
						$target_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, developer-defined attribute string.
						esc_attr(
							sprintf(
								/* translators: %s: social network name. */
								__( 'Share on %s', 'suredonation' ),
								$network['label']
							)
						),
						self::get_icon( $key ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, developer-defined inline SVG markup.
					);
				}
				?>
			</div>
			<?php self::maybe_print_mastodon_script( $enabled ); ?>
		</div>
		<?php
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}

	/**
	 * The supported social networks, in display order.
	 *
	 * Keys, labels and share-intent URL patterns mirror WP Charitable's social
	 * sharing field. `{text}` = url-encoded campaign title, `{url}` = url-encoded
	 * permalink. Mastodon has no central endpoint (handled client-side).
	 *
	 * @since 1.2.0
	 * @return array<string, array{label:string, share_url:string}>
	 */
	private static function get_networks() {
		return [
			'twitter'   => [
				'label'     => __( 'Twitter / X', 'suredonation' ),
				'share_url' => 'https://x.com/intent/post?text={text}&url={url}',
			],
			'facebook'  => [
				'label'     => __( 'Facebook', 'suredonation' ),
				'share_url' => 'https://www.facebook.com/sharer/sharer.php?u={url}',
			],
			'linkedin'  => [
				'label'     => __( 'LinkedIn', 'suredonation' ),
				'share_url' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
			],
			'pinterest' => [
				'label'     => __( 'Pinterest', 'suredonation' ),
				'share_url' => 'https://www.pinterest.com/pin/create/button/?url={url}&description={text}',
			],
			'mastodon'  => [
				'label'     => __( 'Mastodon', 'suredonation' ),
				'share_url' => '',
			],
			// Threads/Bluesky take the title and URL in a single `text` field.
			// esc_url() strips encoded CR/LF (%0D/%0A), so a space separates them
			// (Charitable uses a blank line, which esc_url would drop entirely).
			'threads'   => [
				'label'     => __( 'Threads', 'suredonation' ),
				'share_url' => 'https://www.threads.net/intent/post?text={text}%20{url}',
			],
			'bluesky'   => [
				'label'     => __( 'Bluesky', 'suredonation' ),
				'share_url' => 'https://bsky.app/intent/compose?text={text}%20{url}',
			],
		];
	}

	/**
	 * Inline brand-colored SVG for a network key.
	 *
	 * Each icon carries its own brand color(s) baked into the SVG (no CSS tinting),
	 * so it renders identically in the editor and on the front end.
	 *
	 * @since 1.2.0
	 * @param string $key Network key.
	 * @return string SVG markup (static, developer-defined).
	 */
	private static function get_icon( $key ) {
		$icons = [
			'twitter'   => '<path fill="#000000" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>',
			'facebook'  => '<path fill="#1877F2" d="M12 0C5.373 0 0 5.373 0 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078V12h3.047V9.356c0-3.007 1.792-4.669 4.533-4.669 1.313 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874V12h3.328l-.532 3.469h-2.796v8.385C19.612 22.954 24 17.99 24 12 24 5.373 18.627 0 12 0z"/><path fill="#ffffff" d="M16.671 15.469 17.203 12h-3.328V9.75c0-.949.465-1.874 1.956-1.874h1.513V4.923s-1.373-.235-2.686-.235c-2.741 0-4.533 1.662-4.533 4.669V12H7.078v3.469h3.047v8.385a12.06 12.06 0 0 0 3.75 0v-8.385h2.796z"/>',
			'linkedin'  => '<path fill="#0A66C2" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>',
			'pinterest' => '<path fill="#BD081C" d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.402.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.357-.629-2.749-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641.001 12.017.001z"/>',
			'mastodon'  => '<path fill="#6364FF" d="M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 0 0 .023-.043v-1.809a.052.052 0 0 0-.02-.041.053.053 0 0 0-.046-.01 20.282 20.282 0 0 1-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 0 1-.319-1.433.053.053 0 0 1 .066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.67 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z"/>',
			'threads'   => '<path fill="#000000" d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a13.853 13.853 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32L7.734 7.847c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388.108.046.216.094.321.142 1.49.7 2.58 1.761 3.154 3.07.797 1.82.871 4.79-1.548 7.158-1.85 1.81-4.094 2.628-7.277 2.65zm1.003-11.69c-.242 0-.487.007-.739.021-1.836.103-2.98.946-2.916 2.143.067 1.256 1.452 1.839 2.784 1.767 1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221z"/>',
			'bluesky'   => '<path fill="#0285FF" d="M5.203 3.036C7.955 5.132 10.916 9.38 12 11.66c1.084-2.28 4.045-6.528 6.797-8.624C20.783 1.524 24 .363 24 4.03c0 .733-.42 6.156-.667 7.037-.856 3.06-3.978 3.84-6.755 3.368 4.854.826 6.089 3.562 3.422 6.299-5.065 5.196-7.28-1.304-7.847-2.97-.104-.305-.153-.448-.153-.326 0-.122-.049.021-.153.326-.567 1.666-2.782 8.166-7.847 2.97-2.667-2.737-1.432-5.473 3.422-6.299-2.777.472-5.899-.308-6.755-3.368C.42 10.185 0 4.763 0 4.03 0 .363 3.217 1.524 5.203 3.036z"/>',
		];

		if ( ! isset( $icons[ $key ] ) ) {
			return '';
		}

		return '<svg class="suredonation-campaign-social__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">' . $icons[ $key ] . '</svg>';
	}

	/**
	 * Print the Mastodon click handler once per page (only when Mastodon is used).
	 *
	 * Mirrors WP Charitable: on click, read the saved instance from localStorage or
	 * prompt for it, remember it, then open `https://{instance}/share?text=…\n{url}`.
	 *
	 * @since 1.2.0
	 * @param array<int, string> $enabled Enabled network keys.
	 * @return void
	 */
	private static function maybe_print_mastodon_script( $enabled ) {
		if ( self::$mastodon_script_printed || ! in_array( 'mastodon', $enabled, true ) ) {
			return;
		}
		self::$mastodon_script_printed = true;

		// Nowdoc keeps the JS literal (no PHP interpolation of the regexes/'\n');
		// the translatable prompt is injected via a placeholder instead.
		$js = <<<'JS'
document.addEventListener('click',function(e){
	var link=e.target.closest('.suredonation-campaign-social__link--mastodon');
	if(!link){return;}
	e.preventDefault();
	var key='suredonation-mastodon-instance';
	var instance=window.localStorage.getItem(key);
	if(!instance){
		instance=window.prompt('%PROMPT%');
		if(!instance){return;}
		instance=instance.replace(/^https?:\/\//,'').replace(/\/+$/,'');
		window.localStorage.setItem(key,instance);
	}
	var text=decodeURIComponent(link.getAttribute('data-share-text')||'');
	var url=decodeURIComponent(link.getAttribute('data-share-url')||'');
	var share='https://'+instance+'/share?text='+encodeURIComponent(text+'\n'+url);
	window.open(share, link.getAttribute('data-new-tab')==='1'?'_blank':'_self','noopener');
});
JS;

		$js = str_replace(
			'%PROMPT%',
			esc_js( __( 'Enter your Mastodon instance (e.g. mastodon.social):', 'suredonation' ) ),
			$js
		);

		// wp_print_inline_script_tag() lets WordPress attach CSP nonces/attributes
		// a hand-printed <script> tag would miss (same as inc/assets/register.php).
		wp_print_inline_script_tag( $js, [ 'id' => 'suredonation-mastodon-share' ] );
	}
}
