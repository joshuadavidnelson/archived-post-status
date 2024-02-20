<?php
/**
 * Fired during plugin activation
 *
 * @link       https://github.com/joshuadavidnelson/archived-post-status
 * @since      0.3.9
 * @package    ArchivedPostStatus
 * @subpackage Activator
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since 0.3.9
 */
class Activator {

	/**
	 * The $_REQUEST during plugin activation.
	 *
	 * @since  0.3.9
	 * @access private
	 * @var    array  $request The $_REQUEST array during plugin activation.
	 */
	private static $request = array();

	/**
	 * The $_REQUEST['plugin'] during plugin activation.
	 *
	 * @since  0.3.9
	 * @access private
	 * @var    string $plugin The $_REQUEST['plugin'] value during plugin activation.
	 */
	private static $plugin = 'archived-post-status';

	/**
	 * Activate the plugin.
	 *
	 * Checks if the plugin was (safely) activated.
	 * Place to add any custom action during plugin activation.
	 *
	 * @since 0.3.9
	 */
	public static function activate() {

		if ( false === self::get_request()
			|| false === self::validate_request( self::$plugin )
			|| false === self::check_caps()
		) {
			if ( isset( $_REQUEST['plugin'] ) ) {
				if ( ! check_admin_referer( 'activate-plugin_' . self::$request['plugin'] ) ) {
					exit;
				}
			} elseif ( isset( $_REQUEST['checked'] ) ) {
				if ( ! check_admin_referer( 'bulk-plugins' ) ) {
					exit;
				}
			}
		}

		/**
		 * The plugin is now safely activated.
		 */
		flush_rewrite_rules();
	}

	/**
	 * Get the request.
	 *
	 * Gets the $_REQUEST array and checks if necessary keys are set.
	 * Populates self::request with necessary and sanitized values.
	 *
	 * @since  0.3.9
	 * @return bool|array false or self::$request array.
	 */
	private static function get_request() {

		if ( ! empty( $_REQUEST )
			&& isset( $_REQUEST['_wpnonce'] )
			&& isset( $_REQUEST['action'] )
		) {
			if ( isset( $_REQUEST['plugin'] ) ) {
				if ( false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'activate-plugin_' . sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) ) ) {

					self::$request['plugin'] = sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) );
					self::$request['action'] = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

					return self::$request;

				}
			} elseif ( isset( $_REQUEST['checked'] ) ) {
				if ( false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-plugins' ) ) {

					self::$request['action']  = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
					self::$request['plugins'] = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['checked'] ) );

					return self::$request;

				}
			}
		}

		return false;
	}

	/**
	 * Validate the Request data.
	 *
	 * Validates the data in $_REQUEST is matching this plugin and action.
	 *
	 * @since 0.3.9
	 * @param string $plugin The Plugin folder/name.php.
	 * @return bool false if either plugin or action does not match, else true.
	 */
	private static function validate_request( $plugin ) {

		if ( isset( self::$request['plugin'] )
			&& $plugin === self::$request['plugin']
			&& 'activate' === self::$request['action']
		) {

			return true;

		} elseif ( isset( self::$request['plugins'] )
			&& 'activate-selected' === self::$request['action']
			&& in_array( $plugin, self::$request['plugins'], true )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Check Capabilities.
	 *
	 * We want no one else but users with activate_plugins or above to be able to active this plugin.
	 *
	 * @since 0.3.9
	 * @return bool false if no caps, else true.
	 */
	private static function check_caps() {
		return current_user_can( 'activate_plugins' );
	}
}
