<?php

namespace ArchivedPostStatus\Archive;

/**
 * Resolves whether the current user is permitted to view archived content.
 *
 * Absorbs {@see aps_current_user_can_view()}. Applies the
 * `aps_default_read_capability` filter to the default capability
 * (`'read_private_posts'`) and routes the resolved capability through
 * `current_user_can()`.
 *
 * @since 0.4.0
 */
final class ViewCapability {

	/**
	 * Whether the current user can view archived content.
	 *
	 * The canonical implementation behind `aps_current_user_can_view()`,
	 * which is a one-line delegate to this method.
	 *
	 * @since 0.4.0
	 * @param int $post_id Optional. The post ID to check against. Default 0.
	 * @return bool
	 */
	public static function granted( int $post_id = 0 ): bool {

		/**
		 * Default capability to grant ability to view Archived content.
		 *
		 * @since 0.3.0
		 * @param string $capability The user capability to view archived content.
		 * @param int    $post_id    Optional. The post ID to check against.
		 * @return string
		 */
		$capability = (string) apply_filters( 'aps_default_read_capability', 'read_private_posts', $post_id );

		return current_user_can( $capability, $post_id );
	}
}
