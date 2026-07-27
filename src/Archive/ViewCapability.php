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

		if ( current_user_can( $capability, $post_id ) ) {
			return true;
		}

		return self::author_owns_and_can_edit( $post_id );
	}

	/**
	 * Ownership fallback: a post's own author can always view their
	 * archived content, provided they hold the post type's `edit_posts`
	 * primitive.
	 *
	 * The primitive — not the `edit_post` meta cap — keeps this path open
	 * while PostEditorGuard's read-only deny blocks actual editing. This
	 * path is additive to `aps_default_read_capability`: it applies even
	 * when that filter's capability is overridden.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to check against; 0 disables the fallback.
	 * @return bool
	 */
	private static function author_owns_and_can_edit( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$post    = get_post( $post_id );
		$user_id = get_current_user_id();
		if ( ! $post || ! $user_id || (int) $post->post_author !== $user_id ) {
			return false;
		}

		// A post row can outlive its post type's registration (e.g. a
		// deactivated CPT plugin) — fall back to the core primitive. The
		// `??` also tolerates type objects without a full cap map.
		$type_object = get_post_type_object( $post->post_type );

		return current_user_can( $type_object ? ( $type_object->cap->edit_posts ?? 'edit_posts' ) : 'edit_posts' );
	}
}
