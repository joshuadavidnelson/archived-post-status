<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Schedule\Queue\BatchProcessorInterface;
use ArchivedPostStatus\Schedule\Queue\BatchResult;
use ArchivedPostStatus\Schedule\Queue\Budget;

/**
 * Turns due schedules into archived posts, one bounded batch at a time.
 *
 * Knows nothing about cron or any other dispatch mechanism — that is a
 * {@see \ArchivedPostStatus\Schedule\Queue\QueueRunnerInterface}'s job, in a
 * later phase. This class only answers "process one bounded chunk of the
 * sweep queue", per {@see BatchProcessorInterface}.
 *
 * The sweep queue is self-consuming: every successful outcome (archived,
 * cleared, exempted) removes the post from {@see SweepQuery}'s own result
 * set, so re-running the same query is the entire resumption story — see
 * the plan's §5.5. The one outcome that does NOT remove a post is a failed
 * archive attempt below the retry cap, and that is deliberate: a post
 * whose {@see \aps_archive_post()} call fails (an `aps_pre_archive_post`
 * veto, a persist failure) keeps its schedule meta and is retried on the
 * very next sweep. Left unchecked that would let one bad post sit at the
 * head of the due-time-ordered queue forever, blocking every post behind
 * it. `_aps_schedule_meta_attempts` plus the abandon path below is what
 * stops that: once a post's attempts reach the cap, it is dropped from the
 * queue (by default) rather than retried indefinitely.
 *
 * @since 0.5.0
 */
final class Sweeper implements BatchProcessorInterface {

	/**
	 * The queue name this processor reports to
	 * {@see BatchProcessorInterface::queue_name()}.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const QUEUE_NAME = 'sweep';

	/**
	 * Failed archive attempts a schedule tolerates before it is abandoned.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const DEFAULT_MAX_ATTEMPTS = 3;

	/**
	 * Default action taken when a due post is no longer archivable.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const DEFAULT_STALE_ACTION = 'clear';

	/**
	 * Default action taken when a schedule is abandoned at the attempt cap.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const DEFAULT_ABANDON_ACTION = 'exempt';

	/**
	 * Builds the due-posts {@see \WP_Query} from {@see SweepQuery::args()}'s
	 * output. Defaults to a real query; the constructor seam below is what
	 * keeps {@see process_batch()} a plain unit test — a live `WP_Query`
	 * cannot be exercised without a database.
	 *
	 * @since 0.5.0
	 * @var \Closure
	 */
	private readonly \Closure $query_factory;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param callable|null $query_factory Builds the due-posts WP_Query from
	 *                                     {@see SweepQuery::args()}'s output,
	 *                                     e.g. `fn( array $args ) => new
	 *                                     \WP_Query( $args )`. Defaults to
	 *                                     exactly that.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- \Closure::fromCallable() is core PHP, not project coupling.
	 */
	public function __construct( ?callable $query_factory = null ) {
		$this->query_factory = null !== $query_factory
			? \Closure::fromCallable( $query_factory )
			: static function ( array $args ): \WP_Query {
				return new \WP_Query( $args );
			};
	}

	/**
	 * @since 0.5.0
	 * @return string
	 */
	public function queue_name(): string {
		return self::QUEUE_NAME;
	}

