<?php

namespace ArchivedPostStatus\Admin;

use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * Accumulates the results of a bulk archive/unarchive operation and
 * produces the redirect URL with appropriate query args.
 *
 * Separating count accumulation from URL construction keeps each concern
 * at a single level of abstraction and eliminates branching in PostList.
 *
 * Reason-bucketed counters (denied / not_found / wrong_status) exist so
 * {@see BulkActionHandler} can continue a batch on per-item failure rather
 * than calling wp_die() mid-loop. The
 * URL pipeline emits a `skipped=N` query arg whenever the total of the
 * skip buckets is non-zero.
 *
 * That arg is currently emitted but not displayed: {@see NoticeBuilder}
 * reads the individual buckets only and renders one notice per reason, with
 * no aggregate "skipped" line. Whether an aggregate notice should exist is
 * an open decision — until it is made, `skipped` is an accurate but unused
 * value in the URL.
 *
 * @since 0.4.0
 */
final class BulkActionResult {

	private int $count        = 0;
	private int $locked       = 0;
	private int $denied       = 0;
	private int $not_found    = 0;
	private int $wrong_status = 0;
	/** @var int[] */
	private array $ids = array();

	/**
	 * Record a successful action on a post.
	 *
	 * @param int $post_id The post ID that was successfully processed.
	 */
	public function record( int $post_id ): void {
		++$this->count;
		$this->ids[] = $post_id;
	}

	/**
	 * Record a post that was skipped because it is locked by another user.
	 */
	public function record_locked(): void {
		++$this->locked;
	}

	/**
	 * Record a post that was skipped because the current user lacks the
	 * capability to perform the action on it.
	 *
	 * Per-item cap failures do not halt the batch via wp_die(); the outer
	 * cap gate in {@see BulkActionHandler::handle()} remains the only
	 * fail-closed exit.
	 *
	 * The $post_id parameter is part of the locked public signature and is
	 * reserved for future per-id logging / structured audit output without
	 * breaking the API contract — callers already pass the id at every
	 * site, so adding it now keeps a single insertion point later.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $post_id is part of
	 * the locked public signature; reserved for future per-id logging.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID that the capability was denied for.
	 */
	public function record_denied( int $post_id ): void {
		++$this->denied;
	}

	/**
	 * Record a post that was skipped because it does not exist (deleted
	 * between bulk-select and dispatch) or is of an unsupported post type.
	 *
	 * The $post_id parameter is part of the locked public signature and is
	 * reserved for future per-id logging without breaking the API contract.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $post_id is part of
	 * the locked public signature; reserved for future per-id logging.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID that could not be located.
	 */
	public function record_not_found( int $post_id ): void {
		++$this->not_found;
	}

	/**
	 * Record a post that was skipped because its status is not archivable
	 * (e.g. already archived, or in a status that the
	 * `aps_archivable_statuses` filter excludes).
	 *
	 * Distinct from {@see record_locked()} (post lock) and {@see record_denied()}
	 * (capability) so the notice can explain the exact reason.
	 *
	 * The $post_id parameter is part of the locked public signature and is
	 * reserved for future per-id logging without breaking the API contract.
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $post_id is part of
	 * the locked public signature; reserved for future per-id logging.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID whose status disqualified it.
	 */
	public function record_wrong_status( int $post_id ): void {
		++$this->wrong_status;
	}

	/**
	 * Apply result counts to a redirect URL as query args.
	 *
	 * Adds the action's query arg (e.g. 'archived=3'), and optional
	 * 'locked', 'denied', 'not_found', 'wrong_status', and
	 * 'ids' args when applicable. Also emits a single aggregate
	 * `skipped=N` arg = sum of every skip bucket.
	 *
	 * No notice is built from `skipped`: the notice layer reads the
	 * individual buckets and renders one line per reason. The aggregate is
	 * carried in the URL only, pending a decision on whether to display it.
	 */
	public function apply_to_url( string $url, ArchiveAction $action ): string {
		$url = add_query_arg( $action->query_arg(), $this->count, $url );

		$skipped = $this->skipped_count();

		if ( $skipped ) {
			$url = add_query_arg( 'skipped', $skipped, $url );
		}

		if ( $this->locked ) {
			$url = add_query_arg( 'locked', $this->locked, $url );
		}

		if ( $this->denied ) {
			$url = add_query_arg( 'denied', $this->denied, $url );
		}

		if ( $this->not_found ) {
			$url = add_query_arg( 'not_found', $this->not_found, $url );
		}

		if ( $this->wrong_status ) {
			$url = add_query_arg( 'wrong_status', $this->wrong_status, $url );
		}

		if ( $this->ids ) {
			$url = add_query_arg( 'ids', implode( ',', $this->ids ), $url );
		}

		return $url;
	}

	public function count(): int             { return $this->count; }
	public function locked(): int            { return $this->locked; }
	public function denied_count(): int      { return $this->denied; }
	public function not_found_count(): int   { return $this->not_found; }
	public function wrong_status_count(): int { return $this->wrong_status; }

	/**
	 * Aggregate skip count across every reason bucket.
	 *
	 * @since 0.4.0
	 */
	public function skipped_count(): int {
		return $this->locked
			+ $this->denied
			+ $this->not_found
			+ $this->wrong_status;
	}

	/** @return int[] */
	public function ids(): array { return $this->ids; }
}
