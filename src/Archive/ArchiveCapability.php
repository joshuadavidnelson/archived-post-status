<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves whether the current user is permitted to archive / unarchive a post.
 *
 * @since 0.4.0
 */
final class ArchiveCapability {

	/**
	 * Whether the current user can archive content.
	 *
	 * @since 0.4.0
	 * @param int $post_id Optional. The post ID to check against. Default 0.
	 * @return bool
	 */
	public static function can_archive( int $post_id = 0 ): bool {

		/**
		 * Default capability to grant ability to archive content.
		 *
		 * With post context the default is ownership-aware: the post's own
		 * author needs only the post type's `edit_posts` primitive, anyone
		 * else needs its `edit_others_posts`. Without post context the
		 * stricter `edit_others_posts` is the default. A filter return
		 * replaces the default outright.
		 *
		 * @since 0.4.0
		 * @param string $capability The user capability to archive content.
		 * @param int    $post_id    Optional. The post ID to check against.
		 * @return string
		 */
		$capability = (string) apply_filters( 'aps_default_archive_capability', self::default_capability( $post_id ), $post_id );

		return current_user_can( $capability, $post_id );
	}

	/**
	 * Whether the current user can unarchive content.
	 *
	 * @since 0.4.0
	 * @param int $post_id Optional. The post ID to check against. Default 0.
	 * @return bool
	 */
	public static function can_unarchive( int $post_id = 0 ): bool {

		/**
		 * Default capability to grant ability to unarchive content.
		 *
		 * Ownership-aware like `aps_default_archive_capability`: the post's
		 * own author needs only the post type's `edit_posts` primitive,
		 * anyone else its `edit_others_posts`. A filter return replaces the
		 * default outright.
		 *
		 * @since 0.4.0
		 * @param string $capability The user capability to unarchive content.
		 * @param int    $post_id    Optional. The post ID to check against.
		 * @return string
		 */
		$capability = (string) apply_filters( 'aps_default_unarchive_capability', self::default_capability( $post_id ), $post_id );

		return current_user_can( $capability, $post_id );
	}

	/**
	 * Resolve the ownership-aware default capability for a post context.
	 *
	 * Deliberately resolves to post type primitives rather than the
	 * `edit_post` meta cap: PostEditorGuard denies `edit_post` on archived
	 * posts while read-only mode is active, and routing these checks
	 * through primitives keeps archiving, unarchiving, and author view
	 * access independent of that editing deny.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to check against; 0 for screen-level checks.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- shared post-type-primitive lookup.
	 */
	private static function default_capability( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return 'edit_others_posts';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return 'edit_others_posts';
		}

		$user_id = get_current_user_id();

		if ( $user_id && (int) $post->post_author === $user_id ) {
			return PostTypeCapabilityPrimitive::resolve( $post->post_type, 'edit_posts' );
		}

		return PostTypeCapabilityPrimitive::resolve( $post->post_type, 'edit_others_posts' );
	}
}