	/**
	 * Process one bounded chunk of the sweep queue.
	 *
	 * Checks the budget before EVERY post, not once per batch — the moment
	 * it reports exhausted, the loop stops and every post not yet reached
	 * is left untouched for the next run to pick up.
	 *
	 * @since 0.5.0
	 * @param Budget $budget The time and memory ceiling this run may spend.
	 * @return BatchResult
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical query-builder accessors.
	 */
	public function process_batch( Budget $budget ): BatchResult {
		$now        = time();
		$batch_size = SweepQuery::batch_size();
		$query      = ( $this->query_factory )( SweepQuery::args( $now, $batch_size ) );

		$ids         = array_map( 'absint', (array) $query->posts );
		$found_posts = (int) $query->found_posts;

		$processed        = 0;
		$failed           = 0;
		$dropped          = 0;
		$budget_exhausted = false;
		$archived_ids     = array();
		$skipped_ids      = array();

		foreach ( $ids as $post_id ) {
			if ( $budget->exceeded( time(), memory_get_usage( true ) ) ) {
				$budget_exhausted = true;
				break;
			}

			$dropped += $this->process_one( $post_id, $processed, $failed, $archived_ids, $skipped_ids );
		}

		/**
		 * Fires once, after a sweep batch finishes processing.
		 *
		 * @since 0.5.0
		 * @param array<int, int> $archived_ids Post IDs archived this batch.
		 * @param array<int, int> $skipped_ids  Post IDs that were due but not
		 *                                      archived this batch — missing,
		 *                                      or no longer archivable.
		 */
		do_action( 'aps_scheduled_archives_swept', $archived_ids, $skipped_ids );

		// $found_posts is every post SweepQuery matched as due, before this
		// batch touched any of them — the same count the identical query
		// would report again right now, unchanged. Each id that DROPPED OUT
		// of that due set this batch (archived, or cleared/exempted via the
		// stale or abandon policies below) is one fewer row the same query
		// will match on the next run, so it is subtracted here.
		//
		// An id that only failed to archive without reaching the attempt cap,
		// or was explicitly kept by a stale/abandon policy, still carries its
		// due META_TIME and so still matches the same query next time — it is
		// NOT subtracted, because it is still remaining work. Ids never
		// reached at all because the budget ran out are never subtracted
		// either, for the same reason: they are still sitting in the due set.
		$remaining = max( 0, $found_posts - $dropped );

		return new BatchResult( $processed, $failed, $remaining, $budget_exhausted );
	}

	/**
	 * Process a single due post.
	 *
	 * @since 0.5.0
	 * @param int              $post_id      The due post ID.
	 * @param int              $processed    Running count of successful archives, by reference.
	 * @param int              $failed       Running count of failed archive attempts, by reference.
	 * @param array<int, int>  $archived_ids Post IDs archived so far this batch, by reference.
	 * @param array<int, int>  $skipped_ids  Post IDs skipped so far this batch, by reference.
	 * @return int 1 if this post dropped out of the due set, 0 if it is still due.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical archivable-statuses lookup.
	 */
	private function process_one( int $post_id, int &$processed, int &$failed, array &$archived_ids, array &$skipped_ids ): int {
		$post = get_post( $post_id );

		if ( ! $post ) {
			$this->clear_schedule( $post_id );
			$skipped_ids[] = $post_id;
			return 1;
		}

		if ( ! ArchivableStatuses::includes( (string) $post->post_status ) ) {
			$skipped_ids[] = $post_id;
			return $this->handle_stale( $post_id, (string) $post->post_status );
		}

		if ( false !== aps_archive_post( $post_id ) ) {
			$this->clear_schedule( $post_id );
			$archived_ids[] = $post_id;
			++$processed;
			return 1;
		}

		++$failed;
		return $this->handle_failed_attempt( $post_id );
	}

	/**
	 * Decide what happens to a due post whose status is no longer archivable
	 * — someone archived, trashed, or otherwise transitioned it since it was
	 * scheduled.
	 *
	 * @since 0.5.0
	 * @param int    $post_id The post ID.
	 * @param string $status  The post's current status.
	 * @return int 1 if this post dropped out of the due set, 0 if it is still due.
	 */
	private function handle_stale( int $post_id, string $status ): int {

		/**
		 * Filters what happens to a due post whose status is no longer
		 * archivable.
		 *
		 * @since 0.5.0
		 * @param string $action  What to do: 'clear' | 'keep' | 'exempt'. Default 'clear'.
		 * @param int    $post_id The post ID.
		 * @param string $status  The post's current status.
		 */
		$action = (string) apply_filters( 'aps_schedule_stale_action', self::DEFAULT_STALE_ACTION, $post_id, $status );

		return $this->apply_lifecycle_action( $action, $post_id );
	}

