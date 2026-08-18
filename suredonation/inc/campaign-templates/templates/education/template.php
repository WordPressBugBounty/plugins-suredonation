<?php
/**
 * "School / Education" campaign template. Goal by number of supporters.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'id'                   => 'education',
	'name'                 => __( 'School / Education', 'suredonation' ),
	'category'             => __( 'Education', 'suredonation' ),
	'description'          => __( 'Fund a school project, club, or scholarship drive.', 'suredonation' ),
	/* translators: [School] is a literal placeholder the site owner replaces after creating the campaign; keep it as-is. */
	'campaign_title'       => __( 'Support [School] students', 'suredonation' ),
	'campaign_description' => __( 'Explain the project and rally supporters to hit the goal together.', 'suredonation' ),
	'goal_type'            => 'donation_count',
	'goal_amount'          => 500,
	'get_form_blocks'      => static function () {
		return \SureDonation\Inc\Campaign_Templates\Campaign_Templates::build_form_blocks(
			[
				'amounts'     => [ 20, 50, 100 ],
				'button_text' => __( 'Support', 'suredonation' ),
			]
		);
	},
	'get_page_blocks'      => static function ( $ctx ) {
		return \SureDonation\Inc\Campaigns\Campaign_Page::get_default_layout( (int) ( $ctx['campaign_id'] ?? 0 ) );
	},
];
