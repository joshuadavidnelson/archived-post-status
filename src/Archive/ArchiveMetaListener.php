<?php

namespace ArchivedPostStatus\Archive;

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Persists and deletes archive meta in response to the plugin's archive hooks.
 *
 * Hooks into aps_archived_post and aps_unarchived_post — the actions fired
 * by aps_archive_post() and aps_unarchive_post() after a successful status change.
 *
 * Separated from ArchiveMeta so the value object remains a pure data class
 * with no hook awareness. This listener can be disabled independently via
 * the aps_enable_archive_meta filter in Plugin::hookables().
 *
 * @since 0.4.0
 */
final class ArchiveMetaListener implements HookableInterface {

	/**
	 * Hook descriptors.
	 *
	 * Both hooks register at priority 10 with accepted_args = 3:
	 *  - aps_archived_post  → save_meta( int $post_id, string $previous_status, \WP_Post $original_post )
	 *  - aps_unarchived_post → delete_meta( int $post_id, string $previous_status, \WP_Post $post )
	 *
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action() is a
	 * named-constructor factory for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'aps_archived_post', array( $this, 'save_meta' ), 10, 3 ),
			HookDescriptor::action( 'aps_unarchived_post', array( $this, 'delete_meta' ), 10, 3 ),
		);
	}

	/**
	 * Save archive meta when a post is archived.
	 *
	 * Receives the original WP_Post object (before the status change) so that
	 * previous_status, comment_status, and ping_status are captured accurately —
	 * they would be stale after wp_update_post() has already run.
	 *
	 * INVARIANT (C3):
	 *   `$original_post` MUST be the pre-archive snapshot — the same object
	 *   {@see aps_archive_post()} captured before calling `wp_update_post`.
	 *   This listener does NOT re-read the post (a re-read would defeat the
	 *   purpose: the comment_status/ping_status fields would be 'closed' by
	 *   then, not the user's pre-archive choice). The contract is pinned by
	 *   `tests/php/Archive/ArchivePostContractTest.php`.
	 *
	 * @since 0.4.0
	 * @param int      $post_id         The post ID.
	 * @param string   $previous_status The status before archiving (unused here; part of the
	 *                                   `aps_archived_post` 3-arg hook signature so other
	 *                                   listeners can observe it).
	 * @param \WP_Post $original_post   The post object before archiving.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $previous_status is part of the
	 * locked public 3-arg `aps_archived_post` hook signature (post_id, previous_status,
	 * original_post). The hook contract — not this method body — dictates the param list;
	 * third-party listeners may depend on the 3-arg shape, so the param stays.
	 */
	public function save_meta( int $post_id, string $previous_status, \WP_Post $original_post ): void {
		ArchiveMeta::from_post( $original_post )->save( $post_id );
	}

	/**
	 * Delete archive meta when a post is unarchived.
	 *
	 * Meta is read inside aps_unarchive_post() to restore previous status
	 * before wp_update_post() runs. By the time this fires (after the update
	 * succeeds), the meta is no longer needed and can be removed.
	 *
	 * The $post parameter is accepted for symmetry with `aps_archived_post`
	 * (locked public API for 0.4.0) but unused — ArchiveMeta::for_post() is
	 * sufficient to locate the stored meta.
	 *
	 * INVARIANT (C4):
	 *   This is one of potentially many listeners on `aps_unarchived_post`.
	 *   Third-party callbacks registered at the same or higher priority may
	 *   alter (or already have altered) the archive meta keys before this
	 *   listener runs. The deletion is therefore best-effort: a missing
	 *   {@see ArchiveMeta::for_post()} return is a no-op (early return), and
	 *   the unarchive operation itself never depends on the deletion's
	 *   outcome. Do NOT change the `aps_unarchived_post` action signature —
	 *   it is locked public API for 0.4.0.
	 *
	 * @since 0.4.0
	 * @param int      $post_id         The post ID.
	 * @param string   $previous_status The status before unarchiving (unused here; part of the
	 *                                   `aps_unarchived_post` 3-arg hook signature).
	 * @param \WP_Post $post            The post object (archived form) before unarchiving
	 *                                   (unused here; part of the 3-arg hook signature).
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $previous_status and $post are part
	 * of the locked public 3-arg `aps_unarchived_post` hook signature (post_id,
	 * previous_status, post). The hook contract — not this method body — dictates the param
	 * list; third-party listeners may depend on the 3-arg shape, so the params stay.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchiveMeta::for_post()} is a
	 * named-constructor factory for the ArchiveMeta value object; static access is
	 * the canonical WP convention for value-object hydration in hook callbacks.
	 */
	public function delete_meta( int $post_id, string $previous_status, \WP_Post $post ): void {
		$meta = ArchiveMeta::for_post( $post_id );
		if ( $meta ) {
			$meta->delete( $post_id );
		}
	}
}
