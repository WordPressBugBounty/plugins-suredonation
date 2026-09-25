<?php
/**
 * Email Reports.
 *
 * The weekly donation digest: owns its settings (option key, defaults,
 * sanitiser), the WP-Cron schedule that sends it, and the report email
 * itself. Global Settings → General Settings → Email Reports.
 *
 * @package SureDonation
 * @since 1.6.1
 */

namespace SureDonation\Inc\Emails;

use DateTimeImmutable;
use DateTimeZone;
use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Email_Reports class.
 *
 * @since 1.6.1
 */
class Email_Reports {
	use Get_Instance;

	/**
	 * Key within the consolidated suredonation_options array.
	 *
	 * @since 1.6.1
	 */
	public const OPTION_KEY = 'email_reports';

	/**
	 * Cron hook the weekly send runs on.
	 *
	 * @since 1.6.1
	 */
	public const CRON_HOOK = 'suredonation_send_email_report';

	/**
	 * Allowed send days. Stored lowercase English; the UI translates labels.
	 *
	 * @since 1.6.1
	 */
	public const DAYS = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

	/**
	 * Send time in the site timezone (HH:MM:SS).
	 *
	 * @since 1.6.1
	 */
	public const DEFAULT_TIME = '09:00:00';

	/**
	 * How many campaigns each list in the digest shows.
	 *
	 * @since 1.6.1
	 */
	private const LIST_LIMIT = 5;

	/**
	 * Constructor.
	 *
	 * @since 1.6.1
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'send_scheduled_report' ] );
		add_action( 'admin_init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * Default settings.
	 *
	 * @since 1.6.1
	 * @return array{enabled: bool, recipients: string, day: string}
	 */
	public static function get_defaults() {
		return [
			'enabled'    => false,
			'recipients' => Helper::get_string_value( get_option( 'admin_email' ) ),
			'day'        => 'monday',
		];
	}

	/**
	 * Stored settings merged over the defaults.
	 *
	 * @since 1.6.1
	 * @return array{enabled: bool, recipients: string, day: string}
	 */
	public static function get_settings() {
		$stored   = Helper::get_suredonation_option( self::OPTION_KEY, [] );
		$settings = wp_parse_args( is_array( $stored ) ? $stored : [], self::get_defaults() );

		return [
			'enabled'    => ! empty( $settings['enabled'] ),
			'recipients' => Helper::get_string_value( $settings['recipients'] ?? '' ),
			'day'        => Helper::get_string_value( $settings['day'] ?? 'monday' ),
		];
	}

	/**
	 * Normalise a comma-separated recipients string into deliverable addresses.
	 *
	 * The single path every recipient takes — on save, on the scheduled send
	 * and on a test send — so an address that cannot reach wp_mail() headers
	 * intact never reaches them at all. Line breaks are stripped, invalid
	 * addresses dropped, duplicates collapsed.
	 *
	 * @since 1.6.1
	 * @param mixed $raw Comma-separated string (anything else yields []).
	 * @return array<int, string>
	 */
	public static function parse_recipients( $raw ) {
		if ( ! is_string( $raw ) ) {
			return [];
		}

		$recipients = [];
		foreach ( explode( ',', $raw ) as $candidate ) {
			$candidate = trim( str_replace( [ "\r", "\n" ], '', $candidate ) );
			if ( '' === $candidate || ! is_email( $candidate ) ) {
				continue;
			}
			// First spelling wins; later case-variants of the same address are dropped.
			$key = strtolower( $candidate );
			if ( ! isset( $recipients[ $key ] ) ) {
				$recipients[ $key ] = $candidate;
			}
		}

		return array_values( $recipients );
	}

