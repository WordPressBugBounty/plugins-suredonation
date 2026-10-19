<?php
/**
 * Debug logging.
 *
 * Owns the debug log file that the "Enable Logs" setting writes to: where it
 * lives, what may be written to it, how large it is allowed to get and how long
 * it is kept. Nothing else in the plugin should open that file.
 *
 * The log records donation failures - a gateway call that errored, a webhook
 * that could not be verified, a receipt that would not send, a payment form
 * that never finished loading in the donor's browser - so a site owner can hand
 * the file to support instead of being talked through DevTools after the
 * evidence is gone.
 *
 * @package SureDonation
 * @since   1.7.0
 */

namespace SureDonation\Inc;

use SureDonation\Inc\Payments\Payment_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug_Log
 *
 * Design notes that are load-bearing:
 *
 *  - Every method fails silently. The uploads directory is not writable on
 *    hardened or read-only-deploy hosts, and a logger that warns or fatals
 *    during a donation is worse than one that records nothing. A log is never
 *    worth a lost donation.
 *  - The file name is unguessable and never disclosed. `.htaccess` does nothing
 *    on nginx, so the name is the real protection; the download handler derives
 *    the path itself and takes no filename parameter.
 *  - Over the size cap the log stops accepting writes rather than trimming or
 *    rotating. Someone reproducing a bug must not have the tail of their repro
 *    evicted by newer noise from an unrelated donor.
 *  - Only failures are written. A site whose donations work never creates the
 *    file at all, which is what makes an on-by-default diagnostic defensible
 *    next to a payment flow.
 *  - The schema is a closed allowlist and the amounts, donor details and card
 *    data are not in it. See sanitize_entry() for what that excludes and why.
 *
 * @since 1.7.0
 */
class Debug_Log {
	/**
	 * Settings key holding the on/off switch, inside Helper::OPTION_NAME.
	 *
	 * @since 1.7.0
	 */
	public const SETTING_KEY = 'suredonation_enable_logs';

	/**
	 * Option holding the random component of the log file name.
	 *
	 * @since 1.7.0
	 */
	public const FILENAME_OPTION = 'suredonation_debug_log_file';

	/**
	 * Option holding per-category failure state, keyed by category.
	 *
	 * Shape: [ category => [ 'count' => int, 'label' => string, 'gateway' => string,
	 * 'mode' => string, 'at' => int ] ].
	 *
	 * Summarised at the top of the support report, so whoever picks up the
	 * ticket sees what is failing before reading a single log line. Kept per
	 * category because the five read completely differently: donations failing
	 * means money is not arriving, a payment failing after the charge means the
	 * books are wrong, a notification failing means a donor paid and heard
	 * nothing. Collapsing them into one line would
	 * describe none of those accurately.
	 *
	 * @since 1.7.0
	 */
	public const FAILURES_OPTION = 'suredonation_debug_log_failures';

	/**
	 * Consecutive faults before the site owner is told something is wrong.
	 *
	 * One. A fault is already filtered down to what a donor cannot fix by trying
	 * again - the gateway call errored, the webhook would not verify, the receipt
	 * would not send - so waiting for a run of them means staying quiet through
	 * the first several lost donations.
	 *
	 * @since 1.7.0
	 */
	public const FAULT_THRESHOLD = 1;

	/**
	 * Maximum size of the log file in bytes.
	 *
	 * @since 1.7.0
	 */
	public const MAX_FILE_SIZE = 1048576;

	/**
	 * How much of the file a browser-sourced report may occupy, in bytes.
	 *
	 * The invariant: the server's own entries can always be written. The
	 * reporting endpoint is public by necessity, since the failures worth
	 * hearing about are the ones a donor hits, and the file stops at
	 * MAX_FILE_SIZE rather than evicting. Untrusted writers therefore get a
	 * quarter of it, leaving the rest for the entries that say money moved.
	 *
	 * A ceiling rather than a rate, because a rate is per address and rotating
	 * an address is free, while this holds however many of them there are.
	 *
	 * It is a ceiling on the whole file, not a private allowance: a browser
	 * report is refused once the file reaches this size whoever wrote the
	 * bytes. That is the right way round, and it means donor-side reporting can
	 * stop while the log is only a quarter full, so is_browser_paused() reports
	 * that state separately for the Tools screen.
	 *
	 * @since 1.7.0
	 */
	public const BROWSER_BUDGET = 262144;

	/**
	 * How long entries are kept before the file is discarded, in days.
	 *
	 * The size cap alone is not a retention policy. This feature is on by
	 * default and sits beside a payment flow, so an untouched file must not
	 * accumulate indefinitely on a site nobody is debugging. Expiring a cold log
	 * is not the same as evicting lines from a live one, which is why the cap
	 * still refuses rather than trims.
	 *
	 * @since 1.7.0
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * Longest free-text value stored on a single entry, in bytes.
	 *
	 * Bytes rather than characters, because the cap exists to bound the file and
	 * the file is measured in bytes. A character budget is not a bound at all: an
	 * emoji is four bytes of UTF-8, so 500 characters is up to 2 KB on disk, and
	 * the entry carries several of these fields.
	 *
	 * @since 1.7.0
	 */
	public const MAX_TEXT_LENGTH = 500;

	/**
	 * Longest single field key stored on an entry, in bytes.
	 *
	 * @since 1.7.0
	 */
	public const MAX_KEY_LENGTH = 100;

	/**
	 * How many field keys one entry records.
	 *
	 * A donation form with twenty failing fields is already past the point where
	 * naming each one helps, and this is the multiplier on MAX_KEY_LENGTH that
	 * decides how large a single entry can get.
	 *
	 * @since 1.7.0
	 */
	public const MAX_KEYS = 20;

	/**
	 * Longest identifier-shaped value stored on an entry, in characters.
	 *
	 * @since 1.7.0
	 */
	public const MAX_ID_LENGTH = 64;

	/**
	 * Categories a failure can belong to.
	 *
	 * 'donation'     - the donor could not complete. The money never arrived.
	 * 'payment'      - the money moved but our record did not follow. Books wrong.
	 * 'notification' - a receipt or admin email did not send. Donor paid, heard nothing.
	 * 'recurring'    - a renewal failed. Silent revenue churn. Fed by pro.
	 * 'integration'  - a connected service never received the donation. Fed by pro.
	 *
	 * 'donation' and 'payment' are mutually exclusive for one event: either the
	 * charge happened or it did not, and raising both would tell the site owner
	 * two contradictory things about the same donor.
	 *
	 * @since 1.7.0
	 */
	public const CATEGORIES = [ 'donation', 'payment', 'notification', 'recurring', 'integration' ];

	/**
	 * Entry types the log accepts.
	 *
	 * Browser-reported: network, response, error, message, blocked, finalize.
	 * Server-reported: gateway, webhook, email, pdf, renewal, dispatch.
	 *
	 * The type says what happened; the category says what it means. They are
	 * separate fields so this vocabulary can grow for filtering without any of
	 * it reaching the notice logic, which reads categories only.
	 *
	 * 'finalize' is the one browser type that changes which category an entry
	 * lands in, because it is the one browser-visible failure where the money
	 * already moved: the gateway took the payment and our own request to record
	 * it did not come back.
	 *
	 * @since 1.7.0
	 */
	public const TYPES = [ 'network', 'response', 'error', 'message', 'blocked', 'finalize', 'gateway', 'webhook', 'email', 'pdf', 'renewal', 'dispatch' ];

