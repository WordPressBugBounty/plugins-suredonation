<?php
/**
 * SureDonation Uninstall
 *
 * Fired when the plugin is deleted (not deactivated), and only when the site
 * owner has opted in from the deactivation survey, which offers the choice at
 * the moment the plugin is being switched off. Default is off, so deleting the
 * plugin changes no data unless that box was ticked first.
 *
 * Scope is deliberately limited to *settings*: options, site options,
 * transients and scheduled events. The donation ledger is never touched.
 * Charities are generally required to retain donation records for years
 * (HMRC, for example, requires Gift Aid records be kept for six years after
 * the accounting period, and enduring declarations indefinitely), so nothing
 * here drops a table, deletes a donation, a donor, a campaign, a form, a post
 * meta row or a generated receipt PDF. Those survive by design.
 *
 * One exception, and only once Pro is gone for good: the rows of
 * `{prefix}suredonation_integrations`, which hold encrypted third-party API
 * credentials rather than any record. The table itself is left in place.
 *
 * Runs with the plugin NOT loaded - WordPress includes this file standalone
 * (see uninstall_plugin() in wp-admin/includes/plugin.php), so no SureDonation
 * class, constant or autoloader is available here. Everything is plain core
 * API plus $wpdb.
 *
 * Multisite: deleting through the admin UI is a network-admin action, so this
 * file runs once against the main site's options table and sub-site settings
 * are left in place. There is no per-site loop in core and none is added here.
 * `wp plugin uninstall --url=<subsite>` is the exception: WP-CLI runs it in
 * that sub-site's context, so it cleans that sub-site instead.
 *
 * @package SureDonation
 * @since 1.7.0
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Respect the opt-in, before anything at all happens.
 *
 * With the toggle off this file is a complete no-op: not a row, not a cron
 * entry, nothing. Deleting the plugin leaves the site exactly as it was found.
 * Nothing may move above this gate.
 *
 * The check is `true !==` rather than `! empty()` on purpose. Under empty(),
 * a stray string such as "false" or "no" - which the REST route cannot write,
 * but a hand-edited row or an options editor can - would read as consent and
 * arm an irreversible sweep. Anything that is not boolean true fails closed.
 *
 * The plugin is not loaded, so read the consolidated settings row directly
 * rather than through Helper::get_suredonation_option().
 */
$suredonation_options = get_option( 'suredonation_options', [] );

if ( ! is_array( $suredonation_options ) || true !== ( $suredonation_options['remove_settings_on_uninstall'] ?? false ) ) {
	return;
}

global $wpdb;

/*
 * The debug log.
 *
 * Taken before the option sweep below, because the file is named after a hash
 * stored in `suredonation_debug_log_file` - sweep that first and nothing can
 * ever name the file again. It would then sit in uploads indefinitely, holding
 * donation diagnostics on a site that no longer has the plugin that wrote them,
 * with no screen left that could delete it.
 *
 * Only this directory, and only the files this plugin puts in it: PDF receipts
 * live beside it under `suredonation/` and are a site's own records, not
 * plugin state.
 *
 * Best effort. An uploads directory that cannot be read is not a reason to
 * abandon the rest of the uninstall.
 */
$suredonation_uploads = wp_upload_dir();

