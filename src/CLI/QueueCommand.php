<?php
/**
 * `wp aps queue run` command — the operator escape hatch for draining a
 * queue backlog with no WP-Cron time limit.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\AutoArchive\RuleStamper;
use ArchivedPostStatus\Schedule\Queue\BatchProcessorInterface;
use ArchivedPostStatus\Schedule\Queue\BatchResult;
use ArchivedPostStatus\Schedule\Queue\BudgetFactory;
use ArchivedPostStatus\Schedule\Queue\QueueLock;
use ArchivedPostStatus\Schedule\Sweeper;
use WP_CLI;
use WP_CLI\Utils;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Drives {@see Sweeper} or {@see RuleStamper} directly through
 * {@see BatchProcessorInterface::process_batch()}, bypassing cron
 * entirely — the tool an admin reaches for after enabling a rule over a
 * decade of content, to drain the backlog in one deliberate command instead
 * of waiting for cron to chew through it in bounded batches.
 *
 * `--all` loops until {@see BatchResult::remaining} reaches zero. The
 * loop's only real risk is never terminating; {@see self::is_stalled()} is
 * the guard against it. A single batch reporting zero processed and zero
 * failed is not, on its own, proof of a stall — the sweeper can legitimately
 * drop stale/abandoned posts from the due set without either counter moving
 * (see {@see Sweeper::apply_lifecycle_action()}), so `remaining` can still
 * be falling. Only TWO CONSECUTIVE such batches with `remaining` unchanged
 * between them mean the queue is genuinely stuck — nothing eligible, or the
 * budget is too small to reach even one item — and the loop stops rather
 * than spin forever.
 *
 * Each batch is wrapped in the same {@see QueueLock} acquire/release-in-
 * finally sequence {@see \ArchivedPostStatus\Schedule\Queue\CronQueueRunner}
 * uses, and for the identical reason: this command drives the same
 * processor the recurring cron tick drives, on the same queue name, so
 * without a shared lock a `wp aps queue run sweep --all` overlapping a
 * cron tick (or a second concurrent CLI invocation) would let both process
 * the same due-posts snapshot and double-fire every per-item side effect.
 * A batch that cannot acquire the lock stops the run with a warning rather
 * than spinning to retry — the lock is already short-lived (TTL just above
 * one batch's own time limit), so the next manual invocation, or the next
 * cron tick, picks the queue back up.
 *
 * @since 0.5.0
 */
final class QueueCommand {

	/**
	 * Seconds of headroom added to the budget's time limit when computing
	 * the queue lock's TTL — the same margin and the same reasoning as
	 * {@see \ArchivedPostStatus\Schedule\Queue\CronQueueRunner::LOCK_TTL_MARGIN}:
	 * the lock must outlive the batch it guards.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const LOCK_TTL_MARGIN = 30;

	/**
	 * Typed against {@see BatchProcessorInterface}, not the concrete
	 * {@see Sweeper}/{@see RuleStamper} classes, for the same reason
	 * {@see \ArchivedPostStatus\Schedule\Queue\CronQueueRunner} is: both are
	 * `final`, so a test double can only stand in for the interface. In
	 * production {@see \ArchivedPostStatus\Plugin} always wires the two real
	 * processors here — this command drives them directly, per plan §6.
	 *
	 * @since 0.5.0
	 * @param BatchProcessorInterface $sweeper The sweep queue processor — a {@see Sweeper}.
	 * @param BatchProcessorInterface $stamper The stamp queue processor — a {@see RuleStamper}.
	 */
	public function __construct(
		private readonly BatchProcessorInterface $sweeper,
		private readonly BatchProcessorInterface $stamper
	) {}

