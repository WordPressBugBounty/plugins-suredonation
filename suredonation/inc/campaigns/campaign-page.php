<?php
/**
 * Campaign Page
 *
 * In SureDonation the campaign post (`suredonation_cmpgn`) IS the campaign page:
 * it is a public, block-editable post with its own permalink. This class owns the
 * "campaign page" concept on top of that post — detecting whether a page has been
 * set up, seeding a default block layout, and resolving the campaign ID for the
 * campaign display blocks.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Campaigns;

use SureDonation\Inc\Traits\Get_Instance;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Campaign_Templates\Campaign_Templates;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign_Page class.
 *
 * @since 1.0.0
 */
class Campaign_Page {
	use Get_Instance;

	/**
	 * Anchor id used to link the donate button to the donation form on the page.
	 *
	 * @since 1.0.0
	 */
	public const FORM_ANCHOR = 'suredonation-donation-form';

	/**
	 * Meta flag marking that the campaign has been auto-seeded once. Gates the
	 * publish hook so it never re-seeds on later saves — letting a user clear the
	 * page and have it stay empty. (Button state still keys off has_page(), and
	 * the Create-Page CTA can re-seed on demand regardless of this flag.)
	 *
	 * @since 1.0.0
	 */
	public const INITIALIZED_META = '_suredonation_campaign_page_initialized';

	/**
	 * Re-entrancy guard so seeding (which calls wp_update_post) does not recurse
	 * through the save_post hook.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	private $is_seeding = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Runs after Campaign_Cpt::maybe_create_default_form() (priority 20) so the
		// default form id is available when the layout is seeded.
		add_action( 'save_post_' . Campaign_Cpt::POST_TYPE, [ $this, 'maybe_seed_layout' ], 30, 2 );

		// Render single campaigns without the theme's single-post chrome (byline /
		// theme featured image) around the seeded block layout. Classic themes swap
		// the PHP template; block themes register a native block template so the
		// theme's header/footer/layout still render through the block canvas.
		add_filter( 'template_include', [ $this, 'load_campaign_template' ] );
		add_action( 'init', [ $this, 'register_campaign_block_template' ] );
	}

	/**
	 * Swap in the plugin's single-campaign template for classic themes.
	 *
	 * Block themes are handled by register_campaign_block_template() instead — a
	 * PHP template cannot reproduce the block theme's header/footer/layout canvas.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 * @since 1.0.0
	 */
	public function load_campaign_template( $template ) {
		if ( wp_is_block_theme() || ! is_singular( Campaign_Cpt::POST_TYPE ) ) {
			return $template;
		}

		$custom = SUREDONATION_DIR . 'templates/single-campaign.php';

		return file_exists( $custom ) ? $custom : $template;
	}

	/**
	 * Register a native block template for single campaigns on block themes.
	 *
	 * The template renders the theme's header/footer parts, the title, and the
	 * seeded page content (post-content) — but omits the post-meta byline and the
	 * template-level featured image, so the campaign's own cover block shows once.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_campaign_block_template() {
		if ( ! wp_is_block_theme() || ! function_exists( 'register_block_template' ) ) {
			return;
		}

		register_block_template(
			'suredonation//single-' . Campaign_Cpt::POST_TYPE,
			[
				'title'       => __( 'Single Campaign', 'suredonation' ),
				'description' => __( 'Campaign page without the theme byline or duplicate featured image.', 'suredonation' ),
				'content'     => self::get_block_template_content(),
				'post_types'  => [ Campaign_Cpt::POST_TYPE ],
			]
		);
	}

	/**
	 * Block markup for the single-campaign block template.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function get_block_template_content() {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group"><!-- wp:post-title {"level":1} /-->

<!-- wp:post-content {"layout":{"type":"constrained"}} /--></main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}

	/**
	 * Seed the default campaign page layout the first time a campaign is published.
	 *
	 * Runs once per campaign (guarded by INITIALIZED_META) so a user who later
	 * clears the page and saves keeps it empty instead of having it re-populated.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 * @since 1.0.0
	 */
	public function maybe_seed_layout( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only seed published campaigns, mirroring default-form creation.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( $this->is_seeding ) {
			return;
		}

		// Auto-seed only once. After the first publish the user owns the page —
		// clearing it must not trigger a re-seed on the next save.
		if ( get_post_meta( $post_id, self::INITIALIZED_META, true ) ) {
			return;
		}

		update_post_meta( $post_id, self::INITIALIZED_META, 1 );

		self::seed_if_empty( $post_id );
	}

