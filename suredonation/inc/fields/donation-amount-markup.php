<?php
/**
 * SureDonation Donation Amount Markup Class.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Icons\Amount_Icons;
use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donation Amount Markup Class.
 *
 * @since 0.0.1
 */
class Donation_Amount_Markup extends Base {
	/**
	 * Choice type (radio or checkbox).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $choice_type;

	/**
	 * Layout (horizontal or vertical).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $layout;

	/**
	 * Choice width (width of each option in horizontal layout).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $choice_width;

	/**
	 * Whether the optional custom amount input is shown after the presets.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	protected $allow_custom_amount;

	/**
	 * Whether per-option descriptions are rendered under each preset amount.
	 *
	 * @var bool
	 * @since 1.7.0
	 */
	protected $show_descriptions;

	/**
	 * Whether the radio/checkbox icon is drawn on each option.
	 *
	 * Off leaves a plain box that still selects: the native input is only ever
	 * visually hidden by the pill mixin, never removed.
	 *
	 * @var bool
	 * @since 1.7.0
	 */
	protected $show_selection_icon;

	/**
	 * Which kind of visual each option may carry — 'icon' or 'image'.
	 *
	 * Block level rather than per option, matching the control in the editor, so
	 * one form cannot mix the two and leave the pills visually ragged. Both values
	 * stay stored on the option when the author switches, so flipping back does
	 * not discard work.
	 *
	 * @var string
	 * @since 1.7.0
	 */
	protected $option_type;

	/**
	 * Minimum allowed value for the custom amount input. 0 means no min.
	 *
	 * @var float
	 * @since 1.0.0
	 */
	protected $custom_amount_min;

