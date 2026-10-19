<?php
/**
 * AJAX Donation Handler
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Ajax;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Debug_Log;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use SureDonation\Inc\Post_Types\Donation_Form;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donation_Handler class.
 *
 * @since 0.0.1
 */
class Donation_Handler {
	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'wp_ajax_suredonation_submit_donation', [ $this, 'handle_donation_submission' ] );
		add_action( 'wp_ajax_nopriv_suredonation_submit_donation', [ $this, 'handle_donation_submission' ] );

		// Runtime gateway configuration, read by the form script when it initialises.
		add_action( 'wp_ajax_suredonation_gateway_config', [ $this, 'get_gateway_config' ] );
		add_action( 'wp_ajax_nopriv_suredonation_gateway_config', [ $this, 'get_gateway_config' ] );

		// Donation failures reported by the donor's browser.
		add_action( 'wp_ajax_suredonation_log_client_error', [ $this, 'log_client_error' ] );
		add_action( 'wp_ajax_nopriv_suredonation_log_client_error', [ $this, 'log_client_error' ] );
	}

	/**
	 * Record donation failures reported by the donor's browser.
	 *
	 * Half of what breaks a donation never reaches PHP: a Stripe Elements mount
	 * that threw, a PayPal SDK that would not load, a request that died in the
	 * network. The server cannot see any of it, so the browser reports it here.
	 *
	 * On the authentication of this endpoint, plainly: the nonce it checks is
	 * the one baked into the donation form's markup, which a page cache can
	 * serve to anyone and which is readable from a single GET. It filters out
	 * scanners and nothing more. It is not visitor authentication and must not
	 * be read as any.
	 *
	 * What actually protects the log is that an anonymous caller cannot choose
	 * what goes in it: Debug_Log::sanitize_entry() reduces every request to a
	 * fixed schema with no free-form keys, the category is decided here rather
	 * than taken from the payload, the batch is capped, the rate limit applies
	 * per address, and the file itself stops at 1 MB.
	 *
	 * Always answers 204, whatever happened. A browser that has just failed to
	 * take a donation must not be given a reason to retry, and an error body
	 * would tell an anonymous caller which of the guards above it tripped.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function log_client_error() {
		// Off means off, regardless of what the cached page believed when it
		// shipped its copy of the flag.
		if ( ! Debug_Log::is_enabled() ) {
			$this->end_log_request();
		}

		// A donor's browser reports at most a handful of failures in a session,
		// and the batch below carries several at once, so this ceiling is far
		// above anything a real donation produces.
		if ( ! Helper::check_rate_limit( 'client_log', 30 ) ) {
			$this->end_log_request();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the next line; check_ajax_referer() reads the field itself.
		if ( ! isset( $_POST['nonce'] ) || ! check_ajax_referer( 'suredonation_donation_form', 'nonce', false ) ) {
			$this->end_log_request();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above. The value is a JSON document: running it through sanitize_text_field() here would corrupt it before it can be parsed, and every field inside is reduced to the fixed schema by Debug_Log::sanitize_entry() below.
		$entries = isset( $_POST['entries'] ) ? wp_unslash( $_POST['entries'] ) : '';
		$raw     = is_string( $entries ) ? json_decode( $entries, true ) : null;

		// Capped so one request cannot spend the whole file. The browser flushes
		// in batches of this size for the same reason.
		$batch = is_array( $raw ) ? array_slice( $raw, 0, 10 ) : [];

		// One finalize is settled per request, whatever the batch holds.
		//
		// A browser reports at most one of these: it is written when a single
		// donation's completion fails, and a page puts through one donation.
		// The per-donation cooldown below bounds repeats of the same id, but
		// only a per-request limit bounds a batch of ten different ones - and
		// minting ten rows to fill it costs nothing, so without this a single
		// request still multiplies the calls out to the gateway by ten.
		$finalize_settled = false;

		foreach ( $batch as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// Everything that names something is resolved here, from the form id
			// alone. The caller is anonymous, so a payload that could set its own
			// campaign title would let anyone write "Emergency Flood Appeal" next
			// to a failure that never touched it - and that text goes straight
			// into the warning the site owner reads.
			$entry = array_merge( $entry, $this->resolve_log_context( Helper::get_integer_value( $entry['form_id'] ?? 0 ) ) );

			// The category is ours to derive, never the payload's to name. The
			// browser can distinguish only the two states it can actually see -
			// the donation never completed, or it completed at the gateway and
			// our own record of it did not - so those are the only two
			// categories reachable from here. 'recurring' and 'integration' are
			// server-only and stay that way.
			$type = sanitize_key( Helper::get_string_value( $entry['type'] ?? '' ) );

			// Nothing is resolved for an entry the schema is going to discard.
			// Settling a finalize costs a call out to the gateway and spends
			// that donation's cooldown, and an entry carrying no detail was
			// never going to be recorded either way - so without this an empty
			// payload buys both, and can be repeated to deny the donor's real
			// report the one slot it has. Judged on the entry append() will
			// see, so the two cannot disagree.
			if ( empty( Debug_Log::sanitize_entry( $entry ) ) ) {
				continue;
			}

			// 'finalize' is the most serious thing anything can write here: the
			// line that tells the owner money reached the gateway and this site
			// lost it. The payload names the type but does not decide it - the
			// server settles what the report is worth against its own records
			// and the gateway's.
			$donation = null;

			if ( 'finalize' === $type ) {
				$resolved = $finalize_settled
					? [
						'type'     => 'response',
						'donation' => null,
					]
					: $this->resolve_finalize( $entry );

				$finalize_settled = true;

				$type          = $resolved['type'];
				$entry['type'] = $type;
				$donation      = $resolved['donation'];
			}

			if ( is_array( $donation ) ) {
				// From the row, never from the payload. The gateway decides
				// which successes are allowed to clear this failure later, so a
				// caller that could name it could pin a warning open that no
				// gateway will ever match.
				$entry['gateway'] = Helper::get_string_value( $donation['gateway'] ?? '' );
			}

			// Bounded well below the file's own cap. This is the one writer an
			// anonymous caller drives, and the log stops rather than evicting,
			// so without a ceiling of its own a few seconds of posting would
			// leave no room for anything the server has to say.
			$recorded = Debug_Log::append( $entry, 'finalize' === $type ? 'payment' : 'donation', Debug_Log::BROWSER_BUDGET );

			// Spent only once the report has had an effect. append() counts a
			// raised warning as an effect even when the file was too full to
			// hold the line, so a full log cannot turn the one report this
			// donation is allowed into an allowance that never runs out.
			if ( $recorded && is_array( $donation ) ) {
				set_transient( self::finalize_lock_key( Helper::get_integer_value( $donation['id'] ?? 0 ) ), 1, DAY_IN_SECONDS );
			}
		}

		$this->end_log_request();
	}

	/**
	 * The key holding the one finalize report a donation is allowed.
	 *
	 * @param int $donation_id Donation the report is about.
	 * @since 1.7.0
	 * @return string Transient key.
	 */
	private static function finalize_lock_key( $donation_id ) {
		return 'suredonation_finalize_report_' . absint( $donation_id );
	}

	/**
	 * The key holding a donation's one gateway round trip per minute.
	 *
	 * Separate from the report lock because the two answer different
	 * questions. The lock says this donation's warning has been raised and
	 * lasts a day; this says the gateway has been asked about it recently and
	 * lasts a minute, whatever the gateway said.
	 *
	 * @param int $donation_id Donation the report is about.
	 * @since 1.7.0
	 * @return string Transient key.
	 */
	private static function gateway_cooldown_key( $donation_id ) {
		return 'suredonation_finalize_check_' . absint( $donation_id );
	}

	/**
	 * What a browser's "the money arrived and you lost it" is actually worth.
	 *
	 * Every other field in a report is reduced to a fixed schema or resolved on
	 * the server, so the worst a caller can do with one is write a line nobody
	 * acts on. This is the exception: it is the only thing a report can say
	 * that puts a payment warning on the dashboard. The route is public, so the
	 * claim is settled against records the caller does not control.
	 *
	 * The invariant: a payment warning is raised only when the gateway itself
	 * confirms the donor's money moved and this site's own row does not say so.
	 * Three answers come out of that.
	 *
	 * - 'finalize' when the gateway confirms a captured payment against a row
	 *   that is still pending. Stripe only. PayPal's capture-mismatch branch
	 *   records the same fact server-side, where the capture response is
	 *   already in hand, so a browser report there would duplicate it.
	 * - 'message' when the row shows the site has already dealt with it:
	 *   completed by the webhook before the report arrived, which is the
	 *   self-healing path working, or flagged suspicious by the gateway path
	 *   that wrote its own entry. Kept in the file as a diagnostic and never
	 *   counted, because warning about a donation that was recorded correctly
	 *   is how a panel teaches people to ignore it.
	 * - 'response' for everything else: a donation that did not complete, which
	 *   is what an unproven report describes and what the caller could have
	 *   sent directly anyway.
	 *
	 * @param array<string,mixed> $entry The reported entry.
	 * @since 1.7.0
	 * @return array{type: string, donation: array<string,mixed>|null} Type to record, and the row backing it when there is one.
	 */
	private function resolve_finalize( array $entry ) {
		$unproven = [
			'type'     => 'response',
			'donation' => null,
		];

		$donation_id = Helper::get_integer_value( $entry['donation_id'] ?? 0 );

		if ( $donation_id <= 0 ) {
			return $unproven;
		}

		$donation = Donations::get( $donation_id );

		if ( ! is_array( $donation ) ) {
			return $unproven;
		}

		// 'manual' and 'offline' are written by paths that move no money, so a
		// row on either cannot be evidence that a gateway took any.
		$gateway = Helper::get_string_value( $donation['gateway'] ?? '' );

		if ( '' === $gateway || in_array( $gateway, [ 'manual', 'offline' ], true ) ) {
			return $unproven;
		}

		$status = Helper::get_string_value( $donation['payment_status'] ?? '' );

		if ( in_array( $status, [ 'completed', 'suspicious' ], true ) ) {
			return [
				'type'     => 'message',
				'donation' => null,
			];
		}

		if ( 'pending' !== $status ) {
			return $unproven;
		}

		if ( Payment_Helper::get_payment_mode() !== Helper::get_string_value( $donation['payment_mode'] ?? '' ) ) {
			return $unproven;
		}

		// Stored as GMT (Donations::add), so it is read back as GMT. A donation
		// abandoned last month is not being finalised now.
		$created = strtotime( Helper::get_string_value( $donation['created_at'] ?? '' ) . ' UTC' );

		if ( false === $created || time() - $created > DAY_IN_SECONDS ) {
			return $unproven;
		}

		// Checked before the gateway is called, not after, so that the one
		// report a donation is allowed is also one round trip at most.
		if ( get_transient( self::finalize_lock_key( $donation_id ) ) ) {
			return $unproven;
		}

		// The lock above is only taken when the gateway confirms, so on its own
		// it bounds nothing: a report the gateway refuses leaves no trace and
		// can be sent again immediately. This one is taken before the call and
		// kept whatever the answer, which is what holds an unconfirmed id to
		// one outbound request a minute rather than one per report.
		if ( get_transient( self::gateway_cooldown_key( $donation_id ) ) ) {
			return $unproven;
		}

		set_transient( self::gateway_cooldown_key( $donation_id ), 1, MINUTE_IN_SECONDS );

		if ( ! self::gateway_confirms_capture( $donation ) ) {
			return $unproven;
		}

		return [
			'type'     => 'finalize',
			'donation' => $donation,
		];
	}

	/**
	 * Whether the gateway says the donor's money actually moved.
	 *
	 * The payment intent id is on the row from the moment the intent is
	 * created, so there is always something to ask about, and the answer is the
	 * one part of this claim a browser cannot produce for itself.
	 *
	 * Intents are created with capture_method 'manual' (Stripe_Helper), so
	 * 'requires_capture' is an authorisation that was never charged: the
	 * donation failed and no money arrived, which is a different sentence from
	 * the one this type writes. Only a captured intent counts, plus
	 * 'processing' for the payment methods that settle asynchronously.
	 *
	 * Stripe only. Returning false for every other gateway is what keeps the
	 * browser out of PayPal's reporting, which happens server-side.
	 *
	 * @param array<string,mixed> $donation The donation row.
	 * @since 1.7.0
	 * @return bool True when the gateway confirms a captured payment.
	 */
	private static function gateway_confirms_capture( array $donation ) {
		if ( 'stripe' !== Helper::get_string_value( $donation['gateway'] ?? '' ) ) {
			return false;
		}

		$intent_id = Helper::get_string_value( $donation['transaction_id'] ?? '' );

		if ( '' === $intent_id ) {
			return false;
		}

		$account_id = Helper::get_string_value( $donation['stripe_account_id'] ?? '' );
		$intent     = Stripe_Helper::retrieve_payment_intent( $intent_id, '' !== $account_id ? $account_id : null );

		if ( is_wp_error( $intent ) || ! is_array( $intent ) ) {
			return false;
		}

		return in_array( Helper::get_string_value( $intent['status'] ?? '' ), [ 'succeeded', 'processing' ], true );
	}

	/**
	 * Resolve the fields a browser report is not trusted to name for itself.
	 *
	 * @param int $form_id Form the failure was reported against.
	 * @since 1.7.0
	 * @return array<string,mixed> Fields to overwrite on the entry.
	 */
	private function resolve_log_context( $form_id ) {
		$context = [
			// The mode the site is in now, not the mode baked into the page the
			// donor was looking at, which on a cached site can predate a switch.
			'mode'           => Payment_Helper::get_payment_mode(),
			'form_id'        => 0,
			'form_title'     => '',
			'campaign_id'    => 0,
			'campaign_title' => '',
			// Blanked, and only ever filled in from a donation row we looked up
			// ourselves. record_failure() stores this, and clear_category()
			// reads it back to decide which gateway's success is allowed to
			// clear the warning - so a caller that could name a gateway no
			// gateway will ever match could leave a warning up until it expires.
			'gateway'        => '',
		];

		$form = $form_id ? get_post( $form_id ) : null;

		if ( ! $form instanceof \WP_Post || Donation_Form::POST_TYPE !== $form->post_type ) {
			return $context;
		}

		$context['form_id']    = $form_id;
		$context['form_title'] = $form->post_title;

		$campaign_id = absint( Helper::get_string_value( get_post_meta( $form_id, Donation_Form::META_CAMPAIGN_ID, true ) ) );
		$campaign    = $campaign_id ? get_post( $campaign_id ) : null;

		if ( $campaign instanceof \WP_Post ) {
			$context['campaign_id']    = $campaign_id;
			$context['campaign_title'] = $campaign->post_title;
		}

		return $context;
	}

	/**
	 * Close a client log request with an empty 204.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function end_log_request() {
		wp_die( '', '', [ 'response' => 204 ] );
	}

	/**
	 * Serve the gateway configuration for a donation form.
	 *
	 * Public read, fetched by the form script when it initialises so the Stripe
	 * key, PayPal SDK URL, payment mode and currency reflect the settings as
	 * they are now — not as they were when a page cache stored the form. It
	 * goes through admin-ajax, which page caches leave alone by default and
	 * which keeps working on sites that restrict the REST API for visitors.
	 *
	 * @return void
	 * @since 1.5.1
	 */
	public function get_gateway_config() {
		// Throttle abuse as every other public endpoint does. The ceiling is
		// far above the default because this fires once per form page view,
		// not per donor action, and many visitors can legitimately share one
		// address (an office or campus NAT). When it trips, the scripts fall
		// back to the rendered configuration rather than failing.
		if ( ! Helper::check_rate_limit( 'gateway_config', 120 ) ) {
			wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait a moment and try again.', 'suredonation' ) ], 429 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read of non-secret data; nothing changes state, and a nonce would be cached with the page it is meant to protect.
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		// Freshness is the whole point of this response. admin-ajax already
		// sends these, but an edge cache with a blanket rule would not care,
		// so the guarantee is made explicit rather than inherited.
		nocache_headers();

		wp_send_json_success( Payment_Helper::get_frontend_gateway_config( $form_id ) );
	}

	/**
	 * Handle donation form submission.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function handle_donation_submission() {
		// Throttle abuse on this public endpoint before doing any work.
		if ( ! Helper::check_rate_limit( 'submit_donation' ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait a moment and try again.', 'suredonation' ), 429 );
		}

		// First check if nonce exists before accessing any other POST data.
		if ( ! isset( $_POST['suredonation_nonce'] ) ) {
			wp_send_json_error( __( 'Security check failed', 'suredonation' ) );
		}

		// Sanitize nonce value.
		$nonce = sanitize_text_field( wp_unslash( $_POST['suredonation_nonce'] ) );

		// Now get values needed to determine nonce action.
		$is_standalone = isset( $_POST['is_standalone'] ) && '1' === $_POST['is_standalone'];
		$campaign_id   = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		// Standalone forms must not have a campaign — prevent bypass of campaign validation.
		if ( $is_standalone ) {
			$campaign_id = 0;
		}

		// Verify nonce - different nonce for standalone vs campaign-linked forms.
		$nonce_action = Helper::get_donation_nonce_action( $campaign_id );

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_send_json_error( __( 'Security check failed', 'suredonation' ) );
		}

		// Reject bot submissions caught by the honeypot before processing.
		if ( Helper::is_honeypot_spam() ) {
			wp_send_json_error( __( 'Your submission was flagged as spam. Please try again.', 'suredonation' ) );
		}

		// Validate campaign only if not standalone.
		$campaign = null;
		if ( ! $is_standalone ) {
			if ( ! $campaign_id ) {
				wp_send_json_error( __( 'Invalid campaign', 'suredonation' ) );
			}

			$campaign = get_post( $campaign_id );
			if ( ! $campaign || SUREDONATION_POST_TYPE !== $campaign->post_type ) {
				wp_send_json_error( __( 'Invalid campaign', 'suredonation' ) );
			}
		}

		// Get form data.
		$amount     = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
		$cover_fees = isset( $_POST['cover_fees'] ) && 'true' === $_POST['cover_fees'];
		// The anonymous flag is display-only: the donor's real name is stored as
		// usual below and only public surfaces mask it.
		$donor_name  = sanitize_text_field( wp_unslash( $_POST['donor_name'] ?? '' ) );
		$donor_email = sanitize_email( wp_unslash( $_POST['donor_email'] ?? '' ) );

		// Get form_id and block_id for amount validation.
		$form_id      = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$is_anonymous = Payment_Helper::get_submitted_is_anonymous( $form_id );
		// Derive the donor phone from the validated mapped field, not a separate
		// unvalidated $_POST['donor_phone'] (see Payment_Helper::get_mapped_donor_phone).
		$donor_phone = Payment_Helper::get_mapped_donor_phone( $form_id );
		// Likewise derive the comment from the form's Donor Comment field rather
		// than an unvalidated $_POST['donor_comment'] (see
		// Payment_Helper::get_mapped_donor_comment).
		$donor_comment = Payment_Helper::get_mapped_donor_comment( $form_id );
		$block_id      = isset( $_POST['block_id'] ) ? sanitize_text_field( wp_unslash( $_POST['block_id'] ) ) : '';

		// Validate required fields.
		if ( $amount <= 0 ) {
			wp_send_json_error( __( 'Invalid donation amount', 'suredonation' ) );
		}

		// Require form_id and block_id for amount validation — reject if missing to prevent bypass.
		if ( empty( $form_id ) || empty( $block_id ) ) {
			wp_send_json_error( __( 'Invalid form configuration.', 'suredonation' ) );
		}

		// Validate field values + amount against block configuration. Pass the
		// offline gateway so the Stripe-only minimum floor is not applied here.
		$currency          = Payment_Helper::get_submitted_currency();
		$validation_result = Payment_Helper::validate_submission( Payment_Helper::get_submitted_fields(), $amount, $currency, $form_id, $block_id, 'offline', 'one-time' );
		if ( ! $validation_result['valid'] ) {
			wp_send_json_error( esc_html( $validation_result['message'] ) );
		}

		// Name and email are required whether or not the donation is anonymous —
		// the flag only masks the name on public surfaces, so there still has to
		// be a real name to mask (matches the gateway handlers, which validate
		// these through validate_submission() regardless of the flag).
		if ( empty( $donor_name ) ) {
			wp_send_json_error( __( 'Donor name is required', 'suredonation' ) );
		}
		if ( empty( $donor_email ) || ! is_email( $donor_email ) ) {
			wp_send_json_error( __( 'Valid email address is required', 'suredonation' ) );
		}

		// Server-side fee calculation — ignore client-supplied base_amount to prevent manipulation.
		$base_amount  = $amount;
		$fees_covered = 0;

		if ( $cover_fees && $base_amount > 0 ) {
			$fee_config = Payment_Helper::get_cover_fees_config( $form_id, 'offline' );

			if ( ! $fee_config['enabled'] ) {
				$cover_fees = false;
			}

			if ( $cover_fees ) {
				$fees_covered = Payment_Helper::calculate_fee( $base_amount, $fee_config['fee_percentage'], $fee_config['fee_fixed'], $currency );
			} else {
				$fees_covered = 0;
			}
		}

		// Get or create donor. The email is validated as non-empty above, so
		// there is no guard here — anonymous or not, this path always has one.
		$donor_id = Donors::get_or_create( $donor_email, $donor_name, $donor_phone );

		// Get payment mode.
		$payment_mode = 'live';
		if ( class_exists( 'SureDonation\Inc\Payments\Payment_Helper' ) ) {
			$payment_mode = Payment_Helper::get_payment_mode();
		}

		// Create donation in database.
		$donation_id = Donations::add(
			[
				'campaign_id'          => $campaign_id,
				'donor_id'             => $donor_id ? $donor_id : 0,
				'amount'               => number_format( $base_amount, 2, '.', '' ),
				'fees_covered'         => number_format( $fees_covered, 2, '.', '' ),
				'currency'             => $currency,
				'gateway'              => 'manual',
				'payment_status'       => 'pending',
				'payment_mode'         => $payment_mode,
				'donor_name'           => $donor_name,
				'donor_email'          => $donor_email,
				'donor_phone'          => $donor_phone,
				'is_anonymous'         => $is_anonymous ? 1 : 0,
				'donation_type'        => 'one-time',
				'donor_comment'        => $donor_comment,
				'donor_comment_status' => Donations::initial_comment_status( $donor_comment ),
				'form_id'              => $form_id,
				'ip_address'           => Helper::get_client_ip(),
				'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'referer_url'          => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			]
		);

		if ( ! $donation_id ) {
			wp_send_json_error( __( 'Failed to create donation', 'suredonation' ) );
		}

		// Persist the submitted field values for the entry record.
		Donations::set_submitted_fields( $donation_id, Payment_Helper::get_submitted_field_data() );

		// Note: Donation status will be updated by payment gateway webhooks or manual confirmation.

		// This donation is created as pending/manual, so send the "processing"
		// (donation received) email rather than the completed-confirmation
		// email. The confirmation email is reserved for when payment is
		// actually confirmed, matching the gateway flows.
		$donation_data = [
			'id'            => $donation_id,
			'donor_name'    => $donor_name,
			'donor_email'   => $donor_email,
			'amount'        => $base_amount,
			'fees_covered'  => $fees_covered,
			'currency'      => $currency,
			'gateway'       => 'manual',
			// One-time regardless of the block's configured type, and intentionally
			// unguarded: this handler has no remaining caller in src/, writes a
			// record rather than moving money, and gating it on payment type would
			// reject manual entries on recurring forms. Whether it should still be
			// registered at all is the better question, tracked separately.
			'donation_type' => 'one-time',
		];

		Email_Handler::send_donation_processing( $donation_id, $campaign_id, $donation_data, $form_id );

		// Build the confirmation/thank-you HTML from the form's confirmation message.
		$confirmation_html = Helper::render_confirmation_message( $donation_id );
		if ( '' === $confirmation_html ) {
			$confirmation_html = esc_html__( 'Your generous contribution will make a real difference. A confirmation email has been sent to you.', 'suredonation' );
		}

		// Send success response.
		wp_send_json_success(
			[
				'donation_id' => $donation_id,
				'message'     => $confirmation_html,
			]
		);
	}
}
