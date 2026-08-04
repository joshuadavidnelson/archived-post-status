<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Resolves the archive / unarchive row-action policy for a post.
 *
 * Pure function of (post, actions, current user); `PostList` calls it at the
 * use site rather than taking it through the constructor.
 *
 * @since 0.4.0
 */
final class RowActionPolicy {

	/**
	 * Label of the Archive row action.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function archive_label(): string {
		/* translators: label for the "Archive" row action link. */
		return __( 'Archive', 'archived-post-status' );
	}

	/**
	 * Label of the Unarchive row action.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function unarchive_label(): string {
		/* translators: label for the "Unarchive" row action link. */
		return __( 'Unarchive', 'archived-post-status' );
	}

	/**
	 * Compute the row-actions array for a given post + caller-supplied baseline.
	 *
	 * Branch contract:
	 *
	 *   1. Unsupported post type → return `$actions` unchanged.
	 *   2. Archivable status + can-archive → append an `archive` entry.
	 *   3. Status === resolved archive slug + can-unarchive →
	 *      - drop `edit` and `inline hide-if-no-js`,
	 *      - drop `view` if the user can NOT view archived content,
	 *      - append an `unarchive` entry.
	 *   4. Otherwise → return `$actions` unchanged.
	 *
	 * @since 0.4.0
	 *
	 * @param \WP_Post              $post    Post object whose row is being rendered.
	 * @param array<string, string> $actions Incoming row-actions array from the WP filter.
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical vocabulary lookups.
	 */
	public static function for_post( \WP_Post $post, array $actions ): array {
		if ( ! aps_is_supported_post_type( $post->post_type ) ) {
			return $actions;
		}

		$archive_cap   = ArchiveAction::Archive->capability_function();
		$unarchive_cap = ArchiveAction::Unarchive->capability_function();

		if ( ArchivableStatuses::includes( (string) $post->post_status )
			&& $archive_cap( $post->ID ) ) {

			$actions[ ArchiveAction::Archive->value ] = sprintf(
				'<a href="%s" title="%s">%s</a>',
				aps_get_archive_post_link( $post->ID ),
				esc_attr( __( 'Archive this post', 'archived-post-status' ) ),
				self::archive_label()
			);

			return $actions;
		}

		if ( PostStatusValue::resolved_slug() === $post->post_status
			&& $unarchive_cap( $post->ID ) ) {

			// Remove actions that don't apply to Archived posts.
			$removed_actions = array( 'inline hide-if-no-js', 'edit' );

			if ( ! aps_current_user_can_view( $post->ID ) ) {
				$removed_actions[] = 'view';
			}

			foreach ( $removed_actions as $action ) {
				unset( $actions[ $action ] );
			}

			$actions[ ArchiveAction::Unarchive->value ] = sprintf(
				'<a href="%s" title="%s">%s</a>',
				aps_get_unarchive_post_link( $post->ID ),
				esc_attr( __( 'Unarchive this post', 'archived-post-status' ) ),
				self::unarchive_label()
			);
		}

		return $actions;
	}
}
