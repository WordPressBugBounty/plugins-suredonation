<?php
/**
 * Email Handler - Sends donation-related emails
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\Emails;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\FormEditor\Assets;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Offline\Offline_Helper;
use SureDonation\Inc\Payments\Payment_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email_Handler class.
 *
 * Reads email notification config from per-form post meta
 * (_suredonation_form_email_notifications) and sends all enabled
 * notifications when a donation event occurs.
 *
 * @since 0.0.1
 */
class Email_Handler {
	/**
	 * Valid trigger event types.
	 *
	 * @since 1.0.0
	 */
	public const EVENT_DONATION_COMPLETED  = 'donation_completed';
	public const EVENT_DONATION_PROCESSING = 'donation_processing';
	public const EVENT_DONATION_FAILED     = 'donation_failed';
	public const EVENT_REFUND_PROCESSED    = 'refund_processed';

	/**
	 * Send email notifications matching a specific event.
	 *
	 * Only notifications whose trigger matches the event (or trigger 'all') are sent.
	 *
	 * @param int                  $donation_id   Donation ID.
	 * @param int                  $campaign_id   Campaign ID.
	 * @param array<string, mixed> $donation_data Donation data array.
	 * @param int                  $form_id       Form post ID.
	 * @param string               $event         The event that triggered this call.
	 * @return void
	 * @since 1.0.0
	 */
	public static function send_donation_emails( $donation_id, $campaign_id, $donation_data, $form_id = 0, $event = self::EVENT_DONATION_COMPLETED ) {
		// Prevent duplicate emails for the same donation + event (e.g. AJAX and webhook racing).
		// Note: get/set transient is non-atomic (TOCTOU), but the race window is microseconds
		// and the worst case is a duplicate email — not data corruption. wp_cache_add() would
		// only be atomic with an external object cache; most WP installs use DB transients
		// where it offers no real advantage.
		if ( $donation_id > 0 ) {
			$lock_key = 'suredonation_email_lock_' . $event . '_' . $donation_id;
			if ( get_transient( $lock_key ) ) {
				return;
			}
			set_transient( $lock_key, true, 60 );
		}

		if ( empty( $form_id ) ) {
			$form_id = self::get_form_id_from_donation( $donation_id );
		}

		$notifications = self::get_form_notifications( $form_id );

		if ( empty( $notifications ) ) {
			return;
		}

		$campaign = get_post( $campaign_id );
		if ( ! $campaign ) {
			return;
		}

		foreach ( $notifications as $notification ) {
			if ( empty( $notification['status'] ) ) {
				continue;
			}

			// Only send notifications whose trigger matches the current event.
			$trigger = isset( $notification['trigger'] ) && is_string( $notification['trigger'] ) ? $notification['trigger'] : '';
			if ( empty( $trigger ) || ( 'all' !== $trigger && $trigger !== $event ) ) {
				continue;
			}

			// Resolve email_to using smart tags.
			$email_to_raw = isset( $notification['email_to'] ) && is_string( $notification['email_to'] ) ? $notification['email_to'] : '';
			$email_to     = self::process_smart_tags( $email_to_raw, $donation_data, $campaign );

			// Support comma-separated recipients.
			$recipients = array_map( 'trim', explode( ',', $email_to ) );
			$recipients = array_filter(
				$recipients,
				static function ( string $email ): bool {
					return (bool) is_email( $email );
				}
			);

			if ( empty( $recipients ) ) {
				continue;
			}

			foreach ( $recipients as $recipient ) {
				self::send_email( $recipient, $notification, $donation_data, $campaign, $donation_id );
			}
		}
	}

	/**
	 * Send donation confirmation emails.
	 *
	 * @param int                  $donation_id   Donation ID.
	 * @param int                  $campaign_id   Campaign ID.
	 * @param array<string, mixed> $donation_data Donation data array.
	 * @param int                  $form_id       Form post ID.
	 * @return void
	 * @since 0.0.1
	 */
	public static function send_donation_confirmation( $donation_id, $campaign_id, $donation_data, $form_id = 0 ) {
		self::send_donation_emails( $donation_id, $campaign_id, $donation_data, $form_id, self::EVENT_DONATION_COMPLETED );
	}

