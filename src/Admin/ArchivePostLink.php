<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * Builds nonce-wrapped admin URLs that archive or unarchive a post.
 *
 * Holds the bodies of {@see aps_get_archive_post_link()} and
 * {@see aps_get_unarchive_post_link()}; the procedural facades in
 * `src/functions/functions.php` are one-line delegates to this class.
 *
 * The `$action` parameter is type-narrowed to {@see ArchiveAction} so the
 * slug-vs-action ambiguity that the procedural facade had to defend against
 * (validating an arbitrary string in `in_array( $action, ['archive', 'unarchive'], true )`)
 * is eliminated inside this method body — the enum is the only legal input.
 *
 * @since 0.4.0
 */
final class ArchivePostLink {

	/**
	 * Build the un/archive admin link for a post.
	 *
	 * Modeled after the core `get_delete_post_link()` function. The
	 * `aps_get_{$action}_post_link` filter (action-suffixed name preserved
	 * verbatim from the procedural facade) lets sites mutate the final URL.
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_delete_post_link/
	 *
	 * @since 0.4.0
	 * @param int|\WP_Post  $post    Post ID or WP_Post object. Defaults to the global `$post` via get_post().
	 * @param ArchiveAction $action  Archive action enum (Archive or Unarchive).
	 * @param string        $context Optional. The context. Default is 'display'.
	 * @return string|false URL used to perform the un/archive action, or false if the post is not eligible.
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

		$cap = $action->capability_function();
		if ( ! $cap( $post->ID ) ) {
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
