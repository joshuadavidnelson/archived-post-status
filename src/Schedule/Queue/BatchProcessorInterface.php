<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The unit any queue runner can drive: one bounded chunk of work.
 *
 * An implementor owns the query and the per-item work; it knows nothing
 * about cron, Action Scheduler, or any other dispatch mechanism — that is
 * {@see QueueRunnerInterface}'s job. The contract that makes budgeting
 * actually work lives entirely in {@see process_batch()}: an implementor
 * MUST consult the given {@see Budget} before EACH item, not once per
 * batch, and stop cleanly — returning a {@see BatchResult} that reflects
 * whatever was processed so far — the moment it is exhausted. Checking the
 * budget only at the top of the batch silently reintroduces the
 * `max_execution_time` / `memory_limit` timeouts this whole abstraction
 * exists to avoid.
 *
 * @since 0.5.0
 */
interface BatchProcessorInterface {

	/**
	 * A short, stable identifier for this queue.
	 *
	 * Used as the transient key suffix for {@see QueueLock} and passed to
	 * the queue observability hooks (`aps_queue_batch_completed`,
	 * `aps_queue_drained`), so it must not change across releases.
	 *
	 * @since 0.5.0
	 * @return string The queue name, e.g. 'sweep' or 'stamp'.
	 */
	public function queue_name(): string;

	/**
	 * Process one bounded chunk of this queue's work.
	 *
	 * MUST check `$budget->exceeded()` before starting work on each item —
	 * not only once before the batch begins — and stop as soon as it
	 * reports true, returning a {@see BatchResult} with `budget_exhausted`
	 * true and `remaining` reflecting whatever is still left to do.
	 *
	 * @since 0.5.0
	 * @param Budget $budget The time and memory ceiling this run may spend.
	 * @return BatchResult What happened in this chunk.
	 */
	public function process_batch( Budget $budget ): BatchResult;
}