	/**
	 * Send donation processing emails.
	 *
	 * @param int                  $donation_id   Donation ID.
	 * @param int                  $campaign_id   Campaign ID.
	 * @param array<string, mixed> $donation_data Donation data array.
	 * @param int                  $form_id       Form post ID.
	 * @return void
	 * @since 1.0.0
	 */
	public static function send_donation_processing( $donation_id, $campaign_id, $donation_data, $form_id = 0 ) {
		self::send_donation_emails( $donation_id, $campaign_id, $donation_data, $form_id, self::EVENT_DONATION_PROCESSING );
	}

	/**
	 * Send donation failed emails.
	 *
	 * @param int                  $donation_id   Donation ID.
	 * @param int                  $campaign_id   Campaign ID.
	 * @param array<string, mixed> $donation_data Donation data array.
	 * @param int                  $form_id       Form post ID.
	 * @return void
	 * @since 1.0.0
	 */
	public static function send_donation_failed( $donation_id, $campaign_id, $donation_data, $form_id = 0 ) {
		self::send_donation_emails( $donation_id, $campaign_id, $donation_data, $form_id, self::EVENT_DONATION_FAILED );
	}

	/**
	 * Send refund processed emails.
	 *
	 * @param int                  $donation_id   Donation ID.
	 * @param int                  $campaign_id   Campaign ID.
	 * @param array<string, mixed> $donation_data Donation data array.
	 * @param int                  $form_id       Form post ID.
	 * @return void
	 * @since 1.0.0
	 */
	public static function send_refund_processed( $donation_id, $campaign_id, $donation_data, $form_id = 0 ) {
		self::send_donation_emails( $donation_id, $campaign_id, $donation_data, $form_id, self::EVENT_REFUND_PROCESSED );
	}

	/**
	 * Get email notifications from form post meta.
	 *
	 * @param int $form_id Form post ID.
	 * @return array<int, array<string, mixed>> Array of notification configs.
	 * @since 1.0.0
	 */
	private static function get_form_notifications( $form_id ) {
		if ( empty( $form_id ) ) {
			return [];
		}

		$raw = get_post_meta( $form_id, Assets::EMAIL_NOTIFICATIONS_META_KEY, true );

		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return [];
		}

		$notifications = json_decode( $raw, true );

		if ( ! is_array( $notifications ) ) {
			return [];
		}