	/**
	 * Cron hook that enforces the retention window on an idle site.
	 *
	 * @since 1.7.0
	 */
	public const EXPIRY_HOOK = 'suredonation_debug_log_expiry';

	/**
	 * How far back the last line is searched when collapsing a repeat, in bytes.
	 *
	 * @since 1.7.0
	 */
	private const TAIL_SCAN_BYTES = 8192;

	/**
	 * Memoised get_tail() results for this request, keyed by character budget.
	 *
	 * A class property rather than a static inside the method so append() and
	 * clear() can invalidate it: a request that writes to the log and then reads
	 * a tail must not be handed the tail from before the write.
	 *
	 * @var array<string,array{text:string,shown:int,total:int}>
	 * @since 1.7.0
	 */
	private static $tail_memo = [];

	/**
	 * Register the retention cron.
	 *
	 * The write and read paths expire a stale file on their own, which covers
	 * every site anyone is actually looking at. This covers the one they do not:
	 * donations failed last month, stopped, and nobody has opened the Logs screen
	 * since. Without it that file would sit in uploads until something touched
	 * it, which on a quiet site is never.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function register_hooks() {
		add_action( self::EXPIRY_HOOK, [ self::class, 'run_expiry' ] );
		add_action( 'admin_init', [ self::class, 'schedule_expiry' ] );

		// A donation getting through is the proof that donations work again.
		// Hooked here rather than called from the table class so every "we have
		// recovered" rule lives next to the ones that record the failures.
		add_action( 'suredonation_donation_completed', [ self::class, 'clear_donation_failures' ] );
	}

	/**
	 * Forget the donation failures once one gets through.
	 *
	 * Hooked - suredonation_donation_completed, which fires on the success path
	 * only.
	 *
	 * Only this category. A completed donation says nothing about whether its
	 * receipt sent, whether the webhook that should have recorded an earlier one
	 * ever arrived, or whether a renewal ran; each of those clears on its own
	 * success path.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function clear_donation_failures() {
		self::clear_category( 'donation' );
	}

	/**
	 * Cron entry point for the retention sweep.
	 *
	 * Its own method so maybe_expire() can keep returning whether it did
	 * anything, which the tests read. An action callback that returns a value
	 * is a mistake waiting to matter.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function run_expiry() {
		self::maybe_expire();
	}

	/**
	 * Put the retention cron back if it is missing.
	 *
	 * Self-healing rather than scheduled once at activation: cron entries are
	 * lost to migrations, staging clones and anything that empties the options
	 * table, and a retention policy that silently stops running is worse than
	 * none because nobody finds out.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function schedule_expiry() {
		if ( ! wp_next_scheduled( self::EXPIRY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EXPIRY_HOOK );
		}
	}

	/**
	 * Whether debug logging is currently switched on.
	 *
	 * On by default, including on installs whose stored settings predate the
	 * option. The point of the log is that the evidence already exists when a
	 * support ticket arrives - a default of off would mean asking the reporter
	 * to enable it and reproduce a failed donation, which is the round trip this
	 * feature removes and the one nobody can reproduce on demand.
	 *
	 * Costs nothing on a healthy site: only failures are ever written, so a site
	 * whose donations work never creates the file at all.
	 *
	 * This is the one authority. The frontend also carries a flag, but that flag
	 * is baked into cached HTML and can be a full cache TTL out of date, so every
	 * write path re-checks here.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public static function is_enabled() {
		$value = Helper::get_suredonation_option( self::SETTING_KEY, null );

		if ( null === $value ) {
			return true;
		}

		return (bool) $value;
	}

	/**
	 * Whether an entry means the site is broken, rather than the donor.
	 *
	 * This distinction is the whole basis of the failure notice. Much of what
	 * the log records is routine: a declined card, an expired session, a donor
	 * closing the PayPal window. Those are the form working correctly, and
	 * counting them would tell healthy sites to contact support - on every
	 * install, because logging is on by default. A declined card is still
	 * written to the file, because "donors tell me their cards keep declining"
	 * is a real ticket and `decline_code` answers it; it is simply not a fault.
	 *
	 * A fault is what a donor cannot resolve by trying again correctly: the
	 * gateway call errored, the webhook would not verify, the receipt would not
	 * send, the renewal did not run.
	 *
	 * @param array<string,mixed> $entry Entry as returned by sanitize_entry().
	 * @since 1.7.0
	 * @return bool
	 */
	public static function is_fault( array $entry ) {
		$type = Helper::get_string_value( $entry['type'] ?? '' );

		// A webhook route is public and unauthenticated by necessity - the sender
		// is a gateway, not a logged-in user - so anyone can post to it. So only
		// a delivery this site positively authenticated may claim that money
		// moved. Anything else is a sentence a stranger wrote for us, and the
		// dashboard would repeat it back to the owner as lost revenue.
		//
		// An allowlist rather than a list of exemptions, because the exemptions
		// were the wrong way round: every new rejection reason a gateway grows
		// counted as a fault until someone remembered to add it here, and a
		// shape check cannot tell a gateway from a stranger when the shape is
		// public.
		//
		// 'present' verified. 'invalid' is narrower and belongs to the call
		// sites: a rejection only the real sender could have produced. PayPal's
		// relay path reaches it only after matching a tracking id that never
		// leaves the server, so it is a rotated relay secret and does mean every
		// real event is being dropped. Stripe has no such gate - its signature
		// header's shape is documented and public - so nothing unverified from
		// Stripe is counted at all. Both are still written to the file; this
		// decides only whether the owner is told donations are broken.
		if ( 'webhook' === $type ) {
			return in_array( Helper::get_string_value( $entry['signature'] ?? '' ), [ 'present', 'invalid' ], true );
		}

		// Allowlist, so an unrecognised or newly added type is not a fault by
		// default. 'blocked' is deliberately absent: it is the label put on a stop
		// the donor can clear themselves. 'message' is absent too - it carries no
		// claim about whether anything failed. 'webhook' is absent because the
		// branch above has already returned for it.
		if ( in_array( $type, [ 'error', 'response', 'finalize', 'gateway', 'email', 'pdf', 'renewal', 'dispatch' ], true ) ) {
			return true;
		}

		if ( 'network' !== $type ) {
			return false;
		}

		$status = Helper::get_integer_value( $entry['status'] ?? 0 );

		// 403 is the nonce being refused, which on a cached site means the page is
		// serving a donor a token the server will not accept - every donation from
		// that page fails and nothing about it is the donor's doing.
		return $status >= 500 || 403 === $status;
	}

