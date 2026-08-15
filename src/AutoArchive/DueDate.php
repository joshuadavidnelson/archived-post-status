<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Turns a resolved cascade rule into the instant a post becomes due.
 *
 * `max( basis + days * SECONDS_PER_DAY, now + grace_seconds )` is the whole
 * backlog-safety mechanism, in one expression. A post published today under
 * a 365-day rule stamps a year out — the due date wins because it is far
 * beyond the grace floor. A five-year-old post whose due date passed long
 * ago instead stamps at `now + grace`, not at the already-past due instant,
 * so it appears in the list column for a week (by default) before it
 * actually archives. Without this floor, enabling a rule on a decade of
 * content would empty the site on the very next cron tick.
 *
 * Defines its own {@see SECONDS_PER_DAY} rather than using WordPress's
 * `DAY_IN_SECONDS` so this class has zero WordPress dependency, per this
 * phase's scope.
 *
 * @since 0.5.0
 */
final class DueDate {

	/**
	 * Seconds in a day. Defined locally rather than reusing WordPress's
	 * `DAY_IN_SECONDS` so this class stays free of any WordPress constant.
	 *
	 * @since 0.5.0
	 */
	private const SECONDS_PER_DAY = 86400;

	/**
	 * Compute the instant a post becomes due for auto-archive.
	 *
	 * @since 0.5.0
	 * @param int $basis         Epoch the days count runs from (post_date or
	 *                           post_modified, per `auto_archive_age_basis`).
	 * @param int $days          Days after `$basis` the resolved rule says
	 *                           to archive at.
	 * @param int $now           Current epoch.
	 * @param int $grace_seconds Minimum lead time from `$now` before the
	 *                           stamped instant may occur — the backlog
	 *                           floor.
	 * @return int The stamp instant, as a UTC epoch.
	 */
	public static function stamp_at( int $basis, int $days, int $now, int $grace_seconds ): int {
		$due = $basis + $days * self::SECONDS_PER_DAY;

		return max( $due, $now + $grace_seconds );
	}
}
