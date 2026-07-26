<?php

namespace ArchivedPostStatus\Status;

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Enforces correct archive state when a post is archived via direct
 * wp_update_post() calls that bypass aps_archive_post().
 *
 * aps_archive_post() closes comments and pings as part of its operation.
 * If someone sets post_status = 'archive' directly — bypassing the public API —
 * this guard catches that on save_post and corrects the state.
 *
 * Note: meta persistence intentionally only fires through the public API
 * (via ArchiveMetaListener on the aps_archived_post hook). A direct
 * wp_update_post() call gets state enforced but not meta written.
 *
 * @since 0.4.0
 */
final class PostStatusGuard implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action() is a
	 * named-constructor factory for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'save_post', array( $this, 'enforce_archive_state' ), 10, 2 ),
		);
	}

	/**
	 * Close comments and pings if a post was archived outside aps_archive_post().
	 *
	 * Uses remove_action/add_action around the corrective wp_update_post() call
	 * to prevent this callback from triggering itself. The [$this, 'method']
	 * callable is a stable reference WordPress can match — unlike __FUNCTION__
	 * inside a closure, which always returns '{closure}'.
	 *
	 * @since 0.4.0
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor consulted by every consumer
	 * that compares against `$post->post_status` (Phase 3B leak fix).
	 */
	public function enforce_archive_state( int $post_id, \WP_Post $post ): void {
		if ( wp_doing_ajax() || wp_doing_cron() || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( PostStatusValue::resolved_slug() !== $post->post_status || ! aps_is_supported_post_type( $post->post_type ) ) {
			return;
		}

		if ( 'closed' === $post->comment_status && 'closed' === $post->ping_status ) {
			return; // Already correct — aps_archive_post() handled this.
		}

		remove_action( 'save_post', array( $this, 'enforce_archive_state' ) );

		wp_update_post(
			array(
				'ID'             => $post_id,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		add_action( 'save_post', array( $this, 'enforce_archive_state' ), 10, 2 );
	}
}
