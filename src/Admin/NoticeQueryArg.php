<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The eight query-arg names that carry a bulk archive/unarchive result
 * across the post-list redirect: the action counter, the id list consumed
 * by the undo link, and the four per-reason skip buckets.
 *
 * Single source of truth for names that previously had to be hand-copied,
 * identically, across {@see PostList::query_vars()},
 * {@see PostList::removable_query_args()}, {@see BulkActionHandler} (the
 * args stripped before a fresh redirect), and {@see BulkActionResult}
 * (the args written onto it) — a human-maintained sync that this enum
 * removes the need for.
 *
 * @since 0.4.0
 */
enum NoticeQueryArg: string {

	case Archived    = 'archived';
	case Unarchived  = 'unarchived';
	case Ids         = 'ids';
	case Locked      = 'locked';
	case Denied      = 'denied';
	case NotFound    = 'not_found';
	case WrongStatus = 'wrong_status';
	case Skipped     = 'skipped';

	/**
	 * All eight arg names, in declaration order.
	 *
	 * Derived from {@see self::cases()} rather than listed again, so the
	 * order can never drift from the case declarations above.
	 *
	 * @since 0.4.0
	 * @return string[]
	 */
	public static function values(): array {
		return array_map( static fn ( self $case ) => $case->value, self::cases() );
	}
}
