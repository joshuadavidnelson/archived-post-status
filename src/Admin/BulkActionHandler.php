<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * Handle the bulk archive/unarchive actions on the post list table.
 *
 * Injected into both PostList and PostActionHandler. Receives post ids and
 * the sendback URL from `handle_bulk_actions-edit-{type}` and returns the
 * redirect URL for the post-list refresh.
 *
 * Per-item failures are bucketed by reason into a {@see BulkActionResult} and
 * surfaced through the redirect URL for {@see NoticeBuilder} to render. The
 * loop continues on every per-item failure; only the outer gate at
 * {@see handle()} entry aborts the batch.
 *
 * @since 0.4.0
 */
final class BulkActionHandler {

	/**
	 * Handle the bulk action filter callback.
	 *
	 * Entry point for `handle_bulk_actions-edit-{type}`: returns the sendback
	 * URL with the result counters appended.
	 *
	 * @since 0.4.0
	 * @param string            $sendback The redirect URL.
	 * @param string            $doaction The action being taken.
	 * @param array<int, mixed> $post_ids The items to take the action on,
	 *                                    as received from WordPress — entries
	 *                                    are not guaranteed to be int.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- backed-enum hydration and canonical query-arg-name source.
	 */
	public function handle( string $sendback, string $doaction, array $post_ids ): string {
		if ( empty( $post_ids ) ) {
			return $sendback;
		}

		// Core's JS-disabled `ids=` fallback on edit.php populates $post_ids
		// with a bare `explode( ',', … )` — no intval, unlike the `post[]`
		// checkbox path. Without this, non-numeric junk reaches the int-typed
		// per-id processors below and throws, killing the whole batch.
		$post_ids = array_filter( array_map( 'absint', $post_ids ) );

		if ( empty( $post_ids ) ) {
			return $sendback;
		}

		try {
			$action = ArchiveAction::from( $doaction );
		} catch ( \ValueError $e ) {
			return $sendback; // Not our action
		}

		// No screen-level capability pre-gate: capabilities are ownership-aware
		// (a post's own author may act on it), so the per-post check inside
		// each loop below is the only correct gate.
		$sendback = remove_query_arg( NoticeQueryArg::values(), $sendback );

		return match ( $action ) {
			ArchiveAction::Archive => $this->bulk_archive( $post_ids, $sendback ),
			ArchiveAction::Unarchive => $this->bulk_unarchive( $post_ids, $sendback ),
		};
	}

	/**
	 * Handle archive bulk action.
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
	 * The undo override registers at `PHP_INT_MAX` — the priority reserved for
	 * the plugin's own overrides on `aps_unarchive_post_status` — so a site
	 * that registered the same callback at the default priority for its own
	 * reasons is never touched by the paired `remove_filter()`.
	 *
	 * @since 0.4.0
	 * @param array<int, int> $post_ids Array of post IDs.
	 * @param string          $sendback The redirect URL.
	 * @return string
	 */
	private function bulk_unarchive( array $post_ids, string $sendback ): string {
		$is_undo = isset( $_GET['doaction'] ) && 'undo' === $_GET['doaction']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $is_undo ) {
			add_filter( 'aps_unarchive_post_status', 'aps_unarchive_post_set_previous_status', PHP_INT_MAX, 3 );
		}

		$action = ArchiveAction::Unarchive;
		$result = new BulkActionResult();

		foreach ( $post_ids as $post_id ) {
			$this->process_unarchive_post( $post_id, $action, $result );
		}

		if ( $is_undo ) {
			remove_filter( 'aps_unarchive_post_status', 'aps_unarchive_post_set_previous_status', PHP_INT_MAX );
		}

		return $result->apply_to_url( $sendback, $action );
	}

	/**
	 * Per-id processor for the archive path.
	 *
	 * @since 0.4.0
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical archivable-statuses lookup.
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
			// The post existed and the cap passed, so the transition was
			// rejected by wp_update_post or the aps_pre_archive_post filter.
			// wrong_status is the closest bucket.
			$result->record_wrong_status( $post_id );
			return;
		}

		$result->record( $post_id );
	}

	/**
	 * Per-id processor for the unarchive path.
	 *
	 * One guard fewer than the archive path: no status pre-check, because
	 * {@see \ArchivedPostStatus\Archive\UnarchiveOperation::perform()} already
	 * returns false for missing posts, non-archived posts, and persist failures.
	 *
	 * @since 0.4.0
	 */
	private function process_unarchive_post( int $post_id, ArchiveAction $action, BulkActionResult $result ): void {
		$cap = $action->capability_function();

		if ( ! $cap( $post_id ) ) {
			$result->record_denied( $post_id );
			return;
		}

		if ( wp_check_post_lock( $post_id ) ) {
			$result->record_locked();
			return;
		}

		if ( ! $action->perform( $post_id ) ) {
			// Missing post, not archived, or a rejected write — indistinguishable
			// without a second DB hit, so bucket as the most common cause.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- pure-functional URL builder and canonical query-arg-name source.
	 */
	public function get_redirect_url( string $post_type ): string {
		$sendback = wp_get_referer();

		if ( ! $sendback || $this->is_edit_screen_url( $sendback ) ) {
			$sendback = PostListUrlBuilder::for_post_type( $post_type );
		}

		return remove_query_arg( NoticeQueryArg::values(), $sendback );
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
