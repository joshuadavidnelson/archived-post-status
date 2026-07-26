<?php
/**
 * Deprecated `aps_*` functions and filter-default shims.
 *
 * This file is the home for self-deprecating facades (functions that emit
 * `_deprecated_function()`) and any filter-default-restoring shim callbacks
 * the 0.4.0 filter-drift audit decided to keep on a deprecation timer.
 *
 * Per `docs/0.4.0-filter-drift-audit.md`, none of the four audited drifts
 * required a shim:
 *
 *   - `aps_post_status_slug` — centralized at runtime via
 *     {@see \ArchivedPostStatus\Status\PostStatusValue::resolved_slug()} so
 *     the filter is consulted at every consumer site.
 *   - The "unarchive capability rename" was a phantom — no rename occurred.
 *   - `aps_status_arg_private` — kept at the 0.4.0 default `! is_admin()`,
 *     set in {@see \ArchivedPostStatus\Status\PostStatus}.
 *   - `aps_status_arg_public` — kept at the 0.4.0 default
 *     `! is_admin() && aps_current_user_can_view()` (Option C).
 *
 * So the only resident here today is `aps_is_excluded_post_type`, lifted
 * verbatim from the pre-0.4.0 single-file plugin (including its
 * `_deprecated_function()` call) — it has been self-deprecating since 0.4.0
 * and remains BC-locked for the 0.4.0 line.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Check if a post type should NOT be using the Archived status.
 *
 * @param  string $post_type
 * @deprecated 0.4.0 Use ( ! aps_is_supported_post_type( $type ) ) instead.
 * @return bool
 */
function aps_is_excluded_post_type( $post_type ) {

	_deprecated_function( 'aps_is_excluded_post_type', '0.4.0', 'aps_is_supported_post_type' );

	return ! aps_is_supported_post_type( $post_type );
}
