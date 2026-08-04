<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * Builds nonce-wrapped admin URLs that archive or unarchive a post.
 *
 * `build()` decides only whether a link is constructible (post exists, post
 * type is supported) — it does not check the archive/unarchive capability.
 * Any caller that exposes the built URL must gate on the matching
 * `aps_current_user_can_*` capability first.
 *
 * @since 0.4.0
 */
final class ArchivePostLink {

	/**
	 * Build the un/archive admin link for a post.
	 *
	 * Modeled after core's `get_delete_post_link()`.
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_delete_post_link/
	 *
	 * @since 0.4.0
	 * @param int|\WP_Post  $post    Post ID or WP_Post object. Defaults to the global `$post` via get_post().
	 * @param ArchiveAction $action  Archive action enum (Archive or Unarchive).
	 * @param string        $context Optional. The context. Default is 'display'.
	 * @return string|false URL used to perform the un/archive action, or false if the post does not exist or its post type is not supported.
	 */
	public static function build( int|\WP_Post $post, ArchiveAction $action, string $context = 'display' ): string|false {

		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object
			|| ! aps_is_supported_post_type( $post->post_type ) ) {
				return false;
		}

		$link = add_query_arg( 'action', $action->value, admin_url( sprintf( $post_type_object->_edit_link, $post->ID ) ) );

		/**
		 * Filters the post un/archive link.
		 *
		 * @since 0.4.0
		 * @param string $link    The un/archive link.
		 * @param int    $post_id Post ID.
		 * @param string $context The link context. If set to 'display' then ampersands
		 *                        are encoded.
		 */
		return (string) esc_url(
			apply_filters(
				"aps_get_{$action->value}_post_link",
				wp_nonce_url( $link, $action->nonce_key( $post->ID ) ),
				$post->ID,
				$context
			)
		);
	}
}
