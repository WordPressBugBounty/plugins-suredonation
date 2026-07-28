<?php
/**
 * Bricks element: Donation Form.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Page_Builders\Bricks\Elements;

use SureDonation\Inc\Page_Builders\Bricks\Base_Element;
use SureDonation\Inc\Page_Builders\Page_Builders;
use SureDonation\Inc\Post_Types\Donation_Form as Donation_Form_Cpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Donation_Form class.
 *
 * @since 1.2.0
 */
class Donation_Form extends Base_Element {
	/**
	 * Element name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $name = 'suredonation-donation-form';

	/**
	 * Element icon.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $icon = 'ti-write';

	/**
	 * Element label shown in the builder panel.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Donation Form', 'suredonation' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array_merge( parent::get_keywords(), [ 'form', 'donate' ] );
	}

	/**
	 * Load the donation form assets on the front end and in the builder canvas.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'suredonation-donation-form' );
		wp_enqueue_script( 'suredonation-form-frontend' );
	}

	/**
	 * Register the element controls.
	 *
	 * Mirrors the Gutenberg donation-form block inspector, which exposes only a
	 * campaign selector and a form selector (the form's own display options are
	 * configured in the form editor, not on the embed).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_controls() {
		$this->add_campaign_control();

		$options = Page_Builders::get_donation_form_options();
		unset( $options[''] ); // Bricks selects use 'placeholder', not an empty option.

		$this->controls['formId'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Donation form', 'suredonation' ),
			'type'        => 'select',
			'options'     => $options,
			'searchable'  => true,
			'clearable'   => true,
			'placeholder' => esc_html__( 'Select a donation form', 'suredonation' ),
		];
	}

	/**
	 * Render the donation form block. Unlike the campaign-context elements this
	 * gates on a selected form (a campaign is optional — the block falls back
	 * to the form's own campaign meta).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function render() {
		$settings = $this->settings;
		$form_id  = $this->setting_int( $settings, 'formId' );

		if ( ! $form_id ) {
			$this->render_element_placeholder(
				[
					'icon-class'  => $this->icon,
					'description' => esc_html__( 'Select a donation form to display.', 'suredonation' ),
				]
			);
			return;
		}

		$form = get_post( $form_id );
		if ( ! $form instanceof \WP_Post || 'publish' !== $form->post_status || Donation_Form_Cpt::POST_TYPE !== $form->post_type ) {
			$this->render_element_placeholder(
				[
					'icon-class'  => $this->icon,
					'description' => esc_html__( 'The selected donation form is unavailable.', 'suredonation' ),
				]
			);
			return;
		}

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks root attributes are escaped by the builder.

		echo render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block render output is escaped in the block's render callback.
			[
				'blockName'    => $this->block_name(),
				'attrs'        => $this->get_block_attrs( $settings ),
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

		echo '</div>';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function block_name() {
		return 'suredonation/donation-form';
	}

	/**
	 * Map the element settings to the block attributes.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	protected function get_block_attrs( $settings ) {
		$attrs = [
			'formId' => $this->setting_int( $settings, 'formId' ),
		];

		$campaign_id = $this->setting_int( $settings, 'campaignId' );
		if ( $campaign_id ) {
			$attrs['campaignId'] = $campaign_id;
		}

		return $attrs;
	}
}