	/**
	 * `wp aps queue run <sweep|stamp> [--all]`
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the queue name.
	 * @param array<string, mixed>   $assoc_args Associative CLI flags (--all).
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		$processor = $this->processor_for( (string) ( $args[0] ?? '' ) );
		if ( null === $processor ) {
			// WP_CLI is only loaded under a real WP-CLI request; not a composer
			// dependency, same gap the existing phpstan.neon.dist ignoreErrors
			// entries cover for ::success()/::warning()/::add_command().
			// @phpstan-ignore class.notFound
			WP_CLI::error( 'Unknown queue. Valid queues: sweep, stamp.' );
			return;
		}

		$this->drain( $processor, (bool) Utils\get_flag_value( $assoc_args, 'all', false ) );
	}

	/**
	 * @since 0.5.0
	 * @param string $name 'sweep' or 'stamp'.
	 * @return BatchProcessorInterface|null
	 */
	private function processor_for( string $name ): ?BatchProcessorInterface {
		return match ( $name ) {
			'sweep' => $this->sweeper,
			'stamp' => $this->stamper,
			default => null,
		};
	}

	/**
	 * Run batches until the queue is dry, a single batch completes without
	 * `--all`, {@see is_stalled()} detects no progress, or a batch cannot
	 * acquire the queue lock.
	 *
	 * @since 0.5.0
	 * @param BatchProcessorInterface $processor The queue to drain.
	 * @param bool                    $all       Whether to loop until dry.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical budget-factory accessor.
	 */
	private function drain( BatchProcessorInterface $processor, bool $all ): void {
		$batch    = 0;
		$previous = null;

		do {
			++$batch;
			$result = $this->locked_batch( $processor );
			if ( null === $result ) {
				WP_CLI::warning( "Batch {$batch}: queue \"{$processor->queue_name()}\" is locked by another run; stopping." );
				return;
			}

			$this->report_batch( $batch, $result );

			if ( $result->is_dry() ) {
				WP_CLI::success( "Queue \"{$processor->queue_name()}\" drained after {$batch} batch(es)." );
				return;
			}

			if ( $this->is_stalled( $result, $previous ) ) {
				WP_CLI::warning( "Batch {$batch}: no progress; stopping to avoid an infinite loop." );
				return;
			}

			$previous = $result->remaining;
		} while ( $all );

		WP_CLI::success( "Batch {$batch} complete; {$result->remaining} item(s) remain." );
	}

	/**
	 * Run exactly one batch under the processor's {@see QueueLock}, mirroring
	 * {@see \ArchivedPostStatus\Schedule\Queue\CronQueueRunner::run_batch()}
	 * so a CLI drain and a cron tick on the same queue never overlap. See the
	 * class docblock for why this can never be skipped.
	 *
	 * @since 0.5.0
	 * @param BatchProcessorInterface $processor The queue to drive.
	 * @return BatchResult|null The batch result, or null if the lock could
	 *                          not be acquired.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical budget-factory accessor.
	 */
	private function locked_batch( BatchProcessorInterface $processor ): ?BatchResult {
		$budget = BudgetFactory::build( time() );
		$lock   = new QueueLock( $processor->queue_name() );

		if ( ! $lock->acquire( $budget->time_limit + self::LOCK_TTL_MARGIN ) ) {
			return null;
		}

		try {
			return $processor->process_batch( $budget );
		} finally {
			$lock->release();
		}
	}

	/**
	 * @since 0.5.0
	 * @param int         $batch  1-based batch number.
	 * @param BatchResult $result The batch just completed.
	 * @return void
	 */
	private function report_batch( int $batch, BatchResult $result ): void {
		$budget_note = $result->budget_exhausted ? ' (budget exhausted)' : '';

		// WP_CLI is only loaded under a real WP-CLI request; see the ::error() call above.
		// @phpstan-ignore class.notFound
		WP_CLI::log( "Batch {$batch}: processed {$result->processed}, failed {$result->failed}, remaining {$result->remaining}{$budget_note}." );
	}

	/**
	 * Whether this batch made no progress at all compared to the last one —
	 * see the class docblock for why this needs two consecutive
	 * observations, not one.
	 *
	 * @since 0.5.0
	 * @param BatchResult $result   The batch just completed.
	 * @param int|null    $previous The previous batch's `remaining`, or null on the first batch.
	 * @return bool
	 */
	private function is_stalled( BatchResult $result, ?int $previous ): bool {
		return null !== $previous
			&& 0 === $result->processed
			&& 0 === $result->failed
			&& $result->remaining === $previous;
	}
}
