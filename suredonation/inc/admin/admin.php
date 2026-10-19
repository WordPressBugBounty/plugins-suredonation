<?php
/**
 * Admin Interface
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Debug_Log;
use SureDonation\Inc\API\Onboarding_API;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Onboarding;
use SureDonation\Inc\Pdf\Pdf_Utils;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\PayPal\PayPal_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 *
 * @since 0.0.1
 */
class Admin {
	use Get_Instance;

	/**
	 * Where Contact Support writes to.
	 *
	 * @since 1.7.0
	 */
	private const SUPPORT_EMAIL = 'support@suredonation.com';

	/**
	 * Longest support mailto: this class will produce, in characters.
	 *
	 * Mail clients enforce a URL length limit and none of them truncate
	 * politely: the body is dropped, or the link is, and the person is left
	 * looking at an empty composer with no idea anything was missing. 1800 sits
	 * under the shortest limit in common use.
	 *
	 * @since 1.7.0
	 */
	private const SUPPORT_MAILTO_MAX_LENGTH = 1800;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_menu', [ $this, 'hide_forms_submenu' ], 999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'admin_init', [ $this, 'redirect_cpt_listings' ] );
		add_action( 'admin_init', [ $this, 'register_privacy_policy_content' ] );
		add_action( 'admin_init', [ $this, 'register_react_notices' ], 5 );
	}

	/**
	 * Register the payment-gateway React admin notice.
	 *
	 * Bridges the payment/test-mode nudges into the React admin app via
	 * Notice_Manager. Runs on admin_init (priority 5) so the notice is
	 * registered before admin_enqueue_scripts localizes the data for React.
	 *
	 * Two mutually-exclusive states, mirroring the existing PHP/front-end
	 * notice chain:
	 * - No gateway connected           -> prompt to configure a gateway.
	 * - Connected, but site in test mode -> prompt to switch to live mode.
	 * When a gateway is connected and the site is live, no notice shows.
	 *
	 * Capability-gated to manage_options; the data is only consumed on the
	 * SureDonation admin pages, where the React app is enqueued.
	 *
	 * Hooked - admin_init (priority 5)
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function register_react_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! Payment_Helper::is_any_gateway_connected() ) {
			Notice_Manager::register_notice(
				[
					'id'          => 'sd-configure-gateway',
					'variant'     => 'error',
					// translators: the <a></a> tags wrap the inline link text and must be kept intact.
					'message'     => __( 'Your forms cannot accept donations yet. <a>Connect Stripe or PayPal</a> and you can start accepting them today.', 'suredonation' ),
					'link'        => [
						// Deep-link to the gateway connect screen, matching the dashboard Quick Access entry.
						'url' => Payment_Helper::get_settings_url( 'stripe' ),
					],
					'event'       => Analytics::TRACKED_EVENTS['configure_gateway'],
					'dismissible' => false,
				]
			);
			return;
		}

		// The webhook used to be reported here as well. It is a row on the
		// dashboard card now, which is the same screen this notice bar sits on,
		// and saying it twice in one view taught people to read neither.
		//
		// Dropping it from the chain promotes what follows: a site that is
		// connected, unconfigured and in test mode now sees the test-mode
		// notice, which the webhook branch used to return before. That is the
		// more urgent of the two anyway - nothing the site takes is real - and
		// the webhook is still named on the card beneath it.
		if ( 'test' === Payment_Helper::get_payment_mode() ) {
			$settings_url = Payment_Helper::get_settings_url();
			Notice_Manager::register_notice(
				[
					'id'          => 'sd-test-mode-react',
					'variant'     => 'error',
					// translators: the <a></a> tags wrap the inline link text and must be kept intact.
					'message'     => __( 'Supporters cannot donate while your site is in test mode. Anything they try now is a test and no money reaches you. <a>Switch to live mode</a> when you are ready to accept real donations.', 'suredonation' ),
					'link'        => [
						'url' => $settings_url,
					],
					'event'       => Analytics::TRACKED_EVENTS['test_mode'],
					'dismissible' => false,
				]
			);
		}
	}



	/**
	 * Self-describing rows for the dashboard failure card.
	 *
	 * A card rather than an admin notice. A red banner across the top of the
	 * screen people land on is a heavy way to deliver a diagnostic, and it
	 * outranks whatever else that screen was trying to say. The card sits in the
	 * sidebar, says the same thing, and does not take the page over.
	 *
	 * One row per failing category. They read completely differently to a site
	 * owner and must not be collapsed: donations failing means money is not
	 * arriving at all, a payment failing after the charge means the books are
	 * wrong, a notification failing means a donor paid and heard nothing.
	 *
	 * Not dismissible, and deliberately so. These clear themselves when the
	 * thing they describe starts working again - a verified webhook, a clean
	 * email run, a completed donation - so a dismiss button would only let
	 * someone hide a fault that is still costing them money. Deleting the log
	 * clears them too, which is the deliberate way to say "I have dealt with
	 * this".
	 *
	 * Each row carries its own copy, so the front end has no rules of its own to
	 * keep in step. Nothing here comes from the log text: the title carries a post
	 * title this site owns, and the message is fixed copy. The entries themselves
	 * stay in the file, which is what keeps text authored by an anonymous reporter
	 * out of a payload that every admin page carries - the one thing a row does
	 * not carry is its support email, which holds an excerpt of them and is built
	 * on request instead.
	 *
	 * @since 1.7.0
	 * @return array<int,array<string,mixed>>
	 */
	private function get_failure_action_items() {
		if ( ! Helper::current_user_can() ) {
			return [];
		}

		// The logging switch is the opt-out for the recorded failures, and only
		// for those. Someone who turned recording off has said they do not want
		// to be told about faults it wrote down; they have said nothing about a
		// webhook that was never set up, and the log has no bearing on whether
		// it was.
		$items = Debug_Log::is_enabled() ? $this->get_recorded_failure_items() : [];

		// After the failures, not before. A webhook that is not configured
		// costs reconciliation; donations that are failing cost donations.
		$webhook = $this->get_webhook_action_item();

		if ( ! empty( $webhook ) ) {
			$items[] = $webhook;
		}

		return $items;
	}

	/**
	 * One row per category the log has recorded a failure for.
	 *
	 * @since 1.7.0
	 * @return array<int,array<string,mixed>>
	 */
	private function get_recorded_failure_items() {
		// One shape for every row: "We noticed a <thing> failure on <campaign>."
		// SureForms words its Form Checks this way and the two are read by the
		// same people. It also holds the title to naming what broke and leaves
		// the message below to say what it cost; the previous wording tried to
		// do both, and each row argued its case differently.
		//
		// Configuration checks are deliberately not in this voice: nothing was
		// noticed happening, something was never set up. SureForms draws the
		// same line: its caching row reads "%s may interfere with your forms".
		$copy = [
			'donation'     => [
				/* translators: %s: campaign or form name. */
				'titled'  => __( 'We noticed a donation failure on %s.', 'suredonation' ),
				'generic' => __( 'We noticed a donation failure.', 'suredonation' ),
				'message' => __( 'Supporters are trying to give and cannot complete the payment.', 'suredonation' ),
			],
			'payment'      => [
				/* translators: %s: campaign or form name. */
				'titled'  => __( 'We noticed a payment recording failure on %s.', 'suredonation' ),
				'generic' => __( 'We noticed a payment recording failure.', 'suredonation' ),
				'message' => __( 'The money reached your gateway but SureDonation could not record it, so your donation list is incomplete.', 'suredonation' ),
			],
			'recurring'    => [
				/* translators: %s: campaign or form name. */
				'titled'  => __( 'We noticed a renewal failure on %s.', 'suredonation' ),
				'generic' => __( 'We noticed a renewal failure.', 'suredonation' ),
				'message' => __( 'Repeat supporters stop giving without ever being told.', 'suredonation' ),
			],
			'notification' => [
				/* translators: %s: campaign or form name. */
				'titled'  => __( 'We noticed a notification failure on %s.', 'suredonation' ),
				'generic' => __( 'We noticed a notification failure.', 'suredonation' ),
				'message' => __( 'A supporter paid and heard nothing back.', 'suredonation' ),
			],
			'integration'  => [
				/* translators: %s: campaign or form name. */
				'titled'  => __( 'We noticed an integration failure on %s.', 'suredonation' ),
				'generic' => __( 'We noticed an integration failure.', 'suredonation' ),
				'message' => __( 'The records on the other side are incomplete.', 'suredonation' ),
			],
		];

		$failures = Debug_Log::get_failures();
		$items    = [];
		$is_test  = 'test' === Payment_Helper::get_payment_mode();

		// Ordered by what the failure costs, not by what the option happens to
		// hold, so the worst row is the one read first.
		foreach ( array_keys( $copy ) as $category ) {
			if ( ! isset( $failures[ $category ] ) ) {
				continue;
			}

			// Scoped to the mode the failure happened in, not deleted on a mode
			// change. A test-mode fault carried into live mode reported itself
			// with the live wording - "the money reached your gateway" - about
			// money that never existed, and clearing the counts instead would
			// have hidden genuine live faults the moment someone dipped into
			// test mode to reproduce one. Scoping fixes both directions and
			// destroys nothing; the log file keeps every entry either way, each
			// carrying its own mode.
			$recorded_mode = Helper::get_string_value( $failures[ $category ]['mode'] ?? '' );

			// A failure recorded before this field existed has no mode to
			// compare, and is shown rather than hidden: a warning that silently
			// stops appearing after an update is worse than one shown in the
			// wrong mode.
			if ( '' !== $recorded_mode && Payment_Helper::get_payment_mode() !== $recorded_mode ) {
				continue;
			}

			$label = Helper::get_string_value( $failures[ $category ]['label'] ?? '' );

			$items[] = [
				'id'            => 'sd-failure-' . $category,
				'category'      => $category,
				'title'         => '' !== $label
					? sprintf( $copy[ $category ]['titled'], $label )
					: $copy[ $category ]['generic'],
				// Dropped in test mode rather than replaced. Each of these says
				// what the failure cost - "supporters cannot complete the
				// payment" - and in test mode none of it is true, because
				// nobody could have given anyway. The caveat that replaces it
				// is the same sentence for every category, so it is said once
				// for the card instead of repeated on every row.
				'message'       => $is_test ? '' : $copy[ $category ]['message'],
				'count'         => Helper::get_integer_value( $failures[ $category ]['count'] ?? 0 ),
				// The category, not a finished mailto:. Building one here means
				// building the support report here, and the report carries the tail
				// of the log - text an anonymous caller wrote through the public
				// reporting endpoint - which would then sit in the page source of
				// every SureDonation admin screen, and cost a read of a file capped
				// at 1 MB on every one of those page loads. The card asks for it
				// when somebody presses the button.
				'support_label' => __( 'Contact Support', 'suredonation' ),
				'support_for'   => $category,
			];
		}

		return $items;
	}

	/**
	 * The webhook row, when Stripe is connected and its webhook is not set up.
	 *
	 * The same state the admin notice reports, said again where someone is
	 * already reading about what is wrong with their donations. Nothing here is
	 * recorded in the log: it is a configuration check, answered by asking
	 * Stripe's own helper, so it appears on a site that has never had a
	 * failure.
	 *
	 * No Contact Support on this one. It is the site owner's to fix and the
	 * screen that fixes it is one click away, so a support queue would be the
	 * wrong first suggestion.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed> Empty when the webhook is configured or
	 *                             Stripe is not connected.
	 */
	private function get_webhook_action_item() {
		if ( ! Stripe_Helper::is_stripe_connected() || Stripe_Helper::is_webhook_configured() ) {
			return [];
		}

		return [
			'id'        => 'sd-webhook-not-configured',
			'category'  => 'webhook',
			'title'     => __( 'Stripe webhooks are not configured.', 'suredonation' ),
			'message'   => __( 'Webhooks keep SureDonation in sync with Stripe by automatically updating donation and subscription data.', 'suredonation' ),
			'count'     => 0,
			'cta_label' => __( 'Configure', 'suredonation' ),
			'cta_url'   => Payment_Helper::get_settings_url( 'stripe' ),
			// Carried over with the row. This check used to be an admin notice,
			// and the notice reported its CTA under this event; moving it here
			// without the event would have left the allowlist entry with nothing
			// sending it and quietly ended a metric that was already running.
			'event'     => Analytics::TRACKED_EVENTS['webhook'],
		];
	}

	/**
	 * A pre-addressed support email for one failure.
	 *
	 * A mailto: rather than the contact page, because the composer can carry the
	 * report and a web form cannot. The site, the versions, which categories are
	 * failing and the tail of the log are already written out by
	 * Debug_Log::get_support_report(), so reporting a fault is one click and a
	 * send rather than "download the log and attach it".
	 *
	 * The log is in the body because mailto: has no attachment parameter -
	 * browsers drop any attempt to add one - and it is a tail rather than the
	 * whole file because a megabyte of JSON exceeds the URL length every mail
	 * client enforces.
	 *
	 * Subject and body are English on every site, deliberately untranslated:
	 * they are written for SureDonation support, and a plain literal cannot be
	 * rewritten by a locale, a translation plugin or a gettext filter.
	 *
	 * Built when someone presses Contact Support, never as part of the admin
	 * bootstrap: the report inside it holds the tail of the log, and the log is
	 * written through a public endpoint.
	 *
	 * The label is resolved here rather than accepted from the caller. This is
	 * reached over REST, and a caller-supplied campaign name would be text of
	 * their choosing in an email addressed to our own support queue.
	 *
	 * @param string $category One of Debug_Log::CATEGORIES.
	 * @since 1.7.0
	 * @return string
	 */
	public function get_support_contact_url( $category ) {
		$failures = Debug_Log::get_failures();
		$label    = Helper::get_string_value( $failures[ $category ]['label'] ?? '' );
		$host     = Helper::get_string_value( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$copy     = $this->get_support_copy( $category );
		$subject  = sprintf( $copy['subject'], '' !== $host ? $host : 'my site' );

		$url = $this->build_support_mailto_within_limit( $category, $label, $subject );

		// The last resort: the subject alone still names the problem and the site.
		if ( '' === $url ) {
			$url = $this->build_support_mailto( $subject );
		}

		// Escaped on the way out even though every part of it is built here. The
		// campaign label reaches the body from a post title, and the card renders
		// this straight into an href.
		return esc_url_raw( $url, [ 'mailto' ] );
	}

	/**
	 * The fullest support mailto: that fits SUPPORT_MAILTO_MAX_LENGTH.
	 *
	 * A mailto: is a URL and every client enforces a length limit on it.
	 * Overrunning it does not truncate politely, it drops the body or the whole
	 * link. So the cap is measured on the encoded URL rather than the raw report:
	 * JSON-escaped non-ASCII text grows about eight times once percent-encoded.
	 * The log gives way first, because the site details are the part support
	 * cannot do without.
	 *
	 * @param string $category The failure being reported.
	 * @param string $label    Campaign or form it was recorded against, or ''.
	 * @param string $subject  Subject line.
	 * @since 1.7.0
	 * @return string The URL, or '' when even the shortest body is too long.
	 */
	private function build_support_mailto_within_limit( $category, $label, $subject ) {
		$opening = $this->get_support_message( $category, $label );

		foreach ( [ 1200, 800, 400 ] as $budget ) {
			// CRLF, not "\n". RFC 6068 leaves the line ending to the client and
			// the major composers normalise either, but Outlook renders a bare LF
			// body as one run-on line - which is exactly the report an agent then
			// has to read.
			$body = str_replace(
				"\n",
				"\r\n",
				$opening . "\n\n" . Debug_Log::get_support_report( $budget )
			);

			$url = $this->build_support_mailto( $subject, $body );

			if ( strlen( $url ) <= self::SUPPORT_MAILTO_MAX_LENGTH ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * How one category is named to support.
	 *
	 * Two strings per category: the subject, and the sentence used when nothing
	 * has been counted. Kept apart from the card's own copy, which is written
	 * for a site owner - support is reading for a different thing and the
	 * wording that works on a dashboard reads oddly in a ticket.
	 *
	 * @param string $category One of Debug_Log::CATEGORIES.
	 * @since 1.7.0
	 * @return array{subject:string,anon:string}
	 */
	private function get_support_copy( $category ) {
		$copy = [
			'donation'     => [
				'subject' => 'SureDonation: donations are failing on %s',
				'anon'    => 'SureDonation has recorded donations on %s that could not be completed.',
			],
			'payment'      => [
				'subject' => 'SureDonation: a payment was not recorded on %s',
				'anon'    => 'SureDonation has recorded payments on %s that reached the gateway but were not recorded.',
			],
			'recurring'    => [
				'subject' => 'SureDonation: recurring donations are not renewing on %s',
				'anon'    => 'SureDonation has recorded recurring donations on %s that could not be renewed.',
			],
			'notification' => [
				'subject' => 'SureDonation: donation emails are not being sent on %s',
				'anon'    => 'SureDonation recorded donations on %s but could not send the emails for them.',
			],
			'integration'  => [
				'subject' => 'SureDonation: an integration is not receiving donations on %s',
				'anon'    => 'SureDonation recorded donations on %s but could not pass them to a connected service.',
			],
		];

		return $copy[ $category ] ?? [
			'subject' => 'SureDonation: a problem with donations on %s',
			'anon'    => 'SureDonation has recorded a problem with donations on %s.',
		];
	}

	/**
	 * The sentence that opens the report, with the count in it.
	 *
	 * @param string $category One of Debug_Log::CATEGORIES.
	 * @param int    $count    How many times it has failed.
	 * @since 1.7.0
	 * @return string
	 */
	private function get_support_count_sentence( $category, $count ) {
		$sentences = [
			'donation'     => [
				'SureDonation has recorded %d donation that could not be completed.',
				'SureDonation has recorded %d donations that could not be completed.',
			],
			'payment'      => [
				'SureDonation took %d payment at the gateway but could not record it.',
				'SureDonation took %d payments at the gateway but could not record them.',
			],
			'recurring'    => [
				'SureDonation could not renew %d recurring donation.',
				'SureDonation could not renew %d recurring donations.',
			],
			'notification' => [
				'SureDonation recorded %d donation but could not send the email for it.',
				'SureDonation recorded %d donations but could not send the emails for them.',
			],
			'integration'  => [
				'SureDonation recorded %d donation but could not pass it to a connected service.',
				'SureDonation recorded %d donations but could not pass them to a connected service.',
			],
		];

		$pair = $sentences[ $category ] ?? [
			'SureDonation has recorded %d problem with donations on this site.',
			'SureDonation has recorded %d problems with donations on this site.',
		];

		return sprintf( 1 === $count ? $pair[0] : $pair[1], $count );
	}

	/**
	 * The part of the report written as prose.
	 *
	 * A greeting, what happened, and the campaign it happened on. The site
	 * details and the log follow from Debug_Log::get_support_report().
	 *
	 * With nothing counted it describes the failure without a number rather
	 * than reporting a count of one nobody recorded, which is a figure support
	 * would then chase.
	 *
	 * @param string $category One of Debug_Log::CATEGORIES.
	 * @param string $label    Campaign or form it was recorded against, or ''.
	 * @since 1.7.0
	 * @return string
	 */
	private function get_support_message( $category, $label = '' ) {
		$failures = Debug_Log::get_failures();
		$count    = Helper::get_integer_value( $failures[ $category ]['count'] ?? 0 );
		$copy     = $this->get_support_copy( $category );
		$host     = Helper::get_string_value( wp_parse_url( home_url(), PHP_URL_HOST ) );

		$lines = [
			'Hello SureDonation support,',
			'',
			$count > 0
				? $this->get_support_count_sentence( $category, $count )
				: sprintf( $copy['anon'], '' !== $host ? $host : 'my site' ),
		];

		if ( '' !== $label ) {
			$lines[] = '';
			$lines[] = 'Campaign: ' . $label;
		}

		return implode( "\n", $lines );
	}

	/**
	 * A mailto: to the support inbox.
	 *
	 * @param string $subject Subject line.
	 * @param string $body    Body with CRLF line endings. Omitted when empty.
	 * @since 1.7.0
	 * @return string
	 */
	private function build_support_mailto( $subject, $body = '' ) {
		$query = [ 'subject' => $subject ];

		if ( '' !== $body ) {
			$query['body'] = $body;
		}

		// RFC 3986, so a space is %20 rather than +. A mail client reading a
		// mailto: query the form-encoded way shows literal plus signs through the
		// whole report.
		return 'mailto:' . self::SUPPORT_EMAIL . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Register suggested privacy-policy text for the plugin's data transfers.
	 *
	 * Surfaces the Phone field's automatic country detection (which sends the
	 * visitor's IP to ipapi.co) in the WordPress Privacy Policy Guide
	 * (Settings → Privacy → Policy Guide) so site owners can disclose it in
	 * their own policy. This only suggests text to admins — it publishes
	 * nothing and is not shown to donors.
	 *
	 * @since 1.1.1
	 * @return void
	 */
	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = __( 'When a Phone field with automatic country detection is used, this site sends the visitor’s IP address to a third-party service (ipapi.co) to determine their country code. The IP address is not stored for this purpose; the resulting country code is cached temporarily. Automatic detection can be disabled per Phone field, or site-wide via the “suredonation_phone_geo_enabled” filter.', 'suredonation' );

		wp_add_privacy_policy_content( 'SureDonation', wp_kses_post( wpautop( $content ) ) );
	}

	/**
	 * Redirect the default CPT list screens for suredonation_form and
	 * suredonation_cmpgn to their corresponding plugin pages.
	 *
	 * For the form listing, attempt to resolve the originating campaign
	 * from the referer (the "back" arrow on the form editor sends the
	 * user here) and redirect to that campaign's single view.
	 *
	 * Campaign creation (post-new.php) is redirected too, so bookmarks and
	 * third-party links to the raw editor land in the creation drawer rather
	 * than producing a half-configured campaign. New forms keep using
	 * post-new.php — the campaign screen links there with a campaign_id.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_cpt_listings() {
		global $pagenow;

		if ( 'edit.php' !== $pagenow && 'post-new.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post_type from query, no state mutation.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

		if ( 'post-new.php' === $pagenow ) {
			/*
			 * Only for users who can actually open the destination: the admin app
			 * requires manage_options, while the campaign post type is creatable
			 * by any role with edit_posts. Redirecting those roles would trade the
			 * editor for a permission error.
			 */
			if ( Campaign_Cpt::POST_TYPE === $post_type && current_user_can( 'manage_options' ) ) {
				wp_safe_redirect( Campaign_Cpt::get_create_url() );
				exit;
			}
			return;
		}

		if ( 'suredonation_form' === $post_type ) {
			$campaign_id = $this->resolve_campaign_id_from_referer();
			$target      = $campaign_id
				? admin_url( 'admin.php?page=suredonation#/campaigns/' . $campaign_id )
				: admin_url( 'admin.php?page=suredonation#/campaigns' );

			wp_safe_redirect( $target );
			exit;
		}

		if ( Campaign_Cpt::POST_TYPE === $post_type ) {
			wp_safe_redirect( admin_url( 'admin.php?page=suredonation#/campaigns' ) );
			exit;
		}
	}

	/**
	 * Resolve the campaign ID from the referer when the form list page
	 * is reached from the form editor's "back" button.
	 *
	 * @return int Campaign ID, or 0 when it cannot be determined.
	 * @since 1.0.0
	 */
	private function resolve_campaign_id_from_referer() {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			return 0;
		}

		$parts = wp_parse_url( $referer );
		if ( empty( $parts['query'] ) ) {
			return 0;
		}

		$query = [];
		parse_str( $parts['query'], $query );

		if ( empty( $query['post'] ) || ! is_numeric( $query['post'] ) ) {
			return 0;
		}

		$form_id = absint( $query['post'] );
		if ( 'suredonation_form' !== get_post_type( $form_id ) ) {
			return 0;
		}

		$campaign_id = get_post_meta( $form_id, '_suredonation_campaign_id', true );
		if ( ! is_numeric( $campaign_id ) ) {
			return 0;
		}
		return absint( $campaign_id );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 * @since 0.0.1
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on our plugin pages.
		if ( strpos( $hook, 'suredonation' ) === false ) {
			return;
		}

		// Onboarding gets its own lean bundle — bail before enqueuing the
		// main admin app on that page.
		if ( false !== strpos( $hook, Onboarding::PAGE_SLUG ) ) {
			$this->enqueue_onboarding_assets();
			return;
		}

		$asset_file = SUREDONATION_DIR . 'assets/build/admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		// Enqueue WordPress editor for TinyMCE support.
		wp_enqueue_editor();

		// Enqueue the media library so the campaign image picker can open it.
		wp_enqueue_media();

		// Enqueue editor buttons style (needed for TinyMCE icons).
		wp_enqueue_style( 'editor-buttons' );

		// Enqueue admin app script.
		wp_enqueue_script(
			'suredonation-admin',
			SUREDONATION_URL . 'assets/build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Enqueue admin app styles.
		wp_enqueue_style(
			'suredonation-admin',
			SUREDONATION_URL . 'assets/build/admin.css',
			[],
			$asset['version']
		);

		// The campaign drawer sits at a very high z-index. Lift the media library
		// above it, and keep the media modal above its OWN backdrop so the picker
		// is not dimmed by either overlay.
		wp_add_inline_style(
			'suredonation-admin',
			'.media-modal-backdrop{z-index:2147483646 !important;}.media-modal{z-index:2147483647 !important;}'
		);

		// Load JS translations for the admin app.
		wp_set_script_translations( 'suredonation-admin', 'suredonation' );

		// Campaign guided tour resume point (empty when there is none).
		$campaign_tour_progress = get_user_meta( get_current_user_id(), Onboarding_API::TOUR_PROGRESS_META, true );
		$campaign_tour_progress = is_string( $campaign_tour_progress ) ? $campaign_tour_progress : '';

		// Campaign whose first-run tour the onboarding wizard armed (empty = none).
		$campaign_tour_pending = get_user_meta( get_current_user_id(), Onboarding_API::TOUR_PENDING_META, true );
		$campaign_tour_pending = is_scalar( $campaign_tour_pending ) ? (string) $campaign_tour_pending : '';

		// Localize script with plugin data.
		/**
		 * Filters the bootstrap data localized to the SureDonation admin React app
		 * (exposed as window.suredonation_admin). Lets features inject additional
		 * data before it reaches React.
		 *
		 * @since 1.3.0
		 * @param array<string, mixed> $localized_data The admin app bootstrap data.
		 */
		$localized_data = apply_filters(
			'suredonation_admin_app_data',
			[
				'version'                   => SUREDONATION_VER,
				'apiUrl'                    => rest_url( 'suredonation/v1' ),
				'mcp_url'                   => rest_url( 'suredonation/v1/mcp' ),
				'nonce'                     => wp_create_nonce( 'wp_rest' ),
				'docsURL'                   => 'https://suredonation.com/docs/',
				'plugin_url'                => SUREDONATION_URL,
				'site_url'                  => site_url(),
				'admin_url'                 => admin_url(),
				'is_first_campaign_created' => $this->has_campaigns(),
				'is_onboarding_completed'   => Onboarding::get_instance()->is_completed(),
				'campaign_tour_seen'        => 'yes' === get_user_meta( get_current_user_id(), Onboarding_API::TOUR_SEEN_META, true ),
				'campaign_tour_progress'    => $campaign_tour_progress,
				'campaign_tour_pending'     => $campaign_tour_pending,
				'rotating_plugin_banner'    => $this->get_rotating_plugin_banner(),
				'smart_tags'                => Helper::get_smart_tags(),
				'is_pro_active'             => defined( 'SUREDONATION_PRO_VER' ),
				'pro_plugin_version'        => defined( 'SUREDONATION_PRO_VER' ) ? SUREDONATION_PRO_VER : '',
				'pro_plugin_name'           => defined( 'SUREDONATION_PRO_PRODUCT' ) ? SUREDONATION_PRO_PRODUCT : 'SureDonation Pro',
				'is_license_active'         => defined( 'SUREDONATION_PRO_VER' ) && class_exists( 'SureDonationPro\Inc\Licensing\Licensing' ) ? \SureDonationPro\Inc\Licensing\Licensing::is_license_active() : false,
				'pdf_library_exists'        => Pdf_Utils::check_if_library_exists(),
				'php_version_compatible'    => Pdf_Utils::is_php_compatible(),
				'pdf_nonce'                 => wp_create_nonce( 'suredonation_pdf_nonce' ),
				// Signs the debug-log download link, which is a plain navigation
				// rather than a fetch because only a navigation reaches the browser's
				// save dialog.
				'debug_log_nonce'           => wp_create_nonce( 'suredonation_debug_log' ),
				// Self-describing rows for the dashboard card. The copy is built
				// from the category and a post title this site owns, never from
				// the log text, which has no business in a payload every admin
				// page carries and is only ever read out of the file.
				'failure_items'             => $this->get_failure_action_items(),
				// Said once for the whole card. Someone wiring up a gateway is
				// exactly who needs to hear it is failing, but they have not
				// lost a donation over it.
				'failure_items_note'        => 'test' === Payment_Helper::get_payment_mode()
					? __( 'These were test-mode failures, so no money was involved. The same failure would stop a real donation.', 'suredonation' )
					: '',
				'ajax_url'                  => admin_url( 'admin-ajax.php' ),
				'plugin_installer_nonce'    => wp_create_nonce( 'updates' ),
				'plugin_manager_nonce'      => wp_create_nonce( 'suredonation_plugin_manager' ),
				'has_abilities_api'         => class_exists( 'WP_Ability' ),
				'current_user_login'        => wp_get_current_user()->user_login,
				'integrations'              => [
					'sure_triggers' => Helper::get_ottokit_integration(),
				],
				// Bootstrap settings data so the General currency list and the
				// PayPal connection state render synchronously (no post-mount
				// fetch flicker). PayPal data is the non-sensitive subset only.
				'payments'                  => [
					// The admin figures are all scoped to this server-side, and the
					// query cache is keyed by it so a Test-to-Live switch is a miss
					// rather than five more minutes of rehearsal numbers.
					'payment_mode'             => Payment_Helper::get_payment_mode(),
					'currencies'               => Payment_Helper::get_currencies_list(),
					'paypal'                   => PayPal_Helper::get_connection_state(),
					'currency_sign_position'   => Payment_Helper::get_currency_sign_position(),
					'is_any_gateway_connected' => Payment_Helper::is_any_gateway_connected(),
					'gateway_config_url'       => Payment_Helper::get_settings_url( 'stripe' ),
				],
			]
		);

		wp_localize_script( 'suredonation-admin', 'suredonation_admin', $localized_data );
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_menu() {
		$menu_slug = 'suredonation';

		// SureDonation mark for the admin menu — single path with evenodd
		// fill so the inner heart cuts a hole that reveals the sidebar
		// background (mirrors the pattern used by SureForms).
		$menu_icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M0 3.64405C0 1.6315 1.62712 0 3.63425 0H20.2517C22.2588 0 23.886 1.6315 23.886 3.64405V20.3063C23.886 22.3188 22.2588 23.9503 20.2517 23.9503H3.63425C1.62712 23.9503 0 22.3188 0 20.3063V3.64405ZM12.6516 5.79655C14.4525 3.99075 17.3723 3.99075 19.1734 5.79655C20.9742 7.60234 20.9742 10.5301 19.1734 12.3359L17.5686 13.9451C17.2776 14.2368 16.8057 14.2368 16.5147 13.9451C16.2237 13.6533 16.2237 13.1802 16.5147 12.8884L18.1196 11.2793C19.3385 10.0571 19.3385 8.07537 18.1196 6.85312C16.9007 5.6309 14.9243 5.63092 13.7054 6.85312L11.3607 9.20415C11.0701 9.49552 11.0701 9.96791 11.3607 10.2593C11.6513 10.5506 12.1224 10.5506 12.413 10.2593L13.4659 9.20346C13.7569 8.91169 14.2288 8.91169 14.5198 9.20346C14.8107 9.49523 14.8107 9.96828 14.5198 10.26L13.4667 11.3159C12.5942 12.1909 11.1794 12.1909 10.3069 11.3159C9.43429 10.441 9.43429 9.02249 10.3069 8.14757L10.8879 7.56486L10.1781 6.85312C8.95914 5.63089 6.98277 5.63089 5.76382 6.85312C4.54486 8.07537 4.54488 10.0571 5.76382 11.2793L12.6985 18.2326C12.9895 18.5244 12.9895 18.9974 12.6985 19.2892C12.4075 19.581 11.9358 19.581 11.6448 19.2892L4.71008 12.3359C2.90915 10.5301 2.90913 7.60233 4.71008 5.79655C6.51102 3.99075 9.43091 3.99075 11.2318 5.79655L11.9417 6.50827L12.6516 5.79655ZM11.8605 14.9781C12.1515 14.6863 12.6233 14.6863 12.9143 14.9781L14.5397 16.6079C14.8307 16.8996 14.8307 17.3727 14.5397 17.6645C14.2487 17.9562 13.7769 17.9562 13.4859 17.6645L11.8605 16.0347C11.5695 15.7429 11.5695 15.2699 11.8605 14.9781ZM13.6482 13.1856C13.9392 12.8939 14.4109 12.8939 14.7019 13.1856L16.3273 14.8154C16.6183 15.1072 16.6183 15.5802 16.3273 15.872C16.0363 16.1638 15.5645 16.1638 15.2735 15.872L13.6482 14.2422C13.3572 13.9504 13.3572 13.4774 13.6482 13.1856Z" fill="#D1D5DB"/></svg>';

		// Main menu page.
		add_menu_page(
			__( 'SureDonation', 'suredonation' ),
			__( 'SureDonation', 'suredonation' ),
			'manage_options',
			$menu_slug,
			[ $this, 'render_dashboard' ],
			'data:image/svg+xml;base64,' . base64_encode( $menu_icon_svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building data URI for menu icon.
			25
		);

		// Register submenus.
		$this->register_submenus( $menu_slug );
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function render_dashboard() {
		?>
		<div id="suredonation-admin-root"></div>
		<?php
	}

	/**
	 * Hide the "All Forms" submenu item.
	 *
	 * WordPress automatically adds a submenu for the suredonation_form CPT.
	 * We hide it since forms are managed through campaigns.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function hide_forms_submenu() {
		remove_submenu_page( 'suredonation', 'edit.php?post_type=suredonation_form' );
	}

	/**
	 * Check if any campaigns have been created.
	 *
	 * @return bool True if at least one campaign exists.
	 * @since 0.0.1
	 */
	private function has_campaigns() {
		$campaigns = get_posts(
			[
				'post_type'      => 'suredonation_cmpgn',
				'posts_per_page' => 1,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'fields'         => 'ids',
			]
		);

		return ! empty( $campaigns );
	}

	/**
	 * Get rotating plugin banner data.
	 *
	 * @return array<string,mixed>|null Plugin banner data, or null when the plugin is already active.
	 * @since 0.0.1
	 */
	private function get_rotating_plugin_banner() {
		// Match SureForms: never nudge a plugin that is already active, so the
		// dashboard card is hidden once SureRank is activated.
		$status = $this->get_plugin_status( 'surerank/surerank.php' );
		if ( 'Activated' === $status ) {
			return null;
		}

		// Get SVG logo as base64.
		$logo_svg  = file_get_contents( SUREDONATION_DIR . 'images/surerank.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		$logo_data = $logo_svg ? 'data:image/svg+xml;base64,' . base64_encode( $logo_svg ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		// SureRank plugin data.
		return [
			'slug'                  => 'surerank',
			'path'                  => 'surerank/surerank.php',
			'title'                 => 'SureRank',
			'singleLineDescription' => __( 'SEO Made Easy for Your Website', 'suredonation' ),
			'subtitle'              => __( 'Beautiful pages, persuasive content, and custom code in seconds. The possibilities are endless!', 'suredonation' ),
			'logo'                  => $logo_data,
			'status'                => $status,
		];
	}

	/**
	 * Get plugin installation status.
	 *
	 * @param string $plugin_file Plugin file path (e.g., 'plugin-folder/plugin-file.php').
	 * @return string Plugin status: 'Activated', 'Installed', or 'Install'.
	 * @since 0.0.1
	 */
	private function get_plugin_status( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return 'Activated';
		}

		$all_plugins = get_plugins();
		if ( isset( $all_plugins[ $plugin_file ] ) ) {
			return 'Installed';
		}

		return 'Install';
	}

	/**
	 * Register submenus.
	 *
	 * @param string $menu_slug Menu slug.
	 * @return void
	 * @since 0.0.1
	 */
	private function register_submenus( $menu_slug ) {
		$capability = 'manage_options';

		$submenus = [
			[
				'id'         => $menu_slug,
				'page_title' => __( 'Dashboard', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/campaigns',
				'page_title' => __( 'Campaigns', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/donations',
				'page_title' => __( 'Donations', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/donors',
				'page_title' => __( 'Donors', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/reports',
				'page_title' => __( 'Reports', 'suredonation' ),
			],
			[
				'id'         => 'suredonation#/settings',
				'page_title' => __( 'Settings', 'suredonation' ),
			],
		];

		// Register the submenus.
		foreach ( $submenus as $submenu ) {
			add_submenu_page(
				$menu_slug,
				$submenu['page_title'],
				$submenu['page_title'],
				$capability,
				$submenu['id'],
				[ $this, 'render_dashboard' ]
			);
		}

		// Hidden onboarding page (no menu parent so it never shows in
		// the sidebar but is still routable via admin.php?page=...).
		add_submenu_page(
			'',
			__( 'SureDonation Setup', 'suredonation' ),
			__( 'SureDonation Setup', 'suredonation' ),
			$capability,
			Onboarding::PAGE_SLUG,
			[ $this, 'render_onboarding' ]
		);
	}

	/**
	 * Render onboarding root container.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_onboarding() {
		?>
		<div id="suredonation-onboarding-root"></div>
		<?php
	}

	/**
	 * Enqueue the dedicated onboarding bundle.
	 *
	 * Loaded only on the onboarding admin page so the main admin app
	 * doesn't pay the cost on activation redirects.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function enqueue_onboarding_assets() {
		$asset_file = SUREDONATION_DIR . 'assets/build/admin/onboarding/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'suredonation-onboarding',
			SUREDONATION_URL . 'assets/build/admin/onboarding/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'suredonation-onboarding',
			SUREDONATION_URL . 'assets/build/admin/onboarding/index.css',
			[],
			$asset['version']
		);

		wp_set_script_translations( 'suredonation-onboarding', 'suredonation' );

		$current_user = wp_get_current_user();
		$is_pro       = defined( 'SUREDONATION_PRO_VER' );

		// Detect GiveWP rows once at enqueue time so the JS doesn't have to
		// spin a loader (and won't ever flash an error) just to decide whether
		// to render the optional migration step.
		$has_givewp_data = false;
		try {
			$has_givewp_data = \SureDonation\Inc\Import\Givewp\Source::get_instance()->has_givewp_data();
		} catch ( \Throwable $e ) {
			$has_givewp_data = false;
		}

		$has_charitable_data = false;
		try {
			$has_charitable_data = \SureDonation\Inc\Import\Charitable\Source::get_instance()->has_charitable_data();
		} catch ( \Throwable $e ) {
			$has_charitable_data = false;
		}

		wp_localize_script(
			'suredonation-onboarding',
			'suredonation_onboarding',
			[
				'apiUrl'            => rest_url( 'suredonation/v1' ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'pluginUrl'         => SUREDONATION_URL,
				'adminUrl'          => admin_url(),
				'dashboardUrl'      => admin_url( 'admin.php?page=suredonation' ),
				'paymentsUrl'       => Payment_Helper::get_settings_url( 'stripe' ),
				'campaignsUrl'      => admin_url( 'admin.php?page=suredonation#/campaigns' ),
				'docsUrl'           => 'https://suredonation.com/docs/',
				'isProActive'       => $is_pro,
				'hasGiveWPData'     => $has_givewp_data,
				'hasCharitableData' => $has_charitable_data,
				'currentUser'       => [
					'firstName' => $current_user ? $current_user->first_name : '',
					'lastName'  => $current_user ? $current_user->last_name : '',
					'email'     => $current_user ? $current_user->user_email : '',
				],
				'privacyUrl'        => 'https://suredonation.com/privacy-policy/',
			]
		);

		// Shim suredonation_admin on the onboarding page too — shared API
		// helpers (e.g. importApi.js) read apiUrl + nonce from that global,
		// so without this the migration session fails the REST cookie check.
		wp_localize_script(
			'suredonation-onboarding',
			'suredonation_admin',
			[
				'apiUrl'        => rest_url( 'suredonation/v1' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'is_pro_active' => $is_pro,
			]
		);
	}
}
