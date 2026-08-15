<?php
/**
 * AutoArchive\DueDate Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\DueDate
 *
 * DueDate::stamp_at() is the plan's §4.4 backlog-safety mechanism in one
 * expression: max( basis + days * SECONDS_PER_DAY, now + grace_seconds ).
 * These tests pin both halves of that max() and the exact point where they
 * cross, because a boundary flip here (min() for max(), or a dropped grace
 * term) is precisely the class of bug that empties a site on the next cron
 * tick once a rule is enabled over old content.
 */

use ArchivedPostStatus\AutoArchive\DueDate;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\DueDate
 */
class DueDateTest extends TestCase {

	private const SECONDS_PER_DAY = 86400;

	/**
	 * A post published today under a long rule stamps out at basis + days —
	 * "due" wins because it is far beyond now + grace.
	 */
	public function test_stamp_at_a_fresh_post_the_due_date_wins() {
		$now   = 1_700_000_000;
		$basis = $now;
		$days  = 365;
		$grace = 7 * self::SECONDS_PER_DAY;

		$stamp = DueDate::stamp_at( $basis, $days, $now, $grace );

		$this->assertSame( $basis + $days * self::SECONDS_PER_DAY, $stamp );
	}

	/**
	 * A five-year-old post whose due date has long since passed stamps at
	 * now + grace, not at the (already-past) due instant — this is the
	 * entire mechanism that stops a newly-enabled rule from emptying a
	 * site of a decade of content on the next sweep.
	 */
	public function test_stamp_at_a_long_overdue_post_now_plus_grace_wins() {
		$now   = 1_700_000_000;
		$basis = $now - ( 5 * 365 * self::SECONDS_PER_DAY );
		$days  = 30;
		$grace = 7 * self::SECONDS_PER_DAY;

		$stamp = DueDate::stamp_at( $basis, $days, $now, $grace );

		$this->assertSame( $now + $grace, $stamp );
		$this->assertGreaterThan( $basis + $days * self::SECONDS_PER_DAY, $stamp );
	}

	/**
	 * The exact instant where due and now + grace coincide: max() of two
	 * equal values is that value, either way round is correct here.
	 */
	public function test_stamp_at_the_exact_boundary_where_due_equals_now_plus_grace() {
		$now   = 1_700_000_000;
		$grace = 7 * self::SECONDS_PER_DAY;
		$days  = 3;
		// Choose basis so that basis + days * SECONDS_PER_DAY === now + grace exactly.
		$basis = ( $now + $grace ) - ( $days * self::SECONDS_PER_DAY );

		$stamp = DueDate::stamp_at( $basis, $days, $now, $grace );

		$this->assertSame( $now + $grace, $stamp );
		$this->assertSame( $basis + $days * self::SECONDS_PER_DAY, $stamp );
	}

	/**
	 * With grace at zero, an overdue post stamps at exactly now — the floor
	 * degenerates to "archive on the next sweep" rather than adding any
	 * buffer, per a site that has opted out of the backlog-safety window.
	 */
	public function test_stamp_at_grace_of_zero_overdue_post_stamps_at_now() {
		$now   = 1_700_000_000;
		$basis = $now - ( 10 * 365 * self::SECONDS_PER_DAY );
		$days  = 30;

		$stamp = DueDate::stamp_at( $basis, $days, $now, 0 );

		$this->assertSame( $now, $stamp );
	}

	/**
	 * With grace at zero and a fresh post, due still wins outright since it
	 * is still ahead of now.
	 */
	public function test_stamp_at_grace_of_zero_fresh_post_due_still_wins() {
		$now   = 1_700_000_000;
		$basis = $now;
		$days  = 10;

		$stamp = DueDate::stamp_at( $basis, $days, $now, 0 );

		$this->assertSame( $basis + $days * self::SECONDS_PER_DAY, $stamp );
	}

	/**
	 * days = 0 is a valid input (a rule that archives at the basis instant
	 * itself); the grace floor still applies if now has already passed it.
	 */
	public function test_stamp_at_zero_days_basis_in_the_past_still_respects_grace_floor() {
		$now   = 1_700_000_000;
		$basis = $now - 100;
		$grace = 50;

		$stamp = DueDate::stamp_at( $basis, 0, $now, $grace );

		$this->assertSame( $now + $grace, $stamp );
	}
}
