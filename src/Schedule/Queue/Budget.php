<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The time and memory ceiling a single queue run may spend.
 *
 * A pure value object: every input is injected by {@see BudgetFactory},
 * never read from the environment here. That is what makes every boundary
 * case — exactly at the ceiling, one second under it, one byte over it — a
 * plain unit test with no `ini_get()` or `sleep()` involved.
 *
 * @since 0.5.0
 */
final class Budget {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param int $started_at   Epoch when this run began.
	 * @param int $time_limit   Seconds this run may take.
	 * @param int $memory_limit Bytes this run may use.
	 */
	public function __construct(
		public readonly int $started_at,
		public readonly int $time_limit,
		public readonly int $memory_limit
	) {}

	/**
	 * Whether this run has used up its time allowance.
	 *
	 * `>=` rather than `>`: a run that has consumed exactly `$time_limit`
	 * seconds has spent its whole allowance, not one second of headroom
	 * still to use. Ties go to stopping, matching the rest of this
	 * feature's bias toward finishing early over running long.
	 *
	 * @since 0.5.0
	 * @param int $now Current epoch, from the caller's `time()`.
	 * @return bool
	 */
	public function time_exceeded( int $now ): bool {
		return ( $now - $this->started_at ) >= $this->time_limit;
	}

	/**
	 * Whether this run has used up its memory allowance.
	 *
	 * Same `>=` reasoning as {@see time_exceeded()}: exactly at the ceiling
	 * counts as exceeded, not one byte still available.
	 *
	 * @since 0.5.0
	 * @param int $memory_used Bytes currently in use, from the caller's
	 *                         `memory_get_usage( true )`.
	 * @return bool
	 */
	public function memory_exceeded( int $memory_used ): bool {
		return $memory_used >= $this->memory_limit;
	}

	/**
	 * Whether this run should stop before starting another item.
	 *
	 * True when either ceiling in isolation is reached. Exposed as its own
	 * method — rather than leaving callers to combine
	 * {@see time_exceeded()} and {@see memory_exceeded()} themselves —
	 * because the combination is itself a policy a site may want to
	 * override wholesale (e.g. to ignore memory entirely on a host with no
	 * enforced `memory_limit`).
	 *
	 * @since 0.5.0
	 * @param int $now         Current epoch, from the caller's `time()`.
	 * @param int $memory_used Bytes currently in use, from the caller's
	 *                         `memory_get_usage( true )`.
	 * @return bool
	 */
	public function exceeded( int $now, int $memory_used ): bool {
		$exceeded = $this->time_exceeded( $now ) || $this->memory_exceeded( $memory_used );

		/**
		 * Filters whether a queue run's budget is exhausted.
		 *
		 * The caller (a {@see BatchProcessorInterface} implementation)
		 * cannot distinguish which ceiling fired from this return value
		 * alone; call {@see time_exceeded()} / {@see memory_exceeded()}
		 * directly to log which one it was.
		 *
		 * @since 0.5.0
		 * @param bool   $exceeded    Whether either ceiling was reached.
		 * @param Budget $budget      This budget.
		 * @param int    $now         Current epoch passed to exceeded().
		 * @param int    $memory_used Bytes in use, passed to exceeded().
		 */
		return (bool) apply_filters( 'aps_queue_budget_exceeded', $exceeded, $this, $now, $memory_used );
	}
}
