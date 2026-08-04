<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Reads and writes plugin settings stored in wp_options.
 *
 * All settings live under a single option key as an associative array, merged
 * with defaults() on every read so a setting added in a future version has a
 * safe fallback immediately.
 *
 * A plain data store with no knowledge of WordPress filters —
 * {@see HookAdapter} bridges stored values into the plugin's filter system.
 *
 * @since 0.4.0
 */
final class Store {

	public const OPTION_KEY = 'aps_settings';

	/** @var array<string, mixed>|null In-memory cache, cleared on write. */
	private static ?array $cache = null;

	/**
	 * Get a single setting value by key.
	 *
	 * @param string $key     The setting key.
	 * @param mixed  $default Fallback if the key is not set. If null,
	 *                        the value from defaults() is used.
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$value = self::all()[ $key ] ?? null;

		if ( null === $value ) {
			return $default ?? self::defaults()[ $key ] ?? null;
		}

		return $value;
	}

	/**
	 * Update a single setting value.
	 *
	 * Persist before priming the cache. `update_option()` synchronously fires
	 * the cache-flush hook wired in {@see HookAdapter::hooks()}, so priming
	 * first would just have the cache cleared out from under it and send the
	 * next `get()` back to the database.
	 */
	public static function update( string $key, mixed $value ): void {
		$settings         = self::all();
		$settings[ $key ] = $value;

		update_option( self::OPTION_KEY, $settings );

		self::$cache = $settings;
	}

	/**
	 * Replace all settings at once (reserved for a future settings UI; unused today).
	 *
	 * Write-then-prime ordering, for the reason given on {@see self::update()}.
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function save( array $settings ): void {
		$merged = array_merge( self::defaults(), $settings );

		update_option( self::OPTION_KEY, $merged );

		self::$cache = $merged;
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = array_merge(
				self::defaults(),
				(array) get_option( self::OPTION_KEY, array() )
			);
		}

		return self::$cache;
	}

	/**
	 * Delete all plugin settings. Called from uninstall.php.
	 */
	public static function delete(): void {
		self::$cache = null;
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Clear the in-memory cache.
	 *
	 * Wired by {@see HookAdapter} to the option-write hooks, so external code
	 * that writes the option directly still reads correctly, and to
	 * `switch_blog`, so a multisite request that switches sites does not keep
	 * serving the previous site's settings.
	 *
	 * @since 0.4.0
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Default values for all settings.
	 *
	 * Every setting key {@see HookAdapter} maps to a filter needs a default
	 * here, matching that filter's own default — so enabling the settings
	 * system without configuring anything behaves identically to running
	 * without it.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'is_read_only' => true,

			/*
			 * Future settings to be implemented:
			 * - archivable_statuses: ['publish', 'future', 'draft', 'pending', 'private']
			 * - excluded_post_types: ['attachment']
			 * - read_capability: 'read_private_posts'
			 * - archive_capability: 'edit_others_posts'
			 * - label_string: 'Archived'
			 * - title_label_enabled: true
			 * - title_label_before: true
			 * - auto_archive_enabled: false
			 * - auto_archive_days: 365
			 * - auto_archive_types: []
			 */
		);
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
