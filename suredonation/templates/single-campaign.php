<?php
/**
 * Single campaign template (classic themes).
 *
 * Renders the campaign page — the title plus the seeded block content — without
 * the theme's single-post chrome (author/category byline and the theme-injected
 * featured image, which the page's own cover block already provides).
 *
 * Block themes are handled separately via register_block_template(); a PHP
 * template cannot reproduce a block theme's header/footer/layout canvas.
 *
 * @package SureDonation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Ensure the campaign block styles are present even if the page has no blocks.
wp_enqueue_style( 'suredonation-campaign-blocks' );

get_header();
?>

<main class="suredonation-campaign-page">
	<?php
	while ( have_posts() ) :
		the_post();
		the_title( '<h1 class="suredonation-campaign-page__title">', '</h1>' );
		the_content();
	endwhile;
	?>
</main>

<?php
get_footer();