	/**
	 * Maximum allowed value for the custom amount input. 0 means no max.
	 *
	 * @var float
	 * @since 1.0.0
	 */
	protected $custom_amount_max;

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		$this->slug                = 'donation-amount';
		$this->choice_type         = isset( $attributes['choiceType'] ) ? Helper::get_string_value( $attributes['choiceType'] ) : 'radio';
		$this->layout              = isset( $attributes['layout'] ) ? Helper::get_string_value( $attributes['layout'] ) : 'horizontal';
		$this->choice_width        = isset( $attributes['choiceWidth'] ) ? Helper::get_string_value( $attributes['choiceWidth'] ) : '50';
		$this->allow_custom_amount = ! isset( $attributes['allowCustomAmount'] ) || (bool) $attributes['allowCustomAmount'];
		$this->show_descriptions   = ! empty( $attributes['showDescriptions'] );
		// Defaults on, so an attribute saved before this setting existed keeps its icon.
		$this->show_selection_icon = ! isset( $attributes['showSelectionIcon'] ) || (bool) $attributes['showSelectionIcon'];
		// Defaults to 'icon' to match block.json. A form saved before this setting
		// existed has no per-option icon either, so the default renders nothing.
		$option_type             = isset( $attributes['optionType'] ) ? Helper::get_string_value( $attributes['optionType'] ) : 'icon';
		$this->option_type       = in_array( $option_type, [ 'icon', 'image' ], true ) ? $option_type : 'icon';
		$this->custom_amount_min = isset( $attributes['customAmountMin'] ) && is_numeric( $attributes['customAmountMin'] )
			? (float) $attributes['customAmountMin']
			: 0.0;
		$this->custom_amount_max = isset( $attributes['customAmountMax'] ) && is_numeric( $attributes['customAmountMax'] )
			? (float) $attributes['customAmountMax']
			: 0.0;

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render donation-amount markup
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function markup() {
		$classes            = $this->get_field_classes( [ 'sd-' . $this->choice_type . '-mode' ] );
		$vertical_class     = 'vertical' === $this->layout ? ' sd-vertical-layout' : '';
		$choice_width_class = ' sd-choice-width-' . str_replace( '.', '-', $this->choice_width );
		$descriptions_class = $this->has_any_description() ? ' sd-has-descriptions' : '';
		// With no icon the checked state would otherwise be carried by colour
		// alone, so the stylesheet needs a hook to add a non-colour cue.
		$no_icon_class = $this->show_selection_icon ? '' : ' sd-no-selection-icon';
		// Appended last so a block with no icons or images produces the exact same
		// class string it did before this setting existed.
		$visuals_class = $this->has_any_option_visual() ? ' sd-has-option-visuals' : '';
		$wrap_class    = 'sd-block-wrap sd-donation-amount-wrap' . $choice_width_class . $vertical_class . $descriptions_class . $no_icon_class . $visuals_class;
		$svg_type      = 'radio' === $this->choice_type ? 'circle' : 'square';
		$checked_svg   = $this->get_svg_icon( $svg_type . '-checked', 'sd-donation-amount-icon' );
		$unchecked_svg = $this->get_svg_icon( $svg_type . '-unchecked', 'sd-donation-amount-icon-unchecked' );
		$input_name    = 'checkbox' === $this->choice_type ? $this->field_name . '[]' : $this->field_name;

		// Get the default value for the hidden input (preselected option).
		$hidden_value = '';
		if ( ! empty( $this->default ) && ! empty( $this->options ) && is_array( $this->options ) ) {
			foreach ( $this->options as $option ) {
				$option_value = $option['value'] ?? ( $option['label'] ?? '' );
				if ( $this->default === $option_value ) {
					$hidden_value = $option_value;
					break;
				}
			}
		}

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<?php
			/**
			 * Renders above the amount choices, before the payment-type chooser.
			 *
			 * Exists for the currency switcher, which has to sit where the donor
			 * sees it before picking an amount, and which owns the hidden
			 * `suredonation_currency` input the gateways read on submit. Echo
			 * markup directly; it is already inside the block wrapper.
			 *
			 * @param string $block_id This donation-amount block's own id, not the
			 *                         payment block's. Markup echoed here is filtered
			 *                         by wp_kses() with Helper::get_allowed_form_html(),
			 *                         so any attribute it emits has to be named there.
			 * @param int    $form_id  Form being rendered.
			 * @since 1.7.0
			 */
			do_action( 'suredonation_before_donation_amount_field', $this->block_id, (int) $this->form_id );
			?>
			<?php echo wp_kses( $this->render_payment_type_chooser(), Helper::get_allowed_form_html() ); ?>
			<fieldset>
				<!-- Hidden input to store the selected value for use by payment block and form submission -->
				<input
					type="hidden"
					class="sd-input-donation-amount-hidden sd-input-common"
					name="<?php echo esc_attr( $this->field_name ); ?>-value"
					data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					value="<?php echo esc_attr( $hidden_value ); ?>"
				/>
				<?php
				// The id is what the option group's aria-labelledby below points
				// at. Base::generate_label_markup() emits no id of its own, so
				// without this the group resolves to nothing and is left unnamed.
				?>
				<legend class="sd-block-legend" id="sd-label-<?php echo esc_attr( $this->block_id ); ?>">
					<?php echo wp_kses_post( $this->label_markup ); ?>
				</legend>
				<?php echo wp_kses_post( $this->help_markup ); ?>
				<?php
				$show_custom_amount = $this->allow_custom_amount && 'radio' === $this->choice_type;
				?>
				<?php if ( ! empty( $this->options ) && is_array( $this->options ) ) { ?>
					<div class="<?php echo esc_attr( $wrap_class ); ?>" role="group" aria-labelledby="sd-label-<?php echo esc_attr( $this->block_id ); ?>">
						<?php foreach ( $this->options as $index => $option ) { ?>
							<?php
							$option_label = $option['label'] ?? '';
							$option_value = $option['value'] ?? $option_label;
							$option_id    = $this->unique_slug . '-' . $index;
							$is_checked   = $this->default === $option_value;
							$checked_attr = $is_checked ? 'checked' : '';

							/*
							 * The description is mirrored by the canvas preview in
							 * src/blocks/donation-amount/edit.js; tag, class and placement must
							 * stay in sync with it. Both it and aria-describedby render nothing at
							 * all for an option without a description, following the same
							 * conditional-echo shape the $checked_attr line above already uses.
							 * The description sits outside the <label> so the accessible name
							 * stays the amount alone.
							 */
							$option_description = $this->get_option_description( $option );
							$has_description    = '' !== $option_description;
							$description_id     = $option_id . '-help';
							$option_visual      = $this->get_option_visual( $option );
							$single_class       = 'sd-donation-amount-single' . ( $has_description ? ' sd-has-description' : '' ) . ( '' !== $option_visual ? ' sd-has-option-visual' : '' );
							?>
							<div class="<?php echo esc_attr( $single_class ); ?>">
								<input
									type="<?php echo esc_attr( $this->choice_type ); ?>"
									id="<?php echo esc_attr( $option_id ); ?>"
									name="<?php echo esc_attr( $input_name ); ?>"
									value="<?php echo esc_attr( $option_value ); ?>"
									class="sd-input-<?php echo esc_attr( $this->choice_type ); ?>"
									data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
									<?php echo $has_description ? 'aria-describedby="' . esc_attr( $description_id ) . '"' : ''; ?>
									<?php echo esc_attr( $checked_attr ); ?>
								/>
								<div class="sd-block-content-wrap">
									<div class="sd-option-container">
										<?php
										/*
										 * Sibling of the label, never a child: Pro's currency switcher
										 * sets label.textContent on every switch and would erase a
										 * nested visual. Mirrored by the canvas preview in
										 * src/blocks/donation-amount/edit.js.
										 */
										echo wp_kses( $option_visual, Helper::get_allowed_form_html(), array_merge( wp_allowed_protocols(), [ 'data' ] ) );
										?>
										<label for="<?php echo esc_attr( $option_id ); ?>"><?php echo esc_html( $this->format_option_label( $option_label ) ); ?></label>
										<?php echo $has_description ? '<span id="' . esc_attr( $description_id ) . '" class="sd-donation-amount-description">' . esc_html( $option_description ) . '</span>' : ''; ?>
									</div>
									<?php if ( $this->show_selection_icon ) { ?>
									<div class="sd-icon-container">
										<?php
										$allowed_svg = [
											'svg'    => [
												'class'   => true,
												'width'   => true,
												'height'  => true,
												'viewbox' => true,
												'fill'    => true,
												'xmlns'   => true,
												'aria-hidden' => true,
											],
											'circle' => [
												'cx'     => true,
												'cy'     => true,
												'r'      => true,
												'stroke' => true,
												'stroke-width' => true,
												'fill'   => true,
											],
											'rect'   => [
												'x'      => true,
												'y'      => true,
												'width'  => true,
												'height' => true,
												'rx'     => true,
												'stroke' => true,
												'stroke-width' => true,
											],
											'path'   => [
												'd'      => true,
												'stroke' => true,
												'stroke-width' => true,
												'stroke-linecap' => true,
												'stroke-linejoin' => true,
											],
										];
										echo wp_kses( $checked_svg, $allowed_svg );
										echo wp_kses( $unchecked_svg, $allowed_svg );
										?>
									</div>
									<?php } ?>
								</div>
							</div>
						<?php } ?>
						<?php if ( $show_custom_amount ) { ?>
							<div class="sd-donation-amount-single sd-donation-amount-custom">
								<input
									type="number"
									id="sd-custom-amount-<?php echo esc_attr( $this->block_id ); ?>"
									class="sd-input-common sd-donation-amount-custom-input"
									data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
									step="0.01"
									<?php if ( $this->custom_amount_min > 0 ) { ?>
										min="<?php echo esc_attr( (string) $this->custom_amount_min ); ?>"
									<?php } ?>
									<?php if ( $this->custom_amount_max > 0 ) { ?>
										max="<?php echo esc_attr( (string) $this->custom_amount_max ); ?>"
									<?php } ?>
									placeholder="<?php esc_attr_e( 'Enter custom amount', 'suredonation' ); ?>"
									aria-label="<?php esc_attr_e( 'Enter custom amount', 'suredonation' ); ?>"
								/>
							</div>
						<?php } ?>
					</div>
				<?php } ?>
				<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
			</fieldset>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the donor's one-time/recurring chooser, above the preset amounts.
	 *
	 * Only this block or the payment block carries the control, never both:
	 * `get_recurring_chooser_host()` decides from the stored config, and the
	 * payment block asks the same question, so the two cannot disagree however
	 * the admin has ordered them.
	 *
	 * The markup is the `.sd-payment-type-chooser` pill shape the stylesheet was
	 * already written for, and the recurring label is derived from the configured
	 * billing interval rather than author-entered, so it cannot contradict what
	 * the donor is charged.
	 *
	 * The inputs deliberately carry neither `sd-input-radio`, `sd-input-common`
	 * nor `data-slug`: all three are how this block's own handlers, the gateway's
	 * amount lookup and the field collector find the amount field, and a sibling
	 * wearing them would be mistaken for one.
	 *
	 * @return string Chooser markup, or '' when this block is not the host.
	 * @since 1.7.0
	 */
	private function render_payment_type_chooser() {
		$host = Payment_Helper::get_recurring_chooser_host( (int) $this->form_id );

		if ( null === $host || '' === $this->block_slug || $host['slug'] !== $this->block_slug ) {
			return '';
		}

		$labels  = Payment_Helper::get_payment_type_chooser_labels( $host['interval'], (int) $this->form_id );
		$heading = Helper::get_string_value( $host['label'] ?? '' );

		// Each option points at the amount panel it reveals. The checkbox this
		// replaces listed both, because one control toggled both; a radio reveals
		// exactly one.
		$payment_block_id = Helper::get_string_value( $host['payment_block_id'] ?? '' );
		$group            = 'sd-donation-frequency-' . $this->block_id;
		$checked          = $this->get_svg_icon( 'circle-checked', 'sd-choice-pill-icon' );
		$unchecked        = $this->get_svg_icon( 'circle-unchecked', 'sd-choice-pill-icon-unchecked' );

		// The chooser is rendered hidden and revealed by the payment handler. The
		// payment block can decline to render entirely — no gateway connected, or
		// unmet field requirements — and the stored config this block reads cannot
		// see that, so revealing only once a handler exists means a chooser is
		// never shown wired to nothing.
		ob_start();
		?>
		<fieldset class="sd-donation-frequency sd-donation-frequency--pending">
			<?php
			// Clearing the heading hides it visually but must not remove it: a
			// fieldset with no legend leaves the radio group unnamed for screen
			// readers, so the default text stays as the accessible name.
			$heading_class = '' === $heading ? 'sd-donation-frequency-legend sd-screen-reader-text' : 'sd-donation-frequency-legend';
			?>
			<legend class="<?php echo esc_attr( $heading_class ); ?>">
				<?php echo esc_html( '' === $heading ? __( 'Donation Frequency', 'suredonation' ) : $heading ); ?>
			</legend>
			<div class="sd-payment-type-chooser">
				<?php foreach ( [ 'one-time', 'subscription' ] as $choice ) { ?>
					<div class="sd-choice-pill">
						<input
							type="radio"
							id="<?php echo esc_attr( $group . '-' . $choice ); ?>"
							name="<?php echo esc_attr( $group ); ?>"
							value="<?php echo esc_attr( $choice ); ?>"
							class="sd-payment-type-choice"
							<?php if ( '' !== $payment_block_id ) { ?>
								aria-controls="<?php echo esc_attr( 'sd-payment-amount-' . $payment_block_id . '-' . $choice ); ?>"
							<?php } ?>
							<?php // A soft reload would otherwise restore the selection while the script re-runs from scratch. ?>
							autocomplete="off"
							<?php checked( 'one-time', $choice ); ?>
						/>
						<?php
						// The label wraps the whole box so the radio glyph is clickable
						// too, not just the text. The amount options get this from JS.
						?>
						<label class="sd-block-content-wrap" for="<?php echo esc_attr( $group . '-' . $choice ); ?>">
							<div class="sd-option-container">
								<?php echo esc_html( $labels[ $choice ] ); ?>
							</div>
							<div class="sd-icon-container">
								<?php
								echo wp_kses( $checked, Helper::get_allowed_form_html() );
								echo wp_kses( $unchecked, Helper::get_allowed_form_html() );
								?>
							</div>
						</label>
					</div>
				<?php } ?>
			</div>
		</fieldset>
		<?php
		$markup = ob_get_clean();

		return is_string( $markup ) ? $markup : '';
	}

	/**
	 * Resolve the description text for a single option.
	 *
	 * Returns an empty string when descriptions are switched off for the
	 * block, so callers only have to test the result.
	 *
	 * @param mixed $option Single option entry from the block attributes.
	 * @return string Trimmed description, or '' when there is none to show.
	 * @since 1.7.0
	 */
	private function get_option_description( $option ) {
		if ( ! $this->show_descriptions || ! is_array( $option ) ) {
			return '';
		}

		return trim( Helper::get_string_value( $option['description'] ?? '' ) );
	}

	/**
	 * Whether any option will actually render a description.
	 *
	 * The wrap only takes its descriptions modifier once there is text to
	 * show, so a block with the toggle on but nothing written renders exactly
	 * as it did before the setting existed.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private function has_any_description() {
		if ( ! $this->show_descriptions || ! is_array( $this->options ) ) {
			return false;
		}

		foreach ( $this->options as $option ) {
			if ( '' !== $this->get_option_description( $option ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the icon or image attached to a single option.
	 *
	 * Wrapped in an `aria-hidden` span: the radio's accessible name is the amount,
	 * and announcing "book" before "$25" only pads the label. The span also keeps
	 * the visual out of the `<label>` — see the note in inc/fields/CLAUDE.md about
	 * Pro's currency switcher, which rewrites `label.textContent`.
	 *
	 * @param mixed $option Single option entry from the block attributes.
	 * @return string Visual markup, or '' when this option has none.
	 * @since 1.7.0
	 */
	private function get_option_visual( $option ) {
		if ( ! is_array( $option ) ) {
			return '';
		}

		$inner = 'image' === $this->option_type
			? $this->get_option_image( $option )
			: Amount_Icons::render( Helper::get_string_value( $option['icon'] ?? '' ), 'sd-option-icon-svg' );

		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<span class="sd-option-%1$s" aria-hidden="true">%2$s</span>',
			'image' === $this->option_type ? 'image' : 'icon',
			$inner
		);
	}

