<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Performs the unarchive transition for a post.
 *
 * Holds the body of {@see aps_unarchive_post()} and the filter helper
 * {@see aps_unarchive_post_set_previous_status()}; the procedural facades in
 * `src/functions/functions.php` are one-line delegates to this class.
 *
 * INVARIANT (C4):
 *   Third-party listeners registered on the `aps_unarchived_post` action may
 *   alter post meta before the in-tree {@see ArchiveMetaListener::delete_meta()}
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
	 * consults this so the plugin's own unarchive write — which already
	 * restores comment/ping state and triggers meta cleanup via
	 * {@see ArchiveMetaListener} — is never treated as an out-of-band exit.
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
	 * Modeled after the core `wp_untrash_post()` function — `perform()`
	 * mirrors WordPress core's `untrash_post` in both intent and lifecycle.
	 *
	 * @see https://developer.wordpress.org/reference/functions/wp_untrash_post/
	 *
	 * Return value mirrors the procedural ancestor `aps_unarchive_post()`:
	 *   - `\WP_Post` on success (the pre-unarchive snapshot).
	 *   - `false` on validation failure (missing post, not currently
	 *     archived, `wp_update_post` failure).
	 *   - The `aps_pre_unarchive_post` filter return verbatim when it is
	 *     non-null. Sites typically use `false` to veto unarchival; `true`
	 *     is accepted but undocumented.
	 *
	 * The body is decomposed into private helpers — {@see validate()},
	 * {@see resolve_restore_values()}, {@see dispatch_update()}, and the
	 * in-line filter / action firing — so the conductor below reads as
	 * orchestration. C3 contract pin: there is NO re-read between
	 * `wp_update_post()` and the `aps_unarchived_post` action firing — the
	 * snapshot returned from `validate()` flows verbatim to the action.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to unarchive.
	 * @return \WP_Post|bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- `ArchiveMeta::for_post()` is the canonical
	 * value-object factory; the static-call is the documented public surface, not a service-locator
	 * pull. The same pattern is suppressed in `ArchiveMetaListener::delete_meta()`.
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
		 * Passes the original WP_Post object (the archived post, before the status
		 * change) as a third argument so listeners — such as ArchiveMetaListener —
		 * receive the same shape of arguments as aps_archived_post. Meta is
		 * intentionally read before wp_update_post() (above) so listeners can still
		 * call ArchiveMeta::for_post() and find it; deletion happens in the
		 * listener after this action fires. Existing callbacks registered with one
		 * or two accepted_args continue to work unchanged.
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
	 * Returns `false` when the post does not exist or is not currently in
	 * the resolved archive status. Returning a `\WP_Post|false` shape mirrors
	 * the WP core idiom (`get_post()` itself returns null/false).
	 *
	 * @return \WP_Post|false
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
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
	 * Returns a tuple of `[ previous_status, new_status, comment_status, ping_status ]`.
	 * Two cases:
	 *
	 *   - Meta is present (post archived via 0.4.0+): values come from the
	 *     stored {@see ArchiveMeta} snapshot.
	 *   - Meta is missing (legacy archive, pre-0.4.0): falls back to
	 *     `[ 'draft', 'draft', 'closed', 'closed' ]`. The legacy path is also
	 *     tripped when `META_PREVIOUS_STATUS === '0'` (or any empty value)
	 *     because the `?:` operator coerces it to the `'draft'` fallback —
	 *     this is deliberate, and pinned by
	 *     {@see UnarchiveOperationTest::test_perform_falls_back_to_draft_when_previous_status_meta_is_zero_string}.
	 *
	 * The meta-missing case is an early return, so it reads as a single
	 * linear flow.
	 *
	 * @return array{0:string,1:string,2:string,3:string}
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchiveMeta::for_post()}
	 * is the canonical value-object factory.
	 */
	private static function resolve_restore_values( int $post_id ): array {
		$meta = ArchiveMeta::for_post( $post_id );

		if ( ! $meta ) {
			// Legacy: nothing recorded → restore to draft/closed/closed.
			return array( 'draft', 'draft', 'closed', 'closed' );
		}

		// `?: 'draft'` coerces empty/zero strings (e.g. META_PREVIOUS_STATUS === '0')
		// to the safe 'draft' fallback so wp_update_post() always receives a
		// real status slug.
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
	 * Returns whatever `wp_update_post()` returned — truthy on success,
	 * falsy on failure. Callers must NOT re-read the post between this
	 * call and the `aps_unarchived_post` action (C3 contract).
	 *
	 * The `--status` flag → `add_filter` translation contract pinned in
	 * {@see UnarchiveCommandTest::test_status_flag_overrides_unarchive_status}
	 * traverses the `aps_unarchive_post_status` filter applied here.
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
	 * Modeled on WP core's `wp_untrash_post_set_previous_status()`. Plugins
	 * may add this as a callback on `aps_unarchive_post_status` to force the
	 * restored status back to whatever was captured at archive time. The
	 * `$new_status` and `$post_id` parameters are required by the filter
	 * signature but unused in the body.
	 *
	 * @since 0.4.0
	 *
	 * @param string $new_status      The new status (unused, required for filter signature).
	 * @param int    $post_id         Post ID (unused, required for filter signature).
	 * @param string $previous_status The previous status to restore.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $new_status and $post_id are part
	 * of the locked public 3-arg `aps_unarchive_post_status` filter signature. The hook
	 * contract — not this method body — dictates the param list; third-party callbacks may
	 * depend on the 3-arg shape, so the params stay.
	 */
	public static function set_previous_status( string $new_status, int $post_id, string $previous_status ): string {
		return $previous_status;
	}
}
