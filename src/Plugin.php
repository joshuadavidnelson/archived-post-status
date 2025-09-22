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
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

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

	protected $features = array(
		'AdminNotices',
		'ArchivedTitle',
		'BulkEdit',
		'CLI',
		'PostEditor',
		'RowActions',
		'SavePost',
	);

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
		$this->define_hooks();

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
		 * Abstract class for features.
		 */
		require_once $dir . '/Feature.php';

		// Load the plugin feature classes.
		foreach ( $this->features as $feature ) {
			$filepath = $dir . '/' . $feature . '.php';
			if ( file_exists( $filepath ) ) {
				require_once $filepath;
			}
		}
	}

	/**
	 * Load languages.
	 *
	 * @since 0.4.0
	 * @action plugins_loaded
	 */
	public function set_locale() {
		load_plugin_textdomain( 'archived-post-status', false, ARCHIVED_POST_STATUS_LANG_PATH );
	}

	/**
	 * Hooks!
	 *
	 * @since 0.4.0
	 */
	public function define_hooks() {

		// Add the archive post status.
		add_action( 'init', 'aps_register_archive_post_status' );

		// Add the archive post status to the post state in the admin table view.
		add_filter( 'display_post_states', 'aps_display_post_states', 10, 2 );

		// Prevent Archived content from being edited.
		add_action( 'load-post.php', 'aps_load_post_screen' );

		// Clear the page settings on archive.
		add_action( 'aps_archive_post', '_aps_reset_page_settings' );

		// Register the custom query vars.
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Disable post edit links on archived content in the edit screen.
		add_action( 'admin_enqueue_scripts', array( $this, 'edit_screen_js' ) );

		// Add the plugin screen js.
		add_action( 'admin_enqueue_scripts', array( $this, 'plugin_screen_js' ) );

		// Add plugin features.
		foreach ( $this->features as $feature ) {
			$class   = __NAMESPACE__ . '\\' . $feature;
			$feature = new $class();
			$feature->init();
		}
	}

	/**
	 * Enqueue the edit screen javascript.
	 *
	 * @since 0.4.0
	 * @action admin_enqueue_scripts
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function edit_screen_js( $hook ) {

		global $typenow;
		if ( ! aps_is_supported_post_type( $typenow )
			|| ! aps_is_read_only()
			|| 'edit.php' !== $hook ) {
				return;
		}

		wp_enqueue_script(
			'aps-edit-screen',
			ARCHIVED_POST_STATUS_URL . 'assets/js/edit-screen.js',
			array(),
			ARCHIVED_POST_STATUS_VERSION
		);
	}

	/**
	 * Confirm deactivation of the plugin.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function plugin_screen_js( $hook ) {

		if ( 'plugins.php' != $hook ) {
			return;
		}

		// Enqueue the script.
		wp_enqueue_script(
			'aps-plugin-screen',
			ARCHIVED_POST_STATUS_URL . 'assets/js/plugin-screen.js',
			array( 'jquery' ),
			ARCHIVED_POST_STATUS_VERSION
		);

		// Set the script translations.
		wp_set_script_translations(
			'aps-plugin-screen',
			'archived-post-status',
			plugin_dir_path( __FILE__ ) . '/languages/'
		);

		// Localize the script.
		wp_localize_script(
			'aps-plugin-screen',
			'archivedPostStatus',
			array(
				'hasArchivedPosts' => $this->has_archived_posts(),
			)
		);

	}

	/**
	 * Check if there are any Archived posts.
	 *
	 * @since 0.4.0
	 * @return int
	 */
	private function has_archived_posts() {

		// Check if the query results are already cached
		$has_archived_posts = wp_cache_get( 'has_archived_posts', 'archived-post-status' );
		if ( false === $has_archived_posts ) {

			$args = array(
				'post_status'            => 'archive',
				'post_type'              => \aps_get_supported_post_types(),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'nopaging'               => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			);

			// Query the database if the results are not cached
			$query = new \WP_Query( $args );

			$count = $query->found_posts;
			$has_archived_posts = $count > 0;

			// Cache the query results for future use
			wp_cache_set( 'has_archived_posts', $has_archived_posts, 'archived-post-status', 60 * 60 );

		}

		return $has_archived_posts;
	}

	/**
	 * Add the custom query vars.
	 *
	 * @since 0.4.0
	 * @filter query_vars
	 * @param  array $vars
	 * @return array
	 */
	public function query_vars( $vars ) {

		$vars[] = 'archived';
		$vars[] = 'unarchived';
		$vars[] = 'ids';

		return $vars;
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
