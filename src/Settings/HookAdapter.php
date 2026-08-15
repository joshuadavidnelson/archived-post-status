<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\RulesVersion;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Adapts stored settings values into the plugin's filter system.
 *
 * Registers at priority 20 on each plugin filter it controls, so developer
 * overrides at priority ≤ 10 run first and are respected, while network-level
 * enforcement at priority 50 runs after and can lock values.
 *
 * Reads from Store and returns values on filters — no UI, no admin menu, no
 * form handling.
 *
 * @since 0.4.0
 */
final class HookAdapter implements HookableInterface {

	/** Priority at which this adapter registers on all plugin filters. */
	public const PRIORITY = 20;

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::filter(
				'aps_is_read_only',
				array( $this, 'is_read_only' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_scheduled_archive_enabled',
				array( $this, 'scheduled_archive_enabled' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_scheduled_archive_post_types',
				array( $this, 'scheduled_archive_post_types' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_auto_archive_enabled',
				array( $this, 'auto_archive_enabled' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_auto_archive_days',
				array( $this, 'auto_archive_days' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_auto_archive_child_mode',
				array( $this, 'auto_archive_child_mode' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_auto_archive_types',
				array( $this, 'auto_archive_types' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_auto_archive_taxonomies',
				array( $this, 'auto_archive_taxonomies' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_auto_archive_age_basis',
				array( $this, 'auto_archive_age_basis' ),
				self::PRIORITY
			),
			HookDescriptor::filter(
				'aps_auto_archive_grace_days',
				array( $this, 'auto_archive_grace_days' ),
				self::PRIORITY
			),

			/*
			 * Flush Store's in-memory cache whenever the option changes by any
			 * path, including direct update_option() calls that bypass Store.
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
			 * switch_to_blog() does not touch Store's static cache, so without
			 * this a request that switches sites keeps serving the previous
			 * site's settings.
			 */
			HookDescriptor::action(
				'switch_blog',
				array( Store::class, 'flush_cache' ),
				10,
				0
			),

			/*
			 * Every write of the settings option is a potential rule change
			 * (§4.7) -- bump the counter the stamper compares its stored
			 * schedules against, on the same three write paths the cache-flush
			 * actions above already cover. A sibling action on the same hook
			 * rather than folding into flush_cache() above, so flush_cache's
			 * own callback identity stays a stable, independently-registrable
			 * unit -- WordPress runs both listeners on a single option write.
			 */
			HookDescriptor::action(
				'update_option_' . Store::OPTION_KEY,
				array( RulesVersion::class, 'bump' ),
				10,
				0
			),
			HookDescriptor::action(
				'add_option_' . Store::OPTION_KEY,
				array( RulesVersion::class, 'bump' ),
				10,
				0
			),
			HookDescriptor::action(
				'delete_option_' . Store::OPTION_KEY,
				array( RulesVersion::class, 'bump' ),
				10,
				0
			),
		);
	}

	/**
	 * Return the stored read-only setting, or the filter's default if unset.
	 *
	 * @param bool $default The current filter value (plugin default: true).
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function is_read_only( bool $default ): bool {
		return Store::get( 'is_read_only', $default );
	}

	/**
	 * @since 0.5.0
	 * @param bool $default The current filter value.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function scheduled_archive_enabled( bool $default ): bool {
		return Store::get( 'scheduled_archive_enabled', $default );
	}

	/**
	 * @since 0.5.0
	 * @param string[] $default The current filter value.
	 * @return string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function scheduled_archive_post_types( array $default ): array {
		return Store::get( 'scheduled_archive_post_types', $default );
	}

	/**
	 * @since 0.5.0
	 * @param bool $default The current filter value.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function auto_archive_enabled( bool $default ): bool {
		return Store::get( 'auto_archive_enabled', $default );
	}

	/**
	 * @since 0.5.0
	 * @param ?int $default The current filter value.
	 * @return ?int
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function auto_archive_days( ?int $default ): ?int {
		return Store::get( 'auto_archive_days', $default );
	}

	/**
	 * @since 0.5.0
	 * @param string $default The current filter value.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function auto_archive_child_mode( string $default ): string {
		return Store::get( 'auto_archive_child_mode', $default );
	}

	/**
	 * @since 0.5.0
	 * @param string[] $default The current filter value.
	 * @return string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function auto_archive_types( array $default ): array {
		return Store::get( 'auto_archive_types', $default );
	}

	/**
	 * @since 0.5.0
	 * @param string[] $default The current filter value.
	 * @return string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function auto_archive_taxonomies( array $default ): array {
		return Store::get( 'auto_archive_taxonomies', $default );
	}

	/**
	 * @since 0.5.0
	 * @param string $default The current filter value.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function auto_archive_age_basis( string $default ): string {
		return Store::get( 'auto_archive_age_basis', $default );
	}

	/**
	 * @since 0.5.0
	 * @param int $default The current filter value.
	 * @return int
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store accessor.
	 */
	public function auto_archive_grace_days( int $default ): int {
		return Store::get( 'auto_archive_grace_days', $default );
	}

	// One method per filter, always `Store::get( 'key', $incoming_default )`.
	// Falling back to the incoming default keeps behavior identical to running
	// without the settings layer when a setting is not stored.
}
