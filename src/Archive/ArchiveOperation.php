<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Performs the archive transition for a post.
 *
 * Holds the body of {@see aps_archive_post()}; the procedural facade in
 * `src/functions/functions.php` is a one-line delegate to this class.
 *
 * Contract pin (C3):
 *   This class MUST NOT re-read the post between `wp_update_post` and the
 *   `aps_archived_post` action firing. The original `WP_Post` object captured
 *   at the top of {@see perform()} is the canonical "pre-archive snapshot"
 *   passed to listeners (notably {@see ArchiveMetaListener::save_meta()}),
 *   which rely on it to record `comment_status` / `ping_status` *before* the
 *   archive flow overwrites them with 'closed'. A re-read here would silently
 *   corrupt the meta snapshot — the previous-status restore on unarchive
 *   would then mirror the post-archive state, not the pre-archive state.
 *
 * @since 0.4.0
 */
final class ArchiveOperation {

	/**
	 * Archive a post.
	 *
	 * Modeled after the core `wp_trash_post()` function.
	 *
	 * @see https://developer.wordpress.org/reference/functions/wp_trash_post/
	 *
	 * Return value mirrors the procedural ancestor `aps_archive_post()`:
	 *   - `\WP_Post` on success (the pre-archive snapshot).
	 *   - `false` on validation failure (missing post, already archived,
	 *     `wp_update_post` failure).
	 *   - The `aps_pre_archive_post` filter return verbatim when it is
	 *     non-null. Sites typically use `false` to veto archival; `true` is
	 *     accepted but undocumented.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to archive.
	 * @return \WP_Post|bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor; the wp_update_post call
	 * threads the filtered slug end-to-end.
	 */
	public static function perform( int $post_id ): \WP_Post|bool {

		$slug = PostStatusValue::resolved_slug();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( $slug === $post->post_status ) {
			return false;
		}

		$previous_status = $post->post_status;

		/**
		 * Filters whether a post archiving should take place.
		 *
		 * @since 0.4.0
		 * @param bool|null $archive         Whether to go forward with archiving.
		 * @param \WP_Post  $post            Post object.
		 * @param string    $previous_status The status of the post about to be archived.
		 */
		$check = apply_filters( 'aps_pre_archive_post', null, $post, $previous_status );

		if ( null !== $check ) {
			return $check;
		}

		/**
		 * Fires before a post is archived.
		 *
		 * @since 0.4.0
		 * @param int    $post_id         Post ID.
		 * @param string $previous_status The status of the post about to be archived.
		 */
		do_action( 'aps_archive_post', $post_id, $previous_status );

		$post_archived = wp_update_post(
			array(
				'ID'             => $post_id,
				'post_status'    => $slug,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( ! $post_archived ) {
			return false;
		}

		/**
		 * Fires after a post is archived.
		 *
		 * Passes the original WP_Post object (before the status change) as a third
		 * argument so listeners — such as ArchiveMetaListener — can capture
		 * pre-archive state (comment_status, ping_status) without a separate read.
		 * Existing callbacks registered with one or two accepted_args continue to
		 * work unchanged.
		 *
		 * INVARIANT (C3): the `$post` argument MUST be the same object captured at
		 * the top of `perform()` — never the result of a re-read after
		 * `wp_update_post`. Introducing a re-read between the update and this
		 * action will break listeners that rely on pre-archive
		 * `comment_status` / `ping_status`.
		 *
		 * @since 0.4.0
		 * @param int      $post_id         Post ID.
		 * @param string   $previous_status The status of the post at the point where it was archived.
		 * @param \WP_Post $post            The post object before archiving.
		 */
		do_action( 'aps_archived_post', $post_id, $previous_status, $post );

		return $post;
	}
}