if ( empty( $suredonation_uploads['error'] ) && ! empty( $suredonation_uploads['basedir'] ) ) {
	$suredonation_log_dir = trailingslashit( (string) $suredonation_uploads['basedir'] ) . 'suredonation/logs/';

	if ( is_dir( $suredonation_log_dir ) ) {
		$suredonation_log_files = glob( $suredonation_log_dir . '*' );

		foreach ( is_array( $suredonation_log_files ) ? $suredonation_log_files : [] as $suredonation_log_file ) {
			if ( is_file( $suredonation_log_file ) ) {
				wp_delete_file( $suredonation_log_file );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Uninstall runs without the plugin loaded and WP_Filesystem can prompt for credentials; the directory is this plugin's own, is only removed once it is empty, and a directory something else has written to is meant to stay.
		@rmdir( $suredonation_log_dir );
	}
}

/*
 * Scheduled events.
 *
 * Deactivation already clears these (see plugin-loader.php), and core refuses
 * to delete an active plugin at all (wp-admin/plugins.php filters the list
 * through is_plugin_inactive), so by the time this runs the admin has already
 * deactivated and the hooks are normally gone. This covers the case where that
 * hook never ran and an entry still points at a callback that no longer exists.
 * Note wp_clear_scheduled_hook() only ever clears the current site's cron.
 */
wp_clear_scheduled_hook( 'suredonation_sync_stripe_webhook_events' );
wp_clear_scheduled_hook( 'suredonation_send_email_report' );
wp_clear_scheduled_hook( 'suredonation_register_paypal_relay_routing' );
wp_clear_scheduled_hook( 'suredonation_debug_log_expiry' );

/*
 * Options and transients.
 *
 * Matching by prefix rather than by an explicit list. Live installs carry keys
 * no longer referenced anywhere in the codebase - `suredonation_settings`,
 * `suredonations_payment_settings`, `suredonations_email_notifications` and
 * `__suredonations_do_redirect` are all leftovers from earlier versions - plus
 * unbounded per-entity keys such as the `_transient_suredonation_email_sent_{id}`
 * family. A hand-maintained list would miss every one of those and would go
 * stale again with the next feature.
 *
 * It is not exhaustive, deliberately. A few keys sit outside the prefix
 * entirely - `sd_pp_relay_{md5}` (inc/payments/pay-pal/pay-pal-webhook-listener.php)
 * and the `sd-review-donation` family of admin-notice transients - and are left
 * alone. All are short-lived, so they expire on their own once the plugin that
 * reads them is gone.
 *
 * $wpdb->esc_like() is load-bearing: `_` is a single-character wildcard in
 * LIKE, and several of these keys lead with underscores.
 */
$suredonation_like_prefixes = [
	// Plugin options, including the legacy plural spelling.
	'suredonation_',
	'suredonations_',
	// Redirect flags, current and legacy, which lead with two underscores.
	'__suredonation_',
	'__suredonations_',
	// Transients and their timeout rows, in both single-site and network form.
	'_transient_suredonation_',
	'_transient_timeout_suredonation_',
	'_site_transient_suredonation_',
	'_site_transient_timeout_suredonation_',
];

/*
 * Keys the sweep must not take.
 *
 * Pro owns data that sits under the *free* prefix, and Pro may still be
 * installed and running when the free plugin is deleted:
 *
 *  - Its options and licence row are `suredonation_pro_*` / `suredonationpro_*`.
 *    Note `__suredonation_pro_do_redirect` leads with two underscores, so it is
 *    the `__suredonation_pro_` entry below that protects it, not the plain one.
 *  - Its donor-dashboard sessions, magic links, email-change tokens and
 *    account-link tokens are stored as transients under the plain
 *    `suredonation_` prefix. Taking those would log out every donor
 *    mid-session, invalidate live magic links, dead-end an in-flight account
 *    link and reset its anti-enumeration throttle. Any new transient prefix
 *    added to Pro's donor-dashboard must be mirrored here and in Pro's sweep.
 *  - `suredonation_email_claim_{event}_{donation_id}` is Pro's once-per-event
 *    guard against sending a subscription email twice. It is written with a
 *    raw INSERT IGNORE in Pro's inc/recurring/subscription-emails.php, so it
 *    does not show up in a search for update_option(). Removing these while
 *    Pro is live would let a replayed webhook re-send a "subscription
 *    cancelled" or "renewed" email to a real donor.
 *
 * Pro removes most of it from its own uninstall.php. The exception is
 * `suredonation_email_claim_*`, which Pro also keeps for the reason above, so
 * those rows outlive both plugins by design. They are bounded by the number of
 * subscription events a site has actually sent.
 *
 * Also preserved: `suredonation_options` itself, handled separately below.
 */
$suredonation_keep_like_prefixes = [
	'suredonation_pro_',
	'suredonations_pro_',
	'suredonationpro_',
	'__suredonation_pro_',
	'__suredonations_pro_',
	'_transient_suredonation_pro_',
	'_transient_timeout_suredonation_pro_',
	'_site_transient_suredonation_pro_',
	'_site_transient_timeout_suredonation_pro_',
	// Pro's donor-dashboard session state, stored under the free prefix. The
	// `_magic_`, `_email_change_` and `_acct_link_` entries also cover their
	// `_throttle_` variants, which extend the same prefixes.
	'_transient_suredonation_magic_',
	'_transient_timeout_suredonation_magic_',
	'_transient_suredonation_donor_sess_',
	'_transient_timeout_suredonation_donor_sess_',
	'_transient_suredonation_email_change_',
	'_transient_timeout_suredonation_email_change_',
	'_transient_suredonation_acct_link_',
	'_transient_timeout_suredonation_acct_link_',
	// Pro's subscription-email idempotency guards (see the note above).
	'suredonation_email_claim_',
];

$suredonation_where  = [];
$suredonation_params = [];

foreach ( $suredonation_like_prefixes as $suredonation_prefix ) {
	$suredonation_where[]  = 'option_name LIKE %s';
	$suredonation_params[] = $wpdb->esc_like( $suredonation_prefix ) . '%';
}

$suredonation_sql = '( ' . implode( ' OR ', $suredonation_where ) . ' )';

foreach ( $suredonation_keep_like_prefixes as $suredonation_prefix ) {
	$suredonation_sql     .= ' AND option_name NOT LIKE %s';
	$suredonation_params[] = $wpdb->esc_like( $suredonation_prefix ) . '%';
}

/*
 * The consolidated settings row is rewritten rather than deleted, so exclude
 * it from the sweep. See the block after the delete loop for why.
 */
$suredonation_sql     .= ' AND option_name != %s';
$suredonation_params[] = 'suredonation_options';

/*
 * Delete in bounded batches.
 *
 * The per-entity families named above are unbounded, so a site with a long
 * history can hold a great many matching rows. Loading them all at once risks
 * exhausting memory inside uninstall_plugin(), and a fatal there aborts
 * delete_plugins() before the files are removed - leaving the admin with a
 * deletion that fails again on every retry. Batching keeps both the result set
 * and the cache-eviction loop bounded.
 *
 * Names are read before each delete because a non-autoloaded option is cached
 * under its own key in the `options` group, so the names have to be known to
 * evict them.
 */
do {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup of rows that are about to be deleted; caching the read would be pointless.
	$suredonation_batch = $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $suredonation_sql is built only from literal fragments above; every value is a bound %s placeholder, but the sniff cannot see placeholders inside an interpolated variable.
			"SELECT option_name FROM {$wpdb->options} WHERE {$suredonation_sql} LIMIT 500",
			$suredonation_params
		)
	);

	$suredonation_batch_count = is_array( $suredonation_batch ) ? count( $suredonation_batch ) : 0;

	if ( 0 === $suredonation_batch_count ) {
		break;
	}

	$suredonation_placeholders = implode( ', ', array_fill( 0, $suredonation_batch_count, '%s' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup; the cache is evicted immediately below.
	$suredonation_removed = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $suredonation_placeholders is a generated list of %s placeholders; every name is bound.
			"DELETE FROM {$wpdb->options} WHERE option_name IN ( {$suredonation_placeholders} )",
			$suredonation_batch
		)
	);

	foreach ( $suredonation_batch as $suredonation_deleted_name ) {
		wp_cache_delete( $suredonation_deleted_name, 'options' );
	}

	/*
	 * Evict the shared option-group entries on every pass, not once at the end.
	 * Autoloaded options live in the single `alloptions` blob, so if the loop
	 * stops early the blob must not still be serving rows that have already
	 * gone from the table.
	 *
	 * Transients are a known gap here: under a persistent object cache
	 * (Redis, Memcached) set_transient() writes to the `transient` cache group
	 * and creates no wp_options row at all, so those are neither matched above
	 * nor evicted here. They are TTL-bounded, and the plugin that reads them is
	 * being removed, so they expire harmlessly.
	 */
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	/*
	 * Stop if the delete removed nothing. Without this a failing query would
	 * re-select the same rows on every pass and spin forever inside
	 * uninstall_plugin().
	 */
	if ( ! $suredonation_removed ) {
		break;
	}
} while ( 500 === $suredonation_batch_count );

