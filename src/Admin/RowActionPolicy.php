<?php

namespace ArchivedPostStatus\Admin;

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Resolves the archive / unarchive row-action policy for a post.
 *
 * Pure function of input — `PostList` shrinks to a thin WP-filter adapter
 * by delegating to this helper for the policy branches.
 *
 * Hybrid architecture pattern:
 *   - This class is STATIC; it has no state and no collaborators worth
 *     swapping. Pure functions of (post, screen, current user) only.
 *   - It calls into the other static helpers ({@see ViewCapability},
 *     {@see ArchiveCapability} via the `ArchiveAction::capability_function()`
 *     dispatch, {@see ArchivableStatuses}, {@see PostStatusValue}).
 *   - `PostList` does NOT pass it through its constructor — `PostList`
 *     calls `RowActionPolicy::for_post( $post, $actions, $screen )` at the
 *     use site.
 *
 * @since 0.4.0
 */
final class RowActionPolicy {

	/**
	 * Compute the row-actions array for a given post + screen + caller-supplied baseline.
	 *
	 * The screen argument is accepted for forward compatibility — the
	 * Phase 4 implementation does not consult it. A future Phase 5+ change
	 * may introduce screen-conditional row-action surfaces (e.g. hiding
	 * archive on the legacy `post-new.php` screen) without rewiring callers.
	 *
	 * Branch contract (mirrors the pre-extraction {@see PostList::row_actions()}):
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
	 * @param \WP_Screen|object|null $screen Current admin screen (forward-compat; unused today).
	 *                                       Accepts any object so callers (and tests) can pass
	 *                                       a `WP_Screen` or a screen-shaped stdClass without
	 *                                       a runtime type error; the implementation does not
	 *                                       consult `$screen` today.
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- `$screen` is part of
	 * the documented contract; accepted today for forward compatibility.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchivableStatuses::includes()}
	 * and {@see PostStatusValue::resolved_slug()} are the canonical
	 * vocabulary lookups (Phase 3B).
	 */
	public static function for_post( \WP_Post $post, array $actions, ?object $screen = null ): array {
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
				__( 'Archive', 'archived-post-status' )
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
				__( 'Unarchive', 'archived-post-status' )
			);
		}

		return $actions;
	}
}
