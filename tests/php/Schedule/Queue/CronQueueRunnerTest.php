<?php
/**
 * Schedule\Queue\CronQueueRunner Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner
 *
 * dispatch()/run_batch() always builds a real Budget via BudgetFactory --
 * these tests drive it through the real ini directives with ini_set(),
 * following the same pattern BudgetFactoryTest uses, since WP_Mock cannot
 * stub ini_get(). A neutral, generous ini pairing keeps the budget itself
 * out of every test's way; none of these tests assert on the budget's
 * exact values, only on lock/continuation behavior.
 */

use ArchivedPostStatus\Schedule\CronRegistrar;
use ArchivedPostStatus\Schedule\Queue\BatchProcessorInterface;
use ArchivedPostStatus\Schedule\Queue\BatchResult;
use ArchivedPostStatus\Schedule\Queue\Budget;
use ArchivedPostStatus\Schedule\Queue\CronQueueRunner;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner
 */
class CronQueueRunnerTest extends TestCase {

	/** @var string|false Real ini value, saved to restore after each test. */
	private $original_time_limit;

	/** @var string|false Real ini value, saved to restore after each test. */
	private $original_memory_limit;

	public function set_up() {
		parent::set_up();

		$this->original_time_limit   = ini_get( 'max_execution_time' );
		$this->original_memory_limit = ini_get( 'memory_limit' );

		// Neutral, generous budget ini for every test in this file -- see
		// the class docblock. Individual tests do not assert on Budget's
		// resolved values, so a single fixed pairing is enough.
		ini_set( 'max_execution_time', '15' );
		ini_set( 'memory_limit', '256M' );

		\WP_Mock::onFilter( 'aps_queue_time_limit' )->with( 15, 15 )->reply( 15 );
		\WP_Mock::onFilter( 'aps_queue_memory_percent' )->with( 90, 268435456 )->reply( 90 );
		\WP_Mock::userFunction( 'wp_convert_hr_to_bytes' )
			->with( '256M' )->andReturn( 268435456 );
	}

	public function tear_down() {
		ini_set( 'max_execution_time', $this->original_time_limit );
		ini_set( 'memory_limit', $this->original_memory_limit );

		parent::tear_down();
	}

	/**
	 * A Mockery double of BatchProcessorInterface reporting the 'sweep'
	 * queue name -- the only queue name CronQueueRunner has mapped to a
	 * cron hook as of this phase.
	 */
	private function sweepProcessor(): \Mockery\MockInterface {
		$processor = \Mockery::mock( BatchProcessorInterface::class );
		$processor->shouldReceive( 'queue_name' )->andReturn( 'sweep' );

		return $processor;
	}

