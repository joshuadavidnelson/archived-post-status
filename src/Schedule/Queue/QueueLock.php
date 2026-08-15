<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Best-effort mutual exclusion between two overlapping runs of the same queue.
 *
 * Backed by a transient, not a real mutex — WordPress has no atomic
 * test-and-set, so the check-then-set in {@see acquire()} has an
 * unavoidable race between two requests arriving at the same instant. That
 * is an accepted risk, not an oversight: every queue this lock guards is
 * self-consuming (see `Schedule\Sweeper`, `AutoArchive\RuleStamper`), so
 * the consequence of losing the race is a duplicated batch — the same rows
 * processed twice, harmlessly, in whichever order — never a corrupted one.
 *
 * The transient is why this is safe to just walk away from: its TTL is
 * set just above the run's time limit, so a run killed by a fatal releases
 * the lock by expiry, rather than wedging the queue for every run after it.
 *
 * @since 0.5.0
 */
final class QueueLock {

	/**
	 * Transient key prefix, ahead of the queue name.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'aps_queue_lock_';

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param string $queue The queue name this lock guards (see
	 *                      {@see \ArchivedPostStatus\Schedule\Queue\BatchProcessorInterface::queue_name()}).
	 */
	public function __construct( private readonly string $queue ) {}

	/**
	 * Attempt to acquire the lock.
	 *
	 * @since 0.5.0
	 * @param int $ttl Seconds the lock lives before it self-expires; the
	 *                 caller passes a value just above the run's time
	 *                 limit, per the class docblock.
	 * @return bool True if the lock was free and is now held; false if
	 *              another run already holds it.
	 */
	public function acquire( int $ttl ): bool {
		if ( false !== get_transient( $this->transient_key() ) ) {
			return false;
		}

		/**
		 * Filters the queue lock's TTL, in seconds.
		 *
		 * @since 0.5.0
		 * @param int    $ttl   The proposed TTL, in seconds.
		 * @param string $queue The queue name this lock guards.
		 */
		$ttl = (int) apply_filters( 'aps_queue_lock_ttl', $ttl, $this->queue );

		return (bool) set_transient( $this->transient_key(), time(), $ttl );
	}

	/**
	 * Release the lock.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function release(): void {
		delete_transient( $this->transient_key() );
	}

	/**
	 * The transient key this lock reads and writes.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	private function transient_key(): string {
		return self::TRANSIENT_PREFIX . $this->queue;
	}
}
