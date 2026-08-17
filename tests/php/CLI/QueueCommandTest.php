<?php
/**
 * QueueCommand tests.
 *
 * Both processors are Mockery doubles of BatchProcessorInterface — Sweeper
 * and RuleStamper are final, so only the interface can be doubled, same as
 * CronQueueRunnerTest.
 *
 * dispatch() always builds a real Budget via BudgetFactory and a real
 * QueueLock -- these tests fix the ini directives with ini_set(), following
 * the same pattern CronQueueRunnerTest uses, since WP_Mock cannot stub
 * ini_get(). The default set_up() lock stubs treat the lock as always free
 * and always released; individual tests override them to exercise the
 * "another run holds the lock" path.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\QueueCommand
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\CLI\QueueCommand;
	use ArchivedPostStatus\Schedule\Queue\BatchProcessorInterface;
	use ArchivedPostStatus\Schedule\Queue\BatchResult;

	/**
	 * @since 0.5.0
	 * @covers ArchivedPostStatus\CLI\QueueCommand
	 */
	class QueueCommandTest extends TestCase {

		/** @var \Mockery\MockInterface&BatchProcessorInterface */
		private $sweeper;

		/** @var \Mockery\MockInterface&BatchProcessorInterface */
		private $stamper;

		private QueueCommand $cmd;

		/** @var string|false Real ini value, saved to restore after each test. */
		private $original_time_limit;

		/** @var string|false Real ini value, saved to restore after each test. */
		private $original_memory_limit;

		public function set_up() {
			parent::set_up();
			\WP_CLI::reset();

			$this->sweeper = \Mockery::mock( BatchProcessorInterface::class );
			$this->sweeper->shouldReceive( 'queue_name' )->andReturn( 'sweep' )->byDefault();

			$this->stamper = \Mockery::mock( BatchProcessorInterface::class );
			$this->stamper->shouldReceive( 'queue_name' )->andReturn( 'stamp' )->byDefault();

			$this->cmd = new QueueCommand( $this->sweeper, $this->stamper );

			$this->original_time_limit   = ini_get( 'max_execution_time' );
			$this->original_memory_limit = ini_get( 'memory_limit' );

			// Neutral, generous budget ini for every test in this file -- see
			// the class docblock. Individual tests do not assert on Budget's
			// resolved values, so a single fixed pairing is enough.
			ini_set( 'max_execution_time', '15' );
			ini_set( 'memory_limit', '256M' );

			// BudgetFactory::build() reads php.ini settings via ini_get();
			// stub the underlying WordPress helper it calls so every test
			// gets a real, deterministic Budget without touching the
			// runtime's actual php.ini.
			\WP_Mock::userFunction( 'wp_convert_hr_to_bytes' )->andReturn( 268435456 );
		}

		public function tear_down() {
			ini_set( 'max_execution_time', $this->original_time_limit );
			ini_set( 'memory_limit', $this->original_memory_limit );

			parent::tear_down();
		}

		/**
		 * Stub the QueueLock transient pair as "free" for $queue --
		 * get_transient() reports nothing held, set_transient() succeeds.
		 * The TTL is the fixed value QueueCommand computes from this file's
		 * neutral budget ini (15s time limit) plus its own 30s margin -- 45,
		 * matching CronQueueRunnerTest's identical computation for the
		 * identical reason. Scoped to $queue's own transient key so a test
		 * touching both processors (none currently do) would not cross-wire
		 * one queue's lock state into the other's.
		 */
		private function mockLockFree( string $queue ): void {
			\WP_Mock::userFunction( 'get_transient' )
				->with( "aps_queue_lock_{$queue}" )->andReturn( false );
			\WP_Mock::userFunction( 'set_transient' )
				->with( "aps_queue_lock_{$queue}", \Mockery::type( 'int' ), 45 )->andReturn( true );
			\WP_Mock::userFunction( 'delete_transient' )
				->with( "aps_queue_lock_{$queue}" )->andReturn( true );
		}

		/**
		 * Routes 'sweep' to the sweeper processor, never the stamper.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_run_routes_sweep_to_the_sweep_processor() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )->once()->andReturn( new BatchResult( 3, 0, 0, false ) );
			$this->stamper->shouldNotReceive( 'process_batch' );

			$this->cmd->run( array( 'sweep' ), array() );

			$this->assertCount( 1, \WP_CLI::$successes );
		}

		/**
		 * Routes 'stamp' to the stamp processor, never the sweeper.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_run_routes_stamp_to_the_stamp_processor() {
			$this->mockLockFree( 'stamp' );
			$this->stamper->shouldReceive( 'process_batch' )->once()->andReturn( new BatchResult( 1, 0, 0, false ) );
			$this->sweeper->shouldNotReceive( 'process_batch' );

			$this->cmd->run( array( 'stamp' ), array() );

			$this->assertCount( 1, \WP_CLI::$successes );
		}

		/**
		 * An unrecognized queue name errors and never calls process_batch()
		 * on either processor.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_run_with_an_unknown_queue_name_errors_without_processing_anything() {
			$this->sweeper->shouldNotReceive( 'process_batch' );
			$this->stamper->shouldNotReceive( 'process_batch' );

			$this->cmd->run( array( 'nonsense' ), array() );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'Unknown queue', \WP_CLI::$errors[0] );
		}

		/**
		 * Without --all, exactly one batch runs, even when work remains.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_without_all_runs_exactly_one_batch_even_with_work_remaining() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )->once()->andReturn( new BatchResult( 10, 0, 40, false ) );

			$this->cmd->run( array( 'sweep' ), array() );

			$this->assertCount( 1, \WP_CLI::$successes );
			$this->assertStringContainsString( '40', $this->lastMessage( \WP_CLI::$successes ) );
		}

		/**
		 * --all loops until BatchResult::remaining reaches zero — the
		 * plan's explicit termination contract.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_all_loops_until_remaining_reaches_zero() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 10, 0, 20, false ) )
				->ordered();
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 10, 0, 10, false ) )
				->ordered();
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 10, 0, 0, false ) )
				->ordered();

			$this->cmd->run( array( 'sweep' ), array( 'all' => true ) );

			$this->assertCount( 1, \WP_CLI::$successes );
			$this->assertStringContainsString( 'drained', $this->lastMessage( \WP_CLI::$successes ) );
			$this->assertStringContainsString( '3 batch', $this->lastMessage( \WP_CLI::$successes ) );
		}

		/**
		 * A single batch that already reports remaining=0 stops immediately,
		 * even under --all — the loop must not run one extra, wasted batch
		 * after the queue is already dry.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_all_stops_after_one_batch_when_already_dry() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )->once()->andReturn( new BatchResult( 2, 0, 0, false ) );

			$this->cmd->run( array( 'sweep' ), array( 'all' => true ) );

			$this->assertStringContainsString( '1 batch', $this->lastMessage( \WP_CLI::$successes ) );
		}

		/**
		 * THE non-negotiable case: a batch that processes nothing, fails
		 * nothing, and leaves `remaining` exactly where the previous batch
		 * left it must stop the loop rather than spin forever. Proven by
		 * bounding process_batch() to exactly two calls via Mockery's own
		 * ->times(2) expectation — a third call, which an unbounded loop
		 * would make, fails the test on its own.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_all_terminates_on_a_no_progress_batch_instead_of_looping_forever() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )
				->times( 2 )
				->andReturn( new BatchResult( 0, 0, 5, false ) );

			$this->cmd->run( array( 'sweep' ), array( 'all' => true ) );

			$this->assertCount( 0, \WP_CLI::$successes, 'a stalled queue must not report success' );
			$this->assertCount( 1, \WP_CLI::$warnings );
			$this->assertStringContainsString( 'no progress', \WP_CLI::$warnings[0] );
		}

		/**
		 * A single batch of zero-progress work is NOT, by itself, a stall —
		 * only two CONSECUTIVE such batches are. This is what lets the
		 * sweeper legitimately drop stale/abandoned posts (which can reduce
		 * `remaining` without incrementing processed/failed) without being
		 * mistaken for stagnation on the very first observation.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_a_single_zero_progress_batch_is_not_mistaken_for_a_stall() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 0, 0, 5, false ) )
				->ordered();
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 5, 0, 0, false ) )
				->ordered();

			$this->cmd->run( array( 'sweep' ), array( 'all' => true ) );

			$this->assertCount( 1, \WP_CLI::$successes );
			$this->assertCount( 0, \WP_CLI::$warnings );
		}

		/**
		 * Failures count as progress too — a batch that only fails items
		 * (no successes) but changes nothing about `remaining` staying the
		 * same as a PRIOR batch must still not be treated as a stall on its
		 * own if failed > 0, since failed items are being actively retried,
		 * not stuck.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_a_batch_with_failures_is_not_treated_as_a_stall() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 0, 0, 5, false ) )
				->ordered();
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 0, 2, 5, false ) )
				->ordered();
			$this->sweeper->shouldReceive( 'process_batch' )
				->once()
				->andReturn( new BatchResult( 5, 0, 0, false ) )
				->ordered();

			$this->cmd->run( array( 'sweep' ), array( 'all' => true ) );

			$this->assertCount( 0, \WP_CLI::$warnings );
			$this->assertCount( 1, \WP_CLI::$successes );
		}

		/**
		 * Every batch is reported via WP_CLI::log(), including whether the
		 * budget was exhausted — the operator-facing progress trail the
		 * plan's §6 asks for.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_reports_progress_per_batch_including_budget_exhaustion() {
			$this->mockLockFree( 'sweep' );
			$this->sweeper->shouldReceive( 'process_batch' )->once()->andReturn( new BatchResult( 5, 1, 20, true ) );

			$this->cmd->run( array( 'sweep' ), array() );

			$this->assertCount( 1, \WP_CLI::$logs );
			$this->assertStringContainsString( 'processed 5', \WP_CLI::$logs[0] );
			$this->assertStringContainsString( 'failed 1', \WP_CLI::$logs[0] );
			$this->assertStringContainsString( 'remaining 20', \WP_CLI::$logs[0] );
			$this->assertStringContainsString( 'budget exhausted', \WP_CLI::$logs[0] );
		}

		// -----------------------------------------------------------------------
		// drain() — the queue lock (F1: overlap with CronQueueRunner)
		// -----------------------------------------------------------------------

		/**
		 * When another run already holds the queue lock, drain() stops with a
		 * warning and never calls process_batch() -- the CLI drain path must
		 * not race a concurrent cron tick (or another CLI invocation) on the
		 * same queue.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_run_stops_without_processing_when_the_queue_lock_is_held() {
			\WP_Mock::userFunction( 'get_transient' )
				->with( 'aps_queue_lock_sweep' )->andReturn( time() );
			\WP_Mock::userFunction( 'set_transient' )->never();

			$this->sweeper->shouldNotReceive( 'process_batch' );

			$this->cmd->run( array( 'sweep' ), array() );

			$this->assertCount( 0, \WP_CLI::$successes );
			$this->assertCount( 1, \WP_CLI::$warnings );
			$this->assertStringContainsString( 'locked by another run', \WP_CLI::$warnings[0] );
		}

		/**
		 * A locked queue under --all also stops immediately rather than
		 * looping -- the lock check runs before is_stalled() has anything to
		 * compare, so this must not be mistaken for a stall.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_all_stops_without_processing_when_the_queue_lock_is_held() {
			\WP_Mock::userFunction( 'get_transient' )
				->with( 'aps_queue_lock_stamp' )->andReturn( time() );

			$this->stamper->shouldNotReceive( 'process_batch' );

			$this->cmd->run( array( 'stamp' ), array( 'all' => true ) );

			$this->assertCount( 0, \WP_CLI::$successes );
			$this->assertCount( 1, \WP_CLI::$warnings );
		}

		/**
		 * Each of --all's three batches acquires and releases the lock on its
		 * own -- proving the lock is held per batch, not once around the
		 * whole loop, matching CronQueueRunner's per-tick granularity so the
		 * two never disagree about how long a hold should last.
		 *
		 * @covers ArchivedPostStatus\CLI\QueueCommand::run
		 */
		public function test_all_acquires_and_releases_the_lock_once_per_batch() {
			\WP_Mock::userFunction( 'get_transient' )
				->with( 'aps_queue_lock_sweep' )->times( 3 )->andReturn( false );
			\WP_Mock::userFunction( 'set_transient' )
				->with( 'aps_queue_lock_sweep', \Mockery::type( 'int' ), 45 )->times( 3 )->andReturn( true );
			\WP_Mock::userFunction( 'delete_transient' )
				->with( 'aps_queue_lock_sweep' )->times( 3 )->andReturn( true );

			$this->sweeper->shouldReceive( 'process_batch' )
				->times( 3 )
				->andReturn(
					new BatchResult( 10, 0, 20, false ),
					new BatchResult( 10, 0, 10, false ),
					new BatchResult( 10, 0, 0, false )
				);

			$this->cmd->run( array( 'sweep' ), array( 'all' => true ) );

			$this->assertCount( 1, \WP_CLI::$successes );
		}

		/**
		 * @param array<int, string> $messages
		 */
		private function lastMessage( array $messages ): string {
			return $messages[ count( $messages ) - 1 ];
		}
	}
}
