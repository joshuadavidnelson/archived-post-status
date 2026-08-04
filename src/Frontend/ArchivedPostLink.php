<?php

namespace ArchivedPostStatus\Frontend;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Builds the public-facing permalink for an archived post.
 *
 * @since 0.4.0
 */
final class ArchivedPostLink {

	/**
	 * Build the link to an archived post.
	 *
	 * Modeled after the core `get_preview_post_link()` function.
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_preview_post_link/
	 *
	 * @uses is_post_status_viewable()
	 *
	 * @since 0.4.0
	 * @param int|\WP_Post|null     $post          Optional. Post ID or `WP_Post` object. Defaults to the global `$post`.
	 * @param array<string, mixed>  $query_args    Optional. Array of additional query args to be appended to the link.
	 *                                             Default empty array.
	 * @param string                $archived_link Optional. Base preview link to be used if it should differ from the
	 *                                             post permalink. Default empty.
	 * @return string|false URL used for the archived post permalink, or false if the post does not exist or is not viewable.
	 */
	public static function build( int|\WP_Post|null $post = null, array $query_args = array(), string $archived_link = '' ): string|false {

		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		if ( is_post_status_viewable( $post->post_status ) ) {
			return get_permalink( $post );
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object
			|| ! aps_is_supported_post_type( $post->post_type )
			|| is_post_type_viewable( $post_type_object ) ) {
				return false;
		}

		if ( ! $archived_link ) {
			$archived_link = set_url_scheme( get_permalink( $post ) );
		}

		$query_args['preview'] = 'true';
		$archived_link         = add_query_arg( $query_args, $archived_link );

		/**
		 * Filters the URL used for a archived post.
		 *
		 * @since 0.4.0
		 * @param string   $archived_link URL used to view the archived post.
		 * @param \WP_Post $post          Post object.
		 * @return string
		 */
		return (string) esc_url( apply_filters( 'aps_archived_post_link', $archived_link, $post ) );
	}
}
