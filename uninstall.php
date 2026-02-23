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

// Delete plugin options.
	delete_option( 'archived_post_status_version' );
	delete_option( 'archived_post_status_previous_version' );
}

// Handle multisite vs single site cleanup.
if ( is_multisite() ) {

	// Only process smaller networks to avoid performance issues.
	$network_id = get_current_network_id();
	$count      = get_blog_count( $network_id );

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