	/**
	 * Whether this is the first time $key has come up inside $window.
	 *
	 * A global throttle rather than a per-address one. Its callers are the
	 * unauthenticated webhook routes, where the address is whatever the sender
	 * chose and rotating it costs nothing. What has to be bounded is how often a
	 * reason is written at all, however many senders raise it: the log stops at
	 * 1 MB rather than evicting, so an unbounded writer does not just add noise,
	 * it silently disables the feature until someone clears the file.
	 *
	 * Fails open. A transient backend that is down must not stop a real failure
	 * being recorded.
	 *
	 * @param string $key    What is being throttled.
	 * @param int    $window Seconds before the same key may be recorded again.
	 * @since 1.7.0
	 * @return bool True when the caller may record.
	 */
	public static function throttle( $key, $window = HOUR_IN_SECONDS ) {
		$transient = 'suredonation_log_throttle_' . md5( Helper::get_string_value( $key ) );

		if ( get_transient( $transient ) ) {
			return false;
		}

		set_transient( $transient, 1, $window );

		return true;
	}

	/**
	 * Record a failure against a category.
	 *
	 * The label is carried because "donations are failing" is not actionable on
	 * a site with twenty campaigns - the first thing anyone asks is which one.
	 * The gateway is carried for the same reason: "Stripe is failing but PayPal
	 * is fine" is a different conversation from "payments are down".
	 *
	 * @param string              $category One of self::CATEGORIES.
	 * @param array<string,mixed> $context  Entry the failure came from.
	 * @since 1.7.0
	 * @return void
	 */
	public static function record_failure( $category, array $context = [] ) {
		// Logging off means nothing is recorded, not merely nothing displayed.
		// Gated here rather than at the call sites, for the reason append() states:
		// the guard belongs on the function that writes, not on today's callers.
		// clear_category() is deliberately left ungated - it only removes state,
		// and must keep working so nothing is stranded by the toggle.
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return;
		}

		$failures = self::get_failures();
		$existing = $failures[ $category ] ?? [];
		$mode     = Helper::get_string_value( $context['mode'] ?? '' );

		if ( ! in_array( $mode, [ 'test', 'live' ], true ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$failures[ $category ] = [
			'count'   => Helper::get_integer_value( $existing['count'] ?? 0 ) + 1,
			'label'   => self::get_context_label( $context ),
			'gateway' => Helper::get_string_value( $context['gateway'] ?? '' ),
			// Recorded here, not read back later. The dashboard card's wording
			// turns on this - a test-mode failure must not tell someone money
			// went missing - and the site's mode is a setting that changes. Read
			// at display time, switching to live silently relabelled every
			// test-mode fault as lost revenue.
			//
			// Taken from the entry where it has one, because that is the mode
			// the failure actually happened in, and only falling back to the
			// setting for the call sites that do not pass it.
			'mode'    => $mode,
			'at'      => time(),
		];

		update_option( self::FAILURES_OPTION, $failures, false );
	}

	/**
	 * The human-readable thing a failure happened on.
	 *
	 * Campaign first, because a site owner thinks in campaigns and a donation
	 * form is usually embedded in one. Falls back to the form.
	 *
	 * @param array<string,mixed> $context Entry the failure came from.
	 * @since 1.7.0
	 * @return string
	 */
	private static function get_context_label( array $context ) {
		$label = Helper::get_string_value( $context['campaign_title'] ?? '' );

		if ( '' === $label ) {
			$label = Helper::get_string_value( $context['form_title'] ?? '' );
		}

		return mb_substr( sanitize_text_field( $label ), 0, 100 );
	}

	/**
	 * All recorded failure state.
	 *
	 * @since 1.7.0
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_failures() {
		$stored = Helper::get_array_value( get_option( self::FAILURES_OPTION, [] ) );
		$clean  = [];

		// Validated on the way out rather than trusted. This option survives
		// migrations, imports and hand-edited databases, and every caller
		// indexes into it; one non-array value in there would otherwise be a
		// fatal on an admin screen.
		foreach ( $stored as $category => $failure ) {
			if ( is_string( $category ) && is_array( $failure ) ) {
				$clean[ $category ] = $failure;
			}
		}

		return $clean;
	}

	/**
	 * Forget one category's failures, when the success is in the same books.
	 *
	 * Called on the success path of whatever that category measures, so a site
	 * that recovers stops being warned about a problem it no longer has.
	 *
	 * Scoped to the payment mode, because a success in one mode is not evidence
	 * about the other. Someone who switches to test to reproduce a live failure
	 * and gets a test donation through has proved nothing about live, and
	 * clearing the warning there deletes the only record of what they were
	 * investigating. The row already carries the mode it was recorded in.
	 *
	 * Scoped to the gateway too, where the caller names one. A Stripe webhook
	 * getting through says nothing about PayPal, and on a site running both it
	 * was wiping the PayPal fault - including the server-side 'we captured the
	 * money and failed to record it', which is the most expensive line this
	 * feature writes - on the next Stripe donation.
	 *
	 * The earlier reasoning for leaving it out was that a fault would outlive
	 * the gateway that raised it, with nothing left to clear it once that
	 * gateway was switched off. That is now bounded: expire_stale_failures()
	 * ages a row out after RETENTION_DAYS whether or not anything succeeds.
	 *
	 * One row per category is all this option holds, so the gateway on it is
	 * whichever wrote last. That makes the scoping a guard against the wrong
	 * gateway clearing a row, not a way to hold two gateways' faults side by
	 * side; the second gateway to fail still takes the row over.
	 *
	 * A row with no recorded mode or gateway predates the field or came from a
	 * path without one, and is cleared by any success rather than stranded.
	 *
	 * @param string $category One of self::CATEGORIES.
	 * @param string $mode     Mode the success happened in. Defaults to the site's.
	 * @param string $gateway  Gateway the success happened on. '' clears whatever
	 *                         the row names, for callers that are not a gateway.
	 * @since 1.7.0
	 * @return void
	 */
	public static function clear_category( $category, $mode = '', $gateway = '' ) {
		$failures = self::get_failures();

		if ( ! isset( $failures[ $category ] ) ) {
			return;
		}

		$gateway          = Helper::get_string_value( $gateway );
		$recorded_gateway = Helper::get_string_value( $failures[ $category ]['gateway'] ?? '' );

		if ( '' !== $gateway && '' !== $recorded_gateway && $recorded_gateway !== $gateway ) {
			return;
		}

		if ( ! in_array( $mode, [ 'test', 'live' ], true ) ) {
			$mode = Payment_Helper::get_payment_mode();
		}

		$recorded = Helper::get_string_value( $failures[ $category ]['mode'] ?? '' );

		if ( in_array( $recorded, [ 'test', 'live' ], true ) && $recorded !== $mode ) {
			return;
		}

		unset( $failures[ $category ] );

		update_option( self::FAILURES_OPTION, $failures, false );
	}

	/**
	 * Absolute path to the log file, creating its directory if needed.
	 *
	 * @param bool $create Whether to create the directory when it is absent.
	 * @since 1.7.0
	 * @return string Absolute path, or '' when the location is unusable.
	 */
	public static function get_log_path( $create = true ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( Helper::get_string_value( $uploads['basedir'] ) ) . 'suredonation/logs/';

		if ( ! is_dir( $dir ) && ( ! $create || ! wp_mkdir_p( $dir ) ) ) {
			return '';
		}

		// Re-checked rather than written once at creation. Guards that are only
		// written the first time never arrive on a site whose directory already
		// exists, so adding one in an update would protect new installs and skip
		// every existing one - and a guard deleted by a careless cleanup would
		// stay gone. Three file_exists() against a directory WordPress has
		// already stat'ed, next to an flock'd read-modify-write, is not the cost
		// worth saving. Matches Pdf_Utils::ensure_receipts_dir().
		if ( $create ) {
			self::protect_directory( $dir );
		}

		return $dir . 'suredonation-debug-' . self::get_filename_hash() . '.log';
	}

	/**
	 * Validate, redact and write one entry.
	 *
	 * The single entry point. Everything that reaches the file goes through
	 * sanitize_entry() here rather than at the call sites, so no caller - ours
	 * today or anyone's tomorrow - can put a value in the log that the schema
	 * does not allow.
	 *
	 * The category is the server's to decide, never the caller's when the caller
	 * is a browser. A donor's browser reports what happened; it does not get to
	 * label its own failure as a renewal or an integration and so choose which
	 * warning the site owner sees.
	 *
	 * @param array<string,mixed> $raw      Entry fields. See sanitize_entry().
	 * @param string              $category One of self::CATEGORIES. Server callers
	 *                                      pass theirs; '' derives 'donation',
	 *                                      which is what a browser report is.
	 * @param int                 $budget   Largest the file may grow to on this
	 *                                      caller's behalf. Defaults to the whole
	 *                                      cap; the public reporting endpoint
	 *                                      passes self::BROWSER_BUDGET.
	 * @since 1.7.0
	 * @return bool True when the report had an effect: a line on disk, a
	 *              counted failure, or both. False when it was rejected.
	 */
	public static function append( array $raw, $category = '', $budget = self::MAX_FILE_SIZE ) {
		// Normalised like every other input. A caller passing 0 would otherwise
		// silently stop every write while still recording the failure.
		$budget = Helper::get_integer_value( $budget );

		if ( $budget < 1 ) {
			$budget = self::MAX_FILE_SIZE;
		}

		// Any write invalidates a memoised tail, whether or not this one lands.
		self::$tail_memo = [];

		// Checked here as well as at the route, so the guard sits on the function
		// that writes rather than only on today's callers. Without it any future
		// caller writes to disk on a site that never switched logging on.
		if ( ! self::is_enabled() ) {
			return false;
		}

		$entry = self::sanitize_entry( $raw );

		if ( empty( $entry ) ) {
			return false;
		}

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			$category = 'donation';
		}

		$entry['category'] = $category;

		// Counted before the file is touched. A full log or an unwritable uploads
		// directory must not stop the site owner being told donations are failing -
		// on a badly broken site those are exactly the conditions that occur.
		$counted = self::is_fault( $entry );

		if ( $counted ) {
			self::record_failure( $category, $entry );
		}

		$written = self::write( $entry, $budget );

		// A counted failure is an effect even with no line behind it. The one
		// caller that reads this return spends a public report's single
		// allowance on it, so answering false for an entry that did raise the
		// warning would hand that allowance back and let the same report raise
		// it again, every time, for as long as the file stayed full.
		return $written || $counted;
	}

