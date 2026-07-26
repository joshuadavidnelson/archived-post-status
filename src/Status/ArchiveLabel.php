<?php

namespace ArchivedPostStatus\Status;

/**
 * Resolves the user-facing label for the archived post status.
 *
 * Absorbs the body of {@see aps_archived_label_string()}: applies the
 * `aps_archived_label_string` filter to the translated default and escapes
 * the result for attribute context (preserving the existing behaviour while
 * the procedural facade still exists).
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
		return (string) esc_attr( apply_filters( 'aps_archived_label_string', $label ) );
	}
}
