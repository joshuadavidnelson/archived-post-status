<?php

namespace ArchivedPostStatus\Archive;

/**
 * Resolves whether the current user is permitted to archive / unarchive a post.
 *
 * Absorbs {@see aps_current_user_can_archive()} and
 * {@see aps_current_user_can_unarchive()}. Applies the
 * `aps_default_archive_capability` / `aps_default_unarchive_capability`
 * filters and routes through `current_user_can()`.
 *
 * @since 0.4.0
 */
final class ArchiveCapability {

	/**
	 * Whether the current user can archive content.
	 *
	 * Mirrors `aps_current_user_can_archive()` so the facade can be rewritten
	 * as a one-line delegate in Step 3B.
	 *
	 * @since 0.4.0
	 * @param int $post_id Optional. The post ID to check against. Default 0.
	 * @return bool
	 */
	public static function can_archive( int $post_id = 0 ): bool {

		/**
		 * Default capability to grant ability to archive content.
		 *
		 * @since 0.4.0
		 * @param string $capability The user capability to archive content.
		 * @param int    $post_id    Optional. The post ID to check against.
		 * @return string
		 */
		$capability = (string) apply_filters( 'aps_default_archive_capability', 'edit_others_posts', $post_id );

		return current_user_can( $capability, $post_id );
	}

	/**
	 * Whether the current user can unarchive content.
	 *
	 * Mirrors `aps_current_user_can_unarchive()` so the facade can be
	 * rewritten as a one-line delegate in Step 3B.
	 *
	 * @since 0.4.0
	 * @param int $post_id Optional. The post ID to check against. Default 0.
	 * @return bool
	 */
	public static function can_unarchive( int $post_id = 0 ): bool {

		/**
		 * Default capability to grant ability to unarchive content.
		 *
		 * @since 0.4.0
		 * @param string $capability The user capability to unarchive content.
		 * @param int    $post_id    Optional. The post ID to check against.
		 * @return string
		 */
		$capability = (string) apply_filters( 'aps_default_unarchive_capability', 'edit_others_posts', $post_id );

		return current_user_can( $capability, $post_id );
	}
}
