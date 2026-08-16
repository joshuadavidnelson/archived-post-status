<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves whether the current user is permitted to manage the network
 * settings screen. Mirrors {@see SettingsCapability} exactly, one level up
 * the cascade.
 *
 * @since 0.5.0
 */
final class NetworkSettingsCapability {

	/**
	 * Whether the current user can manage the network settings screen.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public static function granted(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * The resolved capability string itself — not a check. Used wherever
	 * WordPress core wants the capability, not a bool:
	 * `add_submenu_page()`'s capability argument.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	public static function capability(): string {

		/**
		 * Default capability required to manage the network settings screen.
		 *
		 * @since 0.5.0
		 * @param string $capability Default `manage_network_options`.
		 */
		return (string) apply_filters( 'aps_default_network_settings_capability', 'manage_network_options' );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
