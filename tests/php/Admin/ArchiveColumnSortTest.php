<?php
/**
 * Admin\ArchiveColumnSort Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort
 *
 * Covers ArchiveColumnSort's meta-aware sorting of the post list by the
 * Archived column:
 *   - hooks() registers the `pre_get_posts` sorting action
 *   - handle_sort() opts a query into scoped `posts_join` / `posts_orderby`
 *     filters instead of rewriting the query directly, and only when it
 *     should
 *   - filter_sort_join() / filter_sort_orderby() are scoped by identity to
 *     the query handle_sort() opted in, so they are a no-op for every other
 *     WP_Query on the page
 *
 * Split out of ArchiveColumnTest as part of the 0.4.0 restructure that moved
 * this stateful sorting cluster into its own class — see
 * ArchivedPostStatus\Admin\ArchiveColumnSort's class docblock.
 */

use ArchivedPostStatus\Admin\ArchiveColumnSort;
use ArchivedPostStatus\Archive\ArchiveMeta;

/**
 * ArchiveColumnSort test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort
 */
class ArchiveColumnSortTest extends TestCase {

	/**
	 * @var ArchiveColumnSort
	 */
	protected $sort;

	/**
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->sort = new ArchiveColumnSort();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * hooks() registers exactly one descriptor: the `pre_get_posts` sorting
	 * action. Before the 0.4.0 restructure this lived alongside the
	 * `the_posts` / `wp_loaded` descriptors on ArchiveColumn::hooks(); see
	 * ArchiveColumnTest::test_hooks_registers_user_cache_priming_and_defers_column_hooks_to_wp_loaded()
	 * for what remains there.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::hooks
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
	 * Regression: a naive `$query->set( 'meta_key', ... )` hands
	 * ordering off to WP_Query's meta_query machinery, which builds a JOIN
	 * + WHERE that only matches posts that HAVE a postmeta row for that
	 * key — silently dropping every post that doesn't. That population is
	 * not hypothetical: posts archived under pre-0.4.0 releases wrote no
	 * archive postmeta at all (see ArchiveMeta::for_post()'s legacy branch
	 * and Plugin::has_pre_040_content()), so clicking the Archived column
	 * header would make that content vanish from the list with no error
	 * and no explanation.
	 *
	 * On an admin main query with `orderby=aps_archived`, handle_sort()
	 * must therefore never touch the query directly via `set()` at all —
	 * it instead registers scoped `posts_join` / `posts_orderby` filters
	 * (see the filter_sort_join() / filter_sort_orderby() tests below) that
	 * LEFT JOIN + COALESCE so meta-less rows sort to one end instead of
	 * disappearing.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
	 */
	public function test_handle_sort_registers_scoped_join_and_orderby_filters_instead_of_exclusionary_meta_key() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );

		// The exclusionary `set( 'meta_key', ... )` / `set( 'orderby', 'meta_value_num' )`
		// path must never run again — it drops meta-less rows entirely.
		$query->shouldReceive( 'set' )->never();

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		// WP_Mock verifies the expectFilterAdded() expectations during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * filter_sort_join() LEFT JOINs wp_postmeta on the archive-date key so
	 * meta-less posts still appear in the JOINed result set — a LEFT JOIN,
	 * unlike the INNER-JOIN-like WHERE the old `set( 'meta_key', ... )`
	 * path produced, cannot exclude rows on its own.
	 *
	 * The scoping proof is the second half of this test: invoked for a
	 * DIFFERENT \WP_Query instance than the one handle_sort() opted in, it
	 * must return $join completely untouched. That identity check is what
	 * actually stops this filter from leaking onto some other query later
	 * in the same admin page load — a leaked posts_join would corrupt every
	 * query on the page, which is a far worse bug than the one being fixed
	 * here.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::filter_sort_join
	 */
	public function test_filter_sort_join_left_joins_postmeta_for_the_scoped_query_only() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
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
			ArchiveMeta::META_ARCHIVE_DATE,
			$wpdb->prepared_queries[0]['args'][0],
			'the JOIN must match on the archive-date meta key'
		);

		// Scoping proof: a query the sort was never applied to must come
		// back with $join completely unchanged.
		$other_query = \Mockery::mock( 'WP_Query' );
		$unchanged   = $this->sort->filter_sort_join( ' original join', $other_query );
		$this->assertSame( ' original join', $unchanged, 'the filter must be a no-op for any query other than the one scoped in handle_sort()' );
	}

	/**
	 * filter_sort_orderby() sorts by the LEFT JOINed archive-date value via
	 * `COALESCE( ..., 0 )`, so meta-less rows land at one end of the sort
	 * instead of being excluded, and it respects the query's own `order`
	 * query var instead of hardcoding a direction — the column header's
	 * normal ASC/DESC toggle-on-click behavior still has to work.
	 *
	 * Same scoping proof as filter_sort_join(): a different \WP_Query
	 * instance must get $orderby back unchanged.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_coalesces_meta_less_rows_and_respects_query_order() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( 'asc' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( 'wp_posts.post_date DESC', $query );

		$this->assertStringContainsString( 'COALESCE', $orderby );
		$this->assertStringContainsString( 'ASC', $orderby );
		$this->assertStringNotContainsString( 'meta_value_num', $orderby );

		// Scoping proof: a query the sort was never applied to must come
		// back with $orderby completely unchanged.
		$other_query = \Mockery::mock( 'WP_Query' );
		$unchanged   = $this->sort->filter_sort_orderby( 'original orderby', $other_query );
		$this->assertSame( 'original orderby', $unchanged, 'the filter must be a no-op for any query other than the one scoped in handle_sort()' );
	}

	/**
	 * When the query's `order` var is neither 'ASC' nor 'DESC' (e.g. unset,
	 * empty string), filter_sort_orderby() must default to DESC rather than
	 * emitting an incomplete/invalid ORDER BY fragment.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_defaults_to_desc_for_an_unrecognized_order_value() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( '' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( '', $query );

		$this->assertStringContainsString( 'DESC', $orderby );
	}

	// -----------------------------------------------------------------------
	// Exact-match pins (Phase 0a) — see docs/plans/0.4.0-refactor.md Step 0.
	//
	// The loose assertStringContainsString() checks above (LEFT JOIN,
	// COALESCE, ASC, DESC) would still pass if a refactor restructured the
	// clause or swapped LEFT for INNER while leaving the literal 'LEFT JOIN'
	// elsewhere in the string. These pins assert the complete generated
	// fragment byte for byte against the CURRENT implementation, so
	// ArchiveColumnSortSql (the class this SQL is slated to move into) has
	// an exact contract to reproduce rather than a fuzzy one.
	// -----------------------------------------------------------------------

	/**
	 * Exact-match pin for filter_sort_join()'s complete output for the
	 * scoped query, including the wpdb double's prepare() returning its
	 * template argument unchanged ('%s )') — that literal is part of the
	 * pinned contract, not an artifact to clean up.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::filter_sort_join
	 */
	public function test_filter_sort_join_pins_the_exact_fragment_for_the_scoped_query() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
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
			. ' LEFT JOIN wp_postmeta AS aps_archive_sort'
			. ' ON ( aps_archive_sort.post_id = wp_posts.ID AND aps_archive_sort.meta_key = %s )',
			$joined,
			'the complete generated LEFT JOIN fragment must match byte for byte'
		);
	}

	/**
	 * Exact-match pin for filter_sort_orderby()'s complete output when the
	 * query's `order` var is 'asc'.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_pins_the_exact_output_for_asc() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( 'asc' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( 'wp_posts.post_date DESC', $query );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) ASC', $orderby );
	}

	/**
	 * Exact-match pin for filter_sort_orderby()'s complete output when the
	 * query's `order` var is 'desc'.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_pins_the_exact_output_for_desc() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( 'desc' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( 'wp_posts.post_date ASC', $query );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) DESC', $orderby );
	}

	/**
	 * Exact-match pin for filter_sort_orderby()'s complete output when the
	 * query's `order` var is neither 'ASC' nor 'DESC' — proving the DESC
	 * fallback produces the exact same fragment as an explicit 'desc'.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::filter_sort_orderby
	 */
	public function test_filter_sort_orderby_pins_the_exact_output_for_an_unrecognized_order_value() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
		$query->shouldReceive( 'set' )->never();
		$query->shouldReceive( 'get' )->with( 'order' )->once()->andReturn( 'banana' );

		\WP_Mock::expectFilterAdded( 'posts_join', array( $this->sort, 'filter_sort_join' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'posts_orderby', array( $this->sort, 'filter_sort_orderby' ), 10, 2 );

		$this->sort->handle_sort( $query );

		$orderby = $this->sort->filter_sort_orderby( '', $query );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) DESC', $orderby );
	}

	/**
	 * On a secondary query (is_main_query() === false), handle_sort()
	 * returns without touching meta_key/orderby — only the main admin
	 * list-table query is rewritten.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
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
	 * When the orderby query var isn't ours, handle_sort() leaves the
	 * query alone — even when the main-query / admin gates pass.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
	 */
	public function test_handle_sort_does_not_rewrite_for_unrelated_orderby() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->andReturn( 'date' );
		$query->shouldReceive( 'set' )->never();

		$this->sort->handle_sort( $query );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Outside the admin (`is_admin()` false), handle_sort() returns without
	 * consulting the query at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSort::handle_sort
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
