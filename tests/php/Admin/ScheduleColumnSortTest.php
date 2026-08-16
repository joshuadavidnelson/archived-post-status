<?php
/**
 * Admin\ScheduleColumnSort Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort
 *
 * Covers ScheduleColumnSort's meta-aware sorting of the post list by the
 * Scheduled column:
 *   - hooks() registers the `pre_get_posts` sorting action
 *   - handle_sort() opts a query into scoped `posts_join` / `posts_orderby`
 *     filters instead of rewriting the query directly, and only when it
 *     should
 *   - filter_sort_join() / filter_sort_orderby() are scoped by identity to
 *     the query handle_sort() opted in, so they are a no-op for every other
 *     WP_Query on the page
 *
 * Mirrors ArchiveColumnSortTest exactly, over the schedule-time meta key.
 */

use ArchivedPostStatus\Admin\ScheduleColumn;
use ArchivedPostStatus\Admin\ScheduleColumnSort;
use ArchivedPostStatus\Schedule\ScheduleMeta;

/**
 * ScheduleColumnSort test case.
 *
 * @since 0.5.0
 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort
 */
class ScheduleColumnSortTest extends TestCase {

	/**
	 * @var ScheduleColumnSort
	 */
	protected $sort;

	/**
	 * @since 0.5.0
	 */
	public function set_up() {
		parent::set_up();
		$this->sort = new ScheduleColumnSort();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * hooks() registers exactly one descriptor: the `pre_get_posts` sorting
	 * action.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::hooks
	 */
	public function test_hooks_registers_the_pre_get_posts_sort_action() {
		$descriptors = $this->sort->hooks();

		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'pre_get_posts', $descriptors[0]->hook );
		$this->assertSame( array( $this->sort, 'handle_sort' ), $descriptors[0]->callback );
	}

	// -----------------------------------------------------------------------
	// handle_sort
	// -----------------------------------------------------------------------

	/**
	 * Regression: a naive `$query->set( 'meta_key', ... )` hands ordering off
	 * to WP_Query's meta_query machinery, which builds a JOIN + WHERE that
	 * only matches posts that HAVE a postmeta row for that key — silently
	 * dropping every post with no pending schedule, i.e. most rows on the
	 * normal views this column shows on. On an admin main query with
	 * `orderby=aps_scheduled`, handle_sort() must therefore never touch the
	 * query directly via `set()` — it instead registers scoped
	 * `posts_join` / `posts_orderby` filters.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 */
	public function test_handle_sort_registers_scoped_join_and_orderby_filters_instead_of_exclusionary_meta_key() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( ScheduleColumn::COLUMN_KEY );

		$query->shouldReceive( 'set' )->never();

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		// WP_Mock verifies the expectFilterAdded() expectations during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * filter_sort_join() LEFT JOINs wp_postmeta on the schedule-time key so
	 * schedule-less posts still appear in the JOINed result set.
	 *
	 * The scoping proof is the second half of this test: invoked for a
	 * DIFFERENT \WP_Query instance than the one handle_sort() opted in, it
	 * must return $join completely untouched.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::filter_sort_join
	 */
	public function test_filter_sort_join_left_joins_postmeta_for_the_scoped_query_only() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( ScheduleColumn::COLUMN_KEY );
		$query->shouldReceive( 'set' )->never();

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		global $wpdb;
		$wpdb = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';

			/** @var array<int, array{query: string, args: array<int, mixed>}> */
			public array $prepared_queries = array();

