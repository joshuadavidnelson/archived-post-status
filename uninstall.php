<?php
/**
 * Uninstall Archived Post Status plugin.
 *
 * @link    https://github.com/joshuadavidnelson/archived-post-status
 * @since   0.4.0
 * @package ArchivedPostStatus
 */

// Prevent direct access.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Handle the uninstallation of the plugin for a single site.
 *
 * @since 0.4.0
 */
function aps_uninstall_site() {
	global $wpdb;

	// Delete plugin options.
	delete_option( 'archived_post_status_version' );
	delete_option( 'archived_post_status_previous_version' );

	// Mirrors Settings\Store::OPTION_KEY. The literal is duplicated on purpose:
	// core loads uninstall.php in a separate request without this plugin's
	// PSR-4 loader registered, so Store::delete() would fatal on a missing
	// class. Any change to Store::OPTION_KEY must be mirrored here.
	delete_option( 'aps_settings' );

	// Mirrors CronQueueRunner's per-queue "aps_last_{$queue}" bookkeeping
	// option. Two queues exist as of the 0.5.0 stamp-queue wiring -- sweep
	// and stamp -- each with its own delete_option() line here, alongside
	// its own entry in CronRegistrar::RECURRING_EVENTS -- same
	// duplicate-literal reasoning as the class-constant mirrors throughout
	// this function.
	delete_option( 'aps_last_sweep' );
	delete_option( 'aps_last_stamp' );

	// Mirrors RulesVersion::OPTION_KEY, same duplicate-literal reasoning as
	// the aps_settings option key above.
	delete_option( 'aps_rules_version' );

	// Mirrors CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES,
	// CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES, and
	// CronQueueRunner::CONTINUE_HOOK. Literals duplicated on purpose, same
	// reasoning as the aps_settings option key above.
	wp_clear_scheduled_hook( 'aps_run_scheduled_archives' );
	wp_clear_scheduled_hook( 'aps_apply_auto_archive_rules' );
	wp_clear_scheduled_hook( 'aps_continue_queue' );

	// Mirrors QueueLock's per-queue "aps_queue_lock_{$queue}" transient.
	// delete_transient() removes both the value and its paired timeout row
	// in one call. Two queues exist as of the 0.5.0 stamp-queue wiring,
	// same two-variant reasoning as the aps_last_sweep/aps_last_stamp
	// options above.
	delete_transient( 'aps_queue_lock_sweep' );
	delete_transient( 'aps_queue_lock_stamp' );

	// One query per postmeta namespace rather than loading every affected
	// post into memory. A direct DB call is right here: it runs once during
	// uninstall, and there is no object cache left to invalidate.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_aps_archive_meta_' ) . '%'
		)
	);

	// Mirrors ScheduleMeta's five _aps_schedule_meta_* keys (time, source,
	// user, rule_version, attempts) -- one LIKE DELETE covers the whole
	// family, same shape as the archive-meta DELETE above.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_aps_schedule_meta_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

if ( is_multisite() ) {

	$network_id = get_current_network_id();
	$count      = get_blog_count( $network_id );

	// Intentional 5000-site cap, with no `else` arm. Iterating a network that
	// large inside one PHP request is unsafe: switch_to_blog() re-bootstraps
	// the option cache every iteration, and the postmeta DELETE is a
	// non-transactional cross-site loop that leaves the network half-cleaned
	// if it fatals partway. Operators at that scale clean up out of band.
	if ( $count < 5000 ) {
		$sites = get_sites( array( 'number' => $count ) );

		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			aps_uninstall_site();
			restore_current_blog();
		}
	}
} else {
	aps_uninstall_site();
}
