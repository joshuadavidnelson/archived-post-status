<?php
/**
 * Schedule\ScheduleTime Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\ScheduleTime
 *
 * ScheduleTime is the only place a wall clock becomes a UTC epoch or back,
 * so these tests deliberately run against a non-UTC site timezone
 * (America/New_York) rather than UTC — a test that only passes under UTC
 * would not catch a broken timezone conversion, since UTC has no offset to
 * get wrong. Expected epochs are independently verified with `date(1)` and
 * a bare DateTimeImmutable call (see the phase report), not derived from
 * the SUT.
 */

use ArchivedPostStatus\Schedule\ScheduleTime;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\ScheduleTime
 */
class ScheduleTimeTest extends TestCase {

	/**
	 * Stub wp_timezone() to a real, non-UTC DateTimeZone.
	 */
	private function mockSiteTimezone( string $timezone = 'America/New_York' ): void {
		\WP_Mock::userFunction( 'wp_timezone' )
			->andReturn( new \DateTimeZone( $timezone ) );
	}

	// -----------------------------------------------------------------------
	// to_timestamp() — non-UTC correctness
	// -----------------------------------------------------------------------

	/**
	 * A "Y-m-d\TH:i" datetime-local string parses in the site's timezone to
	 * the correct UTC epoch. 2024-06-15 is EDT (UTC-4): 10:30 local is
	 * 14:30 UTC, epoch 1718461800.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_converts_datetime_local_format_in_non_utc_timezone() {
		$this->mockSiteTimezone();

		$this->assertSame( 1718461800, ScheduleTime::to_timestamp( '2024-06-15T10:30' ) );
	}

	/**
	 * The alternate "Y-m-d H:i:s" format parses to the same instant as the
	 * equivalent datetime-local string.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_converts_full_datetime_string_format() {
		$this->mockSiteTimezone();

		$this->assertSame( 1718461800, ScheduleTime::to_timestamp( '2024-06-15 10:30:00' ) );
	}

	/**
	 * DST boundary: 2024-03-09 is still EST (UTC-5); 2024-03-11 — after the
	 * US spring-forward on 2024-03-10 — is EDT (UTC-4). The same 12:00 local
	 * wall clock on either side of the transition must produce epochs one
	 * hour apart from what a fixed-offset conversion would give, proving
	 * to_timestamp() re-resolves the offset per date rather than caching it.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_applies_correct_offset_on_either_side_of_dst_boundary() {
		$this->mockSiteTimezone();

		$before_dst = ScheduleTime::to_timestamp( '2024-03-09T12:00' ); // EST, UTC-5.
		$after_dst  = ScheduleTime::to_timestamp( '2024-03-11T12:00' ); // EDT, UTC-4.

		$this->assertSame( 1710003600, $before_dst );
		$this->assertSame( 1710172800, $after_dst );

		// Two calendar days apart, minus the one hour the spring-forward
		// clock skipped: 172800 - 3600 = 169200.
		$this->assertSame( 169200, $after_dst - $before_dst );
	}

	// -----------------------------------------------------------------------
	// to_timestamp() — malformed input
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_returns_null_for_garbage_input() {
		$this->mockSiteTimezone();

		$this->assertNull( ScheduleTime::to_timestamp( 'not-a-date' ) );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_returns_null_for_empty_string() {
		$this->mockSiteTimezone();

		$this->assertNull( ScheduleTime::to_timestamp( '' ) );
	}

	/**
	 * Out-of-range components (month 13, hour 99) must not be silently
	 * rolled forward into a nearby valid date — the defining behavior this
	 * class exists to prevent.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_returns_null_for_out_of_range_components() {
		$this->mockSiteTimezone();

		$this->assertNull( ScheduleTime::to_timestamp( '2024-13-45T99:99' ) );
	}

	/**
	 * A calendar date that does not exist (February 30) is rejected rather
	 * than coerced into March.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_returns_null_for_nonexistent_calendar_date() {
		$this->mockSiteTimezone();

		$this->assertNull( ScheduleTime::to_timestamp( '2024-02-30T10:30' ) );
	}

	/**
	 * Trailing characters after a structurally valid match are rejected,
	 * not silently ignored.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp
	 */
	public function test_to_timestamp_returns_null_for_trailing_characters() {
		$this->mockSiteTimezone();

		$this->assertNull( ScheduleTime::to_timestamp( '2024-06-15T10:30extra' ) );
	}

	// -----------------------------------------------------------------------
	// to_local()
	// -----------------------------------------------------------------------

	/**
	 * to_local() is the inverse of to_timestamp(): the same UTC epoch
	 * round-trips back to the original local wall-clock string.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_local
	 */
	public function test_to_local_formats_utc_epoch_as_site_timezone_datetime_local_string() {
		$this->mockSiteTimezone();

		$this->assertSame( '2024-06-15T10:30', ScheduleTime::to_local( 1718461800 ) );
	}

	/**
	 * to_local() reflects the DST-adjusted offset just like to_timestamp() —
	 * the same epoch used for the after-DST boundary case above round-trips
	 * back to 12:00 local, not 11:00 or 13:00.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_local
	 */
	public function test_to_local_round_trips_the_dst_boundary_epoch() {
		$this->mockSiteTimezone();

		$this->assertSame( '2024-03-11T12:00', ScheduleTime::to_local( 1710172800 ) );
	}

	// -----------------------------------------------------------------------
	// to_display()
	// -----------------------------------------------------------------------

	/**
	 * to_display() formats through wp_date() using the site's date_format +
	 * time_format options, joined by a space.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleTime::to_display
	 */
	public function test_to_display_formats_via_wp_date_with_site_date_and_time_format_options() {
		\WP_Mock::userFunction( 'get_option' )
			->with( 'date_format' )
			->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'time_format' )
			->andReturn( 'g:i a' );
		\WP_Mock::userFunction( 'wp_date' )
			->once()
			->with( 'F j, Y g:i a', 1718461800 )
			->andReturn( 'June 15, 2024 10:30 am' );

		$this->assertSame( 'June 15, 2024 10:30 am', ScheduleTime::to_display( 1718461800 ) );
	}
}