	/**
	 * Append one sanitized entry to the file, collapsing an immediate repeat.
	 *
	 * Deduplication is not a nicety here. Stripe retries a failing webhook for
	 * three days and PayPal for the same order many times over; five hundred
	 * byte-identical lines would spend the entire size cap on one fact and then
	 * silently stop the log recording anything else. So an entry identical to
	 * the line already at the end of the file rewrites that line with a `repeat`
	 * count and a fresh `last_time` instead of adding another.
	 *
	 * `request_id` and `duration_ms` sit outside the fingerprint because both
	 * differ on every attempt; including them would mean dedupe never fires.
	 * What the collapsed line then carries is the most recent attempt's values,
	 * alongside `time` for when the run started and `last_time` for when it was
	 * last seen. The newest request id is the right one to keep: it is the one a
	 * gateway still has logs for, and it pairs with the `last_time` beside it.
	 *
	 * @param array<string,mixed> $entry  Sanitized entry.
	 * @param int                 $budget Largest the file may grow to for this
	 *                                    caller. See self::BROWSER_BUDGET.
	 * @since 1.7.0
	 * @return bool True when the line was written.
	 */
	private static function write( array $entry, $budget = self::MAX_FILE_SIZE ) {
		self::maybe_expire();

		$path = self::get_log_path();

		if ( '' === $path ) {
			return false;
		}

		// Cap and stop. Deliberately not a trim or a rotate: the person who
		// reproduced the bug is the one whose lines would be discarded.
		//
		// Against this caller's ceiling, not always the file's. They are the same
		// number for everything the server writes about itself; a report posted
		// by a browser stops at the smaller one so it cannot spend the room the
		// server needs. Checked before the lock, like the cap it replaces.
		if ( self::get_file_size() >= min( $budget, self::MAX_FILE_SIZE ) ) {
			return false;
		}

		$entry['time'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem can prompt for credentials and is not initialised on a public donation request; a raw handle is also the only way to rewrite the last line under one lock.
		$handle = fopen( $path, 'c+' );

		if ( ! is_resource( $handle ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Held across the read-compare-rewrite below, which is the point.
		if ( ! flock( $handle, LOCK_EX ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			return false;
		}

		$offset = self::find_repeat_offset( $handle, $entry );

		if ( null !== $offset ) {
			$entry['repeat']    = $offset['repeat'] + 1;
			$entry['time']      = $offset['time'];
			$entry['last_time'] = gmdate( 'Y-m-d H:i:s' );

			$start = max( 0, $offset['start'] );

			ftruncate( $handle, $start );
			fseek( $handle, $start );
		} else {
			fseek( $handle, 0, SEEK_END );
		}

		// JSON_UNESCAPED_UNICODE so a byte budget means something on disk. Without
		// it every non-ASCII character is written as \uXXXX and an emoji as a
		// twelve-byte surrogate pair, which multiplies a bounded entry by six on
		// its way into the file. The file is read back with json_decode(), which
		// does not care either way, and a support ticket is easier to read for it.
		$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing through the handle opened and exclusively locked above; WP_Filesystem has no equivalent that keeps the lock, and it can prompt for credentials on a public donation request.
		$written = is_string( $line ) && false !== fwrite( $handle, $line . "\n" );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $written;
	}

	/**
	 * Where the last line starts, when it is a repeat of the entry being written.
	 *
	 * @param resource            $handle Open, exclusively locked log handle.
	 * @param array<string,mixed> $entry  Entry about to be written.
	 * @since 1.7.0
	 * @return array{start:int,repeat:int,time:string}|null Null when there is nothing to collapse.
	 */
	private static function find_repeat_offset( $handle, array $entry ) {
		$stat = fstat( $handle );
		$size = is_array( $stat ) ? Helper::get_integer_value( $stat['size'] ?? 0 ) : 0;

		if ( $size < 2 ) {
			return null;
		}

		$window = min( $size, self::TAIL_SCAN_BYTES );
		$start  = $size - $window;

		fseek( $handle, $start );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading through the handle opened and exclusively locked by write(); re-reading the file through WP_Filesystem would drop out of that lock.
		$tail = fread( $handle, $window );

		// A file not ending in a newline was truncated mid-write by something
		// outside this class. Appending is safe; rewriting a partial line is not.
		if ( ! is_string( $tail ) || '' === $tail || "\n" !== substr( $tail, -1 ) ) {
			return null;
		}

		$body     = substr( $tail, 0, -1 );
		$position = strrpos( $body, "\n" );

		if ( false === $position ) {
			// No newline inside the window. That is the whole file only when the
			// window was the whole file; otherwise the last line is longer than the
			// scan and we have not actually found its start.
			if ( 0 !== $start ) {
				return null;
			}

			$line_start = 0;
			$last_line  = $body;
		} else {
			$line_start = $start + $position + 1;
			$last_line  = substr( $body, $position + 1 );
		}

		$previous = json_decode( $last_line, true );

		if ( ! is_array( $previous ) || self::get_fingerprint( $previous ) !== self::get_fingerprint( $entry ) ) {
			return null;
		}

		return [
			'start'  => $line_start,
			'repeat' => max( 1, Helper::get_integer_value( $previous['repeat'] ?? 1 ) ),
			'time'   => Helper::get_string_value( $previous['time'] ?? $entry['time'] ?? '' ),
		];
	}

	/**
	 * What makes two entries the same failure.
	 *
	 * Everything except the fields that differ on every attempt by design: the
	 * timestamps, the repeat counter itself, the per-request identifier and the
	 * duration.
	 *
	 * @param array<string,mixed> $entry Entry to fingerprint.
	 * @since 1.7.0
	 * @return string
	 */
	private static function get_fingerprint( array $entry ) {
		unset( $entry['time'], $entry['last_time'], $entry['repeat'], $entry['request_id'], $entry['duration_ms'] );

		ksort( $entry );

		return md5( (string) wp_json_encode( $entry ) );
	}

	/**
	 * Discard the log once its newest entry is past the retention window.
	 *
	 * Newest, not oldest. A site that has failed every day for a month has a log
	 * whose first line is older than the window and whose last line is from this
	 * morning, and expiring on the oldest entry deletes the whole file in the
	 * middle of exactly the investigation it exists for. The question retention
	 * asks is "has anyone needed this lately", and only the newest entry answers
	 * it.
	 *
	 * The age is read from the last line rather than the file's mtime, which a
	 * backup or a sync can refresh without anything being written, or an option,
	 * which can drift out of step with the file it describes. Whole-file, not
	 * per-line: this is a diagnostic scratchpad, and partially expiring it would
	 * leave a repro with its opening missing while costing a rewrite of the whole
	 * file on every write.
	 *
	 * The recorded failures expire on the same window but on their own clock,
	 * because they are not the file. Deleting a cold log used to delete them
	 * too, which meant a warning about a fault that was still costing donations
	 * disappeared the moment the file behind it aged out.
	 *
	 * @since 1.7.0
	 * @return bool True when a file was expired.
	 */
	public static function maybe_expire() {
		self::expire_stale_failures();

		$path = self::get_log_path( false );

		if ( '' === $path || ! file_exists( $path ) ) {
			return false;
		}

		$newest = self::get_newest_entry_time( $path );

		if ( null === $newest || time() - $newest < self::RETENTION_DAYS * DAY_IN_SECONDS ) {
			return false;
		}

		return self::delete_file();
	}

	/**
	 * When the last line of the log was written.
	 *
	 * `last_time` first: a line that collapsed a repeat keeps `time` for when the
	 * run started and carries `last_time` for when it was last seen, so `time`
	 * alone would age a line that is still being written to.
	 *
	 * @param string $path Absolute path to the log file.
	 * @since 1.7.0
	 * @return int|null Unix timestamp, or null when the file cannot be read.
	 */
	private static function get_newest_entry_time( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the tail of a file this class owns; WP_Filesystem would prompt for credentials and is unavailable on the public request that reaches this through write().
		$handle = fopen( $path, 'r' );

		if ( ! is_resource( $handle ) ) {
			return null;
		}

		$stat = fstat( $handle );
		$size = is_array( $stat ) ? Helper::get_integer_value( $stat['size'] ?? 0 ) : 0;

		// Scanned backwards from the end rather than read whole. The file is
		// capped at 1 MB and this runs on the write path of a donation request.
		$window = min( $size, self::TAIL_SCAN_BYTES );

		if ( $window < 1 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			return null;
		}

		fseek( $handle, $size - $window );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading through the handle opened above.
		$tail = fread( $handle, $window );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( ! is_string( $tail ) || '' === trim( $tail ) ) {
			return null;
		}

		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $tail ) ) ) );
		$entry = json_decode( (string) end( $lines ), true );

		if ( ! is_array( $entry ) ) {
			return null;
		}

		$time = Helper::get_string_value( $entry['last_time'] ?? '' );

		if ( '' === $time ) {
			$time = Helper::get_string_value( $entry['time'] ?? '' );
		}

		if ( '' === $time ) {
			return null;
		}

		$written = strtotime( $time . ' UTC' );

		return is_int( $written ) && $written > 0 ? $written : null;
	}

