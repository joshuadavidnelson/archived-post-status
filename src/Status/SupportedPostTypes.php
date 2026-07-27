<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves the set of post types that may carry the archived status.
 *
 * Absorbs {@see aps_get_supported_post_types()} and
 * {@see aps_is_supported_post_type()}. Pure functions of the WP environment
 * plus two filter extension points (`aps_excluded_post_types`,
 * `aps_supported_post_types`); no instance state required.
 *
 * @since 0.4.0
 */
final class SupportedPostTypes {

	/**
	 * Get the post types that can use the Archived post status.
	 *
	 * The canonical implementation behind `aps_get_supported_post_types()`,
	 * which is a one-line delegate to this method.
	 *
	 * @since 0.4.0
	 * @return array<int|string, string> List of supported post type slugs.
	 */
	public static function all(): array {

		// Get all public post types.
		$public_post_types = get_post_types( array( 'public' => true ) );

		/**
		 * Prevent the Archived status from being used on these post types.
		 *
		 * @since 0.1.0
		 * @param array $post_types An array of strings, the slugs for post types excluded.
		 * @return array
		 */
		$excluded = (array) apply_filters( 'aps_excluded_post_types', array( 'attachment' ) );

		// Sanitize the filtered value.
		$excluded = array_map( 'esc_attr', array_filter( $excluded, 'post_type_exists' ) );

		// The difference is the supported post types.
		$supported_post_types = array_diff( $public_post_types, $excluded );

		/**
		 * Filter the post types that can use the Archived post status.
		 *
		 * @since 0.4.0
		 * @param array $post_types An array of post type slugs.
		 * @return array
		 */
		return (array) apply_filters( 'aps_supported_post_types', $supported_post_types );
	}

	/**
	 * Check whether the given post type is supported by the archived status.
	 *
	 * @since 0.4.0
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function includes( string $post_type ): bool {
		return in_array( $post_type, self::all(), true );
	}
}
