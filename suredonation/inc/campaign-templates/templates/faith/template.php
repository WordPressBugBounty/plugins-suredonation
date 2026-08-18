<?php
/**
 * "Faith / Place of Worship" campaign template.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'faith',
	'name'                 => __( 'Faith / Place of Worship', 'suredonation' ),
	'category'             => __( 'Faith', 'suredonation' ),
	'description'          => __( 'Collect offerings and donations for your congregation.', 'suredonation' ),
	'campaign_title'       => __( 'Support our congregation', 'suredonation' ),
	'campaign_description' => __( 'Invite your community to give and share how contributions sustain your mission.', 'suredonation' ),
	'goal_type'            => 'raised_amount',
	'goal_amount'          => 10000,
	'get_form_blocks'      => static function () {
		return \SureDonation\Inc\Campaign_Templates\Campaign_Templates::build_form_blocks(
			[
				'amounts'     => [ 25, 50, 100, 250 ],
				'button_text' => __( 'Give', 'suredonation' ),
			]
		);
	},
	'get_page_blocks'      => static function ( $ctx ) {
		return \SureDonation\Inc\Campaigns\Campaign_Page::get_default_layout( (int) ( $ctx['campaign_id'] ?? 0 ) );
	},
];