	/**
	 * Forget the failures nobody has seen in the retention window.
	 *
	 * Their own sweep, not a side effect of the file going. A category that last
	 * failed five weeks ago is stale whether or not the log still exists, and one
	 * that failed this morning stays whether or not it does.
	 *
	 * A row with no readable timestamp is kept. record_failure() always writes
	 * one, so an unreadable value means something edited the option by hand, and
	 * silently discarding a warning is the worse of the two mistakes available.
	 *
	 * @since 1.7.0
	 * @return bool True when something was forgotten.
	 */
	private static function expire_stale_failures() {
		$failures = self::get_failures();

		if ( empty( $failures ) ) {
			return false;
		}

		$cutoff = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$kept   = [];

		foreach ( $failures as $category => $failure ) {
			$at = Helper::get_integer_value( $failure['at'] ?? 0 );

			if ( $at <= 0 || $at >= $cutoff ) {
				$kept[ $category ] = $failure;
			}
		}

		if ( count( $kept ) === count( $failures ) ) {
			return false;
		}

		if ( empty( $kept ) ) {
			delete_option( self::FAILURES_OPTION );

			return true;
		}

		update_option( self::FAILURES_OPTION, $kept, false );

		return true;
	}

	/**
	 * The most recent whole log lines, up to a character budget.
	 *
	 * An excerpt for reading and pasting. The budget is the caller's: the details
	 * dialog shows it on screen and copies it to a clipboard, neither of which
	 * has a length limit worth designing around, while an excerpt embedded
	 * anywhere length-bound needs a smaller one. Newest entries are the ones that
	 * describe the failure being reported, so the tail is the useful end and the
	 * oldest are what a smaller budget drops.
	 *
	 * Whole lines only - half a JSON object helps nobody.
	 *
	 * @param int $max_chars Character budget for the returned text.
	 * @since 1.7.0
	 * @return array{text:string,shown:int,total:int}
	 */
	public static function get_tail( $max_chars = 1200 ) {
		// Memoised per request and per budget. The action items ask once per open
		// failure category, so a site with three open failures would otherwise read
		// a file capped at 1 MB three times to render one page.
		$max_chars = Helper::get_integer_value( $max_chars );

		// Keyed by blog as well as budget: get_log_path() hashes the blog id into
		// the filename, so after a switch_to_blog() the same budget is a different
		// file.
		//
		// Its own variable, not $max_chars reused - that key is a string, and the
		// byte-budget comparison below coerces "1:1200" to 1, which silently
		// reduces every excerpt to a single line.
		$memo_key = get_current_blog_id() . ':' . $max_chars;

		if ( isset( self::$tail_memo[ $memo_key ] ) ) {
			return self::$tail_memo[ $memo_key ];
		}

		$empty = [
			'text'  => '',
			'shown' => 0,
			'total' => 0,
		];

		$path = self::get_log_path( false );

		if ( '' === $path || ! file_exists( $path ) ) {
			self::$tail_memo[ $memo_key ] = $empty;

			return $empty;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file, WordPress.WP.AlternativeFunctions.file_system_read_file -- Reading a file this class owns; WP_Filesystem would prompt for credentials and is unavailable here.
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( ! is_array( $lines ) || empty( $lines ) ) {
			self::$tail_memo[ $memo_key ] = $empty;

			return $empty;
		}

		$total = count( $lines );
		$kept  = [];
		$used  = 0;

		foreach ( array_reverse( $lines ) as $line ) {
			$length = strlen( $line ) + 1;

			// Always keep one line, even if it alone exceeds the budget: an empty
			// excerpt is worse than a long one.
			if ( $used + $length > $max_chars && ! empty( $kept ) ) {
				break;
			}

			array_unshift( $kept, $line );
			$used += $length;
		}

		self::$tail_memo[ $memo_key ] = [
			'text'  => implode( "\n", $kept ),
			'shown' => count( $kept ),
			'total' => $total,
		];

		return self::$tail_memo[ $memo_key ];
	}

	/**
	 * Everything support needs about this site, as one block of text.
	 *
	 * Built on demand and never localized into an admin page. The log excerpt
	 * inside it is partly attacker-authored - an anonymous caller can reach the
	 * endpoint that writes it - so it is fetched by a capability-gated request
	 * made by someone who asked for it, rather than shipped to every admin
	 * screen on the off chance.
	 *
	 * Markdown-fenced because the destination is a support ticket, where an
	 * unfenced JSON line arrives mangled.
	 *
	 * @param int $max_chars Character budget for the log excerpt.
	 * @since 1.7.0
	 * @return string
	 */
	public static function get_support_report( $max_chars = 4000 ) {
		global $wp_version;

		$tail  = self::get_tail( $max_chars );
		$lines = [
			'---',
			'Site details',
			'Site: ' . home_url(),
			'SureDonation: ' . ( defined( 'SUREDONATION_VER' ) ? SUREDONATION_VER : 'unknown' ),
			// Named either way. "Pro is not active" is itself an answer, and
			// leaving the line out makes support ask for it.
			'SureDonation Pro: ' . ( defined( 'SUREDONATION_PRO_VER' ) ? SUREDONATION_PRO_VER : 'not active' ),
			'WordPress: ' . ( is_string( $wp_version ) ? $wp_version : 'unknown' ),
			'PHP: ' . PHP_VERSION,
			// Whether any of this cost anyone money, which is the first thing
			// worth knowing and the one field a donation plugin has that a form
			// plugin does not.
			'Payment mode: ' . Payment_Helper::get_payment_mode(),
		];

		$summary = [];

		foreach ( self::get_failures() as $category => $failure ) {
			$label     = Helper::get_string_value( $failure['label'] ?? '' );
			$summary[] = $category . ' x' . Helper::get_integer_value( $failure['count'] ?? 0 ) . ( '' !== $label ? ' (' . $label . ')' : '' );
		}

		// Spelled out rather than left blank: "none recorded" is a fact support
		// can act on, an absent line is a question.
		$lines[] = 'Recorded failures: ' . ( ! empty( $summary ) ? implode( ', ', $summary ) : 'none recorded' );

		$lines[] = '';
		$lines[] = '---';

		if ( 0 === $tail['total'] ) {
			$lines[] = 'Debug log: no entries recorded.';

			return implode( "\n", $lines );
		}

		$lines[] = sprintf( 'Debug log (most recent %1$d of %2$d entries)', $tail['shown'], $tail['total'] );

		// Fenced so it survives a reply and reads as data rather than prose
		// wherever Markdown is rendered.
		$lines[] = '```';
		$lines[] = $tail['text'];
		$lines[] = '```';

		if ( $tail['shown'] < $tail['total'] ) {
			$lines[] = '';
			$lines[] = 'Older entries were left out to keep this excerpt readable. The full log can be downloaded from Tools → Logs.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Whether the log has reached its size cap.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public static function is_full() {
		return self::get_file_size() >= self::MAX_FILE_SIZE;
	}

	/**
	 * Whether the file has grown past what a browser report may add to.
	 *
	 * Reported on its own because it arrives long before is_full() does, and
	 * the two need different things from the reader: a full log has stopped
	 * recording everything and wants clearing, while this one has stopped
	 * recording only the donor side and may simply mean the server has a lot
	 * to say. Without it the one channel that hears about a donor's failure
	 * can close with nothing on screen to show for it.
	 *
	 * @since 1.7.0
	 * @return bool True when browser-sourced reports are being refused.
	 */
	public static function is_browser_paused() {
		return self::get_file_size() >= self::BROWSER_BUDGET;
	}

	/**
	 * Current size of the log file in bytes.
	 *
	 * @since 1.7.0
	 * @return int
	 */
	public static function get_file_size() {
		$path = self::get_log_path( false );

		if ( '' === $path || ! file_exists( $path ) ) {
			return 0;
		}

		clearstatcache( true, $path );

		$size = filesize( $path );

		return is_int( $size ) ? $size : 0;
	}

	/**
	 * Delete the log file, and the failure counts that describe it.
	 *
	 * Both or neither, because this is the deliberate "I have dealt with this"
	 * action: someone pressed Clear, and leaving the warnings behind would have
	 * the dashboard still pointing at lines that are gone.
	 *
	 * Retention is the other case and does not come through here. A log aging
	 * out is not a statement that the faults it recorded are resolved, so
	 * maybe_expire() removes the file and ages the counts on their own window.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public static function clear() {
		delete_option( self::FAILURES_OPTION );

		return self::delete_file();
	}

	/**
	 * Remove the log file, leaving every other piece of state alone.
	 *
	 * @since 1.7.0
	 * @return bool True when the file is gone, whether or not it was there.
	 */
	private static function delete_file() {
		self::$tail_memo = [];

		$path = self::get_log_path( false );

		if ( '' === $path || ! file_exists( $path ) ) {
			return true;
		}

		$deleted = wp_delete_file_from_directory( $path, dirname( $path ) );

		clearstatcache( true, $path );

		// wp_delete_file() returns null on success before WordPress 6.7 and this
		// plugin still supports older, so false - the path check refusing, or
		// the unlink failing - is the only answer that means it is still there.
		return false !== $deleted;
	}

	/**
	 * Reduce a caller-supplied payload to the fixed shape the log accepts.
	 *
	 * Redaction governs values; this governs shape. Without it, "we redact the
	 * text" would still leave an anonymous caller writing arbitrary content into
	 * a file an administrator later opens and pastes into a support ticket.
	 *
	 * What is deliberately absent from this allowlist, and must stay absent:
	 *
	 *  - The donation amount. Amount, timestamp and campaign together are close
	 *    to unique for a donor on a small charity's site, and what someone gave
	 *    is the most sensitive non-identifying thing we hold about them.
	 *    `currency` answers the only diagnostic question amounts raise, which is
	 *    zero-decimal and rounding behaviour.
	 *  - Donor name, email, address or phone, in any field. scrub_text() exists
	 *    to catch those when a gateway interpolates them into an error string.
	 *    It is a backstop, not a licence to store them on purpose.
	 *  - Any payment instrument data: no last four, brand, expiry, BIN, PayPal
	 *    payer id, no `pm_` or `src_` token. Last four in particular is what
	 *    support staff use to confirm a caller's identity, so putting it in a
	 *    file any administrator can download makes the log an authentication
	 *    bypass. `code` and `decline_code` diagnose a declined card; the number
	 *    does not.
	 *  - Full webhook payloads, which carry the customer object, the email and
	 *    the amount. The event id is recorded instead, and the payload stays
	 *    where it already is, behind the gateway dashboard login.
	 *  - IP addresses. The plugin ships a GDPR eraser, and an address sitting in
	 *    a file the eraser cannot reach is a liability on an on-by-default
	 *    feature. `request_id` identifies a request more precisely anyway.
	 *  - Donor-authored free text: comments, tribute messages, custom field
	 *    values. `field_keys` records which fields were present, never what was
	 *    in them.
	 *
	 * @param array<string,mixed> $raw Caller payload.
	 * @since 1.7.0
	 * @return array<string,mixed> Empty when nothing usable survived.
	 */
	public static function sanitize_entry( array $raw ) {
		$type = isset( $raw['type'] ) ? sanitize_key( Helper::get_string_value( $raw['type'] ) ) : '';

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return [];
		}

		$entry = [ 'type' => $type ];

		foreach ( [ 'message', 'source', 'body', 'form_title', 'campaign_title' ] as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$text = self::scrub_text( Helper::get_string_value( $raw[ $key ] ) );

			if ( '' !== $text ) {
				$entry[ $key ] = $text;
			}
		}

		foreach ( [ 'status', 'duration_ms', 'line', 'form_id', 'campaign_id', 'donation_id' ] as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$entry[ $key ] = absint( Helper::get_integer_value( $raw[ $key ] ) );
			}
		}

		// Short, closed-vocabulary values. A character class rather than an
		// explicit list, because gateways invent new codes faster than we ship
		// and the risk a list would guard against - free text reaching the file -
		// is already removed by reducing the value to this alphabet.
		//
		// The dot is in it on purpose: Stripe event types are written
		// `payment_intent.succeeded`, and sanitize_key() would strip the
		// separator and hand support `payment_intentsucceeded` to search for.
		foreach ( [ 'gateway', 'code', 'decline_code' ] as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$value = substr(
				(string) preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( Helper::get_string_value( $raw[ $key ] ) ) ),
				0,
				self::MAX_ID_LENGTH
			);

			if ( '' !== $value ) {
				$entry[ $key ] = $value;
			}
		}

