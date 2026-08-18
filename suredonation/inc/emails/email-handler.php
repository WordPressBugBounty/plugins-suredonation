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
		// Prevent duplicate emails for the same donation + event (e.g. AJAX and
		// webhook racing). Claimed immediately after the check rather than once
		// the form and campaign are resolved: those lookups are a DB read, the
		// notification merge and a get_post(), and holding the gap open across
		// them lets both racers pass the check before either claims — which is
		// exactly the pair this lock exists to separate, and they arrive together
		// on essentially every Stripe donation.
		//
		// A claim that resolves nothing is released again at each early return
		// below, so a caller that bailed does not swallow the retry that would
		// have succeeded.
		//
		// get/set is still non-atomic (TOCTOU) and the worst case is a duplicate
		// email, not data corruption. wp_cache_add() would only be atomic with an
		// external object cache; most installs use DB transients.
		$lock_key = $donation_id > 0 ? 'suredonation_email_lock_' . $event . '_' . $donation_id : '';

		if ( '' !== $lock_key && get_transient( $lock_key ) ) {
			return;
		}

		if ( '' !== $lock_key ) {
			set_transient( $lock_key, true, 60 );
		}

		// One lookup covers both the form and the donation timestamp; callers
		// build their own data array and rarely carry created_at.
		$needs_form_id   = empty( $form_id );
		$needs_timestamp = empty( $donation_data['created_at'] );

		if ( ( $needs_form_id || $needs_timestamp ) && ! empty( $donation_id ) ) {
			$donation = Donations::get( $donation_id );

			if ( is_array( $donation ) ) {
				if ( $needs_form_id && isset( $donation['form_id'] ) && is_scalar( $donation['form_id'] ) ) {
					$form_id = absint( $donation['form_id'] );
				}
				if ( $needs_timestamp && isset( $donation['created_at'] ) && is_string( $donation['created_at'] ) ) {
					$donation_data['created_at'] = $donation['created_at'];
				}
			}
		}

		$notifications = self::get_form_notifications( $form_id );

		if ( empty( $notifications ) ) {
			self::release_send_lock( $lock_key );
			return;
		}

		// campaign_id 0 is a supported standalone form, not an error — see the
		// note on Donations::add(). Everything downstream already tolerates a
		// null campaign, so only a genuinely missing campaign should stop the send.
		$campaign = $campaign_id > 0 ? get_post( $campaign_id ) : null;
		if ( $campaign_id > 0 && ! $campaign ) {
			self::release_send_lock( $lock_key );
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
				self::send_email( $recipient, $notification, $donation_data, $campaign, $donation_id, $event );
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
	 * Option recording that stored rows have had their keys back-filled.
	 *
	 * @since 1.4.0
	 */
	public const KEY_BACKFILL_OPTION = 'suredonation_notification_keys_backfilled';

	/**
	 * Write resolved identities back to rows saved before keys existed.
	 *
	 * Resolution is otherwise re-derived on every send and never persisted, so a
	 * form saved before this release stays dependent on guesswork for the rest of
	 * its life — and stays exposed to whatever breaks the guess, whether that is
	 * a rename, a locale difference or an edited recipient. Doing it once, here,
	 * is what makes the fallback a migration rather than a permanent code path.
	 *
	 * Deliberately runs in admin context only: it writes, and the read path it
	 * repairs is reached during donor payment requests.
	 *
	 * Only identity is written: keys, and the triggers the old sanitizer
	 * overwrote. Deliberately not the dedupe — it discards one of two rows that
	 * resolve to the same key, and where both were customised that is an edit
	 * the admin cannot get back. In memory that loss lasts one request; on disk
	 * it is permanent. Collapsing duplicates costs nothing to redo per read, so
	 * it stays there.
	 *
	 * Missing defaults are not appended either. That is a read-time concern
	 * which depends on whether Pro is active, and baking today's answer into the
	 * row would strand the form the next time that changes.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function backfill_notification_keys() {
		$defaults = Assets::get_instance()->get_default_email_notifications();

		// Keyed to the defaults that were available, not a bare "done" flag.
		// Which rows can be identified depends on what is registered at the time:
		// with Pro inactive or on an older build, its templates and their former
		// names are simply absent, and its rows resolve to nothing. Recording the
		// set means the pass runs again once that set changes — when Pro is
		// activated or updated — instead of a one-time run deciding forever.
		$signature = self::default_keys_signature( $defaults );

		if ( get_option( self::KEY_BACKFILL_OPTION ) === $signature ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration over a meta key with no API equivalent.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				Assets::EMAIL_NOTIFICATIONS_META_KEY
			)
		);

		if ( is_array( $rows ) && ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$stored = json_decode( (string) $row->meta_value, true );

				if ( ! is_array( $stored ) || empty( $stored ) ) {
					continue;
				}

				$resolved = self::add_identity_keys( $stored, $defaults );
				$resolved = self::restore_rewritten_triggers( $resolved, $defaults );

				// Only rows that were identified are written. A row that resolved
				// to nothing keeps exactly what it had, including its status: the
				// read path still parks it so it cannot mis-send, but parking it
				// on disk would outlive the reason for it — a row unidentifiable
				// today because Pro is a version behind would stay switched off
				// after Pro caught up, with nothing to switch it back.
				foreach ( $resolved as $index => $row_data ) {
					if ( empty( $row_data['key'] ) ) {
						$resolved[ $index ] = $stored[ $index ];
					}
				}

				if ( $resolved === $stored ) {
					continue;
				}

				update_post_meta(
					(int) $row->post_id,
					Assets::EMAIL_NOTIFICATIONS_META_KEY,
					wp_slash( (string) wp_json_encode( $resolved ) )
				);
			}
		}

		update_option( self::KEY_BACKFILL_OPTION, $signature, false );
	}

	/**
	 * Signature of the default keys currently registered.
	 *
	 * @param array<int, array<string, mixed>> $defaults Default notifications.
	 * @return string Signature.
	 * @since 1.4.0
	 */
	private static function default_keys_signature( $defaults ) {
		$keys = [];

		foreach ( $defaults as $default ) {
			if ( ! empty( $default['key'] ) && is_string( $default['key'] ) ) {
				$keys[] = $default['key'];
			}
		}

		sort( $keys );

		return md5( (string) wp_json_encode( $keys ) );
	}

	/**
	 * Give back a send lock claimed by a call that delivered nothing.
	 *
	 * @param string $lock_key Lock transient name, or '' when unlocked.
	 * @return void
	 * @since 1.4.0
	 */
	private static function release_send_lock( $lock_key ) {
		if ( '' !== $lock_key ) {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Get email notifications for a form.
	 *
	 * Stored settings are authoritative, but they are only written when an admin
	 * opens the form's Email Notifications tab and saves. Until then the meta is
	 * empty, and reading it literally means a form sends nothing at all. Defaults
	 * fill the gaps so delivery never depends on having visited the editor.
	 *
	 * Resolved per read and not persisted: sending happens mid-payment, and a
	 * write there would be a side effect on a path that only needs to read. The
	 * resolution is persisted once by backfill_notification_keys(), which runs
	 * in admin context, so this stays a fallback rather than the normal path.
	 *
	 * @param int $form_id Form post ID.
	 * @return array<int, array<string, mixed>> Array of notification configs.
	 * @since 1.0.0
	 */
	private static function get_form_notifications( $form_id ) {
		if ( empty( $form_id ) ) {
			return [];
		}

		$raw           = get_post_meta( $form_id, Assets::EMAIL_NOTIFICATIONS_META_KEY, true );
		$notifications = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $notifications ) ) {
			$notifications = [];
		}

		$defaults = Assets::get_instance()->get_default_email_notifications();

		if ( empty( $notifications ) ) {
			return $defaults;
		}

		$notifications = self::add_identity_keys( $notifications, $defaults );
		$notifications = self::restore_rewritten_triggers( $notifications, $defaults );
		$notifications = self::drop_duplicate_notifications( $notifications );

		return self::add_missing_notifications( $notifications, $defaults );
	}

	/**
	 * Stamp a stable identity onto rows saved before keys existed.
	 *
	 * Older rows carry only `id` and `name`, and neither identifies anything on
	 * its own: `id` is reassigned whenever the editor re-seeds a set, and `name`
	 * is user-editable and translated — it resolves in the admin's locale when
	 * saved and the site's locale when an email is sent.
	 *
	 * So the fallback is `trigger` + `email_to`. Both are untranslated, neither
	 * changes when a notification is renamed, and the pair is unique across every
	 * default (`trigger` alone is not — donor and admin templates share one).
	 *
	 * @param array<int, array<string, mixed>> $notifications Stored notifications.
	 * @param array<int, array<string, mixed>> $defaults      Default notifications.
	 * @return array<int, array<string, mixed>> Notifications with a `key` where one could be resolved.
	 * @since 1.4.0
	 */
	private static function add_identity_keys( $notifications, $defaults ) {
		$by_name      = [];
		$by_signature = [];

		foreach ( $defaults as $default ) {
			$key = isset( $default['key'] ) && is_string( $default['key'] ) ? $default['key'] : '';
			if ( '' === $key ) {
				continue;
			}

			$signature = self::notification_signature( $default );
			if ( '' !== $signature && ! isset( $by_signature[ $signature ] ) ) {
				$by_signature[ $signature ] = $key;
			}

			if ( isset( $default['name'] ) && is_string( $default['name'] ) ) {
				$by_name[ $default['name'] ] = $key;
			}
			// A default that has been renamed carries the names it used to have,
			// so rows stored under the old wording still resolve.
			if ( isset( $default['legacy_names'] ) && is_array( $default['legacy_names'] ) ) {
				foreach ( $default['legacy_names'] as $legacy ) {
					if ( is_string( $legacy ) && ! isset( $by_name[ $legacy ] ) ) {
						$by_name[ $legacy ] = $key;
					}
				}
			}
		}

		foreach ( $notifications as $index => $notification ) {
			if ( ! empty( $notification['key'] ) ) {
				continue;
			}

			$name = isset( $notification['name'] ) && is_string( $notification['name'] ) ? $notification['name'] : '';

			if ( '' !== $name && isset( $by_name[ $name ] ) ) {
				$notifications[ $index ]['key'] = $by_name[ $name ];
				continue;
			}

			// The name did not match, which a rename or a locale difference is
			// enough to cause. Fall back to what the admin did not edit and
			// gettext does not touch.
			//
			// Not `id`: the editor used to reassign ids when re-seeding, so a
			// stored id lands on whichever default happens to hold it and a donor
			// row can inherit an admin row's key — routing it to the wrong trigger
			// and suppressing the default it was mistaken for. No key at all is
			// recoverable; a confidently wrong one is not.
			$signature = self::notification_signature( $notification );
			if ( '' !== $signature && isset( $by_signature[ $signature ] ) ) {
				$notifications[ $index ]['key'] = $by_signature[ $signature ];
				continue;
			}

			// Unidentifiable, and 'all' means "fire on every event" — the value
			// the old sanitizer fell back to. Leaving it enabled would send a
			// recurring template to a one-time donor, so it is parked rather than
			// guessed at.
			//
			// Parking here is in memory only, so the editor still shows the row
			// switched on. backfill_notification_keys() is what reconciles the
			// two: it persists the resolution, which restores the real trigger
			// for rows that resolve and records the off state for those that do
			// not, so the toggle stops disagreeing with what actually sends.
			if ( 'all' === ( $notification['trigger'] ?? '' ) ) {
				$notifications[ $index ]['status'] = false;
			}
		}

		return $notifications;
	}

	/**
	 * Identify a notification by the two fields that survive editing.
	 *
	 * `trigger` is a sanitized key and `email_to` is a smart tag, so neither is
	 * translated and neither changes when a notification is renamed. Together
	 * they are unique across every default; `trigger` on its own is not, because
	 * the donor and admin templates for an event share it.
	 *
	 * @param array<string, mixed> $notification Notification row.
	 * @return string Signature, or '' when the row cannot supply one.
	 * @since 1.4.0
	 */
	private static function notification_signature( $notification ) {
		$trigger  = isset( $notification['trigger'] ) && is_string( $notification['trigger'] ) ? $notification['trigger'] : '';
		$email_to = isset( $notification['email_to'] ) && is_string( $notification['email_to'] ) ? $notification['email_to'] : '';

		if ( '' === $trigger || '' === $email_to ) {
			return '';
		}

		return $trigger . '|' . $email_to;
	}

	/**
	 * Restore triggers rewritten by an older save.
	 *
	 * Saving a form while Pro was inactive rewrote its recurring triggers to
	 * 'all', so those notifications fire on every donation event. The editor
	 * repairs this when the tab is opened; doing it here as well means a form
	 * nobody edits stops mis-sending too.
	 *
	 * Only 'all' is corrected, and only against the row's resolved key. 'all' is
	 * the exact value the old sanitizer fell back to, so any other mismatch is
	 * treated as a deliberate choice and left alone.
	 *
	 * @param array<int, array<string, mixed>> $notifications Stored notifications.
	 * @param array<int, array<string, mixed>> $defaults      Default notifications.
	 * @return array<int, array<string, mixed>> Notifications with triggers restored.
	 * @since 1.4.0
	 */
	private static function restore_rewritten_triggers( $notifications, $defaults ) {
		$by_key = [];
		foreach ( $defaults as $default ) {
			if ( ! empty( $default['key'] ) && is_string( $default['key'] ) && isset( $default['trigger'] ) ) {
				$by_key[ $default['key'] ] = $default['trigger'];
			}
		}

		foreach ( $notifications as $index => $notification ) {
			$key     = isset( $notification['key'] ) && is_string( $notification['key'] ) ? $notification['key'] : '';
			$trigger = isset( $notification['trigger'] ) && is_string( $notification['trigger'] ) ? $notification['trigger'] : '';

			if ( 'all' !== $trigger || '' === $key || ! isset( $by_key[ $key ] ) ) {
				continue;
			}

			$notifications[ $index ]['trigger'] = $by_key[ $key ];
		}

		return $notifications;
	}

	/**
	 * Collapse rows that resolve to the same notification.
	 *
	 * A form that went through the deactivate/reactivate cycle holds the admin's
	 * customised row alongside a pristine copy the editor appended when it failed
	 * to recognise the original. Restoring the trigger above makes the two exact
	 * twins, so without this the donor receives both.
	 *
	 * The customised row wins: it is the one carrying the admin's edits, and the
	 * pristine copy only exists because of the recognition bug.
	 *
	 * @param array<int, array<string, mixed>> $notifications Stored notifications.
	 * @return array<int, array<string, mixed>> Notifications with duplicates removed.
	 * @since 1.4.0
	 */
	private static function drop_duplicate_notifications( $notifications ) {
		$seen = [];
		$kept = [];

		foreach ( $notifications as $notification ) {
			$key = isset( $notification['key'] ) && is_string( $notification['key'] ) ? $notification['key'] : '';

			// Without a resolved key there is nothing safe to compare on, so the
			// row is kept as-is rather than guessed at.
			if ( '' === $key ) {
				$kept[] = $notification;
				continue;
			}

			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = count( $kept );
				$kept[]       = $notification;
				continue;
			}

			// Prefer whichever copy the admin actually edited.
			$existing = $kept[ $seen[ $key ] ];
			if ( self::is_customised( $notification ) && ! self::is_customised( $existing ) ) {
				$kept[ $seen[ $key ] ] = $notification;
			}
		}

		return $kept;
	}

	/**
	 * Whether a stored row differs from the default it came from.
	 *
	 * @param array<string, mixed> $notification Stored notification.
	 * @return bool True when the row carries admin edits.
	 * @since 1.4.0
	 */
	private static function is_customised( $notification ) {
		$key = isset( $notification['key'] ) && is_string( $notification['key'] ) ? $notification['key'] : '';
		if ( '' === $key ) {
			return false;
		}

		// Cached: this runs inside a payment request, twice per duplicate row, and
		// rebuilding the array means ~40 __() calls plus an apply_filters pass
		// each time. The defaults are constant for the life of the request.
		static $defaults = null;
		if ( null === $defaults ) {
			$defaults = Assets::get_instance()->get_default_email_notifications();
		}

		foreach ( $defaults as $default ) {
			if ( ( $default['key'] ?? '' ) !== $key ) {
				continue;
			}
			foreach ( [ 'subject', 'email_body', 'email_to', 'from_name', 'from_email', 'reply_to', 'name' ] as $field ) {
				if ( ( $notification[ $field ] ?? '' ) !== ( $default[ $field ] ?? '' ) ) {
					return true;
				}
			}
			return false;
		}

		return false;
	}

	/**
	 * Add defaults that the stored set does not already cover.
	 *
	 * Notifications cannot be added or deleted in the editor, so a default with
	 * no stored counterpart was never seeded — most often because Pro was
	 * activated after the form was last saved. Without this, those forms send
	 * nothing for the events Pro adds.
	 *
	 * Matching is on the stable key only. Falling back to `id` or `name` here
	 * would reintroduce the very ambiguity the key exists to remove: a locale
	 * difference used to make a present notification look absent (so it was
	 * added twice), while a reassigned id could collide with a different
	 * notification's default and make an absent one look present (so it was
	 * never sent at all).
	 *
	 * @param array<int, array<string, mixed>> $notifications Stored notifications.
	 * @param array<int, array<string, mixed>> $defaults      Default notifications.
	 * @return array<int, array<string, mixed>> Stored notifications plus any missing defaults.
	 * @since 1.4.0
	 */
	private static function add_missing_notifications( $notifications, $defaults ) {
		$stored_keys      = [];
		$unresolved_slots = [];

		foreach ( $notifications as $notification ) {
			if ( ! empty( $notification['key'] ) && is_string( $notification['key'] ) ) {
				$stored_keys[ $notification['key'] ] = true;
				continue;
			}

			// A row we could not identify still occupies its slot. Appending the
			// default that belongs there would leave two enabled rows on one
			// trigger and send the donor two of every email — so the slot is
			// recorded and the default withheld. Matched on the full signature,
			// not just the trigger: the donor and admin templates for an event
			// share a trigger, and withholding both because one row is
			// unidentified would silence a notification that is working.
			if ( ! empty( $notification['status'] ) ) {
				$signature = self::notification_signature( $notification );
				if ( '' !== $signature ) {
					$unresolved_slots[ $signature ] = true;
				}
			}
		}

		foreach ( $defaults as $default ) {
			$key = isset( $default['key'] ) && is_string( $default['key'] ) ? $default['key'] : '';

			if ( '' === $key || isset( $stored_keys[ $key ] ) ) {
				continue;
			}

			$signature = self::notification_signature( $default );
			if ( '' !== $signature && isset( $unresolved_slots[ $signature ] ) ) {
				continue;
			}

			$notifications[] = $default;
		}

		return $notifications;
	}

	/**
	 * Send email using notification settings.
	 *
	 * @param string               $to_email      Recipient email address.
	 * @param array<string, mixed> $notification  Notification settings.
	 * @param array<string, mixed> $donation_data Donation data for smart tags.
	 * @param \WP_Post|null        $campaign      Campaign post object, or null for a standalone form.
	 * @param int                  $donation_id   Optional donation ID.
	 * @param string               $event         The event that triggered this email.
	 * @return bool True if email was sent successfully.
	 * @since 0.0.1
	 */
	private static function send_email( $to_email, $notification, $donation_data, $campaign, $donation_id = 0, $event = '' ) {
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

		/**
		 * Filter attachments for outgoing notification emails.
		 *
		 * Each entry must be an absolute path to a local, readable file
		 * (wp_mail() contract) inside the uploads directory. Non-string,
		 * non-existent and out-of-uploads entries are dropped before sending.
		 *
		 * @param array<int, string>   $attachments   Attachment file paths. Default empty.
		 * @param array<string, mixed> $notification  Notification settings.
		 * @param array<string, mixed> $donation_data Donation data.
		 * @param \WP_Post             $campaign      Campaign post object.
		 * @param int                  $donation_id   Donation ID (0 when not available).
		 * @param string               $event         The event that triggered this email (e.g. 'donation_completed').
		 * @since 1.5.0
		 */
		$attachments = apply_filters( 'suredonation_email_attachments', [], $notification, $donation_data, $campaign, $donation_id, $event );

		$upload_dir  = wp_upload_dir();
		$base_real   = isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] ) ? realpath( $upload_dir['basedir'] ) : false;
		$uploads_dir = is_string( $base_real ) ? trailingslashit( wp_normalize_path( $base_real ) ) : '';

		$attachments = is_array( $attachments ) ? array_values(
			array_filter(
				$attachments,
				static function ( $path ) use ( $uploads_dir ) {
					if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
						return false;
					}

					// Containment check: only files inside the uploads directory
					// may be attached — a filtered-in traversal path or symlink
					// must not exfiltrate arbitrary server files by email.
					$real = realpath( $path );

					if ( ! is_string( $real ) || '' === $uploads_dir ) {
						return false;
					}

					return 0 === strpos( wp_normalize_path( $real ), $uploads_dir );
				}
			)
		) : [];

		// Send email.
		$sent = wp_mail( $to_email, $subject, $email_body, $headers, $attachments );

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
	 * @param \WP_Post|null        $campaign      Campaign post object, or null for a standalone form.
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

		// Prefer the donation's own timestamp. Falling back to "now" dates a
		// receipt to when the email happened to be sent, which is wrong whenever
		// that is not the moment of the donation — a delayed or redelivered
		// gateway webhook, or a recurring charge whose template labels the field
		// as the start date.
		$created_at    = isset( $donation_data['created_at'] ) && is_string( $donation_data['created_at'] ) ? $donation_data['created_at'] : '';
		$created_stamp = '' !== $created_at ? strtotime( $created_at ) : false;
		$donation_date = false !== $created_stamp
			? wp_date( $date_format, $created_stamp )
			: current_time( $date_format );

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
			'{donation_date}'         => esc_html( (string) $donation_date ),
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
		$core_tags = $tags;
		$tags      = apply_filters( 'suredonation_email_smart_tags', $tags, $donation_data, $campaign );

		// Escape anything the filter introduced. Core tags deliberately carry
		// markup, so they are compared by value rather than by key — checking the
		// key alone let a callback overwrite an existing tag and slip raw HTML
		// into every email untouched.
		//
		// A consequence worth knowing: a callback that *appends* to a core tag
		// changes its value, so the result is escaped and any markup or bare `&`
		// the core tag carried is encoded a second time. Allowlisting the
		// markup-carrying tags would avoid that, but it would also reopen the
		// overwrite path above, so the escaping wins and the filter should
		// replace a tag outright rather than concatenate onto it.
		foreach ( $tags as $tag_key => $tag_value ) {
			if ( isset( $core_tags[ $tag_key ] ) && $core_tags[ $tag_key ] === $tag_value ) {
				continue;
			}

			// A non-scalar cannot be rendered; casting one would emit "Array" or
			// fatal on an object, inside a payment webhook.
			if ( ! is_scalar( $tag_value ) ) {
				unset( $tags[ $tag_key ] );
				continue;
			}

			$tags[ $tag_key ] = esc_html( (string) $tag_value );
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
