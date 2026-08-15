<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The swap point between the plugin's queue processors and whatever keeps
 * calling them.
 *
 * A processor owns "process one bounded chunk"; a runner owns "keep calling
 * it until the queue is dry". The default runner drives this from a
 * recurring cron tick plus a self-scheduled continuation; a site swaps in
 * a different one — Action Scheduler, for example — with:
 *
 *     add_filter( 'aps_queue_runner', fn() => new My_Action_Scheduler_Runner() );
 *
 * A runner MUST NOT contain queue-specific logic — no knowledge of what
 * "sweep" or "stamp" means, no reading schedule or rule meta directly. It
 * only calls {@see BatchProcessorInterface::process_batch()} and reacts to
 * the {@see BatchResult} it gets back. That separation is what lets one
 * runner implementation drive every processor this plugin defines, and
 * what lets a third-party runner drive them too without reimplementing
 * any of the plugin's domain logic.
 *
 * @since 0.5.0
 */
interface QueueRunnerInterface {

	/**
	 * Drive a processor until its queue is dry or this run's budget runs out.
	 *
	 * @since 0.5.0
	 * @param BatchProcessorInterface $processor The queue to drive.
	 * @return void
	 */
	public function dispatch( BatchProcessorInterface $processor ): void;
}