	/**
	 * Render a single option's image.
	 *
	 * Prefers the attachment render for srcset/sizes/lazy-loading, and falls
	 * back to the stored URL when the id no longer resolves to the same image.
	 *
	 * Why the id is not trusted on its own: attachment ids are NOT remapped on
	 * import (inc/import-export/config-io.php rewrites only `formId`), so on a
	 * migrated site id 42 is somebody else's upload and would render the wrong
	 * picture with no error. The stored URL is compared to decide.
	 *
	 * The comparison is on the full URL rather than the basename. Basenames look
	 * like a cheap identity test and are not: WordPress's own image editor
	 * rewrites an edited attachment to `name-e<timestamp>.ext`, so a basename
	 * check sends every cropped image down the fallback path and silently serves
	 * the pre-edit original at full size.
	 *
	 * `alt` is deliberately empty — the image is decoration beside the amount,
	 * which is already the radio's accessible name.
	 *
	 * @param array<string, mixed> $option Single option entry.
	 * @return string Image markup, or '' when there is nothing to show.
	 * @since 1.7.0
	 */
	private function get_option_image( $option ) {
		$image = $option['image'] ?? null;
		if ( ! is_array( $image ) ) {
			return '';
		}

		$id  = isset( $image['id'] ) ? absint( Helper::get_string_value( $image['id'] ) ) : 0;
		$url = isset( $image['url'] ) ? Helper::get_string_value( $image['url'] ) : '';

		if ( ! $id && '' === $url ) {
			return '';
		}

		if ( $id && $this->attachment_matches_url( $id, $url ) ) {
			$markup = wp_get_attachment_image(
				$id,
				// Not 'thumbnail': that size is hard-cropped by default
				// (thumbnail_crop), which no amount of object-fit can undo, so a
				// wide logo would lose its edges. 'medium' is always soft-cropped.
				'medium',
				false,
				[
					'class' => 'sd-option-image-img',
					'alt'   => '',
				]
			);

			if ( '' !== $markup ) {
				return $markup;
			}
		}

		if ( '' === $url ) {
			return '';
		}

		return sprintf(
			'<img class="sd-option-image-img" src="%s" alt="" loading="lazy" decoding="async" />',
			esc_url( $url )
		);
	}

