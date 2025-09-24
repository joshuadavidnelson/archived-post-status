<?php
/**
 * Fired during plugin activation.
 *
 * This class will validate the activation request and check the user's capabilities
 * before activating the plugin and running any on-activation code.
 *
 * @link       https://github.com/joshuadavidnelson/archived-post-status
 * @since      0.4.0
 * @package    ArchivedPostStatus
 * @subpackage Activator
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since 0.4.0
 */
class Activator {

	/**
	 * The $_REQUEST during plugin activation.
	 *
	 * @since  0.4.0
	 * @access private
	 * @var    array<string, mixed> $request The $_REQUEST array during plugin activation.
	 */
	private static $request = array(
		'_wpnonce' => '',
		'action'   => '',
		'plugin'   => '',
		'plugins'  => array(),
		'checked'  => '',
	);

	/**
	 * The $_REQUEST['plugin'] during plugin activation.
	 *
	 * @since  0.4.0
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
	 * @since 0.4.0
	 * @return void
	 */
	public static function activate() {

		// sanitize the request object.
		$sanitized_request = self::get_sanitized_request();
		if ( ! empty( $sanitized_request ) ) {
			self::$request = $sanitized_request;
		} else {
			exit;
		}

		if ( ! self::get_request()
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
	 * @since  0.4.0
	 * @return array<string, mixed> false if no request, else array with plugin and action.
	 */
	private static function get_request() {

		if ( ! empty( self::$request )
			&& isset( self::$request['_wpnonce'] )
			&& isset( self::$request['action'] )
		) {

			if ( ! empty( self::$request['plugin'] ) ) {
				if ( false !== wp_verify_nonce( self::$request['_wpnonce'], 'activate-plugin_' . self::$request['plugin'] ) ) {

					self::$request['plugin'] = (string) self::$request['plugin'];
					self::$request['action'] = (string) self::$request['action'];

					return self::$request;

				}
			} elseif ( ! empty( self::$request['checked'] ) ) {
				if ( false !== wp_verify_nonce( self::$request['_wpnonce'], 'bulk-plugins' ) ) {

					self::$request['action']  = (string) self::$request['action'];
					self::$request['plugins'] = array_map( 'sanitize_text_field', (array) self::$request['checked'] );

					return self::$request;

				}
			}
		}

		return array();
	}

	/**
	 * Get the sanitized request.
	 *
	 * Gets the $_REQUEST array and checks if necessary keys are set.
	 * Populates self::request with necessary and sanitized values.
	 *
	 * @since  0.4.0
	 * @return array<string, mixed> sanitized request array.
	 */
	private static function get_sanitized_request() {

		// Define the list of keys to sanitize and return.
		$keys_to_sanitize = array(
			'_wpnonce',
			'action',
			'plugin',
			'plugins',
			'checked',
		);

		// Initialize the sanitized request array.
		$sanitized_request = array();

		// Iterate over the list of keys and sanitize their values.
		foreach ( $keys_to_sanitize as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This runs during plugin activation where nonce verification is handled by WordPress core
			if ( isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This runs during plugin activation where nonce verification is handled by WordPress core
				$sanitized_request[ $key ] = sanitize_text_field ( wp_unslash( $_REQUEST[ $key ] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This runs during plugin activation where nonce verification is handled by WordPress core
			} elseif ( isset( $_REQUEST[ $key ] ) && is_array( $_REQUEST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This runs during plugin activation where nonce verification is handled by WordPress core
				$sanitized_request[ $key ] = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST[ $key ] ) );
			} else {
				$sanitized_request[ $key ] = '';
			}
		}

		// Return the sanitized request array.
		return $sanitized_request;
	}

	/**
	 * Validate the Request data.
	 *
	 * Validates the data in $_REQUEST is matching this plugin and action.
	 *
	 * @since 0.4.0
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
			&& is_array( self::$request['plugins'] )
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
	 * @since 0.4.0
	 * @return bool false if no caps, else true.
	 */
	private static function check_caps() {
		return \current_user_can( 'activate_plugins' );
	}
}