	/**
	 * Whether the campaign page has been set up (i.e. its content holds blocks).
	 *
	 * "Empty content = no page" is the single source of truth — it drives both the
	 * admin Create/View button and the seed idempotency guard below.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return bool
	 * @since 1.0.0
	 */
	public static function has_page( $campaign_id ) {
		$post = get_post( $campaign_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_blocks( $post->post_content );
	}

	/**
	 * Seed the default layout into a campaign's content, but only when empty so a
	 * user-built page is never clobbered.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return bool True when the layout was seeded.
	 * @since 1.0.0
	 */
	public static function seed_if_empty( $campaign_id ) {
		$campaign_id = absint( $campaign_id );
		$post        = get_post( $campaign_id );

		if ( ! $post instanceof \WP_Post || Campaign_Cpt::POST_TYPE !== $post->post_type ) {
			return false;
		}

		// Never overwrite content the user already has.
		if ( self::has_page( $campaign_id ) ) {
			return false;
		}

		// Resolve the campaign template (falling back to `general`), apply its hero
		// image, and build its page markup. `general` delegates to the default
		// layout, so the scratch path is unchanged.
		$registry = Campaign_Templates::get_instance();
		$template = $registry->get( Helper::get_string_value( get_post_meta( $campaign_id, Campaign_Cpt::META_TEMPLATE_ID, true ) ) )
			?? $registry->get( Campaign_Templates::GENERAL );

		self::maybe_apply_template_hero( $campaign_id, $template );

		$content = ( $template && isset( $template['get_page_blocks'] ) && is_callable( $template['get_page_blocks'] ) )
			? ( $template['get_page_blocks'] )(
				[
					'campaign_id' => $campaign_id,
					'form_id'     => Campaign_Cpt::get_default_form_id( $campaign_id ),
				]
			)
			: self::get_default_layout( $campaign_id );

		$instance             = self::get_instance();
		$instance->is_seeding = true;
		$result               = wp_update_post(
			[
				'ID'           => $campaign_id,
				'post_content' => $content,
			],
			true
		);
		$instance->is_seeding = false;

		return ! is_wp_error( $result );
	}

	/**
	 * Sideload a template's hero image into the Media Library and set it as the
	 * campaign's featured image, which the page's dynamic post-featured-image block
	 * renders. No-op for templates without a hero (e.g. `general`) or when the
	 * campaign already has a featured image. Fail-soft.
	 *
	 * @param int                       $campaign_id Campaign post ID.
	 * @param array<string, mixed>|null $template    Resolved template.
	 * @return void
	 * @since 1.5.0
	 */
	private static function maybe_apply_template_hero( $campaign_id, $template ) {
		if ( ! is_array( $template ) || empty( $template['hero_path'] ) ) {
			return;
		}

		// Respect an image the user already set.
		if ( has_post_thumbnail( $campaign_id ) ) {
			return;
		}

		$attachment_id = Campaign_Templates::import_image( Helper::get_string_value( $template['hero_path'] ), $campaign_id );

		if ( $attachment_id ) {
			set_post_thumbnail( $campaign_id, $attachment_id );
		}
	}

	/**
	 * Resolve the campaign ID a display block should render for.
	 *
	 * Prefers the block's explicit `campaignId` attribute (so the block can be
	 * embedded on any page) and falls back to the current post — which, on a
	 * singular campaign view, is the campaign itself.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return int Campaign post ID (0 when it cannot be resolved).
	 * @since 1.0.0
	 */
	public static function resolve_campaign_id( $attributes ) {
		$campaign_id = isset( $attributes['campaignId'] ) ? absint( $attributes['campaignId'] ) : 0;

		if ( ! $campaign_id ) {
			$campaign_id = (int) get_the_ID();
		}

		if ( Campaign_Cpt::POST_TYPE !== get_post_type( $campaign_id ) ) {
			return 0;
		}

		// Only published campaigns render publicly — the campaignId attribute is
		// attacker-controllable (any page embed, or the core block-renderer REST
		// endpoint), so without this gate donor data of draft/private/trashed
		// campaigns would leak. Users who can edit the specific campaign still
		// get their in-editor preview (the block-renderer runs as them).
		if (
			'publish' !== get_post_status( $campaign_id ) &&
			! current_user_can( 'edit_post', $campaign_id )
		) {
			return 0;
		}

		return $campaign_id;
	}

	/**
	 * Resolve the avatar URL for a donor shown in a campaign block.
	 *
	 * Returns the default Gravatar, then lets add-ons (e.g. the SureDonation
	 * Pro donor dashboard) substitute a donor-uploaded avatar via the
	 * `suredonation_donor_avatar_url` filter. Anonymous donors never expose a
	 * real email: the filter receives an empty email and the anonymous flag,
	 * and the avatar falls back to the generic "mystery person" image.
	 *
	 * @param string $email        Donor email.
	 * @param bool   $is_anonymous Whether the donation/donor is anonymous.
	 * @param int    $size         Avatar size in pixels.
	 * @return string Avatar URL.
	 * @since 1.0.0
	 */
	public static function donor_avatar_url( $email, $is_anonymous = false, $size = 80 ) {
		$email = $is_anonymous ? '' : (string) $email;

		// Anonymous donors use a constant seed so their email hash is not leaked.
		$avatar_url = (string) get_avatar_url(
			$is_anonymous ? 'anonymous' : $email,
			[
				'size'    => $size,
				'default' => 'mm',
			]
		);

		/**
		 * Filters the donor avatar URL shown in campaign blocks.
		 *
		 * Add-ons can substitute a donor-uploaded avatar for the default
		 * Gravatar. The email is empty for anonymous donors, so anonymity is
		 * preserved (no custom avatar should be resolved for them).
		 *
		 * @since 1.0.0
		 *
		 * @param string               $avatar_url Default avatar URL.
		 * @param array<string, mixed> $context    Donor context: email,
		 *                                          is_anonymous, size.
		 */
		return (string) apply_filters(
			'suredonation_donor_avatar_url',
			$avatar_url,
			[
				'email'        => $email,
				'is_anonymous' => (bool) $is_anonymous,
				'size'         => $size,
			]
		);
	}

	/**
	 * Build the default campaign page block layout.
	 *
	 * Mirrors GiveWP's default layout using SureDonation's campaign display blocks
	 * plus core blocks for the cover image and description. The campaign title is
	 * intentionally omitted — the theme renders it for a singular post.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return string Serialized block markup.
	 * @since 1.0.0
	 */
	public static function get_default_layout( $campaign_id ) {
		$campaign_id = absint( $campaign_id );
		$form_id     = Campaign_Cpt::get_default_form_id( $campaign_id );
		$excerpt     = (string) get_post_field( 'post_excerpt', $campaign_id );

		// Two-column hero matching GiveWP: cover image on the left (60%), and the
		// goal, two stats, and donate button stacked on the right; followed by the
		// description, recent donations, and the donor wall.
		//
		// The donations/donors blocks set "showButton":true explicitly: their
		// donate button defaults to off (so the block is safe to drop on any page
		// without producing a dead "#suredonation-donation-form" link), and the
		// campaign page is the one place that link reliably resolves.
		$layout = '<!-- wp:columns {"style":{"spacing":{"padding":{"top":"0","bottom":"0"}}}} -->
<div class="wp-block-columns" style="padding-top:0;padding-bottom:0"><!-- wp:column {"verticalAlignment":"stretch","width":"60%"} -->
<div class="wp-block-column is-vertically-aligned-stretch" style="flex-basis:60%"><!-- wp:post-featured-image {"aspectRatio":"16/9","width":"100%","height":"100%","style":{"border":{"radius":"8px"}}} /--></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"stretch"} -->
<div class="wp-block-column is-vertically-aligned-stretch"><!-- wp:group {"style":{"dimensions":{"minHeight":"100%"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between","justifyContent":"stretch"}} -->
<div class="wp-block-group" style="min-height:100%"><!-- wp:suredonation/campaign-goal {"campaignId":%campaignId%} /-->

<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group"><!-- wp:suredonation/campaign-stats {"campaignId":%campaignId%,"statistic":"top-donation"} /-->

<!-- wp:suredonation/campaign-stats {"campaignId":%campaignId%,"statistic":"average-donation"} /--></div>
<!-- /wp:group -->

<!-- wp:suredonation/campaign-donate-button {"campaignId":%campaignId%} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

%description_block%<!-- wp:suredonation/campaign-social-sharing {"campaignId":%campaignId%} /-->

<!-- wp:suredonation/campaign-donations {"campaignId":%campaignId%,"showButton":true} /-->

<!-- wp:suredonation/campaign-donors {"campaignId":%campaignId%,"showButton":true} /-->';

		// SureDonation addition (not in GiveWP, which opens a modal): an inline
		// donation form. The donate button scrolls to it via the
		// `#suredonation-donation-form` anchor, which the donation-form block
		// renders on itself, so the group below intentionally carries no anchor
		// (setting one too would duplicate the id on the page).
		$form_attrs = $form_id
			? sprintf( '{"formId":%d,"campaignId":%%campaignId%%}', $form_id )
			: '{"campaignId":%campaignId%}';

		$layout .= "\n\n" . '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:suredonation/donation-form ' . $form_attrs . ' /--></div>
<!-- /wp:group -->';

		// Build the description paragraph only when there is one, so an empty
		// description doesn't leave a stray empty paragraph. esc_html() is the
		// correct escaper for the paragraph's HTML text content and also
		// neutralizes any block-delimiter-like sequences ("-->") in the excerpt.
		$description_block = '';
		if ( '' !== trim( $excerpt ) ) {
			$description_block = "<!-- wp:paragraph -->\n<p>" . esc_html( $excerpt ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}

		return str_replace(
			[ '%campaignId%', '%description_block%' ],
			[ (string) $campaign_id, $description_block ],
			$layout
		) . "\n";
	}
}
