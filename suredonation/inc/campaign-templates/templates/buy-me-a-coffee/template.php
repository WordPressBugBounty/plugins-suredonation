<?php
/**
 * "Buy me a coffee" campaign template.
 *
 * A lightweight tip jar: a short form with coffee-sized amounts and a simple
 * page without a fundraising goal (goal_amount is 0, and the page omits the
 * goal/progress and stats blocks).
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'buy-me-a-coffee',
	'name'                 => __( 'Buy me a coffee', 'suredonation' ),
	'category'             => __( 'Personal', 'suredonation' ),
	'description'          => __( 'A simple tip jar so supporters can buy you a coffee.', 'suredonation' ),
	'campaign_title'       => __( 'Buy me a coffee', 'suredonation' ),
	'campaign_description' => __( 'If you enjoy my work, consider supporting it with a coffee. Every cup helps!', 'suredonation' ),
	'goal_type'            => 'raised_amount',
	'goal_amount'          => 0,

	/**
	 * Build the donation form block markup (tip jar: name, email, coffee amounts).
	 *
	 * @return string Serialized block markup.
	 */
	'get_form_blocks'      => static function () {
		$blocks = [];

		$blocks[] = '<!-- wp:suredonation/input ' . wp_json_encode(
			[
				'label'       => __( 'Your Name', 'suredonation' ),
				'required'    => true,
				'placeholder' => __( 'Enter your name', 'suredonation' ),
				'slug'        => 'donor-name',
				'fieldWidth'  => 50,
			]
		) . ' /-->';

		$blocks[] = '<!-- wp:suredonation/email ' . wp_json_encode(
			[
				'label'       => __( 'Email Address', 'suredonation' ),
				'required'    => true,
				'placeholder' => __( 'Enter your email', 'suredonation' ),
				'slug'        => 'donor-email',
				'fieldWidth'  => 50,
			]
		) . ' /-->';

		$blocks[] = '<!-- wp:suredonation/donation-amount ' . wp_json_encode(
			[
				'label'      => __( 'How many coffees?', 'suredonation' ),
				'required'   => true,
				'choiceType' => 'radio',
				'layout'     => 'horizontal',
				'slug'       => 'donation-amount',
				'options'    => [
					[
						'label' => '$3',
						'value' => '3',
					],
					[
						'label' => '$5',
						'value' => '5',
					],
					[
						'label' => '$10',
						'value' => '10',
					],
				],
			]
		) . ' /-->';

		$blocks[] = '<!-- wp:suredonation/payment ' . wp_json_encode(
			[
				'gateway'             => 'stripe',
				'paymentType'         => 'one-time',
				'amountType'          => 'variable',
				'minimumAmount'       => 0,
				'variableAmountField' => 'donation-amount',
				'customerEmailField'  => 'donor-email',
				'customerNameField'   => 'donor-name',
			]
		) . ' /-->';

		$blocks[] = '<!-- wp:suredonation/donate-button ' . wp_json_encode(
			[
				'buttonText' => __( 'Support', 'suredonation' ),
				'slug'       => 'donate-button',
			]
		) . ' /-->';

		return implode( "\n\n", $blocks );
	},

	/**
	 * Build the campaign page block markup. No goal/progress or stats blocks —
	 * a tip jar has no fundraising goal.
	 *
	 * @param array<string, mixed> $ctx Runtime context (campaign_id, form_id).
	 * @return string Serialized block markup.
	 */
	'get_page_blocks'      => static function ( $ctx ) {
		$campaign_id = (int) ( $ctx['campaign_id'] ?? 0 );
		$form_id     = (int) ( $ctx['form_id'] ?? 0 );
		$excerpt     = \SureDonation\Inc\Helper::get_string_value( get_post_field( 'post_excerpt', $campaign_id ) );

		$blocks = '<!-- wp:post-featured-image {"aspectRatio":"16/9","style":{"border":{"radius":"8px"}}} /-->';

		if ( '' !== trim( $excerpt ) ) {
			$blocks .= "\n\n<!-- wp:paragraph -->\n<p>" . esc_html( $excerpt ) . "</p>\n<!-- /wp:paragraph -->";
		}

		$form_attrs = $form_id
			? sprintf( '{"formId":%d,"campaignId":%d}', $form_id, $campaign_id )
			: sprintf( '{"campaignId":%d}', $campaign_id );

		$blocks .= "\n\n" . '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:suredonation/donation-form ' . $form_attrs . ' /--></div>
<!-- /wp:group -->';

		return $blocks . "\n";
	},
];