	/**
	 * Stub the QueueLock transient pair as "free": get_transient() reports
	 * nothing held, set_transient() succeeds. The TTL is the fixed value
	 * CronQueueRunner computes from this file's neutral budget ini (15s
	 * time limit) plus its own 30s margin -- 45.
	 */
	private function mockLockFree(): void {
		\WP_Mock::userFunction( 'get_transient' )
			->with( 'aps_queue_lock_sweep' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_queue_lock_ttl' )
			->with( 45, 'sweep' )
			->reply( 45 );
		\WP_Mock::userFunction( 'set_transient' )
			->with( 'aps_queue_lock_sweep', \Mockery::type( 'int' ), 45 )
			->andReturn( true );
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::hooks
	 */
	public function test_hooks_binds_the_sweep_hook_and_the_continuation_hook() {
		$runner      = new CronQueueRunner( $this->sweepProcessor() );
		$descriptors = $runner->hooks();

		$this->assertCount( 2, $descriptors );

		$hook_names = array_map( fn( $d ) => $d->hook, $descriptors );
		$this->assertContains( CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES, $hook_names );
		$this->assertContains( CronQueueRunner::CONTINUE_HOOK, $hook_names );

		foreach ( $descriptors as $descriptor ) {
			$this->assertTrue( $descriptor->is_action() );
		}
	}

	/**
	 * A processor whose queue_name() has no entry in CronQueueRunner's
	 * cron-hook map is a programming error, not a runtime condition to
	 * degrade gracefully from.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::hooks
	 */
	public function test_hooks_throws_for_a_processor_with_an_unmapped_queue_name() {
		$processor = \Mockery::mock( BatchProcessorInterface::class );
		$processor->shouldReceive( 'queue_name' )->andReturn( 'unmapped-queue' );

		$runner = new CronQueueRunner( $processor );

		$this->expectException( \LogicException::class );

		$runner->hooks();
	}

	// -----------------------------------------------------------------------
	// dispatch() — the lock
	// -----------------------------------------------------------------------

	/**
	 * When the lock is already held by another run, dispatch() returns
	 * immediately without ever calling process_batch().
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::dispatch
	 */
	public function test_dispatch_returns_without_processing_when_lock_is_held() {
		\WP_Mock::userFunction( 'get_transient' )
			->with( 'aps_queue_lock_sweep' )->andReturn( time() );
		\WP_Mock::userFunction( 'set_transient' )->never();

		$processor = $this->sweepProcessor();
		$processor->shouldNotReceive( 'process_batch' );

		( new CronQueueRunner( $processor ) )->dispatch( $processor );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The lock is released even when process_batch() throws -- a fatal
	 * batch must not wedge the queue for every run after it.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::dispatch
	 */
	public function test_dispatch_releases_the_lock_even_when_process_batch_throws() {
		$this->mockLockFree();
		\WP_Mock::userFunction( 'delete_transient' )
			->once()->with( 'aps_queue_lock_sweep' );

		$processor = $this->sweepProcessor();
		$processor->shouldReceive( 'process_batch' )
			->once()
			->andThrow( new \RuntimeException( 'boom' ) );

		$runner = new CronQueueRunner( $processor );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'boom' );

		try {
			$runner->dispatch( $processor );
		} finally {
			// Nothing further to assert here — the delete_transient()
			// expectation above is what proves release() ran; Mockery
			// verifies it during tear_down() regardless of this catch.
		}
	}

	// -----------------------------------------------------------------------
	// dispatch() — continuation decisions
	// -----------------------------------------------------------------------

	/**
	 * Work remaining after a batch schedules a single continuation event on
	 * the distinct aps_continue_queue hook, carrying the queue name and a
	 * batch index that advances from the fresh-dispatch value of 0.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::dispatch
	 */
	public function test_dispatch_schedules_a_continuation_when_work_remains() {
		$this->mockLockFree();
		\WP_Mock::userFunction( 'delete_transient' )->once();

		$result = new BatchResult( 10, 0, 5, false );

		$processor = $this->sweepProcessor();
		$processor->shouldReceive( 'process_batch' )
			->once()
			->with( \Mockery::type( Budget::class ) )
			->andReturn( $result );

		\WP_Mock::expectAction( 'aps_queue_batch_completed', $result, 'sweep' );
		\WP_Mock::userFunction( 'update_option' )
			->once()->with( 'aps_last_sweep', \Mockery::type( 'int' ), false )->andReturn( true );

		\WP_Mock::onFilter( 'aps_queue_should_continue' )
			->with( true, $result, 'sweep' )
			->reply( true );

		\WP_Mock::userFunction( 'wp_schedule_single_event' )
			->once()
			->with( \Mockery::type( 'int' ), CronQueueRunner::CONTINUE_HOOK, array( 'sweep', 1 ) )
			->andReturn( true );

		( new CronQueueRunner( $processor ) )->dispatch( $processor );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `aps_queue_should_continue` returning false suppresses the
	 * continuation even though work remains.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::dispatch
	 */
	public function test_dispatch_suppresses_continuation_when_filter_returns_false() {
		$this->mockLockFree();
		\WP_Mock::userFunction( 'delete_transient' )->once();

		$result = new BatchResult( 10, 0, 5, false );

		$processor = $this->sweepProcessor();
		$processor->shouldReceive( 'process_batch' )->once()->andReturn( $result );

		\WP_Mock::expectAction( 'aps_queue_batch_completed', $result, 'sweep' );
		\WP_Mock::userFunction( 'update_option' )->once()->andReturn( true );

		\WP_Mock::onFilter( 'aps_queue_should_continue' )
			->with( true, $result, 'sweep' )
			->reply( false );

		\WP_Mock::userFunction( 'wp_schedule_single_event' )->never();

		( new CronQueueRunner( $processor ) )->dispatch( $processor );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A drained queue fires aps_queue_drained and schedules no continuation
	 * at all -- the filter is never even consulted once nothing remains.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::dispatch
	 */
	public function test_dispatch_fires_drained_action_and_schedules_no_continuation() {
		$this->mockLockFree();
		\WP_Mock::userFunction( 'delete_transient' )->once();

		$result = new BatchResult( 3, 0, 0, false );

		$processor = $this->sweepProcessor();
		$processor->shouldReceive( 'process_batch' )->once()->andReturn( $result );

		\WP_Mock::expectAction( 'aps_queue_batch_completed', $result, 'sweep' );
		\WP_Mock::userFunction( 'update_option' )->once()->andReturn( true );

		\WP_Mock::expectAction( 'aps_queue_drained', 'sweep' );
		\WP_Mock::userFunction( 'wp_schedule_single_event' )->never();

		( new CronQueueRunner( $processor ) )->dispatch( $processor );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// run() — the cron callback wrapper
	// -----------------------------------------------------------------------

	/**
	 * run() is the zero-arg cron callback hooks() binds; it must drive this
	 * instance's own constructed processor through the same dispatch path.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::run
	 */
	public function test_run_dispatches_the_constructed_processor() {
		$this->mockLockFree();
		\WP_Mock::userFunction( 'delete_transient' )->once();

		$result = new BatchResult( 1, 0, 0, false );

		$processor = $this->sweepProcessor();
		$processor->shouldReceive( 'process_batch' )->once()->andReturn( $result );

		\WP_Mock::expectAction( 'aps_queue_batch_completed', $result, 'sweep' );
		\WP_Mock::userFunction( 'update_option' )->once()->andReturn( true );
		\WP_Mock::expectAction( 'aps_queue_drained', 'sweep' );

		( new CronQueueRunner( $processor ) )->run();

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// handle_continuation()
	// -----------------------------------------------------------------------

	/**
	 * A continuation event addressed to a different queue than the one this
	 * instance drives is ignored outright -- no lock touched, no batch run.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::handle_continuation
	 */
	public function test_handle_continuation_ignores_a_different_queue() {
		\WP_Mock::userFunction( 'get_transient' )->never();

		$processor = $this->sweepProcessor();
		$processor->shouldNotReceive( 'process_batch' );

		( new CronQueueRunner( $processor ) )->handle_continuation( 'stamp', 1 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A continuation event addressed to this instance's own queue runs the
	 * batch and, if it schedules a further continuation, advances the
	 * batch index by one from whatever this call received.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\CronQueueRunner::handle_continuation
	 */
	public function test_handle_continuation_advances_the_batch_index_for_its_own_queue() {
		$this->mockLockFree();
		\WP_Mock::userFunction( 'delete_transient' )->once();

		$result = new BatchResult( 10, 0, 5, false );

		$processor = $this->sweepProcessor();
		$processor->shouldReceive( 'process_batch' )->once()->andReturn( $result );

		\WP_Mock::expectAction( 'aps_queue_batch_completed', $result, 'sweep' );
		\WP_Mock::userFunction( 'update_option' )->once()->andReturn( true );
		\WP_Mock::onFilter( 'aps_queue_should_continue' )->with( true, $result, 'sweep' )->reply( true );

		\WP_Mock::userFunction( 'wp_schedule_single_event' )
			->once()
			->with( \Mockery::type( 'int' ), CronQueueRunner::CONTINUE_HOOK, array( 'sweep', 4 ) )
			->andReturn( true );

		( new CronQueueRunner( $processor ) )->handle_continuation( 'sweep', 3 );

		$this->addToAssertionCount( 1 );
	}
}
