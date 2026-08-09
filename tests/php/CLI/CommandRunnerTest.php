<?php
/**
 * CommandRunner tests.
 *
 * Exercises the per-id loop, the batch-wide defer-term-counting bracket,
 * the post-cache priming, progress-bar branching, per-post emit() of
 * success/warning, exit-code aggregation, and the terminate() override
 * pattern. The WP_CLI static stub lives here alongside {@see \Registrar}'s
 * own — both classes touch WP_CLI::*, for different reasons (this one
 * reports outcomes, Registrar registers the commands).
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\CommandRunner
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value / make_progress_bar polyfills
	// (shared across the CLI suites).
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\Archive\ArchiveAction;
	use ArchivedPostStatus\CLI\CliResult;
	use ArchivedPostStatus\CLI\Command;
	use ArchivedPostStatus\CLI\CommandRunner;

	if ( ! class_exists( 'ScriptedCommand' ) ) {
		/**
		 * Test double for Command: returns a queue of pre-baked CliResults
		 * without touching any WP-Mock functions. Lets the runner tests
		 * stay narrowly focused on iteration/emit/exit-code behavior.
		 */
		// phpcs:disable
		final class ScriptedCommand extends Command {
			/** @var CliResult[] */
			public array $queue = array();
			public string $label = 'Fake';

			protected function action(): ArchiveAction {
				return ArchiveAction::Archive;
			}

			public function progress_label(): string {
				return $this->label;
			}

			protected function validate( int $post_id, array $assoc_args ): ?CliResult {
				return null;
			}
		}
		// phpcs:enable
	}

	if ( ! class_exists( 'ThrowingCommand' ) ) {
		/**
		 * Test double whose validate() always throws — used to prove
		 * CommandRunner::run() still re-enables deferred term counting
		 * (and lets the exception propagate) when a batch item throws
		 * instead of returning a CliResult. Throwing from validate()
		 * exercises the same "something inside $command->run() threw"
		 * path perform() throwing would, without wiring up a real
		 * perform() call.
		 */
		// phpcs:disable
		final class ThrowingCommand extends Command {
			protected function action(): ArchiveAction {
				return ArchiveAction::Archive;
			}

			public function progress_label(): string {
				return 'Fake';
			}

			protected function validate( int $post_id, array $assoc_args ): ?CliResult {
				throw new \RuntimeException( "simulated failure for {$post_id}" );
			}
		}
		// phpcs:enable
	}

	if ( ! class_exists( 'CapturingCommandRunner' ) ) {
		/**
		 * CommandRunner subclass that captures (rather than executes) the
		 * exit code. Mirrors the testability pattern documented on
		 * CommandRunner::terminate().
		 */
		// phpcs:disable
		final class CapturingCommandRunner extends CommandRunner {
			public ?int $captured_code = null;

			protected function terminate( int $code ): void {
				$this->captured_code = $code;
			}
		}
		// phpcs:enable
	}

	/**
	 * Behavior contract for CommandRunner.
	 *
	 * @covers ArchivedPostStatus\CLI\CommandRunner
	 */
	class CommandRunnerTest extends TestCase {

		public function set_up() {
			parent::set_up();
			\WP_CLI::reset();
			$GLOBALS['aps_test_progress_bar_calls']      = array();
			$GLOBALS['aps_test_prime_post_caches_calls'] = array();

			// Permissive stub: records every call instead of constraining the
			// count, so existing tests that don't care about caching are
			// unaffected. Tests that DO care assert on the recorded global
			// directly, mirroring the make_progress_bar() pattern above.
			\WP_Mock::userFunction( '_prime_post_caches' )
				->andReturnUsing(
					static function ( $ids ) {
						$GLOBALS['aps_test_prime_post_caches_calls'][] = $ids;
					}
				);
		}

		/**
		 * The run() method needs a Command instance plus a scripted result
		 * queue. This helper builds a ScriptedCommand whose run() will be
		 * overridden by anonymous subclassing to dequeue from the script.
		 *
		 * @param CliResult[] $script
		 */
		private function scripted( array $script ): Command {
			// Use an anonymous class to override the final run() — but
			// run() is final on the base, so we override validate() to
			// return the next scripted result instead. validate() returning
			// a CliResult short-circuits before execute() can fire.
			$cmd        = new ScriptedCommand();
			$cmd->queue = $script;
			return new class( $cmd ) extends Command {
				public function __construct( private ScriptedCommand $inner ) {}

				protected function action(): ArchiveAction {
					return ArchiveAction::Archive;
				}

				public function progress_label(): string {
					return $this->inner->progress_label();
				}

				protected function validate( int $post_id, array $assoc_args ): ?CliResult {
					$next = array_shift( $this->inner->queue );
					return $next ?? new CliResult( false, "unscripted call for {$post_id}" );
				}
			};
		}

		/**
		 * Single success: emit() should record a WP_CLI::success and the
		 * captured exit code should be 0.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 * @covers ArchivedPostStatus\CLI\CommandRunner::terminate
		 */
		public function test_run_emits_success_and_exits_zero_on_all_success() {
			$cmd    = $this->scripted( array( new CliResult( true, 'archived 1' ) ) );
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1 ), array() );

			$this->assertSame( array( 'archived 1' ), \WP_CLI::$successes );
			$this->assertEmpty( \WP_CLI::$warnings );
			$this->assertSame( 0, $runner->captured_code );
		}

		/**
		 * Single failure: emit() should record a WP_CLI::warning and the
		 * captured exit code should be 1. The "warning lets the run
		 * continue" contract is the reason we use warning over error.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_emits_warning_and_exits_one_on_failure() {
			$cmd    = $this->scripted( array( new CliResult( false, 'failed 1' ) ) );
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1 ), array() );

			$this->assertEmpty( \WP_CLI::$successes );
			$this->assertSame( array( 'failed 1' ), \WP_CLI::$warnings );
			$this->assertSame( 1, $runner->captured_code );
		}

		/**
		 * A failing LAST result yields exit 1 even though earlier results
		 * succeeded. Pairs with the "failure first" case below: the exit
		 * code is an aggregate, not a snapshot of the final iteration.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_exits_one_when_the_last_item_fails() {
			$cmd    = $this->scripted(
				array(
					new CliResult( true, 'archived 1' ),
					new CliResult( false, 'failed 2' ),
				)
			);
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1, 2 ), array() );

			$this->assertSame( array( 'archived 1' ), \WP_CLI::$successes );
			$this->assertSame( array( 'failed 2' ), \WP_CLI::$warnings );
			$this->assertSame( 1, $runner->captured_code );
		}

		/**
		 * A failure ANYWHERE in the batch must survive to the exit code —
		 * a later success cannot clear it. Without aggregation the final
		 * (successful) iteration overwrites the status and the whole batch
		 * exits 0, hiding the failed item from any calling script.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_exits_one_when_an_early_item_fails_and_the_last_succeeds() {
			$cmd    = $this->scripted(
				array(
					new CliResult( false, 'failed 1' ),
					new CliResult( true, 'archived 2' ),
				)
			);
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1, 2 ), array() );

			$this->assertSame( array( 'archived 2' ), \WP_CLI::$successes );
			$this->assertSame( array( 'failed 1' ), \WP_CLI::$warnings );
			$this->assertSame( 1, $runner->captured_code );
		}

		/**
		 * An all-success multi-id batch exits 0 — the aggregation must not
		 * manufacture a failure.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_exits_zero_when_every_item_in_a_batch_succeeds() {
			$cmd    = $this->scripted(
				array(
					new CliResult( true, 'archived 1' ),
					new CliResult( true, 'archived 2' ),
					new CliResult( true, 'archived 3' ),
				)
			);
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1, 2, 3 ), array() );

			$this->assertSame( array( 'archived 1', 'archived 2', 'archived 3' ), \WP_CLI::$successes );
			$this->assertEmpty( \WP_CLI::$warnings );
			$this->assertSame( 0, $runner->captured_code );
		}

		/**
		 * Above the count limit, the runner switches to a progress bar
		 * and emits no per-post success/warning lines. This batch is all
		 * success, so the exit code is 0 — see the sibling test for the
		 * failure case, which must still exit non-zero despite the
		 * suppressed per-post output.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_uses_progress_bar_above_count_limit() {
			$cmd    = $this->scripted(
				array(
					new CliResult( true, 'archived 1' ),
					new CliResult( true, 'archived 2' ),
					new CliResult( true, 'archived 3' ),
				)
			);
			$runner = new class(2) extends CommandRunner {
				public ?int $captured_code = null;

				protected function terminate( int $code ): void {
					$this->captured_code = $code;
				}
			};

			$runner->run( $cmd, array( 1, 2, 3 ), array() );

			$this->assertEmpty( \WP_CLI::$successes, 'progress-bar branch must not call WP_CLI::success' );
			$this->assertEmpty( \WP_CLI::$warnings );
			$this->assertCount( 1, $GLOBALS['aps_test_progress_bar_calls'] );
			$this->assertSame( 'Fake', $GLOBALS['aps_test_progress_bar_calls'][0][0] );
			$this->assertSame( 3, $GLOBALS['aps_test_progress_bar_calls'][0][1] );
			$this->assertSame( 0, $runner->captured_code );
		}

		/**
		 * Failures must propagate to the exit code regardless of batch
		 * size. Above the count limit the runner trades per-post
		 * success/warning lines for a progress bar — that is an OUTPUT
		 * decision only. The exit code contract is unconditional: any
		 * failed item exits non-zero, otherwise a scripted
		 * `wp post archive $(wp post list --format=ids)` can never detect
		 * a failure.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_exits_one_when_an_item_fails_above_count_limit() {
			$cmd    = $this->scripted(
				array(
					new CliResult( true, 'archived 1' ),
					new CliResult( false, 'failed 2' ),
					new CliResult( true, 'archived 3' ),
				)
			);
			$runner = new class(2) extends CommandRunner {
				public ?int $captured_code = null;

				protected function terminate( int $code ): void {
					$this->captured_code = $code;
				}
			};

			$runner->run( $cmd, array( 1, 2, 3 ), array() );

			$this->assertEmpty( \WP_CLI::$successes, 'progress-bar branch must not call WP_CLI::success' );
			$this->assertEmpty( \WP_CLI::$warnings, 'progress-bar branch must not call WP_CLI::warning' );
			$this->assertCount( 1, $GLOBALS['aps_test_progress_bar_calls'] );
			$this->assertSame( 1, $runner->captured_code );
		}

		/**
		 * At-or-below the count limit, the runner emits per-post and skips
		 * the progress bar entirely. Two items with count_limit=2 falls in
		 * the per-post branch (the boundary check is strict `> $count_limit`).
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_uses_per_post_emit_at_count_limit_boundary() {
			$cmd    = $this->scripted(
				array(
					new CliResult( true, 'archived 1' ),
					new CliResult( true, 'archived 2' ),
				)
			);
			$runner = new class(2) extends CommandRunner {
				public ?int $captured_code = null;

				protected function terminate( int $code ): void {
					$this->captured_code = $code;
				}
			};

			$runner->run( $cmd, array( 1, 2 ), array() );

			$this->assertSame( array( 'archived 1', 'archived 2' ), \WP_CLI::$successes );
			$this->assertEmpty( $GLOBALS['aps_test_progress_bar_calls'] );
			$this->assertSame( 0, $runner->captured_code );
		}

		/**
		 * The shipped `count_limit` default (20, set on the constructor
		 * parameter — no test double overrides it here) must trip the
		 * progress-bar branch once a batch exceeds it.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_uses_progress_bar_above_the_shipped_default_count_limit() {
			$script = array();
			for ( $i = 1; $i <= 21; $i++ ) {
				$script[] = new CliResult( true, "archived {$i}" );
			}
			$cmd = $this->scripted( $script );

			$runner = new CapturingCommandRunner();
			$runner->run( $cmd, range( 1, 21 ), array() );

			$this->assertEmpty( \WP_CLI::$successes, 'progress-bar branch must not call WP_CLI::success' );
			$this->assertCount( 1, $GLOBALS['aps_test_progress_bar_calls'] );
			$this->assertSame( 21, $GLOBALS['aps_test_progress_bar_calls'][0][1] );
			$this->assertSame( 0, $runner->captured_code );
		}

		// -----------------------------------------------------------------------
		// --defer-term-counting: brackets the whole batch, not one item at a time
		// -----------------------------------------------------------------------
		//
		// This used to be Command::execute()'s responsibility, wrapping a
		// single perform() call — called once per loop iteration, that
		// delivered none of the advertised batching benefit. It now lives in
		// run() instead, bracketing the entire loop.

		/**
		 * A three-item batch with the flag set must defer term counting
		 * exactly ONCE (not once per item) and re-enable it exactly once,
		 * after every item has run. `->once()` on each toggle is the proof:
		 * a per-item bracket would call `true` three times and fail this
		 * expectation.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_defers_term_counting_once_for_the_whole_batch_when_flag_set() {
			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( true )
				->ordered();
			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( false )
				->ordered();

			$cmd    = $this->scripted(
				array(
					new CliResult( true, 'archived 1' ),
					new CliResult( true, 'archived 2' ),
					new CliResult( true, 'archived 3' ),
				)
			);
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1, 2, 3 ), array( 'defer-term-counting' => true ) );

			$this->assertSame( 0, $runner->captured_code );
		}

		/**
		 * Without the flag, neither toggle fires — guards against
		 * accidentally calling wp_defer_term_counting() unconditionally.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_does_not_defer_term_counting_when_flag_absent() {
			\WP_Mock::userFunction( 'wp_defer_term_counting' )->never();

			$cmd    = $this->scripted( array( new CliResult( true, 'archived 1' ) ) );
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1 ), array() );

			$this->assertSame( 0, $runner->captured_code );
		}

		/**
		 * When an item throws mid-batch, the try/finally around the loop
		 * must still re-enable term counting before the exception
		 * propagates — leaving it deferred would leak the global state into
		 * unrelated WordPress operations after the CLI run exits abnormally.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_reenables_term_counting_when_an_item_throws() {
			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( true );
			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( false );

			$runner = new CapturingCommandRunner();

			$caught = null;
			try {
				$runner->run( new ThrowingCommand(), array( 1 ), array( 'defer-term-counting' => true ) );
			} catch ( \RuntimeException $e ) {
				$caught = $e;
			}

			$this->assertNotNull( $caught, 'Exception thrown from inside the loop must propagate' );
			$this->assertSame( 'simulated failure for 1', $caught->getMessage() );
			// WP_Mock verifies that wp_defer_term_counting(false) fired on
			// the way out of the finally block.
		}

		// -----------------------------------------------------------------------
		// Post-cache priming
		// -----------------------------------------------------------------------

		/**
		 * The whole id batch is primed into the post cache once, before the
		 * loop runs — a single _prime_post_caches() call instead of the
		 * one-uncached-get_post()-per-id each command's validate/execute
		 * chain would otherwise trigger.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_primes_post_cache_once_before_the_loop() {
			$cmd    = $this->scripted(
				array(
					new CliResult( true, 'archived 1' ),
					new CliResult( true, 'archived 2' ),
				)
			);
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array( 1, 2 ), array() );

			$this->assertCount( 1, $GLOBALS['aps_test_prime_post_caches_calls'] );
			$this->assertSame( array( 1, 2 ), $GLOBALS['aps_test_prime_post_caches_calls'][0] );
		}

		/**
		 * An empty batch has nothing to prime — _prime_post_caches() must
		 * not fire for zero ids.
		 *
		 * @covers ArchivedPostStatus\CLI\CommandRunner::run
		 */
		public function test_run_does_not_prime_post_cache_for_an_empty_batch() {
			$cmd    = $this->scripted( array() );
			$runner = new CapturingCommandRunner();

			$runner->run( $cmd, array(), array() );

			$this->assertEmpty( $GLOBALS['aps_test_prime_post_caches_calls'] );
			$this->assertSame( 0, $runner->captured_code );
		}
	}
}