	/**
	 * Sanitize a raw settings payload against the schema.
	 *
	 * Unknown days fall back to Monday, and "enabled" cannot survive without
	 * at least one deliverable recipient.
	 *
	 * @since 1.6.1
	 * @param mixed $raw Raw values (e.g. from a REST request).
	 * @return array{enabled: bool, recipients: string, day: string}
	 */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];

		$recipients = self::parse_recipients( $raw['recipients'] ?? '' );

		$day = isset( $raw['day'] ) ? strtolower( sanitize_key( Helper::get_string_value( $raw['day'] ) ) ) : 'monday';
		if ( ! in_array( $day, self::DAYS, true ) ) {
			$day = 'monday';
		}

		// Accepts real booleans and the form-encoded "true"/"false"/"1"/"0" strings.
		$enabled = filter_var( $raw['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );

		return [
			'enabled'    => $enabled && [] !== $recipients,
			'recipients' => implode( ', ', $recipients ),
			'day'        => $day,
		];
	}

	/**
	 * Sanitize, store and (re)schedule.
	 *
	 * @since 1.6.1
	 * @param mixed $raw Raw values.
	 * @return array{enabled: bool, recipients: string, day: string} The stored settings.
	 */
	public static function save( $raw ) {
		$settings = self::sanitize( $raw );
		Helper::update_suredonation_option( self::OPTION_KEY, $settings );
		self::reschedule( $settings );
		return $settings;
	}

	/**
	 * Replace the scheduled send with one matching the given settings.
	 *
	 * A single event, re-armed by send_scheduled_report() after every run,
	 * rather than a `weekly` recurrence: a fixed 604800-second interval keeps
	 * the UTC instant and lets the local wall-clock time drift by an hour at
	 * each DST change, while the settings screen promises 09:00 site time.
	 * Computing the next occurrence from scratch each week is correct by
	 * construction, and it also survives a timezone change.
	 *
	 * @since 1.6.1
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return void
	 */
	public static function reschedule( $settings ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$day = isset( $settings['day'] ) ? Helper::get_string_value( $settings['day'] ) : 'monday';
		wp_schedule_single_event( self::next_run_timestamp( $day ), self::CRON_HOOK );
	}

	/**
	 * Unix timestamp of the next scheduled send, or null when none is armed.
	 *
	 * Exposed to the settings screen so an admin can see that a report is
	 * actually queued; on a host where WP-Cron never runs the absence of an
	 * event is the only visible symptom.
	 *
	 * @since 1.6.1
	 * @return int|null
	 */
	public static function next_run() {
		$next = wp_next_scheduled( self::CRON_HOOK );

		return false === $next ? null : (int) $next;
	}

	/**
	 * Unix timestamp of the next send: the coming $day at the send time, in the
	 * site timezone. If that moment has already passed today, one week on.
	 *
	 * @since 1.6.1
	 * @param string $day One of self::DAYS (anything else is treated as Monday).
	 * @return int
	 */
	public static function next_run_timestamp( $day ) {
		$day = in_array( $day, self::DAYS, true ) ? $day : 'monday';

		/**
		 * Filters the time of day the weekly report is sent, in the site timezone.
		 *
		 * @since 1.6.1
		 * @param string $time HH:MM:SS. Default 09:00:00.
		 */
		$time = apply_filters( 'suredonation_email_report_time', self::DEFAULT_TIME );
		if ( ! is_string( $time ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time ) ) {
			$time = self::DEFAULT_TIME;
		}

		// "friday 09:00:00" resolves to the coming Friday (today, if today is
		// Friday). $day is whitelisted English, so the site locale cannot break it.
		$next = new DateTimeImmutable( "{$day} {$time}", wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 week' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Re-create the schedule when it is enabled but missing (cleared cron
	 * array, in-place upgrade). Admin screens only; ajax and cron requests
	 * must not pay for the option read.
	 *
	 * @since 1.6.1
	 * @return void
	 */
	public function ensure_scheduled() {
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) || false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		self::reschedule( $settings );
	}

	/**
	 * Cron callback. Reads the stored settings rather than trusting the event,
	 * so a disabled report never sends even if an event survives.
	 *
	 * The next event is armed before anything else, so a report that is
	 * skipped or fails to send still runs again next week. A quiet week is
	 * sent (the admin learns the report is alive, and the quiet-campaigns
	 * section is most useful then); a site in test mode is not, because the
	 * plugin treats test donations as non-revenue everywhere else.
	 *
	 * @since 1.6.1
	 * @return void
	 */
	public function send_scheduled_report() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		self::reschedule( $settings );

		$recipients = self::parse_recipients( $settings['recipients'] );
		if ( [] === $recipients ) {
			return;
		}

		if ( 'live' !== Payment_Helper::get_payment_mode() ) {
			return;
		}

		self::send_report( $recipients, false );
	}

	/**
	 * Build and send the weekly report.
	 *
	 * Built under the site locale whether this runs in an admin request (the
	 * test send, in the admin's own language) or under cron, so the preview is
	 * what recipients get.
	 *
	 * @since 1.6.1
	 * @param array<int, string> $recipients Addresses; re-validated here.
	 * @param bool               $is_test    A test send from the settings screen: goes out in test mode too, and says it is a test.
	 * @return bool wp_mail() result, or false when nothing was sent.
	 */
	public static function send_report( array $recipients, $is_test = false ) {
		$recipients = self::parse_recipients( implode( ',', $recipients ) );
		if ( [] === $recipients ) {
			return false;
		}

		$switched = switch_to_locale( get_locale() );

		$currency     = Payment_Helper::get_currency();
		$payment_mode = Payment_Helper::get_payment_mode();
		$report       = self::collect_report_data( time(), $currency, $payment_mode );

		$date_format = Helper::get_string_value( get_option( 'date_format' ) );
		$subject     = sprintf(
			/* translators: 1: start date, 2: end date. */
			__( 'Your weekly donation report: %1$s to %2$s', 'suredonation' ),
			wp_date( $date_format, $report['after_ts'] ),
			wp_date( $date_format, $report['now_ts'] )
		);
		$subject = str_replace( [ "\r", "\n" ], '', $subject );
		$message = self::build_report_html( $report, $currency, $payment_mode, $is_test );

		if ( $switched ) {
			restore_previous_locale();
		}

		$headers     = [ 'Content-Type: text/html; charset=UTF-8' ];
		$admin_email = get_option( 'admin_email' );
		if ( is_string( $admin_email ) && is_email( $admin_email ) ) {
			$from_name = str_replace( [ "\r", "\n" ], '', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $admin_email );
		}

		return (bool) wp_mail( $recipients, $subject, $message, $headers );
	}

	/**
	 * Gather every figure the report prints, for the 7 days ending at $now_ts.
	 *
	 * Windows are computed in the site timezone and queried in GMT, matching
	 * how donations.created_at is stored. The current week has no upper
	 * bound so a donation landing this second is never lost to a boundary.
	 *
	 * @since 1.6.1
	 * @param int    $now_ts       Unix timestamp the report is generated at.
	 * @param string $currency     Currency code to scope to.
	 * @param string $payment_mode 'test' or 'live'.
	 * @return array{after_ts: int, now_ts: int, week: array{raised: float, donations: int}, previous_week: array{raised: float, donations: int}, month: float, last_30_days: float, year: float, top_campaigns: array<int, array<string, mixed>>, quiet_campaigns: array<int, array<string, mixed>>}
	 */
	public static function collect_report_data( $now_ts, $currency, $payment_mode ) {
		$now_ts   = (int) $now_ts;
		$after_ts = $now_ts - WEEK_IN_SECONDS;
		$after    = gmdate( 'Y-m-d H:i:s', $after_ts );

		$utc       = new DateTimeZone( 'UTC' );
		$now_local = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( wp_timezone() );
		$month     = $now_local->modify( 'first day of this month 00:00:00' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$year      = $now_local->setDate( (int) $now_local->format( 'Y' ), 1, 1 )->setTime( 0, 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$week     = Donations::get_dashboard_stats( $currency, $payment_mode, $after );
		$previous = Donations::get_dashboard_stats( $currency, $payment_mode, gmdate( 'Y-m-d H:i:s', $after_ts - WEEK_IN_SECONDS ), $after );

		return [
			'after_ts'        => $after_ts,
			'now_ts'          => $now_ts,
			'week'            => [
				'raised'    => Helper::get_float_value( $week['total_raised'] ),
				'donations' => Helper::get_integer_value( $week['total_donations'] ),
			],
			'previous_week'   => [
				'raised'    => Helper::get_float_value( $previous['total_raised'] ),
				'donations' => Helper::get_integer_value( $previous['total_donations'] ),
			],
			'month'           => Helper::get_float_value( Donations::get_dashboard_stats( $currency, $payment_mode, $month )['total_raised'] ),
			'last_30_days'    => Helper::get_float_value( Donations::get_dashboard_stats( $currency, $payment_mode, gmdate( 'Y-m-d H:i:s', $now_ts - 30 * DAY_IN_SECONDS ) )['total_raised'] ),
			'year'            => Helper::get_float_value( Donations::get_dashboard_stats( $currency, $payment_mode, $year )['total_raised'] ),
			'top_campaigns'   => Donations::get_top_campaigns( self::LIST_LIMIT, $currency, $payment_mode, $after ),
			// Quietness is not currency-specific: a campaign that took gifts in
			// a currency the store has since moved away from is not "never
			// donated". Scoped by mode only.
			'quiet_campaigns' => Donations::get_stale_campaigns( $after, self::LIST_LIMIT, '', $payment_mode ),
		];
	}

	/**
	 * Render the report inside the shared email shell.
	 *
	 * Styled after the donation confirmation's success box and receipt card
	 * (src/blocks/styles/_common.scss): teal panel, white pill badge, white
	 * receipt cards with label/value rows and status-style badges. Everything
	 * is inline because mail clients drop stylesheets.
	 *
	 * Uses Email_Template::get_header()/get_footer() directly: render() runs
	 * nl2br() over the body, which litters table markup with <br>s.
	 *
	 * @since 1.6.1
	 * @param array<string, mixed> $report       Output of collect_report_data().
	 * @param string               $currency     Currency code the figures are scoped to and formatted in.
	 * @param string               $payment_mode 'live' or 'test'; anything but live is labelled as test figures.
	 * @param bool                 $is_test      Whether this is a test send from the settings screen.
	 * @return string Complete HTML document.
	 */
	public static function build_report_html( $report, $currency, $payment_mode = 'live', $is_test = false ) {
		$date_format = Helper::get_string_value( get_option( 'date_format' ) );
		$after_ts    = Helper::get_integer_value( $report['after_ts'] ?? 0 );
		$now_ts      = Helper::get_integer_value( $report['now_ts'] ?? time() );

		$week     = isset( $report['week'] ) && is_array( $report['week'] ) ? $report['week'] : [];
		$previous = isset( $report['previous_week'] ) && is_array( $report['previous_week'] ) ? $report['previous_week'] : [];

		$week_raised    = Helper::get_float_value( $week['raised'] ?? 0 );
		$week_count     = Helper::get_integer_value( $week['donations'] ?? 0 );
		$previous_count = Helper::get_integer_value( $previous['donations'] ?? 0 );
		$delta          = $week_count - $previous_count;

		if ( $delta > 0 ) {
			$delta_color = '#15803d';
			/* translators: %s: number of donations. */
			$delta_text = '&#9650; ' . sprintf( _n( '+%s donation compared to last week', '+%s donations compared to last week', $delta, 'suredonation' ), number_format_i18n( $delta ) );
		} elseif ( $delta < 0 ) {
			$delta_color = '#b91c1c';
			/* translators: %s: number of donations. */
			$delta_text = '&#9660; ' . sprintf( _n( '-%s donation compared to last week', '-%s donations compared to last week', abs( $delta ), 'suredonation' ), number_format_i18n( abs( $delta ) ) );
		} else {
			$delta_color = '#4b5563';
			$delta_text  = __( 'Same number of donations as last week', 'suredonation' );
		}

		$totals = [
			[ __( 'Raised this month', 'suredonation' ), Payment_Helper::format_amount( Helper::get_float_value( $report['month'] ?? 0 ), $currency ) ],
			[ __( 'Raised in the past 30 days', 'suredonation' ), Payment_Helper::format_amount( Helper::get_float_value( $report['last_30_days'] ?? 0 ), $currency ) ],
			[ __( 'Raised this year', 'suredonation' ), Payment_Helper::format_amount( Helper::get_float_value( $report['year'] ?? 0 ), $currency ) ],
		];

		$top_campaigns   = isset( $report['top_campaigns'] ) && is_array( $report['top_campaigns'] ) ? $report['top_campaigns'] : [];
		$quiet_campaigns = isset( $report['quiet_campaigns'] ) && is_array( $report['quiet_campaigns'] ) ? $report['quiet_campaigns'] : [];

		$site_name     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$donations_url = admin_url( 'admin.php?page=suredonation#/donations' );
		$settings_url  = admin_url( 'admin.php?page=suredonation#/settings?tab=general&subpage=email-reports' );
		$is_live       = 'live' === $payment_mode;
		$currency_code = strtoupper( Helper::get_string_value( $currency ) );

		// What the figures cover, said in the email: the Dashboard screen sums
		// every currency and both modes, the Reports screen scopes by both,
		// and this report follows Reports. Without the line the admin has no
		// way to reconcile the two.
		$scope_text = $is_live
			/* translators: %s: currency code, e.g. GBP. */
			? sprintf( __( 'Figures cover live donations in %s.', 'suredonation' ), $currency_code )
			/* translators: %s: currency code, e.g. GBP. */
			: sprintf( __( 'Test mode: these figures come from test donations in %s. No real money was processed.', 'suredonation' ), $currency_code );

		$campaign_url = static function ( $campaign ) {
			$id = absint( Helper::get_string_value( $campaign['campaign_id'] ?? 0 ) );

			return $id > 0 ? admin_url( 'admin.php?page=suredonation#/campaigns/' . $id ) : '';
		};

		// Receipt-card primitives, shared by the three cards below.
		$card_style  = 'border: 1px solid #e5e7eb; border-radius: 12px; padding: 8px 24px; background-color: #ffffff; color: #1f2937;';
		$title_style = 'margin: 0; padding: 16px 0; font-size: 16px; line-height: 24px; font-weight: 600; color: #1f2937;';
		$row_style   = 'padding: 14px 0; border-top: 1px solid #e5e7eb; font-size: 14px; line-height: 20px;';
		$label_style = 'color: #6b7280;';
		$value_style = 'color: #1f2937; text-align: right;';
		$badge_style = 'display: inline-block; padding: 3px 12px; border-radius: 9999px; font-size: 13px; line-height: 18px; font-weight: 500; white-space: nowrap;';

		ob_start();
		?>
		<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-radius: 16px; background-color: #0f6e8c;">
			<tbody>
				<tr>
					<td align="center" style="padding: 32px 24px; color: #ffffff;">
						<span style="display: inline-block; padding: 4px 14px; margin: 0 0 16px; border-radius: 9999px; background-color: #ffffff; color: #15803d; font-size: 13px; line-height: 18px; font-weight: 600;"><?php esc_html_e( 'Weekly Report', 'suredonation' ); ?></span>
						<h1 style="margin: 0 0 8px; font-size: 20px; line-height: 28px; font-weight: 600; color: #ffffff;"><?php esc_html_e( 'Your week in donations', 'suredonation' ); ?></h1>
						<p style="margin: 0; font-size: 14px; line-height: 22px; color: rgba(255, 255, 255, 0.85);"><?php echo esc_html( wp_date( $date_format, $after_ts ) . ' – ' . wp_date( $date_format, $now_ts ) ); ?></p>
						<p style="margin: 24px 0 4px; font-size: 40px; line-height: 48px; font-weight: 700; color: #ffffff;"><?php echo esc_html( Payment_Helper::format_amount( $week_raised, $currency ) ); ?></p>
						<p style="margin: 0 0 16px; font-size: 15px; line-height: 22px; color: rgba(255, 255, 255, 0.85);">
							<?php
							/* translators: %s: number of donations. */
							echo esc_html( sprintf( _n( '%s donation this week', '%s donations this week', $week_count, 'suredonation' ), number_format_i18n( $week_count ) ) );
							?>
						</p>
						<span style="display: inline-block; padding: 4px 14px; border-radius: 9999px; background-color: #ffffff; color: <?php echo esc_attr( $delta_color ); ?>; font-size: 13px; line-height: 18px; font-weight: 600;"><?php echo wp_kses( $delta_text, [] ); ?></span>
						<?php if ( $is_live ) { ?>
							<p style="margin: 16px 0 0; font-size: 13px; line-height: 18px; color: rgba(255, 255, 255, 0.85);"><?php echo esc_html( $scope_text ); ?></p>
						<?php } else { ?>
							<p style="margin: 16px 0 0; padding: 8px 12px; border-radius: 8px; background-color: #fef9c3; font-size: 13px; line-height: 18px; font-weight: 600; color: #a16207;"><?php echo esc_html( $scope_text ); ?></p>
						<?php } ?>
					</td>
				</tr>
			</tbody>
		</table>

		<div style="margin: 24px 0 0; <?php echo esc_attr( $card_style ); ?>">
			<h3 style="<?php echo esc_attr( $title_style ); ?>"><?php esc_html_e( 'Totals', 'suredonation' ); ?></h3>
			<table border="0" cellpadding="0" cellspacing="0" width="100%">
				<tbody>
				<?php foreach ( $totals as $total ) { ?>
					<tr>
						<td style="<?php echo esc_attr( $row_style . $label_style ); ?>"><?php echo esc_html( $total[0] ); ?></td>
						<td style="<?php echo esc_attr( $row_style . $value_style ); ?> font-weight: 700;"><?php echo esc_html( $total[1] ); ?></td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>

		<div style="margin: 16px 0 0; <?php echo esc_attr( $card_style ); ?>">
			<h3 style="<?php echo esc_attr( $title_style ); ?>"><?php esc_html_e( 'Best performing campaigns this week', 'suredonation' ); ?></h3>
			<table border="0" cellpadding="0" cellspacing="0" width="100%">
				<tbody>
				<?php if ( [] === $top_campaigns ) { ?>
					<tr>
						<td style="<?php echo esc_attr( $row_style . $label_style ); ?>"><?php esc_html_e( 'No campaign received a donation this week.', 'suredonation' ); ?></td>
					</tr>
				<?php } ?>
				<?php
				foreach ( $top_campaigns as $campaign ) {
					$count = Helper::get_integer_value( $campaign['donation_count'] ?? 0 );
					?>
					<tr>
						<td style="<?php echo esc_attr( $row_style ); ?>">
							<a href="<?php echo esc_url( $campaign_url( $campaign ) ); ?>" style="display: block; color: #1f2937; font-weight: 600; text-decoration: none;"><?php echo esc_html( Helper::get_string_value( $campaign['campaign_title'] ?? '' ) ); ?></a>
							<span style="display: block; color: #6b7280; font-size: 13px;">
								<?php
								/* translators: %s: number of donations. */
								echo esc_html( sprintf( _n( '%s donation', '%s donations', $count, 'suredonation' ), number_format_i18n( $count ) ) );
								?>
							</span>
						</td>
						<td valign="top" style="<?php echo esc_attr( $row_style . $value_style ); ?> font-weight: 700;"><?php echo esc_html( Payment_Helper::format_amount( Helper::get_float_value( $campaign['total_raised'] ?? 0 ), $currency ) ); ?></td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>

		<?php if ( [] !== $quiet_campaigns ) { ?>
			<div style="margin: 16px 0 0; <?php echo esc_attr( $card_style ); ?>">
				<h3 style="<?php echo esc_attr( $title_style ); ?>"><?php esc_html_e( 'Campaigns that have not received a donation this week', 'suredonation' ); ?></h3>
				<table border="0" cellpadding="0" cellspacing="0" width="100%">
					<tbody>
					<?php
					foreach ( $quiet_campaigns as $campaign ) {
						$last_at = isset( $campaign['last_donation_at'] ) && is_string( $campaign['last_donation_at'] ) ? strtotime( $campaign['last_donation_at'] ) : false;
						if ( false === $last_at ) {
							$badge      = __( 'No donations yet', 'suredonation' );
							$badge_tone = 'background-color: #f3f4f6; color: #4b5563;';
							$detail     = '';
						} else {
							// Exact days up to two months; human_time_diff() would round 10 days to "1 week".
							$days = (int) floor( max( 0, $now_ts - $last_at ) / DAY_IN_SECONDS );
							if ( $days < 60 ) {
								/* translators: %s: number of days. */
								$badge = sprintf( _n( 'Last donation %s day ago', 'Last donation %s days ago', $days, 'suredonation' ), number_format_i18n( $days ) );
							} else {
								/* translators: %s: human-readable time span, e.g. "3 months". */
								$badge = sprintf( __( 'Last donation %s ago', 'suredonation' ), human_time_diff( $last_at, $now_ts ) );
							}
							$badge_tone = 'background-color: #fef9c3; color: #a16207;';
							$detail     = Helper::get_string_value( wp_date( $date_format, $last_at ) );
						}
						?>
						<tr>
							<td style="<?php echo esc_attr( $row_style ); ?>">
								<a href="<?php echo esc_url( $campaign_url( $campaign ) ); ?>" style="display: block; color: #1f2937; font-weight: 600; text-decoration: none;"><?php echo esc_html( Helper::get_string_value( $campaign['campaign_title'] ?? '' ) ); ?></a>
								<?php if ( '' !== $detail ) { ?>
									<span style="display: block; color: #6b7280; font-size: 13px;"><?php echo esc_html( $detail ); ?></span>
								<?php } ?>
							</td>
							<td valign="top" style="<?php echo esc_attr( $row_style . $value_style ); ?>"><span style="<?php echo esc_attr( $badge_style . $badge_tone ); ?>"><?php echo esc_html( $badge ); ?></span></td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
			</div>
		<?php } ?>

		<p style="margin: 28px 0 0; text-align: center;">
			<a href="<?php echo esc_url( $donations_url ); ?>" style="display: inline-block; background-color: #0f6e8c; color: #ffffff; text-decoration: none; font-size: 14px; line-height: 20px; font-weight: 600; padding: 12px 22px; border-radius: 8px;"><?php esc_html_e( 'View all donations', 'suredonation' ); ?></a>
		</p>

		<p style="margin: 28px 0 0; text-align: center; font-size: 13px; line-height: 20px; color: #6b7280;">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color: #0f6e8c;"><?php echo esc_html( $site_name ); ?></a>
		</p>
		<p style="margin: 8px 0 0; text-align: center; font-size: 12px; line-height: 18px; color: #9ca3af;">
			<?php
			if ( $is_test ) {
				esc_html_e( 'This is a test of your weekly email report.', 'suredonation' );
			} else {
				esc_html_e( 'You are receiving this because weekly email reports are turned on.', 'suredonation' );
			}
			?>
			<a href="<?php echo esc_url( $settings_url ); ?>" style="color: #6b7280;"><?php esc_html_e( 'Manage email reports', 'suredonation' ); ?></a>
		</p>
		<?php
		$body = ob_get_clean();

		$template = Email_Template::get_instance();
		return $template->get_header( __( 'Weekly Donation Report', 'suredonation' ) ) . ( false !== $body ? $body : '' ) . $template->get_footer();
	}
}
