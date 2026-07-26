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

	// Delete the settings option introduced in 0.4.0.
	//
	// Source of truth: ArchivedPostStatus\Settings\Store::OPTION_KEY (src/Settings/Store.php).
	//
	// Direct delete_option() bypass — Store::delete() is intentionally NOT used
	// here. The uninstall hook fires from a WP-managed loader that does NOT load
	// this plugin's namespaces (uninstall.php is loaded by core in a separate
	// PHP request without our PSR-4 loader registered), so calling
	// Store::delete() would fatal on a missing class. The string literal is
	// duplicated here as a deliberate, narrow bypass — any change to
	// Store::OPTION_KEY must be mirrored on this line. Pinned by
	// `tests/php/UninstallTest.php::test_uninstall_deletes_aps_settings_option`.
	delete_option( 'aps_settings' );

	// Delete all archive metadata post meta written by ArchiveMeta in 0.4.0
	// (keys of the form `_aps_archive_meta_*`). Done in a single query to avoid
	// loading every archived post into memory on large sites. Direct DB call is
	// the right primitive here: this runs once during uninstall, and there is
	// no object cache to invalidate (the plugin is going away).
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_aps_archive_meta_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Handle multisite vs single site cleanup.
if ( is_multisite() ) {

	// Only process smaller networks to avoid performance issues.
	$network_id = get_current_network_id();
	$count      = get_blog_count( $network_id );

	// Intentional 5000-site cap. Iterating every site in a network at
	// or above this scale is unsafe to run inside a single PHP request:
	//   - switch_to_blog() re-bootstraps the site's option cache on
	//     every iteration, blowing up memory and exceeding max_execution_time
	//   - the postmeta DELETE inside aps_uninstall_site() is a non-
	//     transactional cross-site loop; a fatal partway through leaves
	//     the network in a half-cleaned state with no checkpoint.
	// Operators of networks at this scale are expected to clean up out
	// of band — typically `wp site list | wp aps cleanup --network`
	// from WP-CLI, or a direct SQL pass after the plugin is deleted.
	// This branch having no `else` arm is the design.
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