/*
 * The consolidated settings row: rewritten, not deleted.
 *
 * `database_table_versions` inside it is the schema bookkeeping for the custom
 * tables - and those tables are deliberately preserved by this uninstall, as
 * are Pro's, which record their versions in the same map. Deleting the row
 * would reset every table's recorded version to 0 while the populated tables
 * remain, so a later reinstall would re-enter the upgrade path against real
 * data. Today's migrations are individually idempotent, but a future one gated
 * on a version range would silently skip records it was written to fix.
 *
 * `privacy_settings` is kept for the same reason. It holds
 * `minimum_data_retention_period`, which is what stops a GDPR erase request
 * anonymising donations that are still inside their retention window
 * (Privacy_Settings::is_donation_erasable). Its default is 'none', which means
 * "always erasable" - so dropping the row would quietly convert a charity's
 * six-year Gift Aid retention into no protection at all, against the very
 * records this uninstall preserves.
 *
 * `usage_events_pushed` is the third. It is the permanent, once-per-site
 * dedupe list for one-time analytics events (see the BSF analytics events
 * library), and those milestones - `first_donation_received` and friends - are
 * derived from the donation rows this uninstall preserves. Drop the list and a
 * reinstall re-fires every one-time event against the same surviving records,
 * double-counting the activation funnel. `usage_events_pending` is a queue
 * rather than a guard, so it goes with everything else.
 *
 * The opt-in itself is NOT carried forward. Re-persisting the live toggle
 * would mean a reinstall silently inherits a previous operator's consent: a
 * new administrator could connect live gateway credentials and lose them on a
 * deletion they never agreed to. A reinstall starts opted out, and consent has
 * to be given again.
 *
 * Pro still needs to know this site consented, because it may be deleted after
 * the free plugin and would otherwise have no signal to read. So instead of the
 * toggle, drop a one-shot breadcrumb that Pro consumes and removes. It is
 * written only when Pro is actually installed, so it never lingers on a site
 * that never had it, and this plugin clears it again on activation - so a
 * breadcrumb can only outlive the decision for as long as free stays gone.
 */
