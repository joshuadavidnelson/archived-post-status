<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Performs the unarchive transition for a post.
 *
 * Meta cleanup is best-effort by design: third-party listeners on
 * `aps_unarchived_post` run at the same default priority in registration order
 * and may have already removed the archive-meta keys, so
 * {@see ArchiveMetaListener::delete_meta()} tolerates missing keys and never
 * fails the unarchive.
 *
 * The 3-arg `aps_unarchived_post` signature is locked public API for 0.4.0.
 *
 * @since 0.4.0
 */
final class UnarchiveOperation {

	/**
	 * True while dispatch_update() is writing the restore transition.
	 *
	 * @var bool
	 */
	private static bool $in_flight = false;

	/**
	 * Whether this operation is currently writing its own status transition.
	 *
	 * {@see \ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit()}
	 * consults this so the plugin's own unarchive write — which already restores
	 * comment/ping state and cleans meta up — is not treated as an out-of-band exit.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public static function in_flight(): bool {
		return self::$in_flight;
	}

	/**
	 * Unarchive a post.
	 *
	 * Modeled after core's `wp_untrash_post()`.
	 *
	 * @see https://developer.wordpress.org/reference/functions/wp_untrash_post/
	 *
	 * Returns the pre-unarchive `\WP_Post` snapshot on success, or `false` on
	 * validation failure (missing post, not archived, `wp_update_post` failure).
	 * An `aps_pre_unarchive_post` filter return other than `null` short-circuits
	 * and is returned as-is; sites typically return `false` to veto.
	 *
	 * The snapshot from {@see validate()} flows verbatim to the
	 * `aps_unarchived_post` action — nothing re-reads the post after
	 * `wp_update_post()`.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to unarchive.
	 * @return \WP_Post|bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object factory.
	 */
	public static function perform( int $post_id ): \WP_Post|bool {
		$post = self::validate( $post_id );
		if ( ! $post ) {
			return false;
		}

		[ $previous_status, $new_status, $comment_status, $ping_status ] = self::resolve_restore_values( $post_id );

		/**
		 * Filters whether a post unarchiving should take place.
		 *
		 * @since 0.4.0
		 * @param bool|null $unarchive       Whether to go forward with unarchiving.
		 * @param \WP_Post  $post            Post object.
		 * @param string    $previous_status The status of the post about to be unarchived.
		 */
		$check = apply_filters( 'aps_pre_unarchive_post', null, $post, $previous_status );
		if ( null !== $check ) {
			return $check;
		}

		/**
		 * Fires before a post is unarchived.
		 *
		 * @since 0.4.0
		 * @param int    $post_id         Post ID.
		 * @param string $previous_status The status of the post about to be unarchived.
		 */
		do_action( 'aps_unarchive_post', $post_id, $previous_status );

		self::$in_flight = true;
		try {
			$persisted = self::dispatch_update( $post_id, $new_status, $comment_status, $ping_status, $previous_status );
		} finally {
			self::$in_flight = false;
		}

		if ( ! $persisted ) {
			return false;
		}

		/**
		 * Fires after a post is unarchived.
		 *
		 * `$post` is the archived-form snapshot, matching the argument shape of
		 * `aps_archived_post`. Meta is read before `wp_update_post()` above and
		 * deleted by the listener after this fires, so listeners here can still
		 * call ArchiveMeta::for_post() and find it.
		 *
		 * @since 0.4.0
		 * @param int      $post_id         Post ID.
		 * @param string   $previous_status The status of the post at the point where it was unarchived.
		 * @param \WP_Post $post            The post object (archived form) before unarchiving.
		 */
		do_action( 'aps_unarchived_post', $post_id, $previous_status, $post );

		return $post;
	}

