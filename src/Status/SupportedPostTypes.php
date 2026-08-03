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
	 * Per-request memo of {@see all()}'s result. Null means "not computed
	 * yet this request"; {@see reset()} is the only way back to null.
	 *
	 * @since 0.4.0
	 * @var array<int|string, string>|null
	 */
	private static ?array $cache = null;

	/**
	 * Get the post types that can use the Archived post status.
	 *
	 * The canonical implementation behind `aps_get_supported_post_types()`,
	 * which is a one-line delegate to this method.
	 *
	 * Memoized for the rest of the request after the first call: this is a
	 * list-table-row-heavy getter (called several times per row via
	 * `aps_is_supported_post_type()`), and get_post_types() plus two
	 * apply_filters() passes on every single call added up. Safe to memoize
	 * because every caller that needs a just-registered post type to show
	 * up ({@see \ArchivedPostStatus\Admin\ArchiveColumn::register_post_type_hooks()},
	 * {@see \ArchivedPostStatus\Admin\PostList::register_post_type_hooks()})
	 * is already deferred to `wp_loaded` specifically so every `init`-priority
	 * post type registration has finished first — by the time anything
	 * calls `all()` in a normal request, there is nothing left to miss.
	 *
	 * A site that registers a post type or changes the
	 * `aps_excluded_post_types` / `aps_supported_post_types` filters mid-request
	 * (after `all()` has already memoized) must call {@see reset()} first, or
	 * the change will not be picked up until the next request. The test
	 * suite calls {@see reset()} before and after every test (see
	 * `tests/php/includes/TestCase.php`) so the memo can never leak from one
	 * test into the next — a stale memoized post-type list would otherwise
	 * silently pass or fail unrelated tests depending on run order.
	 *
	 * @since 0.4.0
	 * @return array<int|string, string> List of supported post type slugs.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

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
