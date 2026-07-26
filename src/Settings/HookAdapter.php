<?php

namespace ArchivedPostStatus\Settings;

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Adapts stored settings values into the plugin's filter system.
 *
 * Registers at priority 20 on each plugin filter it controls, so that:
 * - Developer overrides at priority ≤ 10 run first and are respected.
 * - Network-level enforcement at priority 50 runs after and can lock values.
 *
 * See the Filter Priority Contract in the architecture documentation for
 * the full priority table and guidance for developers hooking these filters.
 *
 * This class has no UI, no admin menu, no form handling. It only reads
 * from Store and returns values on filters. A future UI layer (0.5.0)
 * will be a separate class responsible for the admin-facing settings page.
 *
 * @since 0.4.0
 */
final class HookAdapter implements HookableInterface {

	/**
	 * Priority at which this adapter registers on all plugin filters.
	 * Documented as the "site settings" layer in the priority contract.
	 */
	public const PRIORITY = 20;

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action()/::filter()
	 * are named-constructor factories for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 * `Store::class` is a callable reference for the cache-flush hook (PHP language
	 * form, not a service-locator pull).
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::filter(
				'aps_is_read_only',
				array( $this, 'is_read_only' ),
				self::PRIORITY
			),

			/*
			 * Cache invalidation: flush Store's in-memory cache whenever
			 * the underlying option changes via any path (including direct
			 * update_option() calls from external code that bypass
			 * Store::update()/save()/delete()).
			 */
			HookDescriptor::action(
				'update_option_' . Store::OPTION_KEY,
				array( Store::class, 'flush_cache' ),
				10,
				0
			),
			HookDescriptor::action(
				'add_option_' . Store::OPTION_KEY,
				array( Store::class, 'flush_cache' ),
				10,
				0
			),
			HookDescriptor::action(
				'delete_option_' . Store::OPTION_KEY,
				array( Store::class, 'flush_cache' ),
				10,
				0
			),

			/*
			 * Add one entry here per setting as each is introduced.
			 * Each callback follows the same pattern: read from Store,
			 * fall back to the incoming $default if not explicitly set.
			 *
			 * Future hook descriptors (0.5.0+):
			 * - aps_archivable_statuses -> archivable_statuses
			 * - aps_excluded_post_types -> excluded_post_types
			 * - aps_archived_label_string -> label_string
			 */
		);
	}

	/**
	 * Return the stored read-only setting, or the filter's default if unset.
	 *
	 * @param bool $default The current filter value (plugin default: true).
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see Store::get()} is the
	 * canonical settings-store accessor (hybrid pattern: static for value lookups,
	 * DI for Hookables). Replacing with a singleton or DI'd instance would only
	 * indirect the same call without changing testability — the WP option layer
	 * is already mockable via the option filter.
	 */
	public function is_read_only( bool $default ): bool {
		return Store::get( 'is_read_only', $default );
	}

	// Add one method per filter as settings are added to the settings page.
	// The pattern is always: Store::get( 'key', $incoming_default ).
	// The incoming $default is the value from lower-priority callbacks —
	// use it as the fallback so that if the setting is not stored, behavior
	// is identical to running without the settings layer.
}
