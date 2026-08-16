<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Whether this plugin is network-activated.
 *
 * ⚠️ Deliberately does NOT call `is_plugin_active_for_network()`. That
 * function lives in `wp-admin/includes/plugin.php`, which is never loaded on
 * a front-end, cron, or REST request —
 * {@see \ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider} calls
 * this class during rule resolution on exactly those requests, so calling it
 * unguarded there would fatal. Instead this reads the same underlying data
 * core's own implementation does: the network-active plugin list stored in
 * the `active_sitewide_plugins` site option, keyed by plugin basename. That
 * option is ordinary `wp-includes` data with no admin-only file to load.
 *
 * `is_multisite()` is checked first and short-circuits before anything else:
 * a single-site install — the overwhelming majority of requests — never
 * touches `get_site_option()` at all.
 *
 * @since 0.5.0
 */
final class NetworkActivation {

	/**
	 * Fallback plugin basename for a request that has not defined
	 * `ARCHIVED_POST_STATUS_PLUGIN` — isolated unit tests, mainly. Matches
	 * the constant's real production value: `plugin_basename( __FILE__ )` on
	 * the plugin's own root file.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const PLUGIN_BASENAME_FALLBACK = 'archived-post-status/archived-post-status.php';

	/**
	 * Whether this plugin is active network-wide on the current network.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public static function active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );

		return isset( $network_plugins[ self::plugin_basename() ] );
	}

	/**
	 * @since 0.5.0
	 * @return string
	 */
	private static function plugin_basename(): string {
		return defined( 'ARCHIVED_POST_STATUS_PLUGIN' ) ? ARCHIVED_POST_STATUS_PLUGIN : self::PLUGIN_BASENAME_FALLBACK;
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
