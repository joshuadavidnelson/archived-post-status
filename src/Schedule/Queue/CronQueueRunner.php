<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\CronRegistrar;

/**
 * The default {@see QueueRunnerInterface}: drives one processor from a
 * recurring cron tick plus a self-scheduled continuation.
 *
 * One instance drives exactly one processor -- `Plugin::hookables()`
 * constructs one `CronQueueRunner` wrapping a `Sweeper` and a second
 * wrapping a `RuleStamper`, each on its own recurring hook. Both instances
 * share this class and the one distinct `aps_continue_queue` continuation
 * hook; {@see handle_continuation()} ignores any event addressed to a queue
 * it does not drive, which is what lets two instances coexist on that
 * shared hook without either driving the other's processor.
 *
 * @since 0.5.0
 */
final class CronQueueRunner implements HookableInterface, QueueRunnerInterface {

	/**
	 * The distinct hook a continuation is scheduled on.
	 *
	 * MUST stay distinct from every recurring cron hook this class serves.
	 * `wp_schedule_single_event()` silently refuses a duplicate of the same
	 * hook and args within a ten-minute window; reusing a recurring hook's
	 * own name would make the continuation vanish exactly when a backlog
	 * needs it most. The varying batch-index arg (see
	 * {@see maybe_continue()}) keeps successive continuations for the same
	 * queue distinct from each other for the identical reason.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const CONTINUE_HOOK = 'aps_continue_queue';

	/**
	 * Seconds of headroom added to the budget's time limit when computing
	 * the queue lock's TTL. The lock must outlive the run it guards, or a
	 * slow-but-legitimate batch would trip over its own lock's expiry and
	 * let an overlapping run start before this one finishes.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const LOCK_TTL_MARGIN = 30;

	/**
	 * Maps a processor's {@see BatchProcessorInterface::queue_name()} to the
	 * recurring cron hook {@see hooks()} binds it to. One entry per queue.
	 *
	 * @since 0.5.0
	 * @var array<string, string>
	 */
	private const QUEUE_CRON_HOOKS = array(
		'sweep' => CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES,
		'stamp' => CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES,
	);

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param BatchProcessorInterface $processor The queue this instance drives.
	 */
	public function __construct( private readonly BatchProcessorInterface $processor ) {}

	/**
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( $this->cron_hook(), array( $this, 'run' ) ),
			HookDescriptor::action( self::CONTINUE_HOOK, array( $this, 'handle_continuation' ), 10, 2 ),
		);
	}

	/**
	 * Cron callback for this instance's recurring hook.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function run(): void {
		$this->dispatch( $this->processor );
	}

	/**
	 * Drive this instance's processor through exactly one bounded batch.
	 *
	 * @since 0.5.0
	 * @param BatchProcessorInterface $processor The queue to drive.
	 * @return void
	 */
	public function dispatch( BatchProcessorInterface $processor ): void {
		$this->run_batch( $processor, 0 );
	}

	/**
	 * `aps_continue_queue` callback, shared by every CronQueueRunner
	 * instance. A continuation addressed to a different queue than the one
	 * this instance drives is silently ignored -- see the class docblock.
	 *
	 * @since 0.5.0
	 * @param string $queue       The queue name this continuation is for.
	 * @param int    $batch_index The 1-based index of the batch about to run.
	 * @return void
	 */
	public function handle_continuation( string $queue, int $batch_index ): void {
		if ( $queue !== $this->processor->queue_name() ) {
			return;
		}

		$this->run_batch( $this->processor, $batch_index );
	}

	/**
	 * The lock/budget/dispatch/continuation sequence shared by a fresh cron
	 * tick and a resumed continuation.
	 *
	 * The budget is built before the lock is acquired, not after, even
	 * though {@see QueueLock} conceptually guards the whole run: the lock's
	 * TTL is derived from the budget's time limit, so the budget has to
	 * exist first to size it.
	 *
	 * @since 0.5.0
	 * @param BatchProcessorInterface $processor   The queue to drive.
	 * @param int                     $batch_index This call's position in a
	 *                                              chain of continuations;
	 *                                              0 for a fresh cron tick.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical budget-factory accessor.
	 */
	private function run_batch( BatchProcessorInterface $processor, int $batch_index ): void {
		$queue  = $processor->queue_name();
		$budget = BudgetFactory::build( time() );
		$lock   = new QueueLock( $queue );

		if ( ! $lock->acquire( $budget->time_limit + self::LOCK_TTL_MARGIN ) ) {
			return;
		}

		try {
			$result = $processor->process_batch( $budget );

			/**
			 * Fires once a queue batch finishes processing, successful or not.
			 *
			 * @since 0.5.0
			 * @param BatchResult $result The batch that just completed.
			 * @param string      $queue  The queue name.
			 */
			do_action( 'aps_queue_batch_completed', $result, $queue );

			update_option( "aps_last_{$queue}", time(), false );
			$this->maybe_continue( $result, $queue, $batch_index );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Decide, and act on, whether this queue needs another batch.
	 *
	 * @since 0.5.0
	 * @param BatchResult $result      The batch just completed.
	 * @param string      $queue       The queue name.
	 * @param int         $batch_index This batch's index, for the next
	 *                                 continuation's varying arg.
	 * @return void
	 */
	private function maybe_continue( BatchResult $result, string $queue, int $batch_index ): void {
		if ( ! $result->has_more_work() ) {

			/**
			 * Fires when a queue run leaves nothing left to process.
			 *
			 * @since 0.5.0
			 * @param string $queue The queue name.
			 */
			do_action( 'aps_queue_drained', $queue );
			return;
		}

		/**
		 * Filters whether a queue with remaining work gets an immediate
		 * continuation scheduled, rather than waiting for the next
		 * recurring tick.
		 *
		 * @since 0.5.0
		 * @param bool        $continue Whether to schedule a continuation. Default true.
		 * @param BatchResult $result   The batch just completed.
		 * @param string      $queue    The queue name.
		 */
		$continue = (bool) apply_filters( 'aps_queue_should_continue', true, $result, $queue );

		if ( $continue ) {
			wp_schedule_single_event( time(), self::CONTINUE_HOOK, array( $queue, $batch_index + 1 ) );
		}
	}

	/**
	 * Resolve this instance's recurring cron hook from its processor's
	 * queue name.
	 *
	 * @since 0.5.0
	 * @return string
	 * @throws \LogicException When the processor's queue name has no
	 *                         entry in {@see QUEUE_CRON_HOOKS} -- a
	 *                         processor was wired up without adding its
	 *                         cron hook to that map.
	 */
	private function cron_hook(): string {
		$queue = $this->processor->queue_name();

		if ( ! isset( self::QUEUE_CRON_HOOKS[ $queue ] ) ) {
			throw new \LogicException( "CronQueueRunner: no cron hook mapped for queue \"{$queue}\"." );
		}

		return self::QUEUE_CRON_HOOKS[ $queue ];
	}
}
