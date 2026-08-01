<?php
/**
 * Public `aps_*` global functions.
 *
 * Each function below is a one-line delegate to the corresponding static
 * class in `src/Status/`, `src/Archive/`, `src/Admin/`, or `src/Frontend/`.
 * The public signatures (BC contract) are preserved verbatim; the mechanics
 * live with the classes. The legacy `_aps_*` internal helpers were retired
 * (net-new in 0.4.0, no callers); `aps_is_excluded_post_type` lives in
 * the sibling `deprecated.php` file.
 *
 * `@SuppressWarnings("PHPMD.StaticAccess")` is applied per-delegate: each
 * function is, by design, a static call to the canonical class — that's the
 * whole point of the delegate, so the suppression is scoped to the delegate
 * sites rather than declared codebase-wide.
 *
 * @since 0.3.9
 * @package ArchivedPostStatus
 */

use ArchivedPostStatus\Admin\ArchivePostLink;
use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Archive\ArchiveCapability;
use ArchivedPostStatus\Archive\ArchiveOperation;
use ArchivedPostStatus\Archive\ReadOnlyPolicy;
use ArchivedPostStatus\Archive\UnarchiveOperation;
use ArchivedPostStatus\Archive\ViewCapability;
use ArchivedPostStatus\Frontend\ArchivedPostLink;
use ArchivedPostStatus\Status\ArchiveLabel;
use ArchivedPostStatus\Status\SupportedPostTypes;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Filter the archived string.
 *
 * @since 0.3.9
 * @return string
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate: the static
 * call to {@see ArchiveLabel::value()} is the entire point of the function.
 */
function aps_archived_label_string() {
	return ArchiveLabel::value();
}

/**
 * Get the post types that can use the Archived post status.
 *
 * @since 0.4.0
 * @return array<int|string, string> List of supported post type slugs.
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see SupportedPostTypes::all()}.
 */
function aps_get_supported_post_types() {
	return SupportedPostTypes::all();
}

/**
 * Check if a post type is supported by the Archived post status.
 *
 * @since 0.4.0
 * @param  string $post_type
 * @return bool
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see SupportedPostTypes::includes()}.
 */
function aps_is_supported_post_type( $post_type ) {
	return SupportedPostTypes::includes( (string) $post_type );
}

/**
 * Check if the current user can view Archived content.
 *
 * Grants access via the filterable read capability (default
 * `read_private_posts`), with an ownership fallback: a post's own author
 * can always view their archived content when they hold the post type's
 * `edit_posts` primitive.
 *
 * @since 0.4.0
 * @param int $post_id Optional. The post ID to check against.
 * @return bool
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ViewCapability::granted()}.
 */
function aps_current_user_can_view( $post_id = 0 ) {
	return ViewCapability::granted( (int) $post_id );
}

/**
 * Check if Archived content is read-only.
 *
 * @return bool
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ReadOnlyPolicy::enabled()}.
 */
function aps_is_read_only() {
	return ReadOnlyPolicy::enabled();
}

/**
 * Check that the current user can archive content.
 *
 * Ownership-aware with post context: the post's own author needs the post
 * type's `edit_posts` primitive, anyone else its `edit_others_posts`.
 * Without post context the default is `edit_others_posts`. Filterable via
 * `aps_default_archive_capability`.
 *
 * @since 0.4.0
 * @param int $post_id
 * @return bool
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ArchiveCapability::can_archive()}.
 */
function aps_current_user_can_archive( $post_id = 0 ) {
	return ArchiveCapability::can_archive( (int) $post_id );
}

/**
 * Check that the current user can unarchive content.
 *
 * Ownership-aware with post context, like {@see aps_current_user_can_archive()}.
 * Filterable via `aps_default_unarchive_capability`.
 *
 * @since 0.4.0
 * @param int $post_id
 * @return bool
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ArchiveCapability::can_unarchive()}.
 */
function aps_current_user_can_unarchive( $post_id = 0 ) {
	return ArchiveCapability::can_unarchive( (int) $post_id );
}

/**
 * Check that the current user can edit a post.
 *
 * Mirrors the other `aps_current_user_can_*` helpers so the "can this
 * user view / archive / unarchive / edit" question is answered through a
 * single filterable surface — `aps_default_edit_capability` — rather
 * than a raw `current_user_can( 'edit_post', $post_id )` call at the
 * consumer site (notably {@see \ArchivedPostStatus\Admin\NoticeBuilder}'s
 * edit-link gate).
 *
 * @since 0.4.0
 * @param int $post_id
 * @return bool
 */
function aps_current_user_can_edit( $post_id = 0 ) {

	/**
	 * Default capability to grant ability to edit a post.
	 *
	 * @since 0.4.0
	 * @param string $capability The user capability to edit content.
	 * @param int    $post_id    Optional. The post ID to check against.
	 * @return string
	 */
	$capability = (string) apply_filters( 'aps_default_edit_capability', 'edit_post', $post_id );

	return current_user_can( $capability, $post_id );
}

