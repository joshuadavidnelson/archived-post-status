<?php
/**
 * Schedule\Sweeper Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\Sweeper
 *
 * The injected query_factory closure stands in for a real WP_Query — these
 * are isolated unit tests with no database, so Sweeper's constructor seam
 * (see its class docblock) is what lets each test hand it a canned {posts,
 * found_posts} result instead of exercising WP_Query itself.
 */

use ArchivedPostStatus\Schedule\Queue\Budget;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\Sweeper;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\Sweeper
 */
class SweeperTest extends TestCase {

	/**
	 * Build a Sweeper whose query_factory ignores the args SweepQuery::args()
	 * assembled and always returns a canned {posts, found_posts} double.
	 *
	 * @param array<int, int> $ids   The due post IDs the query "found".
	 * @param int             $found The found_posts count the query reports.
	 */
	private function sweeperWithQueryResult( array $ids, int $found ): Sweeper {
		$query = (object) array(
			'posts'       => $ids,
			'found_posts' => $found,
		);

		return new Sweeper( static fn( array $args ): object => $query );
	}

	/**
	 * A Budget that is never exceeded, regardless of the real clock/memory
	 * usage this process happens to have at call time.
	 */
	private function neverExceededBudget(): Budget {
		return new Budget( time(), 999999, PHP_INT_MAX );
	}

	/**
	 * A Budget that is exceeded from the very first check — started_at 0 and
	 * a 0-second time limit means `( now - 0 ) >= 0` is true for any real
	 * "now", with no need to know its exact value.
	 */
	private function alwaysExceededBudget(): Budget {
		return new Budget( 0, 0, PHP_INT_MAX );
	}