			/**
			 * @param string $query Prepared query template.
			 * @param mixed  ...$args Bound parameters.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->prepared_queries[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}
		};

		$joined = $this->sort->filter_sort_join( ' INNER JOIN wp_term_relationships ON ( wp_posts.ID = wp_term_relationships.object_id )', $query );

		$this->assertStringContainsString( 'LEFT JOIN', $joined );
		$this->assertStringContainsString( $wpdb->postmeta, $joined );
		$this->assertStringNotContainsString( 'INNER JOIN wp_postmeta', $joined );
		$this->assertCount( 1, $wpdb->prepared_queries, 'expected exactly one prepared LEFT JOIN fragment' );
		$this->assertSame(
			ScheduleMeta::META_TIME,
			$wpdb->prepared_queries[0]['args'][0],
			'the JOIN must match on the schedule-time meta key'
		);

		// Scoping proof: a query the sort was never applied to must come
		// back with $join completely unchanged.
		$other_query = \Mockery::mock( 'WP_Query' );
		$unchanged   = $this->sort->filter_sort_join( ' original join', $other_query );
		$this->assertSame( ' original join', $unchanged, 'the filter must be a no-op for any query other than the one scoped in handle_sort()' );
	}

	/**
	 * filter_sort_orderby() sorts by the LEFT JOINed schedule-time value via
	 * `COALESCE( ..., 0 )`, and respects the query's own `order` query var.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_coalesces_schedule_less_rows_and_respects_query_order() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( ScheduleColumn::COLUMN_KEY );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( 'asc' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( 'wp_posts.post_date DESC', $query );

		$this->assertStringContainsString( 'COALESCE', $orderby );
		$this->assertStringContainsString( 'ASC', $orderby );
		$this->assertStringNotContainsString( 'meta_value_num', $orderby );

		// Scoping proof.
		$other_query = \Mockery::mock( 'WP_Query' );
		$unchanged   = $this->sort->filter_sort_orderby( 'original orderby', $other_query );
		$this->assertSame( 'original orderby', $unchanged, 'the filter must be a no-op for any query other than the one scoped in handle_sort()' );
	}

	/**
	 * When the query's `order` var is neither 'ASC' nor 'DESC', it defaults
	 * to DESC rather than emitting an incomplete/invalid ORDER BY fragment.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_defaults_to_desc_for_an_unrecognized_order_value() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( ScheduleColumn::COLUMN_KEY );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( '' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( '', $query );

		$this->assertStringContainsString( 'DESC', $orderby );
	}

	// -----------------------------------------------------------------------
	// Exact-match pins
	// -----------------------------------------------------------------------

	/**
	 * Exact-match pin for filter_sort_join()'s complete output for the
	 * scoped query, using the aps_schedule_sort alias — distinct from
	 * ArchiveColumnSort's aps_archive_sort — so both sorts can be active on
	 * the same screen without colliding.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::filter_sort_join
	 */
	public function test_filter_sort_join_pins_the_exact_fragment_for_the_scoped_query() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( ScheduleColumn::COLUMN_KEY );
		$query->shouldReceive( 'set' )->never();

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		global $wpdb;
		$wpdb = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';

			/** @var array<int, array{query: string, args: array<int, mixed>}> */
			public array $prepared_queries = array();

			/**
			 * @param string $query Prepared query template.
			 * @param mixed  ...$args Bound parameters.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->prepared_queries[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}
		};

		$joined = $this->sort->filter_sort_join( ' INNER JOIN wp_term_relationships ON ( wp_posts.ID = wp_term_relationships.object_id )', $query );

		$this->assertSame(
			' INNER JOIN wp_term_relationships ON ( wp_posts.ID = wp_term_relationships.object_id )'
			. ' LEFT JOIN wp_postmeta AS aps_schedule_sort'
			. ' ON ( aps_schedule_sort.post_id = wp_posts.ID AND aps_schedule_sort.meta_key = %s )',
			$joined,
			'the complete generated LEFT JOIN fragment must match byte for byte'
		);
	}

	/**
	 * Exact-match pin for filter_sort_orderby()'s complete output when the
	 * query's `order` var is 'asc'.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_pins_the_exact_output_for_asc() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( ScheduleColumn::COLUMN_KEY );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( 'asc' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( 'wp_posts.post_date DESC', $query );

		$this->assertSame( 'COALESCE( aps_schedule_sort.meta_value + 0, 0 ) ASC', $orderby );
	}

	/**
	 * Exact-match pin for filter_sort_orderby()'s complete output when the
	 * query's `order` var is 'desc'.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_pins_the_exact_output_for_desc() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( ScheduleColumn::COLUMN_KEY );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( 'desc' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( 'wp_posts.post_date ASC', $query );

		$this->assertSame( 'COALESCE( aps_schedule_sort.meta_value + 0, 0 ) DESC', $orderby );
	}

	/**
	 * On a secondary query (is_main_query() === false), handle_sort()
	 * returns without touching meta_key/orderby — only the main admin
	 * list-table query is rewritten.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 */
	public function test_handle_sort_does_not_rewrite_for_secondary_query() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( false );
		$query->shouldReceive( 'set' )->never();

		$this->sort->handle_sort( $query );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * When the orderby query var isn't ours, handle_sort() leaves the query
	 * alone — even when the main-query / admin gates pass. Explicitly proves
	 * ArchiveColumn's own key ('aps_archived') does not accidentally trigger
	 * this sort too.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 */
	public function test_handle_sort_does_not_rewrite_for_unrelated_orderby() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->andReturn( 'aps_archived' );
		$query->shouldReceive( 'set' )->never();

		$this->sort->handle_sort( $query );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Outside the admin (`is_admin()` false), handle_sort() returns without
	 * consulting the query at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnSort::handle_sort
	 */
	public function test_handle_sort_does_not_rewrite_outside_admin() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->never();
		$query->shouldReceive( 'set' )->never();

		$this->sort->handle_sort( $query );

		$this->addToAssertionCount( 1 );
	}
}
