<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves whether archived content is treated as read-only.
 *
 * @since 0.4.0
 */
final class ReadOnlyPolicy {

	/**
	 * Whether read-only mode is enabled for archived content.
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