	/**
	 * Stub the five get_post_meta() reads ScheduleMeta::for_post() makes.
	 *
	 * @param int    $post_id
	 * @param string $source
	 * @param int    $time
	 * @param int    $user
	 * @param int    $rule_version
	 * @param int    $attempts
	 */
	private function stubScheduleMeta(
		int $post_id,
		string $source = 'manual',
		int $time = 1000,
		int $user = 0,
		int $rule_version = 0,
		int $attempts = 0
	): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_SOURCE, true )->andReturn( $source );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_TIME, true )->andReturn( $time );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_USER, true )->andReturn( $user );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_RULE_VERSION, true )->andReturn( $rule_version );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_ATTEMPTS, true )->andReturn( $attempts );
	}

	// -----------------------------------------------------------------------
	// constructor — default query_factory
	// -----------------------------------------------------------------------

	/**
	 * With no query_factory supplied, process_batch() builds a real
	 * \WP_Query from SweepQuery::args() rather than requiring every caller
	 * to know about the injection seam. The bootstrap's minimal \WP_Query
	 * stub ignores its constructor args and defaults to an empty result,
	 * which is enough to prove the default factory actually runs.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::__construct
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_default_query_factory_constructs_a_real_wp_query() {
		$sweeper = new Sweeper();
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 0, $result->remaining );
		$this->assertFalse( $result->budget_exhausted );
	}

	// -----------------------------------------------------------------------
	// queue_name()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\Sweeper::queue_name
	 */
	public function test_queue_name_is_sweep() {
		$sweeper = new Sweeper( static fn( array $args ): object => (object) array(
			'posts'       => array(),
			'found_posts' => 0,
		) );

		$this->assertSame( 'sweep', $sweeper->queue_name() );
	}

	// -----------------------------------------------------------------------
	// process_batch() — a post that archives cleanly
	// -----------------------------------------------------------------------

	/**
	 * A due post that archives successfully: schedule meta deleted, counted
	 * processed, and dropped out of the due set (remaining reflects that).
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_archives_a_due_post_cleanly() {
		$post = new WP_Post( array( 'ID' => 10, 'post_status' => 'publish' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );
		\WP_Mock::userFunction( 'aps_archive_post' )->once()->with( 10 )->andReturn( $post );

		$this->stubScheduleMeta( 10 );
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );

		\WP_Mock::expectAction( 'aps_scheduled_archives_swept', array( 10 ), array() );

		$sweeper = $this->sweeperWithQueryResult( array( 10 ), 1 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 0, $result->remaining );
		$this->assertFalse( $result->budget_exhausted );
	}

	// -----------------------------------------------------------------------
	// process_batch() — a post that no longer exists
	// -----------------------------------------------------------------------

	/**
	 * A due post whose ID no longer resolves to a post: its schedule meta is
	 * cleared, it is not archived, and it is counted skipped, not processed.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_clears_schedule_for_a_post_that_no_longer_exists() {
		\WP_Mock::userFunction( 'get_post' )->with( 11 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_archive_post' )->never();

		$this->stubScheduleMeta( 11 );
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );

		\WP_Mock::expectAction( 'aps_scheduled_archives_swept', array(), array( 11 ) );

		$sweeper = $this->sweeperWithQueryResult( array( 11 ), 1 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 0, $result->remaining );
	}

	// -----------------------------------------------------------------------
	// process_batch() — a post whose status is no longer archivable (stale)
	// -----------------------------------------------------------------------

	/**
	 * Default stale action ('clear'): the schedule meta is deleted and the
	 * post drops out of the due set.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_stale_post_default_action_clears_the_schedule() {
		$post = new WP_Post( array( 'ID' => 12, 'post_status' => 'trash' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 12 )->andReturn( $post );
		\WP_Mock::userFunction( 'aps_archive_post' )->never();

		\WP_Mock::onFilter( 'aps_schedule_stale_action' )
			->with( 'clear', 12, 'trash' )
			->reply( 'clear' );

		$this->stubScheduleMeta( 12 );
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );

		$sweeper = $this->sweeperWithQueryResult( array( 12 ), 1 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->remaining );
	}

	/**
	 * Stale action 'keep': nothing is written, the post stays due and is
	 * retried on the next sweep.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_stale_post_keep_action_leaves_schedule_untouched() {
		$post = new WP_Post( array( 'ID' => 13, 'post_status' => 'trash' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 13 )->andReturn( $post );
		\WP_Mock::userFunction( 'aps_archive_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		\WP_Mock::onFilter( 'aps_schedule_stale_action' )
			->with( 'clear', 13, 'trash' )
			->reply( 'keep' );

		$sweeper = $this->sweeperWithQueryResult( array( 13 ), 1 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		// Untouched: still matches the same due query next run.
		$this->assertSame( 1, $result->remaining );
	}

	/**
	 * Stale action 'exempt': source flips to exempt and the due time is
	 * dropped, tombstoning the record instead of deleting it.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_stale_post_exempt_action_tombstones_the_schedule() {
		$post = new WP_Post( array( 'ID' => 14, 'post_status' => 'trash' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 14 )->andReturn( $post );
		\WP_Mock::userFunction( 'aps_archive_post' )->never();

		\WP_Mock::onFilter( 'aps_schedule_stale_action' )
			->with( 'clear', 14, 'trash' )
			->reply( 'exempt' );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 14, ScheduleMeta::META_SOURCE, 'exempt' )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 14, ScheduleMeta::META_TIME )
			->andReturn( true );

		$sweeper = $this->sweeperWithQueryResult( array( 14 ), 1 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->remaining );
	}

	// -----------------------------------------------------------------------
	// process_batch() — a post whose archive attempt fails
	// -----------------------------------------------------------------------

	/**
	 * A failed archive attempt below the cap: attempts increments, the
	 * batch counts it failed, and — critically — the schedule is NOT
	 * abandoned yet, so it stays due for the next sweep.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_failed_archive_increments_attempts_without_abandoning() {
		$post = new WP_Post( array( 'ID' => 15, 'post_status' => 'publish' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 15 )->andReturn( $post );
		\WP_Mock::userFunction( 'aps_archive_post' )->once()->with( 15 )->andReturn( false );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 15, ScheduleMeta::META_ATTEMPTS, true )->andReturn( 0 );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 15, ScheduleMeta::META_ATTEMPTS, 1 )
			->andReturn( true );

		\WP_Mock::onFilter( 'aps_schedule_max_attempts' )->with( 3 )->reply( 3 );

		$sweeper = $this->sweeperWithQueryResult( array( 15 ), 1 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 1, $result->failed );
		// Still due: not abandoned, still matches the same query next run.
		$this->assertSame( 1, $result->remaining );
	}

	/**
	 * A failed archive attempt that reaches the cap: the schedule is
	 * abandoned via the default exempt action, and the abandonment action
	 * fires with the post ID and the attempt count that triggered it.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_failed_archive_at_cap_is_abandoned() {
		$post = new WP_Post( array( 'ID' => 16, 'post_status' => 'publish' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 16 )->andReturn( $post );
		\WP_Mock::userFunction( 'aps_archive_post' )->once()->with( 16 )->andReturn( false );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 16, ScheduleMeta::META_ATTEMPTS, true )->andReturn( 2 );
		\WP_Mock::userFunction( 'update_post_meta' )
			->with( 16, ScheduleMeta::META_ATTEMPTS, 3 )
			->andReturn( true );

		\WP_Mock::onFilter( 'aps_schedule_max_attempts' )->with( 3 )->reply( 3 );
		\WP_Mock::onFilter( 'aps_schedule_abandon_action' )
			->with( 'exempt', 16, 3 )
			->reply( 'exempt' );

		\WP_Mock::userFunction( 'update_post_meta' )
			->with( 16, ScheduleMeta::META_SOURCE, 'exempt' )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 16, ScheduleMeta::META_TIME )
			->andReturn( true );

		\WP_Mock::expectAction( 'aps_schedule_archive_abandoned', 16, 3 );

		$sweeper = $this->sweeperWithQueryResult( array( 16 ), 1 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 1, $result->failed );
		// Abandoned (dropped from the due set) via the default exempt action.
		$this->assertSame( 0, $result->remaining );
	}

	// -----------------------------------------------------------------------
	// process_batch() — the budget stops the loop
	// -----------------------------------------------------------------------

	/**
	 * The single most important test in this phase: with a Budget already
	 * exhausted, the loop must stop before touching the FIRST post, and
	 * every post behind it in the batch must be left completely untouched.
	 * A processor that ignores the budget reintroduces the timeouts this
	 * whole abstraction exists to prevent.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_stops_immediately_when_budget_is_already_exhausted() {
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'aps_archive_post' )->never();
		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		\WP_Mock::expectAction( 'aps_scheduled_archives_swept', array(), array() );

		$sweeper = $this->sweeperWithQueryResult( array( 20, 21, 22 ), 3 );
		$result  = $sweeper->process_batch( $this->alwaysExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertTrue( $result->budget_exhausted );
		// Nothing was touched, so nothing dropped out of the due set — all
		// three posts the query found are still remaining.
		$this->assertSame( 3, $result->remaining );
	}

	/**
	 * remaining stays positive when the batch does NOT drain the queue: one
	 * post drops out (archived) while a second, in the same batch, stays due
	 * (a failed attempt below the cap) — remaining must count only the one
	 * that actually left the due set, not both and not neither.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_remaining_reflects_a_mixed_batch_that_does_not_drain_the_queue() {
		$archived_post = new WP_Post( array( 'ID' => 40, 'post_status' => 'publish' ) );
		$failed_post   = new WP_Post( array( 'ID' => 41, 'post_status' => 'publish' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 40 )->andReturn( $archived_post );
		\WP_Mock::userFunction( 'get_post' )->with( 41 )->andReturn( $failed_post );

		\WP_Mock::userFunction( 'aps_archive_post' )->with( 40 )->andReturn( $archived_post );
		\WP_Mock::userFunction( 'aps_archive_post' )->with( 41 )->andReturn( false );

		$this->stubScheduleMeta( 40 );
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 41, ScheduleMeta::META_ATTEMPTS, true )->andReturn( 0 );
		\WP_Mock::userFunction( 'update_post_meta' )
			->with( 41, ScheduleMeta::META_ATTEMPTS, 1 )->andReturn( true );
		\WP_Mock::onFilter( 'aps_schedule_max_attempts' )->with( 3 )->reply( 3 );

		$sweeper = $this->sweeperWithQueryResult( array( 40, 41 ), 2 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		$this->assertSame( 1, $result->failed );
		// found_posts (2) minus the one that dropped out (post 40, archived);
		// post 41 failed below the cap and still matches the due query.
		$this->assertSame( 1, $result->remaining );
		$this->assertFalse( $result->budget_exhausted );
	}

	/**
	 * remaining must come from the query's found_posts, not from the size of
	 * the page this batch actually received: a due-post backlog larger than
	 * one batch_size page still has to report the TRUE remaining count, or a
	 * runner watching should_continue_now()/has_more_work() would think a
	 * drained page means a drained queue and stop chaining continuations
	 * with a backlog still sitting behind it.
	 *
	 * @covers ArchivedPostStatus\Schedule\Sweeper::process_batch
	 */
	public function test_process_batch_remaining_reflects_found_posts_beyond_this_batchs_page() {
		$post = new WP_Post( array( 'ID' => 50, 'post_status' => 'publish' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 50 )->andReturn( $post );
		\WP_Mock::userFunction( 'aps_archive_post' )->once()->with( 50 )->andReturn( $post );

		$this->stubScheduleMeta( 50 );
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );

		// The query's page contains only ONE due id, but found_posts (100)
		// reports a much larger backlog than fit on this page.
		$sweeper = $this->sweeperWithQueryResult( array( 50 ), 100 );
		$result  = $sweeper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		// found_posts (100) minus the one that dropped out (post 50,
		// archived) — NOT count( $ids ) (1) minus 1, which would wrongly
		// report a drained queue with 99 due posts still waiting.
		$this->assertSame( 99, $result->remaining );
		$this->assertTrue( $result->has_more_work() );
	}
}
