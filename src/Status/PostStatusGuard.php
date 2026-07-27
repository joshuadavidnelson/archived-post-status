<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Archive\UnarchiveOperation;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Enforces correct archive state when a post enters or leaves the archived
 * status via direct wp_update_post() calls that bypass the public API.
 *
 * Entry: aps_archive_post() closes comments and pings as part of its
 * operation. If someone sets post_status = 'archive' directly — bypassing
 * the public API — this guard catches that on save_post and corrects the
 * state.
 *
 * Exit: core's Bulk Edit (and any direct wp_update_post()) can move a post
 * out of the archived status without aps_unarchive_post() running; the
 * transition_post_status guard restores comment/ping from archive meta and
 * cleans the meta rows up.
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
			HookDescriptor::action( 'transition_post_status', array( $this, 'restore_state_on_exit' ), 10, 3 ),
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
	 * that compares against `$post->post_status`.
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

	/**
	 * Restore comment/ping state and clear archive meta when a post leaves
	 * the archived status outside aps_unarchive_post().
	 *
	 * Without this, an out-of-band exit would leave the archived-era
	 * comment/ping lockdown and the archive meta rows on a now-active post.
	 * Meta presence — not current post-type support — is the authority:
	 * type support can change after a post was archived, and stale meta
	 * still needs cleanup. Legacy archives (no meta) exit as a silent no-op.
	 *
	 * The corrective wp_update_post() only touches comment/ping, so the
	 * post status does not change again and this callback cannot re-enter
	 * itself. A failed restore write leaves the meta rows in place so the
	 * recorded state survives for a later exit or unarchive to retry.
	 *
	 * @since 0.4.0
	 * @param string   $new_status New post status.
	 * @param string   $old_status Post status before the transition.
	 * @param \WP_Post $post       Post object, post-transition.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()},
	 * {@see UnarchiveOperation::in_flight()}, and {@see ArchiveMeta::for_post()} are the
	 * canonical accessors for the filterable slug, the plugin's own-write signal, and the
	 * archive-meta value object respectively.
	 */
	public function restore_state_on_exit( string $new_status, string $old_status, \WP_Post $post ): void {
		$slug = PostStatusValue::resolved_slug();

		// Only transitions leaving the archived status — or leaving trash,
		// which is where a trashed archived post's exit was deferred to —
		// can conclude the archive lifecycle.
		if ( $slug !== $old_status && 'trash' !== $old_status ) {
			return;
		}

		// Still inside the lifecycle: archive→trash defers the exit (core
		// records the pre-trash status in _wp_trash_meta_status and the
		// meta must survive for whatever concludes the round-trip), and
		// trash→archive / no-op writes return to the archived state.
		if ( $slug === $new_status || 'trash' === $new_status ) {
			return;
		}

		if ( UnarchiveOperation::in_flight() ) {
			return;
		}

		$meta = ArchiveMeta::for_post( $post->ID );
		if ( ! $meta ) {
			return;
		}

		if ( $meta->comment_status !== $post->comment_status || $meta->ping_status !== $post->ping_status ) {
			$updated = wp_update_post(
				array(
					'ID'             => $post->ID,
					'comment_status' => $meta->comment_status,
					'ping_status'    => $meta->ping_status,
				)
			);

			if ( ! $updated ) {
				return;
			}
		}

		$meta->delete( $post->ID );
	}
}
