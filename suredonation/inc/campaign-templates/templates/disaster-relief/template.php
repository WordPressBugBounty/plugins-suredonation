<?php
/**
 * "Disaster Relief" campaign template.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'disaster-relief',
	'name'                 => __( 'Disaster Relief', 'suredonation' ),
	'category'             => __( 'Emergency', 'suredonation' ),
	'description'          => __( 'Rally urgent support for communities hit by a disaster.', 'suredonation' ),
	/* translators: [Region] is a literal placeholder the site owner replaces after creating the campaign; keep it as-is. */
	'campaign_title'       => __( 'Emergency relief for [Region]', 'suredonation' ),
	'campaign_description' => __( 'Describe the situation, the urgency, and how funds provide immediate relief.', 'suredonation' ),
	'goal_type'            => 'raised_amount',
	'goal_amount'          => 25000,
	'get_form_blocks'      => static function () {
		return \SureDonation\Inc\Campaign_Templates\Campaign_Templates::build_form_blocks(
			[
				'amounts'     => [ 25, 50, 100, 500 ],
				'button_text' => __( 'Donate now', 'suredonation' ),
			]
		);
	},
	'get_page_blocks'      => static function ( $ctx ) {
		return \SureDonation\Inc\Campaigns\Campaign_Page::get_default_layout( (int) ( $ctx['campaign_id'] ?? 0 ) );
	},
];
