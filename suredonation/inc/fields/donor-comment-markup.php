<?php
/**
 * SureDonation Donor Comment Markup Class.
 *
 * @package SureDonation
 * @since 1.6.0
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureDonation\Inc\Helper;

/**
 * Donor Comment Markup Class.
 *
 * Renders the optional public message a donor can leave with their donation.
 * The value rides the standard `fields[slug]` channel like every other field
 * block — `Payment_Helper::get_mapped_donor_comment()` lifts it into the
 * dedicated `donor_comment` column server-side.
 *
 * @since 1.6.0
 */
class Donor_Comment_Markup extends Base {
	/**
	 * Maximum length of text allowed for the comment.
	 *
	 * @var int
	 * @since 1.6.0
	 */
	protected $max_length = 500;

	/**
	 * Number of visible text rows.
	 *
	 * @var int
	 * @since 1.6.0
	 */
	protected $rows = 4;

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 1.6.0
	 */
	public function __construct( $attributes ) {
		$this->slug       = 'donor-comment';
		$this->max_length = isset( $attributes['maxLength'] ) ? absint( Helper::get_string_value( $attributes['maxLength'] ) ) : 500;

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render donor comment markup.
	 *
	 * @since 1.6.0
	 * @return string
	 */
	public function markup() {
		$classes   = $this->get_field_classes();
		$aria_desc = $this->get_aria_describedby();

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<?php echo wp_kses_post( $this->label_markup ); ?>
			<?php echo wp_kses_post( $this->help_markup ); ?>
			<div class="sd-block-wrap">
				<textarea
					class="sd-input-common sd-input-donor-comment"
					name="<?php echo esc_attr( $this->field_name ); ?>"
					id="<?php echo esc_attr( $this->unique_slug ); ?>"
					data-slug="<?php echo esc_attr( $this->block_slug ? $this->block_slug : $this->unique_slug ); ?>"
					rows="<?php echo esc_attr( (string) $this->rows ); ?>"
					<?php if ( ! empty( $aria_desc ) ) { ?>
						aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
					<?php } ?>
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					maxlength="<?php echo esc_attr( (string) $this->max_length ); ?>"
					<?php if ( '' !== $this->placeholder ) { ?>
						placeholder="<?php echo esc_attr( $this->placeholder ); ?>"
					<?php } ?>
				></textarea>
			</div>
			<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
