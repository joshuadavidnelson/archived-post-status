<?php
/**
 * The file that defines the core plugin functions
 *
 * @link    https://github.com/joshuadavidnelson/archived-post-status
 * @since   0.4.0
 * @package ArchivedPostStatus
 * @author  Joshua David Nelson <josh@joshuadnelson.com>, fjarrett
 * @license GPL-2.0+
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since 0.4.0
 */
class Plugin {

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since 0.4.0
	 * @access protected
	 * @var string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since 0.4.0
	 * @access protected
	 * @var string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since 0.4.0
	 * @access public
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since 0.4.0
	 * @access public
	 */
	public function run() {

		/**
		 * Fires when the plugin is initialized, before anything is loaded.
		 *
		 * @since 0.4.0
		 */
		do_action( 'aps_init' );

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

		/**
		 * Fires when the plugin is loaded, after all other plugins have been loaded.
		 *
		 * @since 0.4.0
		 */
		do_action( 'aps_loaded' );
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since 0.4.0
	 * @access private
	 */
	private function load_dependencies() {

		// Includes directory.
		$dir = plugin_dir_path( __DIR__ ) . 'src';

		/**
		 * File with common functions.
		 */
		require_once $dir . '/functions.php';

		/**
		 * The base class for all features.
		 */
		require_once $dir . '/Feature.php';
	}

	/**
	 * Load languages.
	 *
	 * @since 0.4.0
	 * @action plugins_loaded
	 */
	function set_locale() {
		load_plugin_textdomain( 'archived-post-status', false, ARCHIVED_POST_STATUS_LANG_PATH );
	}

	/**
	 * Hook into the admin.
	 *
	 * @since 0.4.0
	 */
	public function define_admin_hooks() {

		// Add the archive post status.
		add_action( 'init', 'aps_register_archive_post_status' );

		// Archive the post on save.
		add_action( 'save_post', 'aps_save_post', 10, 3 );

		// Add the archive post status to the post state in the admin table view.
		add_filter( 'display_post_states', 'aps_display_post_states', 10, 2 );

		// Prevent Archived content from being edited.
		add_action( 'load-post.php', 'aps_load_post_screen' );

		// Modify the DOM on edit screens.
		add_action( 'admin_footer-edit.php', 'aps_edit_screen_js' );
		add_action( 'admin_footer-post.php', 'aps_post_screen_js' );

		//add a column for the archive date
		add_filter( 'page_row_actions', 'aps_post_row_actions', 10, 2 );
		add_filter( 'post_row_actions', 'aps_post_row_actions', 10, 2 );
	}

	/**
	 * Hook into the public side of the site.
	 *
	 * @since 0.4.0
	 */
	public function define_public_hooks() {

		// Add the label to the title.
		add_filter( 'the_title', 'aps_the_title', 10, 2 );

		// Exclude archived posts from search results.
		add_filter( 'aps_status_arg_exclude_from_search', 'aps_is_frontend' );
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since 0.4.0
	 * @access public
	 * @return string The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since 0.4.0
	 * @access public
	 * @return string The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
