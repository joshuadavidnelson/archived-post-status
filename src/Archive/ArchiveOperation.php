<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Performs the archive transition for a post.
 *
 * Never re-read the post between `wp_update_post` and the `aps_archived_post`
 * action. The `WP_Post` captured at the top of {@see perform()} is the
 * pre-archive snapshot listeners depend on — notably
 * {@see ArchiveMetaListener::save_meta()}, which records `comment_status` /
 * `ping_status` before the archive flow overwrites them with 'closed'. A
 * re-read would make unarchive restore the post-archive state instead.
 *
 * @since 0.4.0
 */
final class ArchiveOperation {

	/**
	 * True while perform() is writing the archive transition.
	 *
	 * @var bool
	 */
	private static bool $in_flight = false;

	/**
	 * Whether this operation is currently writing its own status transition.
	 *
	 * {@see \ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state()}
	 * consults this so the plugin's own write is never treated as out-of-band.
	 * That guard runs on `save_post`, which `wp_update_post()` fires
	 * synchronously inside the call this flag wraps — without the flag it would
	 * revert a site's `aps_archive_post_comment_status` /
	 * `aps_archive_post_ping_status` choice straight back to closed/closed.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public static function in_flight(): bool {
		return self::$in_flight;
	}

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
	 *   - The `aps_pre_archive_post` filter only supports a `bool|null`
	 *     return: `null` lets archival continue; any other value
	 *     short-circuits and is returned here as-is. Sites typically
	 *     return `false` to veto archival.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to archive.
	 * @return \WP_Post|bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
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

		/**
		 * Filters the comment status that a post gets assigned when it is archived.
		 *
		 * @since 0.4.0
		 * @param string $comment_status  The comment status of the post being archived.
		 * @param int    $post_id         The ID of the post being archived.
		 * @param string $previous_status The status of the post about to be archived.
		 * @return string
		 */
		$comment_status = apply_filters( 'aps_archive_post_comment_status', 'closed', $post_id, $previous_status );

		$comment_status = in_array( $comment_status, array( 'open', 'closed' ), true ) ? $comment_status : 'closed';

		/**
		 * Filters the ping status that a post gets assigned when it is archived.
		 *
		 * @since 0.4.0
		 * @param string $ping_status     The ping status of the post being archived.
		 * @param int    $post_id         The ID of the post being archived.
		 * @param string $previous_status The status of the post about to be archived.
		 * @return string
		 */
		$ping_status = apply_filters( 'aps_archive_post_ping_status', 'closed', $post_id, $previous_status );

		$ping_status = in_array( $ping_status, array( 'open', 'closed' ), true ) ? $ping_status : 'closed';

		self::$in_flight = true;
		try {
			$post_archived = wp_update_post(
				array(
					'ID'             => $post_id,
					'post_status'    => $slug,
					'comment_status' => $comment_status,
					'ping_status'    => $ping_status,
				)
			);
		} finally {
			self::$in_flight = false;
		}

		if ( ! $post_archived ) {
			return false;
		}

		/**
		 * Fires after a post is archived.
		 *
		 * `$post` is the pre-archive snapshot captured at the top of `perform()`,
		 * so listeners can read `comment_status` / `ping_status` as they were
		 * before archiving. It must never become a re-read after
		 * `wp_update_post()`.
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
