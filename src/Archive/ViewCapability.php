<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves whether the current user is permitted to view archived content.
 *
 * @since 0.4.0
 */
final class ViewCapability {

	/**
	 * Whether the current user can view archived content.
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
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- shared post-type-primitive lookup.
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

		return current_user_can( PostTypeCapabilityPrimitive::resolve( $post->post_type, 'edit_posts' ) );
	}
}