		// Which books the entry belongs to. Test-mode failures are recorded, not
		// suppressed - someone wiring up a gateway wants to know it is failing -
		// but the mode has to travel with the entry so nothing downstream reports
		// a test failure as lost revenue.
		if ( isset( $raw['mode'] ) ) {
			$mode = sanitize_key( Helper::get_string_value( $raw['mode'] ) );

			if ( in_array( $mode, [ 'test', 'live' ], true ) ) {
				$entry['mode'] = $mode;
			}
		}

		if ( isset( $raw['currency'] ) ) {
			$currency = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', Helper::get_string_value( $raw['currency'] ) ) );

			if ( 3 === strlen( $currency ) ) {
				$entry['currency'] = $currency;
			}
		}

		// Gateway-issued identifiers: Stripe's `request-id` header and PayPal's
		// `Paypal-Debug-Id` are what lets support escalate past us to the gateway
		// with a single reference. Both are opaque and carry nothing about the
		// donor. The character class is the strictest one both satisfy.
		foreach ( [ 'request_id', 'webhook_event_id' ] as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$value = substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', Helper::get_string_value( $raw[ $key ] ) ), 0, self::MAX_ID_LENGTH );

			if ( '' !== $value ) {
				$entry[ $key ] = $value;
			}
		}