$suredonation_preserved = [];

/*
 * Is Pro still on this site?
 *
 * Biased towards "yes", because the two wrong answers are not equally bad: a
 * false "absent" clears a live Pro's credentials, while a false "present" only
 * leaves rows behind for Pro's own uninstall to take later.
 *
 * Three probes, cheapest first. The constant is the codebase's own idiom (see
 * inc/helper.php, inc/payments/payment-helper.php) and is the reliable one:
 * core calls uninstall_plugin() from an ordinary admin request, so every OTHER
 * active plugin is loaded and has defined its constants. The path check then
 * catches an installed-but-deactivated Pro in the usual directory, and the
 * get_plugins() scan catches one whose directory was renamed - a versioned zip,
 * a staging sync, or the standard trick of renaming a folder to force a
 * deactivation after a fatal.
 */
$suredonation_pro_present = defined( 'SUREDONATION_PRO_VER' )
	|| file_exists( WP_PLUGIN_DIR . '/suredonation-pro/suredonation-pro.php' );

if ( ! $suredonation_pro_present && function_exists( 'get_plugins' ) ) {
	foreach ( array_keys( get_plugins() ) as $suredonation_plugin_file ) {
		if ( 'suredonation-pro.php' === basename( $suredonation_plugin_file ) ) {
			$suredonation_pro_present = true;
			break;
		}
	}
}

if ( $suredonation_pro_present ) {
	$suredonation_preserved['uninstall_consent_at'] = time();
}

foreach ( [ 'database_table_versions', 'privacy_settings', 'usage_events_pushed' ] as $suredonation_keep_key ) {
	if ( isset( $suredonation_options[ $suredonation_keep_key ] ) ) {
		$suredonation_preserved[ $suredonation_keep_key ] = $suredonation_options[ $suredonation_keep_key ];
	}
}

if ( [] === $suredonation_preserved ) {
	// Nothing worth keeping: no schema map, no retention window, no analytics
	// dedupe list and no Pro waiting on the breadcrumb. Take the row with
	// everything else rather than leaving an empty one behind.
	delete_option( 'suredonation_options' );
} else {
	update_option( 'suredonation_options', $suredonation_preserved );
}

/*
 * Network-scoped options.
 *
 * On single site these live in wp_options and the sweep above has already
 * taken them. On multisite they live in wp_sitemeta, which the sweep does not
 * touch, so remove them explicitly.
 *
 * bsf_analytics_* and bsf_usage_* are deliberately absent: those are shared by
 * every Brainstorm Force product on the site and are not ours to delete.
 */
delete_site_option( 'suredonation_usage_optin' );
delete_site_option( 'suredonation_usage_installed_time' );

/*
 * Pro's integration credentials, but only once Pro itself is gone.
 *
 * They live in `{prefix}suredonation_integrations`, one row per connected
 * service, with the credential set encrypted into the `data` column. Pro
 * removes them itself; this is the fallback for a Pro that was deleted while
 * the site was opted out, whose uninstall ran as a no-op and whose code is now
 * gone - nothing else can ever reach these rows again, and they are encrypted
 * only against a wp-config.php secret that survives. Clearing credentials is
 * what this opt-in is for.
 *
 * Rows only, never the table, and never the workflow meta: that post meta is a
 * form's integration list, not a credential, and form content survives.
 *
 * Pro's option rows are deliberately left alone. Sweeping them would mean
 * duplicating Pro's keep-list here (licence rows, the receipts-reissued flag)
 * and guessing at its internals as they change; those rows are inert.
 */
if ( ! $suredonation_pro_present ) {
	$suredonation_integrations_table = $wpdb->prefix . 'suredonation_integrations';

	// strcasecmp, not ===: SHOW TABLES matches case-insensitively and a
	// lower_case_table_names=1 server stores the name folded, while core allows
	// a mixed-case $table_prefix. A strict compare would skip the clear and
	// silently leave the credentials behind.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall runs without the plugin loaded; there is no API to call and nothing to cache.
	if ( 0 === strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $suredonation_integrations_table ) ) ), $suredonation_integrations_table ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall runs without the plugin loaded; there is no API to call and nothing to cache.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $suredonation_integrations_table ) );
	}
}
