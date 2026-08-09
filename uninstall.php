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

	// One query rather than loading every archived post into memory. A direct
	// DB call is right here: it runs once during uninstall, and there is no
	// object cache left to invalidate.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_aps_archive_meta_' ) . '%'
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
