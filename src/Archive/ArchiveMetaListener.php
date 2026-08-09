<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Persists and deletes archive meta in response to the plugin's archive hooks.
 *
 * Hooks into aps_archived_post and aps_unarchived_post, the actions fired after
 * a successful status change. Disableable via the aps_enable_archive_meta
 * filter in Plugin::hookables().
 *
 * @since 0.4.0
 */
final class ArchiveMetaListener implements HookableInterface {

	/**
	 * Hook descriptors.
	 *
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
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
	 * `$original_post` must be the pre-archive snapshot and is never re-read
	 * here: after wp_update_post() its comment_status and ping_status read
	 * 'closed' rather than the user's pre-archive choice.
	 *
	 * @since 0.4.0
	 * @param int      $post_id         The post ID.
	 * @param string   $previous_status The status before archiving (unused; part of the 3-arg signature).
	 * @param \WP_Post $original_post   The post object before archiving.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 3-arg hook signature.
	 */
	public function save_meta( int $post_id, string $previous_status, \WP_Post $original_post ): void {
		ArchiveMeta::from_post( $original_post )->save( $post_id );
	}

	/**
	 * Delete archive meta when a post is unarchived.
	 *
	 * aps_unarchive_post() has already read the meta to restore the previous
	 * status, so by the time this fires it is safe to remove.
	 *
	 * Deletion is best-effort: another listener on this action may have removed
	 * the keys first, so a missing meta is a no-op and the unarchive never
	 * depends on the outcome.
	 *
	 * @since 0.4.0
	 * @param int      $post_id         The post ID.
	 * @param string   $previous_status The status before unarchiving (unused; part of the 3-arg signature).
	 * @param \WP_Post $post            The post object (archived form) before unarchiving (unused).
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 3-arg hook signature.
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object factory.
	 */
	public function delete_meta( int $post_id, string $previous_status, \WP_Post $post ): void {
		$meta = ArchiveMeta::for_post( $post_id );
		if ( $meta ) {
			$meta->delete( $post_id );
		}
	}
}