		return $notifications;
	}

	/**
	 * Look up form_id from the donations table.
	 *
	 * @param int $donation_id Donation ID.
	 * @return int Form ID, or 0 if not found.
	 * @since 1.0.0
	 */
	private static function get_form_id_from_donation( $donation_id ) {
		if ( empty( $donation_id ) ) {
			return 0;
		}

		$donation = Donations::get( $donation_id );
		if ( ! $donation || ! is_array( $donation ) ) {
			return 0;
		}

		return isset( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
	}

	/**
	 * Send email using notification settings.
	 *
	 * @param string               $to_email      Recipient email address.
	 * @param array<string, mixed> $notification  Notification settings.
	 * @param array<string, mixed> $donation_data Donation data for smart tags.
	 * @param \WP_Post             $campaign      Campaign post object.
	 * @param int                  $donation_id   Optional donation ID.
	 * @return bool True if email was sent successfully.
	 * @since 0.0.1
	 */
	private static function send_email( $to_email, $notification, $donation_data, $campaign, $donation_id = 0 ) {
		if ( empty( $to_email ) || ! is_email( $to_email ) ) {
			return false;
		}

		// Prepare email data - ensure string types for process_smart_tags.
		$subject_raw    = isset( $notification['subject'] ) && is_string( $notification['subject'] ) ? $notification['subject'] : '';
		$email_body_raw = isset( $notification['email_body'] ) && is_string( $notification['email_body'] ) ? $notification['email_body'] : '';
		$subject        = self::process_smart_tags( $subject_raw, $donation_data, $campaign );
		$email_body     = self::process_smart_tags( $email_body_raw, $donation_data, $campaign );

		// Get from name and email - ensure string types.
		$from_name_raw = isset( $notification['from_name'] ) && is_string( $notification['from_name'] ) ? $notification['from_name'] : '';
		$from_name     = ! empty( $from_name_raw ) ? $from_name_raw : get_bloginfo( 'name' );
		$from_email    = isset( $notification['from_email'] ) && is_string( $notification['from_email'] ) && ! empty( $notification['from_email'] )
			? $notification['from_email']
			: get_option( 'admin_email' );
		$reply_to      = isset( $notification['reply_to'] ) && is_string( $notification['reply_to'] ) && ! empty( $notification['reply_to'] )
			? $notification['reply_to']
			: ( is_string( $from_email ) ? $from_email : '' );

		// Process smart tags in from fields.
		$from_name  = self::process_smart_tags( is_string( $from_name ) ? $from_name : '', $donation_data, $campaign );
		$from_email = self::process_smart_tags( is_string( $from_email ) ? $from_email : '', $donation_data, $campaign );
		$reply_to   = self::process_smart_tags( is_string( $reply_to ) ? $reply_to : '', $donation_data, $campaign );
		$subject    = str_replace( [ "\r", "\n" ], '', $subject );

		// Sanitize header values: strip CRLF to prevent header injection, validate emails.
		$from_name   = str_replace( [ "\r", "\n" ], '', $from_name );
		$admin_email = get_option( 'admin_email' );
		$from_email  = is_email( $from_email ) ? (string) $from_email : ( is_string( $admin_email ) ? $admin_email : '' );
		$reply_to    = is_email( $reply_to ) ? (string) $reply_to : $from_email;

		// Set email headers.
		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', (string) $from_name, $from_email ),
			sprintf( 'Reply-To: %s', $reply_to ),
		];

		// Convert plain text to HTML if needed.
		$email_body = self::format_email_body( $email_body );

		// Send email.
		$sent = wp_mail( $to_email, $subject, $email_body, $headers );

		// Log email send attempt.
		// 4th param is display name (not machine ID). Pre-release plugin (v0.0.1) with no
		// external consumers of this hook, so no backward-compatibility concern.
		$notification_name = isset( $notification['name'] ) && is_string( $notification['name'] ) ? $notification['name'] : '';
		do_action( 'suredonation_email_sent', $donation_id, $to_email, $sent, $notification_name );

		return $sent;
	}

	/**
	 * Process smart tags in email content.
	 *
	 * @param string               $content       Content with smart tags.
	 * @param array<string, mixed> $donation_data Donation data.
	 * @param \WP_Post             $campaign      Campaign post object.
	 * @return string Processed content.
	 * @since 0.0.1
	 */
	public static function process_smart_tags( $content, $donation_data, $campaign ) {
		// Get currency symbol - ensure string type.
		$currency       = isset( $donation_data['currency'] ) && is_string( $donation_data['currency'] ) ? $donation_data['currency'] : 'USD';
		$campaign_title = ( $campaign instanceof \WP_Post ) ? $campaign->post_title : '';

		// Calculate total amount (base + fees) - ensure numeric types.
		$amount_value       = $donation_data['amount'] ?? 0;
		$fees_covered_value = $donation_data['fees_covered'] ?? 0;
		$base_amount        = is_numeric( $amount_value ) ? (float) $amount_value : 0.0;
		$fees_covered       = is_numeric( $fees_covered_value ) ? (float) $fees_covered_value : 0.0;
		$total_amount       = $base_amount + $fees_covered;

		// Format amounts with currency symbol.
		$formatted_amount = Payment_Helper::format_amount( $total_amount, $currency );

		// Get date format - ensure string type.
		$date_format = get_option( 'date_format' );
		$date_format = is_string( $date_format ) ? $date_format : 'Y-m-d';

		// Smart tags mapping.
		$donor_name     = isset( $donation_data['donor_name'] ) && is_string( $donation_data['donor_name'] ) ? $donation_data['donor_name'] : __( 'Donor', 'suredonation' );
		$donor_email    = isset( $donation_data['donor_email'] ) && is_string( $donation_data['donor_email'] ) ? $donation_data['donor_email'] : '';
		$transaction_id = isset( $donation_data['transaction_id'] ) && is_string( $donation_data['transaction_id'] ) ? $donation_data['transaction_id'] : '';
		if ( empty( $transaction_id ) && isset( $donation_data['id'] ) ) {
			$transaction_id = is_scalar( $donation_data['id'] ) ? (string) $donation_data['id'] : '';
		}

		// Subscription smart tags.
		$subscription_id = isset( $donation_data['subscription_id'] ) && is_string( $donation_data['subscription_id'] ) ? $donation_data['subscription_id'] : '';
		$admin_email     = get_option( 'admin_email', '' );

		// Payment method smart tags.
		$gateway        = isset( $donation_data['gateway'] ) && is_string( $donation_data['gateway'] ) ? $donation_data['gateway'] : 'stripe';
		$payment_method = Helper::get_payment_method_label( $gateway );
		$payment_status = isset( $donation_data['payment_status'] ) && is_string( $donation_data['payment_status'] ) ? $donation_data['payment_status'] : '';

		$offline_instructions = '';
		if ( 'offline' === $gateway ) {
			$offline_instructions = Offline_Helper::get_offline_instructions();
		}

		$tags = [
			'{donor_name}'            => esc_html( $donor_name ),
			'{donor_email}'           => esc_html( $donor_email ),
			'{amount}'                => esc_html( $formatted_amount ),
			'{campaign_name}'         => esc_html( $campaign_title ),
			'{donation_date}'         => esc_html( (string) current_time( $date_format ) ),
			'{transaction_id}'        => esc_html( $transaction_id ),
			'{site_title}'            => esc_html( get_bloginfo( 'name' ) ),
			'{admin_email}'           => esc_html( Helper::get_string_value( $admin_email ) ),
			'{site_url}'              => esc_url( home_url() ),
			'{admin_url}'             => esc_url( admin_url( 'admin.php?page=suredonation' ) ),
			'{subscription_id}'       => esc_html( $subscription_id ),
			'{subscription_interval}' => isset( $donation_data['subscription_interval'] ) && is_string( $donation_data['subscription_interval'] )
				? esc_html( $donation_data['subscription_interval'] )
				: '',
			'{payment_method}'        => esc_html( $payment_method ),
			'{donation_amount}'       => esc_html( Payment_Helper::format_amount( $base_amount, $currency ) ),
			'{donation_total}'        => esc_html( $formatted_amount ),
			'{payment_status}'        => Helper::render_payment_status_badge( $payment_status ),
			'{success_badge}'         => Helper::render_success_badge(),
			'{donation_receipt}'      => Helper::render_donation_receipt( $donation_data, $campaign_title ),
			'{refund_amount}'         => isset( $donation_data['refund_amount'] ) && is_numeric( $donation_data['refund_amount'] )
				? esc_html( Payment_Helper::format_amount( (float) $donation_data['refund_amount'], $currency ) )
				: '',
			'{offline_instructions}'  => wp_kses_post( $offline_instructions ),
		];

		// Apply filters to allow adding custom smart tags.
		$core_tags = array_keys( $tags );
		$tags      = apply_filters( 'suredonation_email_smart_tags', $tags, $donation_data, $campaign );

		// Sanitize any third-party tags added via the filter to prevent XSS in HTML emails.
		foreach ( $tags as $tag_key => $tag_value ) {
			if ( ! in_array( $tag_key, $core_tags, true ) ) {
				$tags[ $tag_key ] = esc_html( (string) $tag_value );
			}
		}

		// Replace smart tags.
		return str_replace( array_keys( $tags ), array_values( $tags ), $content );
	}

	/**
	 * Format email body with HTML wrapper.
	 *
	 * @param string $body Email body content.
	 * @return string Formatted HTML email.
	 * @since 0.0.1
	 */
	private static function format_email_body( $body ) {
		$email_template = Email_Template::get_instance();
		return $email_template->render( $body );
	}
}
