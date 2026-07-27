<?php
/**
 * Deprecated `aps_*` functions.
 *
 * Self-deprecating facades (functions that emit `_deprecated_function()`)
 * live here until their scheduled removal. Each keeps the exact behavior it
 * had in the release that deprecated it, so sites still calling it see no
 * change until they migrate.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Check if a post type should NOT be using the Archived status.
 *
 * Preserved pre-0.4.0 semantics: membership in the filterable
 * `aps_excluded_post_types` list. This deliberately differs from
 * `! aps_is_supported_post_type()` for post types that are not public —
 * this function never considered visibility, only the list.
 *
 * @param  string $post_type The post type slug to check.
 * @deprecated 0.4.0 Use ( ! aps_is_supported_post_type( $type ) ) instead.
 * @return bool
 */
function aps_is_excluded_post_type( $post_type ) {

	_deprecated_function( 'aps_is_excluded_post_type', '0.4.0', 'aps_is_supported_post_type' );

	/**
	 * Prevent the Archived status from being used on these post types.
	 *
	 * @since 0.1.0
	 * @param array $post_types An array of strings, the slugs for post types excluded.
	 * @return array
	 */
	$excluded = (array) apply_filters( 'aps_excluded_post_types', array( 'attachment' ) );

	return in_array( $post_type, $excluded, true );
}
