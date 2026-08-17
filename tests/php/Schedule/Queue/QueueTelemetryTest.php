<?php
/**
 * Schedule\Queue\QueueTelemetry Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry
 *
 * These pin the contract a REPLACEMENT runner depends on. CronQueueRunner has
 * its own tests proving it delegates here; the point of this file is that the
 * announcements and the last-run record are callable, and correct, from
 * anywhere — including an Action Scheduler adapter that never touches
 * CronQueueRunner at all.
 */

use ArchivedPostStatus\Schedule\Queue\BatchResult;
use ArchivedPostStatus\Schedule\Queue\QueueTelemetry;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry
 */
class QueueTelemetryTest extends TestCase {

	/**
	 * The option key is per queue, so a second queue cannot overwrite the
	 * first's timestamp and leave the health notice reading the wrong one.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry::last_run_option
	 */
	public function test_last_run_option_is_scoped_per_queue() {
		$this->assertSame( 'aps_last_sweep', QueueTelemetry::last_run_option( 'sweep' ) );
		$this->assertSame( 'aps_last_stamp', QueueTelemetry::last_run_option( 'stamp' ) );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry::last_run
	 */
	public function test_last_run_reads_the_recorded_epoch() {
		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'aps_last_sweep', 0 )
			->andReturn( 1700000000 );

		$this->assertSame( 1700000000, QueueTelemetry::last_run( 'sweep' ) );
	}

	/**
	 * A queue that has never run reads 0 rather than false, so the health
	 * notice can treat "never" and "long ago" the same way.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry::last_run
	 */
	public function test_last_run_is_zero_when_the_queue_has_never_run() {
		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'aps_last_stamp', 0 )
			->andReturn( false );

		$this->assertSame( 0, QueueTelemetry::last_run( 'stamp' ) );
	}

	/**
	 * batch_completed() both announces and records. A runner calling it gets
	 * the monitoring hook AND the health-notice timestamp; a runner that
	 * reproduced only one of the two would leave the other silently dead,
	 * which is the whole reason this class exists.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry::batch_completed
	 */
	public function test_batch_completed_fires_the_action_and_records_the_run() {
		$result = new BatchResult( 5, 0, 12, false );

		\WP_Mock::expectAction( 'aps_queue_batch_completed', $result, 'sweep' );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'aps_last_sweep', \WP_Mock\Functions::type( 'int' ), false )
			->andReturn( true );

		QueueTelemetry::batch_completed( $result, 'sweep' );

		// WP_Mock verifies the expectation in tearDown(), which PHPUnit cannot
		// see -- without this the test is reported risky despite genuinely
		// asserting. Proven load-bearing: removing the do_action() fails it.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Autoload must stay off: this option is read only by the settings
	 * screen's health notice, never on a front-end request.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry::batch_completed
	 */
	public function test_batch_completed_records_with_autoload_disabled() {
		$result = new BatchResult( 1, 0, 0, false );

		\WP_Mock::expectAction( 'aps_queue_batch_completed', $result, 'stamp' );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->withArgs(
				static function ( $key, $value, $autoload ) {
					return 'aps_last_stamp' === $key && false === $autoload;
				}
			)
			->andReturn( true );

		QueueTelemetry::batch_completed( $result, 'stamp' );

		// WP_Mock verifies the expectation in tearDown(), which PHPUnit cannot
		// see -- without this the test is reported risky despite genuinely
		// asserting. Proven load-bearing: removing the do_action() fails it.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueTelemetry::drained
	 */
	public function test_drained_fires_the_action_for_that_queue() {
		\WP_Mock::expectAction( 'aps_queue_drained', 'sweep' );

		QueueTelemetry::drained( 'sweep' );

		// WP_Mock verifies the expectation in tearDown(), which PHPUnit cannot
		// see -- without this the test is reported risky despite genuinely
		// asserting. Proven load-bearing: removing the do_action() fails it.
		$this->addToAssertionCount( 1 );
	}
}