	/**
	 * Whether an attachment still points at the image the option stored.
	 *
	 * An empty stored URL means the option predates the URL being recorded, so
	 * the id is all there is and is taken on trust.
	 *
	 * Compared on the URL path, not the basename, and with WordPress's own
	 * derived suffixes removed first. Two traps this has to survive, both of
	 * which silently serve the wrong picture if you get them wrong:
	 *
	 * - The image editor rewrites an edited attachment to `name-e<timestamp>.ext`
	 *   and updates `_wp_attached_file`. A naive compare then fails for every
	 *   cropped image and quietly falls back to the pre-edit original at full size.
	 * - A big upload is stored as `name-scaled.ext`, so the same file can be
	 *   recorded under either name depending on when it was picked.
	 *
	 * Scheme and host are ignored too, so a site moved from http to https, or
	 * served from a CDN host, still matches.
	 *
	 * @param int    $id  Attachment id.
	 * @param string $url URL stored on the option.
	 * @return bool
	 * @since 1.7.0
	 */
	private function attachment_matches_url( $id, $url ) {
		if ( '' === $url ) {
			return true;
		}

		$attachment_url = wp_get_attachment_url( $id );
		if ( ! is_string( $attachment_url ) || '' === $attachment_url ) {
			return false;
		}

		return self::normalize_image_path( $attachment_url ) === self::normalize_image_path( $url );
	}

