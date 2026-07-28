<?php
/**
 * PHP render for HTML Block.
 *
 * A content block that outputs author-supplied custom markup within the donation
 * form. It carries no submission value and does not participate in field
 * validation. The markup is sanitized with wp_kses_post() on output, which also
 * strips form controls (form/input/button/select/textarea) so the block cannot
 * nest a form inside the donation form. Shortcodes are expanded only when the
 * form's author has the unfiltered_html capability; otherwise the raw markup is
 * rendered without shortcode execution.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Blocks\Html;

use SureDonation\Inc\Blocks\Base;
use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * HTML Block.
 *
 * @since 1.1.1
 */
class Block extends Base {
	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @return string
	 * @since 1.1.1
	 */
	public function render( $attributes, $content = '' ) {
		unset( $content ); // Unused parameter.

		if ( empty( $attributes ) ) {
			return '';
		}

		$html_content = isset( $attributes['htmlContent'] ) ? Helper::get_string_value( $attributes['htmlContent'] ) : '';
		if ( '' === trim( $html_content ) ) {
			return '';
		}

		$wrapper_classes = self::get_display_block_classes( $attributes, 'sd-html-block' );

		// Expand shortcodes only when the form's author is allowed to run them
		// (unfiltered_html). The capability is checked on the form author, not the
		// current viewer: render happens on the public, usually logged-out front
		// end, so the trust boundary is who authored the content. Editing a form
		// only needs edit_posts (Author level), so an untrusted author must not be
		// able to have arbitrary shortcodes execute on the published form.
		$form_id          = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;
		$allow_shortcodes = $form_id > 0 && author_can( $form_id, 'unfiltered_html' );
		$processed        = $allow_shortcodes ? do_shortcode( $html_content ) : $html_content;

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_classes ); ?>">
			<?php
			// wp_kses_post() is the final pass on every render path: it strips
			// disallowed elements — including <form> and other form controls,
			// whether author-typed or shortcode-emitted — so the donation form
			// never ends up with a nested form.
			echo wp_kses_post( $processed );
			?>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
