<?php

namespace ArchivedPostStatus\Admin;

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * Handle the bulk archive/unarchive actions on the post list table.
 *
 * Plain service injected into PostList. Receives post ids + sendback URL
 * from the WordPress `handle_bulk_actions-edit-{type}` filter and returns
 * the redirect URL the post-list refresh should use. Owns the bulk
 * dispatch + capability gates + persist + redirect-URL composition;
 * PostList retains the value-returning filter callbacks (`query_vars`,
 * `bulk_actions`, `row_actions`) and the single-post `post_action_*`
 * handlers.
 *
 * Dispatch routes through `ArchiveAction` exclusively — `$action->perform()`
 * and `$action->capability_function()` are the single sources of truth
 * for action dispatch and per-action capability resolution, so no call
 * site branches on action type.
 *
 * Per-item failures (capability denied, post-not-found, wrong status) are
 * accumulated into a {@see BulkActionResult} via the reason-bucketed
 * `record_*` API and surfaced through the redirect URL for
 * {@see NoticeBuilder} to render. The loop continues on every per-item
 * failure — only the outer cap gate at {@see handle()} entry is permitted
 * to abort the batch (fail-closed by default).
 *
 * @since 0.4.0
 */
final class BulkActionHandler {

	/**
	 * Query args stripped from the sendback URL before the result counts
	 * are appended. Single source of truth used by both the bulk-handler
	 * entry point ({@see handle()}) and the per-post-action redirect-URL
	 * builder ({@see get_redirect_url()}) so the two paths never drift.
	 *
	 * Any new counter or id-list arg must be added here AND to
	 * {@see PostList::query_vars()} so WordPress recognizes it on the
	 * subsequent edit.php load.
	 *
	 * @var string[]
	 */
	private const STRIPPED_QUERY_ARGS = array( 'archived', 'unarchived', 'ids' );

	/**
	 * Handle the bulk action filter callback.
	 *
	 * Entry point for `handle_bulk_actions-edit-{type}` — receives the
	 * sendback URL, the action key, and the selected post ids; returns the
	 * (possibly rewritten) sendback URL with the result counters appended.
	 *
	 * Fails closed: if the outer cap gate denies, the sendback comes back
	 * unchanged and no per-id work runs. Per-id failures inside the loop
	 * are bucketed via {@see BulkActionResult} and the loop continues —
	 * no mid-batch wp_die().
	 *
	 * @since 0.4.0
	 * @param string          $sendback The redirect URL.
	 * @param string          $doaction The action being taken.
	 * @param array<int, int> $post_ids The items to take the action on.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchiveAction::from()} is the
	 * PHP enum hydration entry point (backed-enum value -> case lookup); the static
	 * call is the language-mandated form, not a service-locator pull.
	 */
	public function handle( string $sendback, string $doaction, array $post_ids ): string {
		// Early validation
		if ( empty( $post_ids ) ) {
			return $sendback;
		}

		try {
			$action = ArchiveAction::from( $doaction );
		} catch ( \ValueError $e ) {
			return $sendback; // Not our action
		}

		// H5: fail-closed outer cap gate, routed through the same
		// centralized cap function the per-id loop uses. ArchiveAction's
		// capability_function() returns the global function name
		// (`aps_current_user_can_archive` / `_unarchive`), which we invoke
		// with no post id — falling back to its `$post_id = 0` default for
		// a screen-level check. This picks up the
		// `aps_default_archive_capability` /
		// `aps_default_unarchive_capability` filter overrides instead of a
		// hardcoded 'edit_posts' baseline.
		$cap = $action->capability_function();
		if ( ! $cap() ) {
			return $sendback;
		}

		$sendback = remove_query_arg( self::STRIPPED_QUERY_ARGS, $sendback );

		return match ( $action ) {
			ArchiveAction::Archive => $this->bulk_archive( $post_ids, $sendback ),
			ArchiveAction::Unarchive => $this->bulk_unarchive( $post_ids, $sendback ),
		};
	}

	/**
	 * Handle archive bulk action.
	 *
	 * Continues the loop on every per-item failure; per-id bucketing happens
	 * in {@see process_archive_post()}. The outer loop only iterates and
	 * defers the result-aggregation policy to the helper.
	 *
	 * @since 0.4.0
	 * @param array<int, int> $post_ids Array of post IDs.
	 * @param string          $sendback The redirect URL.
	 * @return string
	 */
	private function bulk_archive( array $post_ids, string $sendback ): string {
		$action = ArchiveAction::Archive;
		$result = new BulkActionResult();

		foreach ( $post_ids as $post_id ) {
			$this->process_archive_post( $post_id, $action, $result );
		}

		return $result->apply_to_url( $sendback, $action );
	}

