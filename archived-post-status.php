<?php
/**
 * The plugin bootstrap file.
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link    https://github.com/joshuadavidnelson/archived-post-status
 * @since   0.3.9
 * @package ArchivedPostStatus
 *
 * @wordpress-plugin
 * Plugin Name: Archived Post Status
 * Description: Allows posts and pages to be archived so you can unpublish content without having to trash it.
 * Version:     0.3.9.1
 * Author:      Joshua David Nelson
 * Author URI:  https://joshuadnelson.com
 * Text Domain: archived-post-status
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Define plugin constants.
 */
define( 'ARCHIVED_POST_STATUS_VERSION', '0.3.9.1' );
define( 'ARCHIVED_POST_STATUS_PLUGIN', plugin_basename( __FILE__ ) );
define( 'ARCHIVED_POST_STATUS_DIR', __DIR__ );
define( 'ARCHIVED_POST_STATUS_URL', plugins_url( '/', __FILE__ ) );
define( 'ARCHIVED_POST_STATUS_LANG_PATH', dirname( ARCHIVED_POST_STATUS_PLUGIN ) . '/languages' );

/**
 * The code that runs during plugin activation.
 * This action is documented in src/Activator.php
 */
function activate_archived_post_status() {
	require_once plugin_dir_path( __FILE__ ) . 'src/Activator.php';
	ArchivedPostStatus\Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in src/Deactivator.php
 */
function deactivate_archived_post_status() {
	require_once plugin_dir_path( __FILE__ ) . 'src/Deactivator.php';
	ArchivedPostStatus\Deactivator::deactivate();
}
register_activation_hook( __FILE__, 'activate_archived_post_status' );
register_deactivation_hook( __FILE__, 'deactivate_archived_post_status' );

/**
 * The core plugin class that is used to define everything.
 */
require ARCHIVED_POST_STATUS_DIR . '/src/Plugin.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since 0.3.9
 */
function run_archived_post_status() {
	$plugin = new ArchivedPostStatus\Plugin( ARCHIVED_POST_STATUS_PLUGIN, ARCHIVED_POST_STATUS_VERSION );
	$plugin->run();
}
add_action( 'plugins_loaded', 'run_archived_post_status', 10, 0 );

