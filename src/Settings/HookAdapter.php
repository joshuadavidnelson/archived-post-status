<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

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

	// One method per filter, always `Store::get( 'key', $incoming_default )`.
	// Falling back to the incoming default keeps behavior identical to running
	// without the settings layer when a setting is not stored.
}
