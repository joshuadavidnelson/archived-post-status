<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves whether archived content is treated as read-only.
 *
 * Absorbs {@see aps_is_read_only()}. Applies the `aps_is_read_only` filter
 * to the default `true` and returns the cast result.
 *
 * @since 0.4.0
 */
final class ReadOnlyPolicy {

	/**
	 * Whether read-only mode is enabled for archived content.
	 *
	 * The canonical implementation behind `aps_is_read_only()`, which is a
	 * one-line delegate to this method.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public static function enabled(): bool {

		/**
		 * Archived content is read-only by default.
		 *
		 * @since 0.3.5
		 * @param bool $is_read_only True by default.
		 * @return bool
		 */
		return (bool) apply_filters( 'aps_is_read_only', true );
	}
}
