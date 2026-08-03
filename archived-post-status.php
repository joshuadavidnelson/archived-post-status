<?php
/**
 * The plugin bootstrap file.
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. It defines the plugin's constants, registers the autoloader, and
 * starts the plugin on plugins_loaded.
 *
 * There are no activation or deactivation hooks: the plugin registers a post
 * *status*, never a post type or a rewrite rule, so there is nothing to set up
 * on activation or tear down on deactivation. Data removal lives in
 * uninstall.php, which is where WordPress expects it.
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

/**
 * Define plugin constants.
 */
define( 'ARCHIVED_POST_STATUS_VERSION', '0.4.0' );
define( 'ARCHIVED_POST_STATUS_PLUGIN', plugin_basename( __FILE__ ) );
define( 'ARCHIVED_POST_STATUS_DIR', __DIR__ );
define( 'ARCHIVED_POST_STATUS_URL', plugins_url( '/', __FILE__ ) );
// No language-path constant: translations load just in time from the
// Domain Path header, so nothing needs to name that directory in PHP.

/**
 * Initialize the class loader and run the plugin.
 *
 * The loader handles PSR-4 style autoloading without requiring Composer's
 * autoloader to be shipped to WordPress.org.
 */
require_once ARCHIVED_POST_STATUS_DIR . '/src/Loader.php';
ArchivedPostStatus\Loader::init();

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since 0.4.0
 */
function aps_run_plugin() {
	$plugin = new ArchivedPostStatus\Plugin( ARCHIVED_POST_STATUS_VERSION );
	$plugin->run();
}
add_action( 'plugins_loaded', 'aps_run_plugin', 10, 0 );
