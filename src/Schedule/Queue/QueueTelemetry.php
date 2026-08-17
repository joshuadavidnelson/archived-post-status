<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The observability a queue run owes the rest of the plugin, in one place any
 * runner can call.
 *
 * {@see QueueRunnerInterface} exists so a site can drive the processors with
 * something other than WP-Cron — Action Scheduler being the motivating case.
 * But two of the things a run is expected to announce are not part of
 * processing at all: the `aps_queue_batch_completed` / `aps_queue_drained`
 * actions other code listens on, and the per-queue last-run timestamp the
 * settings screen's cron-health notice reads to tell an operator whether the
 * queue is running at all.
 *
 * Left inside {@see CronQueueRunner}, a replacement runner would have to
 * rediscover and reproduce them by reading that class — and a runner that
 * simply did not would keep archiving correctly while the health notice went
 * permanently stale and every monitoring listener fell silent. That failure is
 * invisible precisely when it matters most, so the announcements live here
 * rather than in one runner's private methods.
 *
 * @since 0.5.0
 */
final class QueueTelemetry {

	/**
	 * The option a queue's last completed batch is recorded under.
	 *
	 * Per queue, so a second queue cannot overwrite the first's timestamp.
	 *
	 * @since 0.5.0
	 * @param string $queue The queue name, e.g. 'sweep'.
	 * @return string
	 */
	public static function last_run_option( string $queue ): string {
		return "aps_last_{$queue}";
	}

	/**
	 * The epoch a queue last completed a batch, or 0 if it never has.
	 *
	 * @since 0.5.0
	 * @param string $queue The queue name.
	 * @return int
	 */
	public static function last_run( string $queue ): int {
		return absint( get_option( self::last_run_option( $queue ), 0 ) );
	}

	/**
	 * Announce a completed batch and record that the queue ran.
	 *
	 * Every runner must call this after each batch, whatever drives it.
	 *
	 * @since 0.5.0
	 * @param BatchResult $result The batch that just completed.
	 * @param string      $queue  The queue name.
	 * @return void
	 */
	public static function batch_completed( BatchResult $result, string $queue ): void {

		/**
		 * Fires once a queue batch finishes processing, successful or not.
		 *
		 * @since 0.5.0
		 * @param BatchResult $result The batch that just completed.
		 * @param string      $queue  The queue name.
		 */
		do_action( 'aps_queue_batch_completed', $result, $queue );

		// Autoload off: read only by the settings screen's health notice.
		update_option( self::last_run_option( $queue ), time(), false );
	}

	/**
	 * Announce that a queue has nothing left to process.
	 *
	 * @since 0.5.0
	 * @param string $queue The queue name.
	 * @return void
	 */
	public static function drained( string $queue ): void {

		/**
		 * Fires when a queue run leaves nothing left to process.
		 *
		 * @since 0.5.0
		 * @param string $queue The queue name.
		 */
		do_action( 'aps_queue_drained', $queue );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
