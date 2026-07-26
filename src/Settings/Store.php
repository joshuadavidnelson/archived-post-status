<?php

namespace ArchivedPostStatus\Settings;

/**
 * Reads and writes plugin settings stored in wp_options.
 *
 * All settings live under a single option key as an associative array.
 * Default values are defined in defaults() and merged on every read,
 * so new settings added in future versions have safe fallbacks immediately.
 *
 * This class has no knowledge of WordPress filters or the plugin's behavior.
 * It is a plain data store. The Bridge class is responsible for bridging
 * stored values into the plugin's filter system.
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
	 * Ordering matters: `update_option` fires FIRST so its
	 * cache-flush hooks (`update_option_aps_settings`, etc.) wired in
	 * {@see HookAdapter::hooks()} run against the freshly persisted value.
	 * Only after the option is committed do we prime the in-memory cache —
	 * otherwise the cache-flush callback would clear the cache we just
	 * populated, and the next `get()` would re-read from the DB.
	 *
	 * Net effect: a `get()` called immediately after `update()` returns the
	 * new value without a second `get_option()` round-trip.
	 */
	public static function update( string $key, mixed $value ): void {
		$settings         = self::all();
		$settings[ $key ] = $value;

		// 1. Persist first — the cache-flush hook on `update_option_aps_settings`
		//    fires synchronously here and clears self::$cache.
		update_option( self::OPTION_KEY, $settings );

		// 2. Now prime the cache with the just-written value so the next
		//    get()/all() serves it without another get_option() call.
		self::$cache = $settings;
	}

	/**
	 * Replace all settings at once (used by the settings page save handler).
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function save( array $settings ): void {
		self::$cache = array_merge( self::defaults(), $settings );
		update_option( self::OPTION_KEY, self::$cache );
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
	 * Wired to update_option_aps_settings, add_option_aps_settings, and
	 * delete_option_aps_settings via HookAdapter so that external code that
	 * writes the option directly (without going through update()/save()/delete())
	 * still produces correct reads on the next call to all()/get().
	 *
	 * @since 0.4.0
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Default values for all settings.
	 *
	 * Every setting key that Bridge maps to a filter must have a
	 * default here. The default should match the filter's own default
	 * so that enabling the settings system without configuring anything
	 * produces identical behavior to running without it.
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
