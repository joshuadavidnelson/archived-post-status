<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Builds admin URLs that point at the post list table (edit.php).
 *
 * Shared by {@see PostEditorGuard} and {@see BulkActionHandler}; consumers call
 * it at the use site rather than taking it through a constructor.
 *
 * @since 0.4.0
 */
final class PostListUrlBuilder {

	/**
	 * Build an admin URL that lands on edit.php for the given post type.
	 *
	 * `edit.php` already lists the default `post` type, so no `post_type` arg
	 * is appended for it.
	 *
	 * @since 0.4.0
	 *
	 * @param string $post_type    Post type slug (e.g. 'post', 'page').
	 * @param bool   $self_admin   True to use {@see self_admin_url()}, keeping a
	 *                             multisite redirect on the current blog, as
	 *                             {@see PostEditorGuard} needs for the post-save
	 *                             redirect. False uses {@see admin_url()}.
	 * @return string The resolved edit.php URL.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag") -- small, stable mode switch.
	 */
	public static function for_post_type( string $post_type, bool $self_admin = false ): string {
		$url = $self_admin ? self_admin_url( 'edit.php' ) : admin_url( 'edit.php' );

		if ( '' !== $post_type && 'post' !== $post_type ) {
			$url = add_query_arg( 'post_type', $post_type, $url );
		}

		return $url;
	}
}
