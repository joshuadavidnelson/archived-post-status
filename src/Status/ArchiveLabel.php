<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves the user-facing label for the archived post status.
 *
 * Absorbs the body of {@see aps_archived_label_string()}: applies the
 * `aps_archived_label_string` filter to the translated default and returns
 * the result verbatim.
 *
 * @since 0.4.0
 */
final class ArchiveLabel {

	/**
	 * Build the archived label.
	 *
	 * The canonical implementation behind `aps_archived_label_string()`,
	 * which is a one-line delegate to this method.
	 *
	 * Returns the filtered label unescaped. This method has no output
	 * context of its own — a site's `aps_archived_label_string` filter
	 * callback may feed HTML text (esc_html()), an HTML attribute
	 * (esc_attr()), or a `register_post_status()` arg core escapes at its
	 * own output sites — so escaping belongs at each consumer's call site,
	 * not here. Pre-0.4.0 this escaped with `esc_attr()` unconditionally,
	 * which meant every text-context consumer that (correctly) escaped
	 * again on its own account double-escaped the label (§1.6).
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
