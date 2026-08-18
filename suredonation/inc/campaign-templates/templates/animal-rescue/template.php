<?php
/**
 * "Animal Rescue" campaign template.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'animal-rescue',
	'name'                 => __( 'Animal Rescue', 'suredonation' ),
	'category'             => __( 'Animal', 'suredonation' ),
	'description'          => __( 'Raise funds to rescue, shelter, and care for animals.', 'suredonation' ),
	'campaign_title'       => __( 'Help us rescue animals in need', 'suredonation' ),
	'campaign_description' => __( 'Share your rescue mission and how donations feed, treat, and rehome animals.', 'suredonation' ),
	'goal_type'            => 'raised_amount',
	'goal_amount'          => 8000,
	'get_form_blocks'      => static function () {
		return \SureDonation\Inc\Campaign_Templates\Campaign_Templates::build_form_blocks(
			[
				'amounts'     => [ 15, 30, 60, 100 ],
				'button_text' => __( 'Donate', 'suredonation' ),
			]
		);
	},
	'get_page_blocks'      => static function ( $ctx ) {
		return \SureDonation\Inc\Campaigns\Campaign_Page::get_default_layout( (int) ( $ctx['campaign_id'] ?? 0 ) );
	},
];
