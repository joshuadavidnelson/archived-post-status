<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves the user-facing label for the archived post status.
 *
 * @since 0.4.0
 */
final class ArchiveLabel {

	/**
	 * Build the archived label.
	 *
	 * Returns the label unescaped — this method has no output context of its
	 * own, so escaping belongs at each consumer's call site. Pre-0.4.0 this
	 * applied `esc_attr()` unconditionally, double-escaping the label for
	 * every text-context consumer that (correctly) escaped it again.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function value(): string {

		// translators: The label used for the status.
		$label = __( 'Archived', 'archived-post-status' );

		/**
		 * Filter the label used in the plugin for archived content.
		 *
		 * @since 0.3.9
		 * @param string $label The "Archived" label.
		 * @return string
		 */
		return (string) apply_filters( 'aps_archived_label_string', $label );
	}
}
