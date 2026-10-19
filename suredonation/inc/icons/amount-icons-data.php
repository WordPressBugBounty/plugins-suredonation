<?php
/**
 * Donation Amount option icons — GENERATED FILE, DO NOT EDIT BY HAND.
 *
 * Regenerate with: node scripts/generate-amount-icons.js
 *
 * Geometry extracted from lucide-react, which is ISC licensed:
 *
 *   Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as
 *   part of Feather (MIT). All other copyright (c) for Lucide are held by
 *   Lucide Contributors 2022.
 *
 *   Permission to use, copy, modify, and/or distribute this software for any
 *   purpose with or without fee is hereby granted, provided that the above
 *   copyright notice and this permission notice appear in all copies.
 *
 * Only geometry is stored. Stroke and fill are applied by
 * Amount_Icons::render(), because wp_kses() rejects stroke attributes on the
 * <svg> root — see Helper::get_allowed_form_html().
 *
 * @package SureDonation
 * @since 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

return [
	'heart'          => [
		'label' => __( 'Heart', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5',
				],
			],
		],
	],
	'hand-heart'     => [
		'label' => __( 'Giving hand', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11 14h2a2 2 0 0 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 16',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm14.45 13.39 5.05-4.694C20.196 8 21 6.85 21 5.75a2.75 2.75 0 0 0-4.797-1.837.276.276 0 0 1-.406 0A2.75 2.75 0 0 0 11 5.75c0 1.2.802 2.248 1.5 2.946L16 11.95',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm2 15 6 6',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm7 20 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a1 1 0 0 0-2.75-2.91',
				],
			],
		],
	],
	'gift'           => [
		'label' => __( 'Gift', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 7v14',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M20 11v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M7.5 7a1 1 0 0 1 0-5A4.8 8 0 0 1 12 7a4.8 8 0 0 1 4.5-5 1 1 0 0 1 0 5',
				],
			],
			[
				'tag'   => 'rect',
				'attrs' => [
					'x'      => '3',
					'y'      => '7',
					'width'  => '18',
					'height' => '4',
					'rx'     => '1',
				],
			],
		],
	],
	'sparkles'       => [
		'label' => __( 'Impact', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M20 2v4',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M22 4h-4',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '4',
					'cy' => '20',
					'r'  => '2',
				],
			],
		],
	],
	'star'           => [
		'label' => __( 'Featured', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z',
				],
			],
		],
	],
	'award'          => [
		'label' => __( 'Patron tier', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '12',
					'cy' => '8',
					'r'  => '6',
				],
			],
		],
	],
	'hand-coins'     => [
		'label' => __( 'Donation', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11 15h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 17',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm7 21 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm2 16 6 6',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '16',
					'cy' => '9',
					'r'  => '2.9',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '6',
					'cy' => '5',
					'r'  => '3',
				],
			],
		],
	],
	'piggy-bank'     => [
		'label' => __( 'Monthly giving', 'suredonation' ),
		'group' => 'giving',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11 17h3v2a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1v-3a3.16 3.16 0 0 0 2-2h1a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1h-1a5 5 0 0 0-2-4V3a4 4 0 0 0-3.2 1.6l-.3.4H11a6 6 0 0 0-6 6v1a5 5 0 0 0 2 4v3a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M16 10h.01',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 8v1a2 2 0 0 0 2 2h1',
				],
			],
		],
	],
	'users'          => [
		'label' => __( 'Community', 'suredonation' ),
		'group' => 'community',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M16 3.128a4 4 0 0 1 0 7.744',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M22 21v-2a4 4 0 0 0-3-3.87',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '9',
					'cy' => '7',
					'r'  => '4',
				],
			],
		],
	],
	'globe'          => [
		'label' => __( 'Global', 'suredonation' ),
		'group' => 'community',
		'nodes' => [
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '12',
					'cy' => '12',
					'r'  => '10',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 12h20',
				],
			],
		],
	],
	'shield'         => [
		'label' => __( 'Protection', 'suredonation' ),
		'group' => 'community',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z',
				],
			],
		],
	],
	'utensils'       => [
		'label' => __( 'Meal', 'suredonation' ),
		'group' => 'food',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M7 2v20',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7',
				],
			],
		],
	],
	'wheat'          => [
		'label' => __( 'Food bank', 'suredonation' ),
		'group' => 'food',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 22 16 8',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M7.47 8.53 9 7l1.53 1.53a3.5 3.5 0 0 1 0 4.94L9 15l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11.47 4.53 13 3l1.53 1.53a3.5 3.5 0 0 1 0 4.94L13 11l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M20 2h2v2a4 4 0 0 1-4 4h-2V6a4 4 0 0 1 4-4Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11.47 17.47 13 19l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L5 19l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M15.47 13.47 17 15l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L9 15l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M19.47 9.47 21 11l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L13 11l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z',
				],
			],
		],
	],
	'droplet'        => [
		'label' => __( 'Clean water', 'suredonation' ),
		'group' => 'water',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z',
				],
			],
		],
	],
	'glass-water'    => [
		'label' => __( 'Drinking water', 'suredonation' ),
		'group' => 'water',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M5.116 4.104A1 1 0 0 1 6.11 3h11.78a1 1 0 0 1 .994 1.105L17.19 20.21A2 2 0 0 1 15.2 22H8.8a2 2 0 0 1-2-1.79z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M6 12a5 5 0 0 1 6 0 5 5 0 0 0 6 0',
				],
			],
		],
	],
	'book-open'      => [
		'label' => __( 'Education', 'suredonation' ),
		'group' => 'education',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 7v14',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z',
				],
			],
		],
	],
	'graduation-cap' => [
		'label' => __( 'Scholarship', 'suredonation' ),
		'group' => 'education',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M22 10v6',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M6 12.5V16a6 3 0 0 0 12 0v-3.5',
				],
			],
		],
	],
	'pencil'         => [
		'label' => __( 'School supplies', 'suredonation' ),
		'group' => 'education',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm15 5 4 4',
				],
			],
		],
	],
	'heart-pulse'    => [
		'label' => __( 'Health care', 'suredonation' ),
		'group' => 'health',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M3.22 13H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27',
				],
			],
		],
	],
	'stethoscope'    => [
		'label' => __( 'Medical', 'suredonation' ),
		'group' => 'health',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11 2v2',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M5 2v2',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M8 15a6 6 0 0 0 12 0v-3',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '20',
					'cy' => '10',
					'r'  => '2',
				],
			],
		],
	],
	'house'          => [
		'label' => __( 'Shelter', 'suredonation' ),
		'group' => 'shelter',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
				],
			],
		],
	],
	'bed'            => [
		'label' => __( 'Beds provided', 'suredonation' ),
		'group' => 'shelter',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 4v16',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 8h18a2 2 0 0 1 2 2v10',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 17h20',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M6 8v9',
				],
			],
		],
	],
	'tent'           => [
		'label' => __( 'Emergency shelter', 'suredonation' ),
		'group' => 'shelter',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M3.5 21 14 3',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M20.5 21 10 3',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M15.5 21 12 15l-3.5 6',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 21h20',
				],
			],
		],
	],
	'life-buoy'      => [
		'label' => __( 'Rescue', 'suredonation' ),
		'group' => 'shelter',
		'nodes' => [
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '12',
					'cy' => '12',
					'r'  => '10',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm4.93 4.93 4.24 4.24',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm14.83 9.17 4.24-4.24',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm14.83 14.83 4.24 4.24',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm9.17 14.83-4.24 4.24',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '12',
					'cy' => '12',
					'r'  => '4',
				],
			],
		],
	],
	'paw-print'      => [
		'label' => __( 'Animals', 'suredonation' ),
		'group' => 'animals',
		'nodes' => [
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '11',
					'cy' => '4',
					'r'  => '2',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '18',
					'cy' => '8',
					'r'  => '2',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '20',
					'cy' => '16',
					'r'  => '2',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z',
				],
			],
		],
	],
	'dog'            => [
		'label' => __( 'Dogs', 'suredonation' ),
		'group' => 'animals',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11.25 16.25h1.5L12 17z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M16 14v.5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M4.42 11.247A13.152 13.152 0 0 0 4 14.556C4 18.728 7.582 21 12 21s8-2.272 8-6.444a11.702 11.702 0 0 0-.493-3.309',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M8 14v.5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M8.5 8.5c-.384 1.05-1.083 2.028-2.344 2.5-1.931.722-3.576-.297-3.656-1-.113-.994 1.177-6.53 4-7 1.923-.321 3.651.845 3.651 2.235A7.497 7.497 0 0 1 14 5.277c0-1.39 1.844-2.598 3.767-2.277 2.823.47 4.113 6.006 4 7-.08.703-1.725 1.722-3.656 1-1.261-.472-1.855-1.45-2.239-2.5',
				],
			],
		],
	],
	'cat'            => [
		'label' => __( 'Cats', 'suredonation' ),
		'group' => 'animals',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 5c.67 0 1.35.09 2 .26 1.78-2 5.03-2.84 6.42-2.26 1.4.58-.42 7-.42 7 .57 1.07 1 2.24 1 3.44C21 17.9 16.97 21 12 21s-9-3-9-7.56c0-1.25.5-2.4 1-3.44 0 0-1.89-6.42-.5-7 1.39-.58 4.72.23 6.5 2.23A9.04 9.04 0 0 1 12 5Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M8 14v.5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M16 14v.5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11.25 16.25h1.5L12 17l-.75-.75Z',
				],
			],
		],
	],
	'leaf'           => [
		'label' => __( 'Environment', 'suredonation' ),
		'group' => 'environment',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12',
				],
			],
		],
	],
	'tree-pine'      => [
		'label' => __( 'Plant a tree', 'suredonation' ),
		'group' => 'environment',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm17 14 3 3.3a1 1 0 0 1-.7 1.7H4.7a1 1 0 0 1-.7-1.7L7 14h-.3a1 1 0 0 1-.7-1.7L9 9h-.2A1 1 0 0 1 8 7.3L12 3l4 4.3a1 1 0 0 1-.8 1.7H15l3 3.3a1 1 0 0 1-.7 1.7H17Z',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 22v-3',
				],
			],
		],
	],
	'sprout'         => [
		'label' => __( 'Growth', 'suredonation' ),
		'group' => 'environment',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M14 9.536V7a4 4 0 0 1 4-4h1.5a.5.5 0 0 1 .5.5V5a4 4 0 0 1-4 4 4 4 0 0 0-4 4c0 2 1 3 1 5a5 5 0 0 1-1 3',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M4 9a5 5 0 0 1 8 4 5 5 0 0 1-8-4',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M5 21h14',
				],
			],
		],
	],
	'baby'           => [
		'label' => __( 'Children', 'suredonation' ),
		'group' => 'people',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M15 12h.01',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M19.38 6.813A9 9 0 0 1 20.8 10.2a2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M9 12h.01',
				],
			],
		],
	],
	'church'         => [
		'label' => __( 'Faith', 'suredonation' ),
		'group' => 'people',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M10 9h4',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 7v5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M14 21v-3a2 2 0 0 0-4 0v3',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm18 9 3.52 2.147a1 1 0 0 1 .48.854V19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6.999a1 1 0 0 1 .48-.854L6 9',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M6 21V7a1 1 0 0 1 .376-.782l5-3.999a1 1 0 0 1 1.249.001l5 4A1 1 0 0 1 18 7v14',
				],
			],
		],
	],
	'palette'        => [
		'label' => __( 'Arts', 'suredonation' ),
		'group' => 'people',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '13.5',
					'cy' => '6.5',
					'r'  => '.5',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '17.5',
					'cy' => '10.5',
					'r'  => '.5',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '6.5',
					'cy' => '12.5',
					'r'  => '.5',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '8.5',
					'cy' => '7.5',
					'r'  => '.5',
				],
			],
		],
	],
	'music'          => [
		'label' => __( 'Music', 'suredonation' ),
		'group' => 'people',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M9 18V5l12-2v13',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '6',
					'cy' => '18',
					'r'  => '3',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '18',
					'cy' => '16',
					'r'  => '3',
				],
			],
		],
	],
	'medal'          => [
		'label' => __( 'Veterans', 'suredonation' ),
		'group' => 'people',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M11 12 5.12 2.2',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'm13 12 5.88-9.8',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M8 7h8',
				],
			],
			[
				'tag'   => 'circle',
				'attrs' => [
					'cx' => '12',
					'cy' => '17',
					'r'  => '5',
				],
			],
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M12 18v-2h-.5',
				],
			],
		],
	],
	'flag'           => [
		'label' => __( 'Service', 'suredonation' ),
		'group' => 'people',
		'nodes' => [
			[
				'tag'   => 'path',
				'attrs' => [
					'd' => 'M4 22V4a1 1 0 0 1 .4-.8A6 6 0 0 1 8 2c3 0 5 2 7.333 2q2 0 3.067-.8A1 1 0 0 1 20 4v10a1 1 0 0 1-.4.8A6 6 0 0 1 16 16c-3 0-5-2-8-2a6 6 0 0 0-4 1.528',
				],
			],
		],
	],
];
