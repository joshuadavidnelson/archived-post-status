<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Reads and writes the network-level settings option, `aps_network_settings`
 * — the `get_network_option()` mirror of {@see Store}, over the current
 * network (`null` network id, per `get_network_option()`'s own convention
 * for "the current network").
 *
 * Same shape as {@see Store} deliberately: a static facade, an in-memory
 * cache flushed on write, defaults merged in on every read. Scoped to
 * {@see Schema::LEVEL_NETWORK}: this class backs only the network option, so
 * a site-only or term-only key must never appear in what it persists — the
 * same discipline {@see Store::defaults()} keeps for the site option (see
 * that method's docblock for the phase-5a bug this guards against, now that
 * this release has a genuinely level-scoped key set to get wrong).
 *
 * `get_network_option()` / `update_network_option()` / `delete_network_option()`
 * transparently degrade to their single-site `*_option()` equivalents on a
 * non-multisite install (core's own implementation), so this class needs no
 * `is_multisite()` guard of its own — {@see NetworkActivation} is where that
 * guard belongs, because only a caller resolving the cascade knows whether
 * the network level should be consulted at all.
 *
 * @since 0.5.0
 */
final class NetworkStore {

	/** The network option key this class reads and writes. */
	public const OPTION_KEY = 'aps_network_settings';

	/** @var array<string, mixed>|null In-memory cache, cleared on write. */
	private static ?array $cache = null;

	/**
	 * Get a single network setting value by key.
	 *
	 * @since 0.5.0
	 * @param string $key     The setting key.
	 * @param mixed  $default Fallback if the key is not set. If null,
	 *                        the value from defaults() is used.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$value = self::all()[ $key ] ?? null;

		if ( null === $value ) {
			return $default ?? self::defaults()[ $key ] ?? null;
		}

		return $value;
	}

	/**
	 * Update a single network setting value.
	 *
	 * Persist before priming the cache — same ordering as {@see Store::update()}
	 * and for the same reason: a write-triggered cache flush from any hook
	 * bound to the option must not race the priming below.
	 *
	 * @since 0.5.0
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public static function update( string $key, mixed $value ): void {
		$settings         = self::all();
		$settings[ $key ] = $value;

		update_network_option( null, self::OPTION_KEY, $settings );

		self::$cache = $settings;
	}

	/**
	 * Replace all network settings at once — how
	 * {@see NetworkSettingsPage::handle_save()} persists a full form submit.
	 *
	 * Write-then-prime ordering, same reasoning as {@see self::update()}.
	 *
	 * @since 0.5.0
	 * @param array<string, mixed> $settings
	 * @return void
	 */
	public static function save( array $settings ): void {
		$merged = array_merge( self::defaults(), $settings );

		update_network_option( null, self::OPTION_KEY, $merged );

		self::$cache = $merged;
	}

	/**
	 * Get all network settings, merged with defaults.
	 *
	 * @since 0.5.0
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = array_merge(
				self::defaults(),
				(array) get_network_option( null, self::OPTION_KEY, array() )
			);
		}

		return self::$cache;
	}

	/**
	 * Delete the network settings option. Called from uninstall.php.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public static function delete(): void {
		self::$cache = null;
		delete_network_option( null, self::OPTION_KEY );
	}

	/**
	 * Clear the in-memory cache.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Default values for every network-applicable setting.
	 *
	 * Derived from {@see Schema}, scoped to {@see Schema::LEVEL_NETWORK} —
	 * see the class docblock.
	 *
	 * @since 0.5.0
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	public static function defaults(): array {
		$defaults = array();

		foreach ( Schema::keys_for_level( Schema::LEVEL_NETWORK ) as $key ) {
			$defaults[ $key ] = Schema::default_for( $key );
		}

		return $defaults;
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
