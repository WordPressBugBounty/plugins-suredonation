<?php
/**
 * "Medical Fundraiser" campaign template.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'medical',
	'name'                 => __( 'Medical Fundraiser', 'suredonation' ),
	'category'             => __( 'Medical', 'suredonation' ),
	'description'          => __( 'Raise funds for treatment, surgery, or medical bills.', 'suredonation' ),
	/* translators: [Name] is a literal placeholder the site owner replaces after creating the campaign; keep it as-is. */
	'campaign_title'       => __( 'Help [Name] with medical costs', 'suredonation' ),
	'campaign_description' => __( 'Share the story, the diagnosis, and how donations will help cover treatment and recovery.', 'suredonation' ),
	'goal_type'            => 'raised_amount',
	'goal_amount'          => 10000,
	'get_form_blocks'      => static function () {
		return \SureDonation\Inc\Campaign_Templates\Campaign_Templates::build_form_blocks(
			[
				'amounts'     => [ 25, 50, 100, 250 ],
				'button_text' => __( 'Donate', 'suredonation' ),
			]
		);
	},
	'get_page_blocks'      => static function ( $ctx ) {
		return \SureDonation\Inc\Campaigns\Campaign_Page::get_default_layout( (int) ( $ctx['campaign_id'] ?? 0 ) );
	},
];
