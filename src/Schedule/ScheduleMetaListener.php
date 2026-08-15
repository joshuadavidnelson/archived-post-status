<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Clears a post's pending schedule when it is archived by any other route.
 *
 * Mirrors {@see \ArchivedPostStatus\Archive\ArchiveMetaListener} in shape:
 * one hook, one small callback. A post archived directly -- the admin
 * action, `aps_archive_post()` called from anywhere else, a rule -- has
 * already reached its destination, so a schedule pointing at a future
 * instant can never fire meaningfully again. Without this listener the
 * list column would keep advertising a date that will never happen.
 *
 * Deliberately NOT hooked: `before_delete_post` and `trashed_post`. Core
 * drops all postmeta -- including this schedule -- on permanent delete, so
 * there is nothing left to clear by the time such a hook would run. A
 * trashed post fails {@see \ArchivedPostStatus\Schedule\Sweeper}'s
 * archivable-status guard outright and has its stale schedule cleared (or
 * exempted) by the next sweep via `aps_schedule_stale_action`, the same
 * path an untrash-then-never-touched-again post already needs regardless
 * of any eager hook here. Adding either hook would duplicate cleanup the
 * sweeper already guarantees, for no post that isn't already covered.
 *
 * @since 0.5.0
 */
final class ScheduleMetaListener implements HookableInterface {

	/**
	 * Hook descriptors.
	 *
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'aps_archived_post', array( $this, 'clear_schedule' ), 10, 3 ),
		);
	}

	/**
	 * Clear a pending schedule once the post it belongs to has been archived.
	 *
	 * Best-effort, like {@see ArchiveMetaListener::delete_meta()}: a post
	 * with no pending schedule is a no-op, not an error.
	 *
	 * @since 0.5.0
	 * @param int      $post_id         The post ID.
	 * @param string   $previous_status The status before archiving (unused; part of the 3-arg signature).
	 * @param \WP_Post $post            The post object before archiving (unused; part of the 3-arg signature).
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 3-arg hook signature.
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical schedule-operation accessor.
	 */
	public function clear_schedule( int $post_id, string $previous_status, \WP_Post $post ): void {
		ScheduleOperation::clear( $post_id );
	}
}