/**
 * Get the link to un/archive a post.
 *
 * Modeled after the core `get_delete_post_link()` function.
 *
 * @see https://developer.wordpress.org/reference/functions/get_delete_post_link/
 *
 * @since 0.4.0
 * @param int    $post    Optional. Post ID. Default is the global `$post`.
 * @param string $context Optional. The context. Default is 'display'.
 * @param string $action  Optional. The action. Default is 'archive'.
 * @return string|false URL used to perform the un/archive action, or false if the post does not exist or its post type is not supported.
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ArchivePostLink::build()}; the {@see ArchiveAction::tryFrom()} call
 * is the documented public surface of the enum.
 */
function aps_get_archive_post_link( $post = 0, $context = 'display', $action = 'archive' ) {
	$archive_action = ArchiveAction::tryFrom( (string) $action ) ?? ArchiveAction::Archive;

	return ArchivePostLink::build( $post, $archive_action, (string) $context );
}

/**
 * Get the link to unarchive a post.
 *
 * @since 0.4.0
 * @param int    $post    Optional. Post ID. Default is the global `$post`.
 * @param string $context Optional. The context. Default is 'display'.
 * @return string|false URL used to perform the unarchive action, or false if the post does not exist or its post type is not supported.
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ArchivePostLink::build()}.
 */
function aps_get_unarchive_post_link( $post = 0, $context = 'display' ) {
	return ArchivePostLink::build( $post, ArchiveAction::Unarchive, (string) $context );
}

/**
 * Get the link to an archived post.
 *
 * Modeled after the core `get_preview_post_link()` function.
 *
 * @see https://developer.wordpress.org/reference/functions/get_preview_post_link/
 *
 * @uses is_post_status_viewable()
 *
 * @since 0.4.0
 * @param int|WP_Post           $post          Optional. Post ID or `WP_Post` object. Defaults to global `$post`.
 * @param array<string, mixed>  $query_args    Optional. Array of additional query args to be appended to the link.
 *                                             Default empty array.
 * @param string                $archived_link Optional. Base preview link to be used if it should differ from the
 *                                             post permalink. Default empty.
 * @return string|false URL used for the post preview, or false if the post does not exist.
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ArchivedPostLink::build()}.
 */
function aps_get_archived_post_link( $post = null, $query_args = array(), $archived_link = '' ) {
	return ArchivedPostLink::build( $post, (array) $query_args, (string) $archived_link );
}

/**
 * Archive a post.
 *
 * Modeled after the core `wp_trash_post()` function.
 *
 * Contract pin (C3):
 *   The {@see ArchiveOperation::perform()} body MUST NOT re-read the post
 *   between `wp_update_post` and the `aps_archived_post` action firing.
 *   The original `WP_Post` object captured at the top of the operation is
 *   the canonical "pre-archive snapshot" passed to listeners (notably
 *   {@see \ArchivedPostStatus\Archive\ArchiveMetaListener::save_meta()}),
 *   which rely on it to record `comment_status` / `ping_status` *before*
 *   the archive flow overwrites them with 'closed'.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_trash_post/
 *
 * @since 0.4.0
 * @param int $post_id
 * @return bool|WP_Post
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see ArchiveOperation::perform()}.
 */
function aps_archive_post( $post_id = 0 ) {
	return ArchiveOperation::perform( (int) $post_id );
}

/**
 * Unarchive a post.
 *
 * Modeled after the core `wp_untrash_post()` function — `aps_unarchive_post`
 * mirrors WordPress core's `untrash_post` in both intent and lifecycle.
 *
 * INVARIANT (C4):
 *   Third-party listeners registered on the `aps_unarchived_post` action may
 *   alter post meta before the in-tree {@see \ArchivedPostStatus\Archive\ArchiveMetaListener::delete_meta()}
 *   listener fires. Action callbacks register at priority 10 by default and
 *   execute in registration order; consumers cannot rely on the archive-meta
 *   keys surviving the action dispatch unmodified. Meta cleanup performed by
 *   this plugin is therefore *best-effort by design* — the deletion call
 *   tolerates missing keys (already deleted by a third-party listener) and
 *   does not fail the unarchive operation.
 *
 *   The 3-arg signature on `aps_unarchived_post` (post_id, previous_status,
 *   pre-unarchive WP_Post) is locked public API for 0.4.0; do not change it.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_untrash_post/
 *
 * @since 0.4.0
 * @param int $post_id
 * @return bool|WP_Post
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see UnarchiveOperation::perform()}.
 */
function aps_unarchive_post( $post_id = 0 ) {
	return UnarchiveOperation::perform( (int) $post_id );
}

/**
 * Helper function to set the unarchive post status to the previous status.
 *
 * Modeled on wp_untrash_post_set_previous_status().
 *
 * @see https://developer.wordpress.org/reference/functions/wp_untrash_post_set_previous_status/
 *
 * @since 0.4.0
 * @param string $new_status      The new status (unused, required for filter signature).
 * @param int    $post_id         Post ID (unused, required for filter signature).
 * @param string $previous_status The previous status to restore.
 * @return string
 *
 * @SuppressWarnings("PHPMD.StaticAccess") -- delegate to
 * {@see UnarchiveOperation::set_previous_status()}.
 */
function aps_unarchive_post_set_previous_status( $new_status, $post_id, $previous_status ) {
	return UnarchiveOperation::set_previous_status(
		(string) $new_status,
		(int) $post_id,
		(string) $previous_status
	);
}
