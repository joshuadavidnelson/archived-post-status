<?php
/**
 * The plugin bootstrap file.
 *
 * Defines the plugin's constants, registers the autoloader, and starts the
 * plugin on plugins_loaded.
 *
 * There is still no activation hook: nothing needs provisioning when the
 * plugin turns on. `Schedule\CronRegistrar` schedules any of its recurring
 * events it finds missing itself, on the next `init` -- the same
 * self-repair path a lost event takes after a bad deactivate or a host that
 * flushed the cron option, so activation has nothing to do that init
 * wouldn't already do on its own.
 *
 * There IS now a deactivation hook, because 0.5.0 added cron: it clears
 * every recurring event `CronRegistrar` schedules (the sweep and the
 * auto-archive stamp) and any pending `aps_continue_queue` continuation, so
 * a deactivated plugin leaves no orphaned `wp_next_scheduled()` entries
 * behind. Data removal otherwise still lives in uninstall.php.
 *
 * @link    https://github.com/joshuadavidnelson/archived-post-status
 * @since   0.4.0
 * @package ArchivedPostStatus
 *
 * @wordpress-plugin
 * Plugin Name: Archived Post Status
 * Description: Allows posts and pages to be archived so you can unpublish content without having to trash it.
 * Version:     0.4.0
 * Plugin URI:  https://archivedpoststat.us/
 * Requires at least: 5.9
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * Author:      Joshua David Nelson
 * Author URI:  https://joshuadnelson.com
 * Text Domain: archived-post-status
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

define( 'ARCHIVED_POST_STATUS_VERSION', '0.4.0' );
define( 'ARCHIVED_POST_STATUS_PLUGIN', plugin_basename( __FILE__ ) );
define( 'ARCHIVED_POST_STATUS_DIR', __DIR__ );
define( 'ARCHIVED_POST_STATUS_URL', plugins_url( '/', __FILE__ ) );
// No language-path constant: translations load just in time from the
// Domain Path header, so nothing needs to name that directory in PHP.

// PSR-4 autoloading without shipping Composer's autoloader to WordPress.org.
require_once ARCHIVED_POST_STATUS_DIR . '/src/Loader.php';
ArchivedPostStatus\Loader::init();

/**
 * Begins execution of the plugin.
 *
 * @since 0.4.0
 */
function aps_run_plugin() {
	$plugin = new ArchivedPostStatus\Plugin( ARCHIVED_POST_STATUS_VERSION );
	$plugin->run();
}
add_action( 'plugins_loaded', 'aps_run_plugin', 10, 0 );

/**
 * Clear every cron event this plugin schedules.
 *
 * Runs on deactivation, not uninstall: a site reactivating the plugin later
 * gets its schedules back for free from `CronRegistrar`'s own `init`
 * self-repair, without needing an activation hook to put them back.
 *
 * @since 0.5.0
 * @return void
 */
function aps_clear_scheduled_events() {
	foreach ( ArchivedPostStatus\Schedule\CronRegistrar::recurring_hooks() as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	wp_clear_scheduled_hook( ArchivedPostStatus\Schedule\Queue\CronQueueRunner::CONTINUE_HOOK );
}
register_deactivation_hook( __FILE__, 'aps_clear_scheduled_events' );
