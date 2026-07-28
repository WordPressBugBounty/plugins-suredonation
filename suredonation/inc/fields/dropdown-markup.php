<?php
/**
 * SureDonation Dropdown Markup Class.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureDonation\Inc\Helper;

/**
 * Dropdown Markup Class.
 *
 * Renders a native <select> enhanced on the front end by tom-select. The native
 * markup is the no-JS fallback; a class-based placeholder (built from tom-select's
 * own .ts-wrapper/.ts-control classes) is shown while the library mounts so there
 * is no flash of the unstyled native control. See assets/build/blocks/dropdown.
 *
 * @since 1.1.1
 */
class Dropdown_Markup extends Base {
	/**
	 * Whether multiple options can be selected.
	 *
	 * @var bool
	 * @since 1.1.1
	 */
	protected $multi_select = false;

	/**
	 * Whether the dropdown is searchable.
	 *
	 * @var bool
	 * @since 1.1.1
	 */
	protected $searchable = true;

	/**
	 * Minimum number of selections (multi-select only; 0 = no minimum).
	 *
	 * @var int
	 * @since 1.1.1
	 */
	protected $min_selection = 0;

	/**
	 * Maximum number of selections (multi-select only; 0 = no maximum).
	 *
	 * @var int
	 * @since 1.1.1
	 */
	protected $max_selection = 0;

	/**
	 * Indices of options preselected by default.
	 *
	 * @var array<int>
	 * @since 1.1.1
	 */
	protected $preselected = [];

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 1.1.1
	 */
	public function __construct( $attributes ) {
		$this->slug          = 'dropdown';
		$this->multi_select  = ! empty( $attributes['multiSelect'] );
		$this->searchable    = ! isset( $attributes['searchable'] ) || ! empty( $attributes['searchable'] );
		$this->min_selection = isset( $attributes['minSelection'] ) ? absint( Helper::get_string_value( $attributes['minSelection'] ) ) : 0;
		$this->max_selection = isset( $attributes['maxSelection'] ) ? absint( Helper::get_string_value( $attributes['maxSelection'] ) ) : 0;
		$this->preselected   = isset( $attributes['preselectedOptions'] ) && is_array( $attributes['preselectedOptions'] )
			? array_map( 'absint', $attributes['preselectedOptions'] )
			: [];

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render dropdown markup.
	 *
	 * @since 1.1.1
	 * @return string
	 */
	public function markup() {
		$classes     = $this->get_field_classes( [ 'sd-dropdown-wrap-block' ] );
		$aria_desc   = $this->get_aria_describedby();
		$placeholder = '' !== $this->placeholder ? $this->placeholder : __( 'Select an option', 'suredonation' );
		$data_slug   = $this->block_slug ? $this->block_slug : $this->unique_slug;

		// Resolve the preselected option labels (single-select keeps only the first).
		$selected_labels = [];
		foreach ( $this->preselected as $index ) {
			if ( isset( $this->options[ $index ]['label'] ) ) {
				$selected_labels[] = Helper::get_string_value( $this->options[ $index ]['label'] );
			}
		}
		if ( ! $this->multi_select && count( $selected_labels ) > 1 ) {
			$selected_labels = [ $selected_labels[0] ];
		}

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<?php echo wp_kses_post( $this->label_markup ); ?>
			<?php echo wp_kses_post( $this->help_markup ); ?>
			<div class="sd-block-wrap">
				<?php echo $this->placeholder_markup( $placeholder, $selected_labels ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal markup, values escaped within. ?>
				<select
					class="sd-dropdown-common sd-dropdown-input<?php echo esc_attr( $this->multi_select ? '' : ' sd-input-common' ); ?>"
					<?php if ( ! $this->multi_select ) { ?>
						name="<?php echo esc_attr( $this->field_name ); ?>"
					<?php } ?>
					id="<?php echo esc_attr( $this->unique_slug ); ?>"
					data-slug="<?php echo esc_attr( $data_slug ); ?>"
					<?php if ( ! empty( $aria_desc ) ) { ?>
						aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
					<?php } ?>
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					data-multiple="<?php echo esc_attr( $this->multi_select ? 'true' : 'false' ); ?>"
					data-searchable="<?php echo esc_attr( $this->searchable ? 'true' : 'false' ); ?>"
					data-min-selection="<?php echo esc_attr( (string) $this->min_selection ); ?>"
					data-max-selection="<?php echo esc_attr( (string) $this->max_selection ); ?>"
					data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
					autocomplete="off"
					tabindex="0"
					<?php echo esc_attr( $this->multi_select ? 'multiple' : '' ); ?>
				>
					<?php if ( ! $this->multi_select ) { ?>
						<option value="" class="sd-dropdown-placeholder-option" <?php echo empty( $selected_labels ) ? 'selected' : ''; ?> disabled><?php echo esc_html( $placeholder ); ?></option>
					<?php } ?>
					<?php foreach ( $this->options as $option ) { ?>
						<?php
						$label = isset( $option['label'] ) ? Helper::get_string_value( $option['label'] ) : '';
						if ( '' === $label ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $label ); ?>" <?php echo in_array( $label, $selected_labels, true ) ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
					<?php } ?>
				</select>
				<?php if ( $this->multi_select ) { ?>
					<input
						type="hidden"
						class="sd-input-common sd-dropdown-hidden"
						name="<?php echo esc_attr( $this->field_name ); ?>"
						data-slug="<?php echo esc_attr( $data_slug ); ?>"
						data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
						data-multiple="true"
						data-min-selection="<?php echo esc_attr( (string) $this->min_selection ); ?>"
						data-max-selection="<?php echo esc_attr( (string) $this->max_selection ); ?>"
						value="<?php echo esc_attr( implode( '|', $selected_labels ) ); ?>"
					/>
				<?php } ?>
			</div>
			<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the class-based placeholder shown until tom-select mounts.
	 *
	 * Reuses tom-select's own .ts-wrapper/.ts-control classes so it is painted by
	 * the same stylesheet (and any custom overrides) as the real control — an exact
	 * match with no duplicated styling. JS removes it once the control is mounted;
	 * CSS hides it entirely when JavaScript is unavailable (the native <select>
	 * remains the fallback). See sass/blocks/default/components/dropdown.scss.
	 *
	 * @param string        $placeholder     Placeholder text.
	 * @param array<string> $selected_labels Preselected option labels.
	 * @return string
	 * @since 1.1.1
	 */
	protected function placeholder_markup( $placeholder, $selected_labels ) {
		$wrapper_classes = 'sd-dropdown-placeholder ts-wrapper ' . ( $this->multi_select ? 'multi plugin-remove_button' : 'single' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_classes ); ?>" aria-hidden="true">
			<div class="ts-control"><div class="ts-dropdown-icon"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" /></svg></div>
				<?php if ( ! empty( $selected_labels ) ) { ?>
					<?php foreach ( $selected_labels as $label ) { ?>
						<div class="item"><?php echo esc_html( $label ); ?></div>
					<?php } ?>
				<?php } else { ?>
					<div class="item sd-dropdown-placeholder-text"><?php echo esc_html( $placeholder ); ?></div>
				<?php } ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
