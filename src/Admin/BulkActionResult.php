<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * Accumulates the results of a bulk archive/unarchive operation and
 * produces the redirect URL with appropriate query args.
 *
 * Reason-bucketed counters (denied / not_found / wrong_status) let
 * {@see BulkActionHandler} continue a batch on per-item failure instead of
 * calling wp_die() mid-loop.
 *
 * The `skipped=N` aggregate is emitted but never displayed — {@see NoticeBuilder}
 * renders one notice per individual bucket. It stays in the URL pending a
 * decision on whether an aggregate notice should exist.
 *
 * @since 0.4.0
 */
final class BulkActionResult {

	/**
	 * Maximum number of ids folded into the redirect URL's `ids=` arg. Beyond
	 * this the list is omitted and the notice degrades to a plain count.
	 *
	 * Browsers, proxies, and servers commonly cap request lines at 2000-8000
	 * characters. At 7 digits per id, 200 comma-joined ids is ~1600 characters,
	 * leaving headroom for the other args under even the tightest of those.
	 *
	 * @since 0.4.0
	 * @var int
	 */
	private const MAX_IDS_IN_URL = 200;

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
	 * Distinct from {@see record_locked()} and {@see record_denied()} so the
	 * notice can explain the exact reason.
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
	 * Adds the action's query arg (e.g. 'archived=3'), the non-zero skip
	 * buckets, an aggregate `skipped=N`, and 'ids'.
	 *
	 * 'ids' is dropped once the count exceeds {@see MAX_IDS_IN_URL}.
	 * {@see NoticeBuilder} already renders a count-only notice when `ids` is
	 * absent, so a capped batch degrades to that rather than risking a
	 * URL-length redirect failure or a truncated undo link.
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

		if ( $this->ids && count( $this->ids ) <= self::MAX_IDS_IN_URL ) {
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