		// Whether the request carried a verifiable signature, never the signature
		// itself. A signature value tells a human reader nothing, and the only
		// question anyone asks of it is this one.
		//
		// 'unverified' is the honest answer for a signature that was present and
		// well formed and that we could not attribute to the sender - a Stripe
		// header of the documented shape that did not match, or a verify call
		// PayPal answered with anything but SUCCESS. Calling either of those
		// 'invalid' would have read, to is_fault() and to a human, as proof the
		// real gateway had been turned away.
		if ( isset( $raw['signature'] ) ) {
			$signature = sanitize_key( Helper::get_string_value( $raw['signature'] ) );

			if ( in_array( $signature, [ 'present', 'missing', 'malformed', 'unverified', 'invalid' ], true ) ) {
				$entry['signature'] = $signature;
			}
		}

		if ( isset( $raw['field_keys'] ) && is_array( $raw['field_keys'] ) ) {
			$keys = [];

			// A field key is a slug, so it is reduced to the slug alphabet rather
			// than scrubbed as prose. sanitize_key() is lossless for every value
			// that belongs here and leaves nothing of one that does not: an
			// address, a token or a run of emoji all come back empty or ASCII, and
			// the result cannot carry an email, a URL or invalid UTF-8 for
			// scrub_text() and wp_check_invalid_utf8() to have to deal with.
			//
			// That is also what makes the cap below a cap. Both halves are needed:
			// capping only the count leaves one request able to write a megabyte
			// of field keys and fill the log in a single call, and because the log
			// stops rather than evicting, that silently disables the feature until
			// an administrator clears it.
			foreach ( array_slice( $raw['field_keys'], 0, self::MAX_KEYS ) as $field_key ) {
				$keys[] = substr( sanitize_key( Helper::get_string_value( $field_key ) ), 0, self::MAX_KEY_LENGTH );
			}

			$entry['field_keys'] = array_values( array_filter( $keys ) );
		}

		// A type alone says nothing. Require at least one substantive value.
		$has_detail = isset( $entry['message'] ) || isset( $entry['body'] ) || isset( $entry['status'] ) || isset( $entry['code'] );

