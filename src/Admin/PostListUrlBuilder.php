<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Builds admin URLs that point at the post list table (edit.php).
 *
 * Pure-functional helper consumed by both {@see PostEditorGuard} (when
 * redirecting away from the editor after an archived-post save) and
 * {@see BulkActionHandler} (when computing the sendback URL for the
 * single-post archive/unarchive flow). The duplicated edit.php +
 * optional post_type query-arg logic that lived in both consumers is
 * centralized here as a single source of truth.
 *
 * Hybrid pattern, as used throughout the plugin: static helper for
 * stateless value builders; DI for hookable services. No constructor
 * wiring through `Plugin::hookables()` — consumers call
 * `PostListUrlBuilder::for_post_type( $post_type )` at the use site.
 *
 * @since 0.4.0
 */
final class PostListUrlBuilder {

	/**
	 * Build an admin URL that lands on edit.php for the given post type.
	 *
	 * For the default `post` post type, `edit.php` already lists posts, so
	 * the URL is returned without a `post_type` query arg. For every other
	 * supported post type, `post_type={type}` is appended.
	 *
	 * @since 0.4.0
	 *
	 * @param string $post_type    Post type slug (e.g. 'post', 'page').
	 * @param bool   $self_admin   True to use {@see self_admin_url()} (current site
	 *                             on a multisite admin); false to use
	 *                             {@see admin_url()} (network-aware default).
	 *                             {@see PostEditorGuard} uses self_admin_url for
	 *                             the post-save redirect (stays on the current
	 *                             blog); {@see BulkActionHandler} uses admin_url
	 *                             for the bulk-action sendback (caller is already
	 *                             on the right blog by the time the bulk hook
	 *                             fires). Defaults to admin_url for symmetry with
	 *                             WP core's typical redirect helpers.
	 * @return string The resolved edit.php URL.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag") -- the boolean is a small,
	 * stable mode switch (self_admin_url vs admin_url) shared by exactly two
	 * call sites; an enum/separate-method split would be ceremony without
	 * meaningful clarity gain. Documented at both call sites.
	 */
	public static function for_post_type( string $post_type, bool $self_admin = false ): string {
		$url = $self_admin ? self_admin_url( 'edit.php' ) : admin_url( 'edit.php' );

		if ( '' !== $post_type && 'post' !== $post_type ) {
			$url = add_query_arg( 'post_type', $post_type, $url );
		}

		return $url;
	}
}
