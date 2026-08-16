<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves whether the current user is permitted to manage the site
 * settings screen.
 *
 * Mirrors {@see \ArchivedPostStatus\Archive\ArchiveCapability} and
 * {@see \ArchivedPostStatus\Archive\ViewCapability}: capability resolution
 * lives in its own small class rather than inline in the `aps_*` function,
 * so the filter it applies is unit-testable in isolation.
 *
 * @since 0.5.0
 */
final class SettingsCapability {

	/**
	 * Whether the current user can manage the site settings screen.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public static function granted(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * The resolved capability string itself — not a check. Used wherever
	 * WordPress core wants the capability, not a bool: `add_options_page()`'s
	 * third argument, and the `option_page_capability_aps` filter that gates
	 * the settings form's own POST handler in `options.php`.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	public static function capability(): string {

		/**
		 * Default capability required to manage the site settings screen.
		 *
		 * @since 0.5.0
		 * @param string $capability Default `manage_options`.
		 */
		return (string) apply_filters( 'aps_default_settings_capability', 'manage_options' );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