	/**
	 * Reduce an image URL to the path that identifies the underlying file.
	 *
	 * @param string $url Image URL.
	 * @return string Comparable path.
	 * @since 1.7.0
	 */
	private static function normalize_image_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : $url;

		// Drop the suffixes WordPress adds to a derived copy of the same upload,
		// so an edited or scaled attachment still matches the URL recorded for it.
		$path = (string) preg_replace( '/-e\d{13}(?=\.[^.\/]+$)/', '', $path );
		$path = (string) preg_replace( '/-scaled(?=\.[^.\/]+$)/', '', $path );

		return $path;
	}

	/**
	 * Whether any option will actually render a visual.
	 *
	 * Mirrors has_any_description(): the wrap only takes its modifier once there
	 * is something to show, so a block with no icons or images set renders exactly
	 * as it did before this setting existed.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private function has_any_option_visual() {
		if ( ! is_array( $this->options ) ) {
			return false;
		}

		foreach ( $this->options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			if ( 'image' === $this->option_type ) {
				$image = $option['image'] ?? null;
				if ( is_array( $image ) && ( ! empty( $image['id'] ) || ! empty( $image['url'] ) ) ) {
					return true;
				}
				continue;
			}

			if ( Amount_Icons::has( Helper::get_string_value( $option['icon'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format an option label — if the label is purely numeric, prepend the
	 * configured currency symbol. Custom (non-numeric) labels render as-is.
	 *
	 * @param string $label Raw option label.
	 * @return string Display label.
	 * @since 1.0.0
	 */
	private function format_option_label( $label ) {
		$trimmed = trim( $label );

		if ( '' === $trimmed || ! is_numeric( $trimmed ) ) {
			return $label;
		}

		$currency = Payment_Helper::get_global_setting( 'currency', 'USD' );
		$symbol   = Payment_Helper::get_currency_symbol( is_string( $currency ) ? $currency : 'USD' );

		// Position the symbol per the global setting while keeping the raw
		// value (presets intentionally show "$3", not "$3.00").
		return Payment_Helper::position_currency_symbol( $symbol, $trimmed );
	}
}
