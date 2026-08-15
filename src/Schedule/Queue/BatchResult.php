<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * What happened in one call to {@see BatchProcessorInterface::process_batch()}.
 *
 * Carries the derived predicates a {@see QueueRunnerInterface} needs, so a
 * runner asks this object a question rather than re-deriving the
 * arithmetic itself at every call site.
 *
 * @since 0.5.0
 */
final class BatchResult {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param int  $processed        Items successfully processed this batch.
	 * @param int  $failed           Items that failed this batch.
	 * @param int  $remaining        Items still left in the queue after this batch.
	 * @param bool $budget_exhausted Whether the batch stopped because its
	 *                               {@see Budget} ran out, rather than
	 *                               because the queue ran dry.
	 */
	public function __construct(
		public readonly int $processed,
		public readonly int $failed,
		public readonly int $remaining,
		public readonly bool $budget_exhausted
	) {}

	/**
	 * Whether the queue is dry: nothing left for another batch to pick up.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public function is_dry(): bool {
		return 0 === $this->remaining;
	}

	/**
	 * Whether more work exists, independent of budget.
	 *
	 * Distinct from {@see should_continue_now()}: this stays true even
	 * when the batch stopped on budget, which is exactly the signal a
	 * runner needs to decide whether to schedule a continuation for later
	 * rather than call {@see BatchProcessorInterface::process_batch()}
	 * again immediately.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public function has_more_work(): bool {
		return ! $this->is_dry();
	}

	/**
	 * Whether a runner should call `process_batch()` again immediately, in
	 * the same dispatch.
	 *
	 * True only when work remains AND this batch did not stop on budget —
	 * a runner with an exhausted budget has nothing left to spend on
	 * another call right now, however much work remains.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public function should_continue_now(): bool {
		return $this->has_more_work() && ! $this->budget_exhausted;
	}
}