		return $has_detail ? $entry : [];
	}

	/**
	 * Strip identifying detail out of free text and clamp its length.
	 *
	 * Redacting structured fields is not sufficient on its own: gateway error
	 * text interpolates donor input constantly ("No such customer: for email
	 * someone@example.com"), and a page URL routinely carries an address or a
	 * key in its query string. Whitespace is collapsed as a log-injection guard.
	 *
	 * Removes, in order: JSON slash-escaping, so the rules below can see URLs at
	 * all; credentials in a URL's userinfo; query strings and fragments; a
	 * foreign URL's path past its first segment, keeping same-origin paths intact
	 * because those are stack frames and the path is the diagnosis; the value
	 * following a name that identifies a credential; email addresses; and long
	 * digit runs, which is what catches a card number pasted into a message.
	 *
	 * A single-segment foreign path is truncated whole rather than kept, which is
	 * the safe direction.
	 *
	 * What it cannot remove is a name or a street address - those have no shape
	 * to match - which is why the schema in sanitize_entry() refuses to carry
	 * them in the first place and why this excerpt should still be treated as
	 * personal data.
	 *
	 * @param string $text Raw text.
	 * @since 1.7.0
	 * @return string
	 */
	public static function scrub_text( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		// Clamped before the rules run, not after. Without it every pattern below
		// is applied to whatever the caller sent, however long that is.
		$text = mb_strcut( $text, 0, self::MAX_TEXT_LENGTH * 4 );

		// A WP REST error body arrives slash-escaped - wp_json_encode() escapes "/"
		// and WP_REST_Server::serve_request() does not pass JSON_UNESCAPED_SLASHES -
		// and the body sinks log the raw response text rather than the decoded
		// object. Without this every URL rule below misses every URL in the largest
		// sink, including the webhook credentials they exist for.
		$text = str_replace( '\\/', '/', $text );

		// Credentials in the userinfo position, before the host rules see them.
		$text = (string) preg_replace( '#(https?://)[^\s/@]+@#i', '$1[credentials]@', $text );

		// Drop query strings and fragments wholesale rather than allowlisting
		// parameters. A token after # is just as sensitive as one after ?.
		$text = (string) preg_replace( '#(https?://[^\s?\#]+)[?\#][^\s"\'<>,;)\]}]*#i', '$1', $text );

		$site_host = Helper::get_string_value( wp_parse_url( home_url(), PHP_URL_HOST ) );

		// Keep the origin and the first path segment of a foreign URL, drop the
		// rest: a webhook credential sits in the path as often as in the query, and
		// Slack and Discord both put theirs there.
		//
		// Same-origin URLs are exempt. `source` is a stack frame, not a page
		// address, so truncating our own paths deletes the filename, the line and
		// the column, which is the whole diagnosis. A third-party credential is
		// never same-origin.
		$text = (string) preg_replace_callback(
			'#(https?://)([^\s/]+)((?:/[^\s/]*)?)/[^\s"\'<>,;)\]}]+#i',
			static function ( $matches ) use ( $site_host ) {
				// Trailing :digits only, so an IPv6 literal keeps its brackets and its
				// own colons - [::1]:8080 becomes [::1], which is the form
				// wp_parse_url() returns for one.
				$host = (string) preg_replace( '/:\d+$/', '', $matches[2] );

				if ( '' !== $site_host && 0 === strcasecmp( $host, $site_host ) ) {
					return $matches[0];
				}

				return $matches[1] . $matches[2] . $matches[3] . '/[path]';
			},
			$text
		);

		// Credentials named in the text itself. The name is matched as a whole
		// identifier, so a keyword with a prefix or suffix is still caught -
		// STRIPE_SECRET_KEY, paypal_client_secret, Stripe-Signature. And a
		// separator is required, so ordinary prose survives: "Invalid token
		// provided" and "password protected" are the most common things support
		// reads out of a log like this. `bearer` and `basic` are the exception,
		// because those carry the value after a space with no separator at all.
		$text = (string) preg_replace(
			'/(\b[\w.-]*(?:api[_-]?key|key|secret|token|password|passwd|pwd|auth|credential|signature)[\w.-]*["\']?\s*[:=]\s*["\']?|\b(?:bearer|basic)\s+)[^\s"\',;&]{8,}/i',
			'$1[redacted]',
			$text
		);

		// Email addresses.
		$text = (string) preg_replace( '/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', $text );

		// Long digit runs: card numbers, phone numbers, ids. Separators are matched
		// too, because a real card number is written 4242 4242 4242 4242 and a
		// contiguous-digits rule never sees it.
		$text = (string) preg_replace( '/\+?\d[\d\s().-]{5,}\d/', '[number]', $text );

		$text = (string) preg_replace( '/\s+/', ' ', $text );

		// mb_strcut(), not mb_substr(): the budget is in bytes and this is the cut
		// that enforces it. It splits on a byte boundary that keeps the string
		// valid UTF-8, so a multibyte character at the limit is dropped whole
		// rather than halved into something wp_json_encode() would refuse.
		return mb_strcut( trim( wp_strip_all_tags( $text ) ), 0, self::MAX_TEXT_LENGTH );
	}

	/**
	 * Random component of the log file name, generated once and reused.
	 *
	 * The blog id is part of the input because wp_salt() is network-wide: a
	 * salt-only hash would be identical on every site of a multisite network, and
	 * older subdirectory installs can share one uploads directory.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	private static function get_filename_hash() {
		$hash = get_option( self::FILENAME_OPTION, '' );

		if ( is_string( $hash ) && 32 === strlen( $hash ) && ctype_xdigit( $hash ) ) {
			return $hash;
		}

		$hash = hash_hmac( 'md5', 'suredonation-debug-log|' . get_current_blog_id(), wp_salt( 'auth' ) );

		update_option( self::FILENAME_OPTION, $hash, false );

		return $hash;
	}

	/**
	 * Write the directory guards, best effort.
	 *
	 * An index.html rather than index.php: the nginx failure mode is
	 * `autoindex on` producing a listing, and an index.html suppresses that.
	 * .htaccess covers Apache and LiteSpeed, web.config covers IIS, and both are
	 * inert on nginx and Caddy - which is why the unguessable file name, not
	 * these files, is what actually protects the log. Measured on an nginx host:
	 * the log is served with a plain 200 and the guards change nothing.
	 *
	 * The web.config mirrors Pdf_Utils::ensure_receipts_dir(), which is the
	 * house precedent and already carries the scars - requestFiltering under
	 * system.webServer/security rather than the authorization section IIS
	 * answers 500.19 on, and no <handlers><remove> block, because it names
	 * host-specific handlers and can itself error the directory. Only the hidden
	 * segment differs.
	 *
	 * @param string $dir Directory to guard.
	 * @since 1.7.0
	 * @return void
	 */
	private static function protect_directory( $dir ) {
		$web_config = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<configuration>' . "\n"
			. '  <system.webServer>' . "\n"
			. '    <security>' . "\n"
			. '      <requestFiltering>' . "\n"
			. '        <hiddenSegments>' . "\n"
			. '          <add segment="logs" />' . "\n"
			. '        </hiddenSegments>' . "\n"
			. '        <fileExtensions allowUnlisted="true">' . "\n"
			. '          <add fileExtension=".log" allowed="false" />' . "\n"
			. '          <add fileExtension=".php" allowed="false" />' . "\n"
			. '          <add fileExtension=".phtml" allowed="false" />' . "\n"
			. '          <add fileExtension=".phar" allowed="false" />' . "\n"
			. '        </fileExtensions>' . "\n"
			. '      </requestFiltering>' . "\n"
			. '    </security>' . "\n"
			. '  </system.webServer>' . "\n"
			. '</configuration>' . "\n";

		$guards = [
			'.htaccess'  => "# Apache 2.4\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n# Apache 2.2\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'index.html' => '',
			'web.config' => $web_config,
		];

		foreach ( $guards as $name => $contents ) {
			if ( file_exists( $dir . $name ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Best-effort directory guard written at creation time; WP_Filesystem may prompt for credentials and is unavailable on the public request that first creates this directory.
			file_put_contents( $dir . $name, $contents );
		}
	}
}