	/**
	 * Handle unarchive bulk action.
	 *
	 * Continues the loop on every per-item failure (H4: SRP-correct
	 * scoping — capability checks happen here, not in
	 * {@see NoticeBuilder}). Bucketed counts flow through
	 * {@see BulkActionResult} to the redirect URL.
	 *
	 * @since 0.4.0
	 * @param array<int, int> $post_ids Array of post IDs.
	 * @param string          $sendback The redirect URL.
	 * @return string
	 */
	private function bulk_unarchive( array $post_ids, string $sendback ): string {
		if ( isset( $_GET['doaction'] ) && 'undo' === $_GET['doaction'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'aps_unarchive_post_status', 'aps_unarchive_post_set_previous_status', 10, 3 );
		}

		$action = ArchiveAction::Unarchive;
		$result = new BulkActionResult();

		foreach ( $post_ids as $post_id ) {
			$this->process_unarchive_post( $post_id, $action, $result );
		}

		remove_filter( 'aps_unarchive_post_status', 'aps_unarchive_post_set_previous_status', 10 );

		return $result->apply_to_url( $sendback, $action );
	}

	/**
	 * Per-id processor for the archive path.
	 *
	 * Kept out of the `foreach` body in {@see bulk_archive()} so the outer
	 * loop is a clean iterate + dispatch + collect pipeline. Each guard
	 * records a bucket on $result and returns; the success path records the
	 * count + id at the bottom.
	 *
	 * @since 0.4.0
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchivableStatuses::includes()}
	 * is the canonical archivable-statuses lookup.
	 */
	private function process_archive_post( int $post_id, ArchiveAction $action, BulkActionResult $result ): void {
		$cap = $action->capability_function();

		if ( ! $cap( $post_id ) ) {
			$result->record_denied( $post_id );
			return;
		}

		if ( wp_check_post_lock( $post_id ) ) {
			$result->record_locked();
			return;
		}

		$status = get_post_status( $post_id );

		if ( false === $status ) {
			$result->record_not_found( $post_id );
			return;
		}

		if ( ! ArchivableStatuses::includes( (string) $status ) ) {
			$result->record_wrong_status( $post_id );
			return;
		}

		if ( ! $action->perform( $post_id ) ) {
			// Persist failure — bucket as wrong_status (closest fit;
			// the post existed and the cap passed but the underlying
			// status transition was rejected by wp_update_post or by
			// the aps_pre_archive_post filter).
			$result->record_wrong_status( $post_id );
			return;
		}

		$result->record( $post_id );
	}

	/**
	 * Per-id processor for the unarchive path.
	 *
	 * Kept out of the `foreach` body in {@see bulk_unarchive()}, mirroring
	 * the archive path. Unarchive has fewer guards than archive
	 * (no lock check, no status pre-check) because
	 * {@see UnarchiveOperation::perform()} already returns false for
	 * missing posts / non-archive-status posts / persist failures.
	 *
	 * @since 0.4.0
	 */
	private function process_unarchive_post( int $post_id, ArchiveAction $action, BulkActionResult $result ): void {
		$cap = $action->capability_function();

		if ( ! $cap( $post_id ) ) {
			$result->record_denied( $post_id );
			return;
		}

		if ( ! $action->perform( $post_id ) ) {
			// aps_unarchive_post() returns false when the post is
			// missing, not in 'archive' status, or wp_update_post
			// rejected the change. We can't disambiguate without a
			// second DB hit, so bucket as wrong_status — the most
			// common cause in the wild.
			$result->record_wrong_status( $post_id );
			return;
		}

		$result->record( $post_id );
	}

	/**
	 * Get the redirect URL for post actions.
	 *
	 * @since 0.4.0
	 * @param string $post_type The post type.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostListUrlBuilder::for_post_type()}
	 * is a pure-functional URL builder (hybrid pattern — static helper for
	 * stateless URL construction, DI for Hookables). The static call is the
	 * documented public surface.
	 */
	public function get_redirect_url( string $post_type ): string {
		$sendback = wp_get_referer();

		if ( ! $sendback || $this->is_edit_screen_url( $sendback ) ) {
			$sendback = PostListUrlBuilder::for_post_type( $post_type );
		}

		return remove_query_arg( self::STRIPPED_QUERY_ARGS, $sendback );
	}

	/**
	 * Check if URL is an edit screen URL.
	 *
	 * @since 0.4.0
	 * @param string $url The URL to check.
	 * @return bool
	 */
	private function is_edit_screen_url( string $url ): bool {
		return str_contains( $url, 'post.php' ) || str_contains( $url, 'post-new.php' );
	}
}
