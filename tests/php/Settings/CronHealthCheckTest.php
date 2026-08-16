<?php
/**
 * Settings\CronHealthCheck Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\CronHealthCheck
 *
 * Pure logic, no WordPress boundary — every case below is a plain PHP
 * function call, no WP_Mock stubbing required.
 */

use ArchivedPostStatus\Settings\CronHealthCheck;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\CronHealthCheck
 */
class CronHealthCheckTest extends TestCase {

	/**
	 * A sweep that ran recently, well inside the interval window, is not
	 * stale.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_false_when_last_sweep_is_recent() {
		$now        = 1_700_000_000;
		$last_sweep = $now - 100;

		$this->assertFalse( CronHealthCheck::is_stale( $last_sweep, false, $now, 300 ) );
	}

	/**
	 * A sweep that has gone quiet beyond several times the configured
	 * interval is stale.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_true_when_last_sweep_exceeds_the_multiplier() {
		$now        = 1_700_000_000;
		$last_sweep = $now - ( 300 * 3 ) - 1; // just past 3x the 300s interval.

		$this->assertTrue( CronHealthCheck::is_stale( $last_sweep, false, $now, 300 ) );
	}

	/**
	 * Exactly at the threshold is not yet stale — only strictly beyond it
	 * triggers the warning.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_false_exactly_at_the_threshold() {
		$now        = 1_700_000_000;
		$last_sweep = $now - ( 300 * 3 );

		$this->assertFalse( CronHealthCheck::is_stale( $last_sweep, false, $now, 300 ) );
	}

	/**
	 * A fresh install (never swept) with real cron available is not stale —
	 * it has not had the chance to fail yet.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_false_when_never_swept_and_cron_not_disabled() {
		$this->assertFalse( CronHealthCheck::is_stale( 0, false, 1_700_000_000, 300 ) );
	}

	/**
	 * Never swept AND DISABLE_WP_CRON is in effect: this never self-resolves
	 * — nothing will ever trigger the sweep — so it warns immediately.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_true_when_never_swept_and_disable_wp_cron_is_set() {
		$this->assertTrue( CronHealthCheck::is_stale( 0, true, 1_700_000_000, 300 ) );
	}

	/**
	 * DISABLE_WP_CRON alone does not warn once a sweep has actually run and
	 * is still recent — some hosts run a real system cron against
	 * wp-cron.php despite disabling WP's own pseudo-cron, and that is a
	 * legitimate, healthy configuration.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_false_when_disable_wp_cron_set_but_recently_swept() {
		$now        = 1_700_000_000;
		$last_sweep = $now - 100;

		$this->assertFalse( CronHealthCheck::is_stale( $last_sweep, true, $now, 300 ) );
	}

	/**
	 * A zero-or-negative configured interval must not divide-by-zero or
	 * produce a permanently-stale/never-stale result — floored to 1 second.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_floors_a_non_positive_interval_to_one_second() {
		$now        = 1_700_000_000;
		$last_sweep = $now - 10;

		$this->assertTrue( CronHealthCheck::is_stale( $last_sweep, false, $now, 0 ) );
	}

	/**
	 * A caller-supplied `$multiplier` (the `aps_schedule_stale_multiplier`
	 * filter's resolved value, per {@see \ArchivedPostStatus\Settings\SettingsPage})
	 * changes the threshold — a site with an unusual cron cadence can tune
	 * how many intervals of silence count as stale.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_honors_a_caller_supplied_multiplier() {
		$now        = 1_700_000_000;
		$last_sweep = $now - 301; // just past 1x the 300s interval.

		$this->assertFalse( CronHealthCheck::is_stale( $last_sweep, false, $now, 300 ), 'default multiplier (3x) is not yet exceeded' );
		$this->assertTrue( CronHealthCheck::is_stale( $last_sweep, false, $now, 300, 1 ), 'a multiplier of 1x is exceeded' );
	}

	/**
	 * A non-positive multiplier must not disable the check entirely —
	 * floored to 1, the same defensive shape as the interval floor above.
	 *
	 * @covers ArchivedPostStatus\Settings\CronHealthCheck::is_stale
	 */
	public function test_is_stale_floors_a_non_positive_multiplier_to_one() {
		$now        = 1_700_000_000;
		$last_sweep = $now - 301;

		$this->assertTrue( CronHealthCheck::is_stale( $last_sweep, false, $now, 300, 0 ) );
	}
}