	/**
	 * Validate the post id and return the pre-unarchive {@see \WP_Post} snapshot.
	 *
	 * Returns `false` when the post does not exist or is not currently in the
	 * resolved archive status.
	 *
	 * @return \WP_Post|false
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	private static function validate( int $post_id ): \WP_Post|false {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( PostStatusValue::resolved_slug() !== $post->post_status ) {
			return false;
		}

		return $post;
	}

	/**
	 * Resolve the previous_status + restoration defaults from archive meta.
	 *
	 * Returns `[ previous_status, new_status, comment_status, ping_status ]` from
	 * the stored {@see ArchiveMeta}, or `[ 'draft', 'draft', 'closed', 'closed' ]`
	 * for pre-0.4.0 archives that recorded no meta.
	 *
	 * @return array{0:string,1:string,2:string,3:string}
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object factory.
	 */
	private static function resolve_restore_values( int $post_id ): array {
		$meta = ArchiveMeta::for_post( $post_id );

		if ( ! $meta ) {
			return array( 'draft', 'draft', 'closed', 'closed' );
		}

		// `?:` also catches a stored '0' or '', so wp_update_post() always
		// receives a real status slug.
		$previous_status = $meta->previous_status ?: 'draft';

		return array(
			$previous_status,
			$meta->previous_status,
			$meta->comment_status,
			$meta->ping_status,
		);
	}

	/**
	 * Apply the three restore filters and dispatch `wp_update_post()`.
	 *
	 * Callers must not re-read the post between this call and the
	 * `aps_unarchived_post` action.
	 *
	 * @return int Post id (truthy) on success, `0` (falsy) on failure — the
	 *             raw `wp_update_post()` return.
	 */
	private static function dispatch_update(
		int $post_id,
		string $new_status,
		string $comment_status,
		string $ping_status,
		string $previous_status
	): int {
		/**
		 * Filters the status that a post gets assigned when it is restored from the archive.
		 *
		 * By default posts will be restored to their previous status.
		 *
		 * Priority `PHP_INT_MAX` is reserved for the plugin's own short-lived
		 * overrides — the bulk-action Undo callback and `wp post unarchive
		 * --status=<status>` — which add and remove themselves within one
		 * request. Third-party callbacks should not register at that priority.
		 *
		 * @since 0.4.0
		 * @param string $new_status      The new status of the post being restored.
		 * @param int    $post_id         The ID of the post being restored.
		 * @param string $previous_status The status of the post at the point where it was archived.
		 */
		$post_status = apply_filters( 'aps_unarchive_post_status', $new_status, $post_id, $previous_status );

		/**
		 * Filters the comment status that a post gets assigned when it is restored from the archive.
		 *
		 * @since 0.4.0
		 * @param string $comment_status  The comment status of the post being restored.
		 * @param int    $post_id         The ID of the post being restored.
		 * @param string $previous_status The status of the post at the point where it was archived.
		 * @return string
		 */
		$comment_status = apply_filters( 'aps_unarchive_post_comment_status', $comment_status, $post_id, $previous_status );

		$comment_status = in_array( $comment_status, array( 'open', 'closed' ), true ) ? $comment_status : 'closed';

		/**
		 * Filters the ping status that a post gets assigned when it is restored from the archive.
		 *
		 * @since 0.4.0
		 * @param string $ping_status     The ping status of the post being restored.
		 * @param int    $post_id         The ID of the post being restored.
		 * @param string $previous_status The status of the post at the point where it was archived.
		 * @return string
		 */
		$ping_status = apply_filters( 'aps_unarchive_post_ping_status', $ping_status, $post_id, $previous_status );

		$ping_status = in_array( $ping_status, array( 'open', 'closed' ), true ) ? $ping_status : 'closed';

		return wp_update_post(
			array(
				'ID'             => $post_id,
				'post_status'    => $post_status,
				'comment_status' => $comment_status,
				'ping_status'    => $ping_status,
			)
		);
	}

	/**
	 * Filter callback that restores the previous status during unarchiving.
	 *
	 * Modeled on core's `wp_untrash_post_set_previous_status()`. Plugins may add
	 * this to `aps_unarchive_post_status` to force the restored status back to
	 * whatever was captured at archive time.
	 *
	 * @since 0.4.0
	 *
	 * @param string $new_status      The new status (unused, required for filter signature).
	 * @param int    $post_id         Post ID (unused, required for filter signature).
	 * @param string $previous_status The previous status to restore.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 3-arg filter signature.
	 */
	public static function set_previous_status( string $new_status, int $post_id, string $previous_status ): string {
		return $previous_status;
	}
}
