<?php
/**
 * "Donate for a Cause" campaign template. The versatile general default.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'donate-for-a-cause',
	'name'                 => __( 'Donate for a Cause', 'suredonation' ),
	'category'             => __( 'General', 'suredonation' ),
	'description'          => __( 'A versatile campaign for any cause or nonprofit.', 'suredonation' ),
	'campaign_title'       => __( 'Support our cause', 'suredonation' ),
	'campaign_description' => __( 'Tell your story and invite supporters to contribute to your mission.', 'suredonation' ),
	'goal_type'            => 'raised_amount',
	'goal_amount'          => 5000,
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
