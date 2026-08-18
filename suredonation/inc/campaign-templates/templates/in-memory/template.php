<?php
/**
 * "In Loving Memory" campaign template.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'in-memory',
	'name'                 => __( 'In Loving Memory', 'suredonation' ),
	'category'             => __( 'Memorial', 'suredonation' ),
	'description'          => __( 'A tribute campaign to honor a loved one.', 'suredonation' ),
	/* translators: [Name] is a literal placeholder the site owner replaces after creating the campaign; keep it as-is. */
	'campaign_title'       => __( 'In loving memory of [Name]', 'suredonation' ),
	'campaign_description' => __( 'Celebrate their life and invite friends and family to give in their memory.', 'suredonation' ),
	'goal_type'            => 'raised_amount',
	'goal_amount'          => 5000,
	'get_form_blocks'      => static function () {
		return \SureDonation\Inc\Campaign_Templates\Campaign_Templates::build_form_blocks(
			[
				'amounts'     => [ 20, 50, 100, 250 ],
				'button_text' => __( 'Give in their memory', 'suredonation' ),
			]
		);
	},
	'get_page_blocks'      => static function ( $ctx ) {
		return \SureDonation\Inc\Campaigns\Campaign_Page::get_default_layout( (int) ( $ctx['campaign_id'] ?? 0 ) );
	},
];
