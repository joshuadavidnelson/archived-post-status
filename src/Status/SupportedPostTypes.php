<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves the set of post types that may carry the archived status.
 *
 * @since 0.4.0
 */
final class SupportedPostTypes {

	/**
	 * Per-request memo of {@see all()}'s result. Null means not yet computed;
	 * {@see reset()} is the only way back to null.
	 *
	 * @since 0.4.0
	 * @var array<int|string, string>|null
	 */
	private static ?array $cache = null;

	/**
	 * Get the post types that can use the Archived post status.
	 *
	 * Memoized per request: this runs several times per list-table row via
	 * `aps_is_supported_post_type()`, and get_post_types() plus two
	 * apply_filters() passes each time added up. Safe because the callers that
	 * need just-registered post types are deferred to `wp_loaded`, after every
	 * `init`-priority registration.
	 *
	 * Anything registering a post type or changing the filters mid-request
	 * must call {@see reset()} or the change waits for the next request.
	 *
	 * @since 0.4.0
	 * @return array<int|string, string> List of supported post type slugs.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$public_post_types = get_post_types( array( 'public' => true ) );

		/**
		 * Prevent the Archived status from being used on these post types.
		 *
		 * @since 0.1.0
		 * @param array $post_types An array of strings, the slugs for post types excluded.
		 * @return array
		 */
		$excluded = (array) apply_filters( 'aps_excluded_post_types', array( 'attachment' ) );

		$excluded = array_map( 'esc_attr', array_filter( $excluded, 'post_type_exists' ) );

		$supported_post_types = array_diff( $public_post_types, $excluded );

		/**
		 * Filter the post types that can use the Archived post status.
		 *
		 * @since 0.4.0
		 * @param array $post_types An array of post type slugs.
		 * @return array
		 */
		self::$cache = (array) apply_filters( 'aps_supported_post_types', $supported_post_types );

		return self::$cache;
	}

	/**
	 * Clear the per-request memo so the next {@see all()} call recomputes.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = null;
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