	/**
	 * Record a failed archive attempt and abandon the schedule once it has
	 * failed too many times.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return int 1 if this post dropped out of the due set, 0 if it is still due.
	 */
	private function handle_failed_attempt( int $post_id ): int {
		$attempts = (int) get_post_meta( $post_id, ScheduleMeta::META_ATTEMPTS, true ) + 1;
		update_post_meta( $post_id, ScheduleMeta::META_ATTEMPTS, $attempts );

		/**
		 * Filters the number of failed archive attempts a schedule tolerates
		 * before it is abandoned.
		 *
		 * @since 0.5.0
		 * @param int $max_attempts Default 3.
		 */
		$max_attempts = (int) apply_filters( 'aps_schedule_max_attempts', self::DEFAULT_MAX_ATTEMPTS );

		if ( $attempts < $max_attempts ) {
			// Still under the cap: the schedule is untouched, stays due, and
			// is retried on the next sweep.
			return 0;
		}

		/**
		 * Filters what happens to a schedule abandoned at the attempt cap.
		 *
		 * @since 0.5.0
		 * @param string $action   What to do: 'exempt' | 'clear' | 'keep'. Default 'exempt'.
		 * @param int    $post_id  The post ID.
		 * @param int    $attempts The number of failed attempts that triggered abandonment.
		 */
		$action = (string) apply_filters( 'aps_schedule_abandon_action', self::DEFAULT_ABANDON_ACTION, $post_id, $attempts );

		$dropped = $this->apply_lifecycle_action( $action, $post_id );

		/**
		 * Fires when a schedule is abandoned after too many failed archive attempts.
		 *
		 * @since 0.5.0
		 * @param int $post_id  The post ID.
		 * @param int $attempts The number of failed attempts that triggered abandonment.
		 */
		do_action( 'aps_schedule_archive_abandoned', $post_id, $attempts );

		return $dropped;
	}

	/**
	 * Apply a 'clear' | 'keep' | 'exempt' policy outcome to a post's schedule.
	 * Shared by {@see handle_stale()} and {@see handle_failed_attempt()} —
	 * both filters return the same three verbs.
	 *
	 * @since 0.5.0
	 * @param string $action  'clear' | 'keep' | 'exempt'.
	 * @param int    $post_id The post ID.
	 * @return int 1 if this post dropped out of the due set, 0 if it is still due.
	 */
	private function apply_lifecycle_action( string $action, int $post_id ): int {
		if ( 'clear' === $action ) {
			$this->clear_schedule( $post_id );
			return 1;
		}

		if ( 'exempt' === $action ) {
			$this->exempt_schedule( $post_id );
			return 1;
		}

		// 'keep', or any value a filter returns that isn't recognized: leave
		// the schedule exactly as it is. It stays due and is retried next sweep.
		return 0;
	}

	/**
	 * Delete a post's schedule meta outright.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object accessor.
	 */
	private function clear_schedule( int $post_id ): void {
		$schedule = ScheduleMeta::for_post( $post_id );

		if ( null !== $schedule ) {
			$schedule->delete( $post_id );
		}
	}

	/**
	 * Mark a schedule exempt: keep the record, but drop the due time so it no
	 * longer matches {@see SweepQuery}'s due-posts query.
	 *
	 * Independent of
	 * {@see \ArchivedPostStatus\Schedule\ScheduleOperation::clear()}'s
	 * tombstone-on-clear policy, which is driven by a different filter for a
	 * different trigger (an editor clearing a schedule, not the sweep's
	 * stale/abandon policies) — this exempts unconditionally once the caller
	 * has already decided to.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return void
	 */
	private function exempt_schedule( int $post_id ): void {
		update_post_meta( $post_id, ScheduleMeta::META_SOURCE, ScheduleSource::Exempt->value );
		delete_post_meta( $post_id, ScheduleMeta::META_TIME );
	}
}
