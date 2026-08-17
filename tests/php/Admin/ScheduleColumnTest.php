<?php
/**
 * Admin\ScheduleColumn Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ScheduleColumn
 * @covers ArchivedPostStatus\Schedule\ScheduleMeta
 *
 * Covers the observable behaviors of ScheduleColumn:
 *   - hooks() defers column registration to `wp_loaded` and registers the
 *     `the_posts` cache-priming filter eagerly, mirroring ArchiveColumn
 *   - prime_schedule_user_cache() warms get_userdata() for Manual-sourced
 *     rows only, gated by the same inverted (non-archived-view) predicate as
 *     add_column() -- the highest-traffic screens this column renders on are
 *     exactly where an uncached per-row lookup would hurt most
 *   - register_post_type_hooks() / post_type_hooks() register per
 *     schedulable post type, scoped by the `aps_scheduled_archive_post_types`
 *     setting (empty means every supported type)
 *   - add_column() / register_sortable() show the column on normal views and
 *     hide it under the archived-status filter -- the INVERSE of
 *     ArchiveColumn's own gate; this is the one deliberate inversion the
 *     phase brief calls out, and is the single riskiest place to get
 *     backwards, since a flipped gate looks identical to "the column exists"
 *     in a test that only checks presence
 *   - render_cell() delegates to ScheduleColumnCellRenderer::render() and
 *     echoes its already-escaped return value without re-escaping, including
 *     the no-record state, which -- unlike ArchiveColumn's render_cell() --
 *     still renders a cell (an em dash) rather than emitting nothing
 *
 * Cell-content correctness (the four ScheduleMeta states, escaping, and the
 * "never resolves the cascade" invariant) is exhaustively covered by
 * ScheduleColumnCellRendererTest; the render_cell() tests here only prove
 * the delegation wiring, mirroring how ArchiveColumnTest's render_cell()
 * suite relates to ArchiveColumnCellRendererTest.
 */

use ArchivedPostStatus\Admin\ScheduleColumn;
use ArchivedPostStatus\Schedule\ScheduleMeta;

/**
 * ScheduleColumn test case.
 *
 * @since 0.5.0
 * @covers ArchivedPostStatus\Admin\ScheduleColumn
 */
class ScheduleColumnTest extends TestCase {

	/**
	 * @var ScheduleColumn
	 */
	protected $column;

	/**
	 * @since 0.5.0
	 */
	public function set_up() {
		parent::set_up();
		$this->column = new ScheduleColumn();
	}

	/**
	 * Stub the boundary schedulable_post_types() walks when the site has no
	 * configured `scheduled_archive_post_types` override: the
	 * aps_scheduled_archive_post_types filter replies empty (the schema
	 * default), so the fallback is every post type
	 * aps_get_supported_post_types() resolves.
	 *
	 * @param string[] $supported Post types aps_get_supported_post_types() should resolve.
	 */
	private function stubEverySupportedPostType( array $supported = array( 'post', 'page' ) ): void {
		\WP_Mock::onFilter( 'aps_scheduled_archive_post_types' )
			->with( array() )
			->reply( array() );

		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array_combine( $supported, $supported ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( $supported )
			->reply( $supported );
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * hooks() registers exactly two descriptors: the eager `the_posts`
	 * cache-priming filter (mirroring ArchiveColumn) and the `wp_loaded`
	 * deferral that later registers the per-post-type column hooks.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::hooks
	 */
	public function test_hooks_registers_cache_priming_and_defers_column_registration_to_wp_loaded() {
		$descriptors = $this->column->hooks();

		$this->assertCount( 2, $descriptors );

		$this->assertSame( 'the_posts', $descriptors[0]->hook );
		$this->assertSame( array( $this->column, 'prime_schedule_user_cache' ), $descriptors[0]->callback );

		$this->assertSame( 'wp_loaded', $descriptors[1]->hook );
		$this->assertSame( array( $this->column, 'register_post_type_hooks' ), $descriptors[1]->callback );
	}

	// -----------------------------------------------------------------------
	// register_post_type_hooks() / schedulable_post_types()
	// -----------------------------------------------------------------------

	/**
	 * With no `scheduled_archive_post_types` override configured, the column
	 * registers for EVERY supported post type -- the documented "empty means
	 * every supported type" semantic, the opposite convention from
	 * `auto_archive_types`.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::register_post_type_hooks
	 */
	public function test_register_post_type_hooks_registers_every_supported_post_type_when_unconfigured() {
		$this->stubEverySupportedPostType( array( 'post', 'page', 'book' ) );

		\WP_Mock::expectFilterAdded( 'manage_post_posts_columns', array( $this->column, 'add_column' ), 10, 1 );
		\WP_Mock::expectActionAdded( 'manage_post_posts_custom_column', array( $this->column, 'render_cell' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'manage_edit-post_sortable_columns', array( $this->column, 'register_sortable' ), 10, 1 );
		\WP_Mock::expectFilterAdded( 'manage_page_posts_columns', array( $this->column, 'add_column' ), 10, 1 );
		\WP_Mock::expectActionAdded( 'manage_page_posts_custom_column', array( $this->column, 'render_cell' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'manage_edit-page_sortable_columns', array( $this->column, 'register_sortable' ), 10, 1 );
		\WP_Mock::expectFilterAdded( 'manage_book_posts_columns', array( $this->column, 'add_column' ), 10, 1 );
		\WP_Mock::expectActionAdded( 'manage_book_posts_custom_column', array( $this->column, 'render_cell' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'manage_edit-book_sortable_columns', array( $this->column, 'register_sortable' ), 10, 1 );

		$this->column->register_post_type_hooks();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * When a site opts a specific subset of post types into scheduling via
	 * `aps_scheduled_archive_post_types`, the column registers ONLY for
	 * those -- e.g. a 'book' post type present but not opted in gets no
	 * column hooks at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::register_post_type_hooks
	 */
	public function test_register_post_type_hooks_registers_only_the_configured_post_types() {
		\WP_Mock::onFilter( 'aps_scheduled_archive_post_types' )
			->with( array() )
			->reply( array( 'post' ) );

		\WP_Mock::expectFilterAdded( 'manage_post_posts_columns', array( $this->column, 'add_column' ), 10, 1 );
		\WP_Mock::expectActionAdded( 'manage_post_posts_custom_column', array( $this->column, 'render_cell' ), 10, 2 );
		\WP_Mock::expectFilterAdded( 'manage_edit-post_sortable_columns', array( $this->column, 'register_sortable' ), 10, 1 );

		$this->column->register_post_type_hooks();

		// WP_Mock has no built-in "hook never added" assertion; the absence
		// of 'book'/'page' expectations above combined with the exact
		// per-type triplet asserted is the negative proof -- any stray
		// registration for another post type would leave those add_filter/
		// add_action calls unaccounted for and WP_Mock would flag the
		// unexpected call.
		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// add_column
	// -----------------------------------------------------------------------

	/**
	 * THE INVERSION: on the normal 'All' / default list-table view (no
	 * archived-status filter active), add_column() injects the
	 * 'aps_scheduled' header -- the opposite gate from ArchiveColumn, which
	 * shows its column ONLY on the archived filter.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::add_column
	 */
	public function test_add_column_adds_scheduled_header_on_the_normal_view() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( '' );

		$result = $this->column->add_column( array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' ) );

		$this->assertArrayHasKey( 'aps_scheduled', $result );
		$this->assertSame( 'Scheduled', $result['aps_scheduled'] );
		// Unlike ArchiveColumn, the core Date column is left alone -- a
		// pending schedule is additive information, not a replacement for
		// the publish/modified date on a non-archived post.
		$this->assertArrayHasKey( 'date', $result );
	}

	/**
	 * THE INVERSION, other half: on the archived-status filter, add_column()
	 * returns the columns unchanged -- every cell would be empty there,
	 * since an archived post cannot also have a pending schedule.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::add_column
	 */
	public function test_add_column_hides_the_scheduled_header_on_the_archived_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'archive' );

		$columns = array( 'cb' => '', 'title' => 'Title' );
		$result  = $this->column->add_column( $columns );

		$this->assertSame( $columns, $result );
		$this->assertArrayNotHasKey( 'aps_scheduled', $result );
	}

	/**
	 * Multi-status filters (`?post_status[]=publish&post_status[]=archive`)
	 * expose `post_status` as an array -- the archived slug appearing
	 * anywhere in it still hides the column, matching ArchiveColumn's own
	 * (array) + in_array handling of the same query var shape.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::add_column
	 */
	public function test_add_column_hides_on_array_post_status_filter_containing_archive() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( array( 'archive', 'publish' ) );

		$result = $this->column->add_column( array( 'cb' => '' ) );

		$this->assertArrayNotHasKey( 'aps_scheduled', $result );
	}

	/**
	 * An array post_status that does NOT contain the archived slug still
	 * shows the column.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::add_column
	 */
	public function test_add_column_shows_on_array_post_status_filter_without_archive() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( array( 'publish', 'draft' ) );

		$result = $this->column->add_column( array( 'cb' => '' ) );

		$this->assertArrayHasKey( 'aps_scheduled', $result );
	}

	/**
	 * Regression: add_column() must escape the header label itself --
	 * core's WP_List_Table::print_column_headers() echoes the returned value
	 * as raw HTML with no escaping of its own.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::add_column
	 */
	public function test_add_column_header_label_is_escaped_by_add_column_itself() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( '' );

		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => "[[{$s}]]"
		);

		$result = $this->column->add_column( array( 'cb' => '' ) );

		$this->assertSame( '[[Scheduled]]', $result['aps_scheduled'] );
	}

	// -----------------------------------------------------------------------
	// register_sortable
	// -----------------------------------------------------------------------

	/**
	 * On the normal view, register_sortable() exposes the column key to
	 * WordPress so the header is clickable for ordering.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::register_sortable
	 */
	public function test_register_sortable_adds_scheduled_column_on_the_normal_view() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( '' );

		$result = $this->column->register_sortable( array( 'title' => 'title' ) );

		$this->assertArrayHasKey( 'aps_scheduled', $result );
		$this->assertSame( 'aps_scheduled', $result['aps_scheduled'] );
	}

	/**
	 * On the archived filter, register_sortable() returns the sortable map
	 * unchanged -- the inverse of ArchiveColumn's own gate.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::register_sortable
	 */
	public function test_register_sortable_returns_unchanged_on_the_archived_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'archive' );

		$sortable = array( 'title' => 'title' );
		$result   = $this->column->register_sortable( $sortable );

		$this->assertSame( $sortable, $result );
	}

	// -----------------------------------------------------------------------
	// prime_schedule_user_cache
	// -----------------------------------------------------------------------

	/**
	 * Stub the get_post_meta() reads ScheduleMeta::for_post() performs for
	 * one post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $source  Raw stored source value ('manual', 'rule', 'exempt', or '' for no record).
	 * @param int    $user    Stored schedule user id.
	 */
	private function stubScheduleMeta( int $post_id, string $source, int $user = 0 ): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_SOURCE, true )
			->andReturn( $source );

		if ( '' === $source ) {
			return;
		}

		\WP_Mock::userFunction( 'get_post_meta' )->with( $post_id, ScheduleMeta::META_TIME, true )->andReturn( '1800000000' );
		\WP_Mock::userFunction( 'get_post_meta' )->with( $post_id, ScheduleMeta::META_USER, true )->andReturn( (string) $user );
		\WP_Mock::userFunction( 'get_post_meta' )->with( $post_id, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )->with( $post_id, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );
	}

	/**
	 * Performance: on the admin normal-view main query, every Manual-sourced
	 * row's schedule-user id is collected and warmed with a single
	 * cache_users() call — a duplicate id (two posts manually scheduled by
	 * the same editor) proves the call is deduped, not one cache_users() per
	 * row. Rule- and Exempt-sourced rows never call get_userdata(), so their
	 * user ids (0, for Rule) must not appear in the primed set. The filter
	 * must also return $posts completely unchanged (the `the_posts`
	 * contract).
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::prime_schedule_user_cache
	 */
	public function test_prime_schedule_user_cache_warms_the_cache_for_manual_schedule_user_ids_on_the_normal_view() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( '' );

		$posts = array(
			$this->createMockPost( array( 'ID' => 1 ) ),
			$this->createMockPost( array( 'ID' => 2 ) ),
			$this->createMockPost( array( 'ID' => 3 ) ),
			$this->createMockPost( array( 'ID' => 4 ) ),
		);

		$this->stubScheduleMeta( 1, 'manual', 7 );
		$this->stubScheduleMeta( 2, 'manual', 7 ); // duplicate on purpose -- proves dedup.
		$this->stubScheduleMeta( 3, 'rule' );      // Rule: no user lookup, must be excluded.
		$this->stubScheduleMeta( 4, 'manual', 9 );

		$captured = null;
		\WP_Mock::userFunction( 'cache_users' )
			->once()
			->andReturnUsing(
				static function ( $ids ) use ( &$captured ) {
					$captured = $ids;
				}
			);

		$result = $this->column->prime_schedule_user_cache( $posts, $query );

		$this->assertSame( $posts, $result, 'the filter must return $posts unchanged' );
		$this->assertSame( array( 7, 9 ), array_values( $captured ) );
	}

	/**
	 * A page whose rows are all no-record, Exempt, or Rule-sourced has
	 * nothing to prime — cache_users() must not fire. The Exempt and Rule
	 * rows are stubbed with a nonzero stored user id on purpose: by
	 * convention those sources always stamp user 0, so a test that left them
	 * at 0 would pass even if the source check were deleted outright (the
	 * `if ( $meta->user )` guard alone would still filter them out). Giving
	 * them a nonzero id is what makes the source-scoping check itself
	 * load-bearing rather than redundant with the zero-user guard.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::prime_schedule_user_cache
	 */
	public function test_prime_schedule_user_cache_skips_non_manual_rows() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( '' );

		$posts = array(
			$this->createMockPost( array( 'ID' => 1 ) ),
			$this->createMockPost( array( 'ID' => 2 ) ),
			$this->createMockPost( array( 'ID' => 3 ) ),
		);

		$this->stubScheduleMeta( 1, '' );
		$this->stubScheduleMeta( 2, 'exempt', 5 );
		$this->stubScheduleMeta( 3, 'rule', 5 );

		\WP_Mock::userFunction( 'cache_users' )->never();

		$result = $this->column->prime_schedule_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}

	/**
	 * An empty page (no rows at all) has nothing to prime either.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::prime_schedule_user_cache
	 */
	public function test_prime_schedule_user_cache_does_nothing_for_an_empty_page() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( '' );

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$result = $this->column->prime_schedule_user_cache( array(), $query );

		$this->assertSame( array(), $result );
	}

	/**
	 * Outside the admin (`is_admin()` false), priming never consults the
	 * query or the posts at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::prime_schedule_user_cache
	 */
	public function test_prime_schedule_user_cache_does_nothing_outside_admin() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->never();

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$posts  = array( $this->createMockPost( array( 'ID' => 1 ) ) );
		$result = $this->column->prime_schedule_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}

	/**
	 * On a secondary query (is_main_query() === false), priming does
	 * nothing — only the main admin list-table query is primed.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::prime_schedule_user_cache
	 */
	public function test_prime_schedule_user_cache_does_nothing_for_secondary_query() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( false );

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$posts  = array( $this->createMockPost( array( 'ID' => 1 ) ) );
		$result = $this->column->prime_schedule_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}

	/**
	 * THE INVERSION applies to priming too: on the archived-status filter
	 * view, priming does nothing — the Scheduled cells never render there
	 * (an archived post cannot also have a pending schedule), so warming the
	 * user cache would spend a query for no reader. Mirrors add_column() /
	 * register_sortable()'s own gate, and ArchiveColumn::prime_archive_user_cache()'s
	 * mirror-image gate.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::prime_schedule_user_cache
	 */
	public function test_prime_schedule_user_cache_does_nothing_on_the_archived_view() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( 'archive' );

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$posts  = array( $this->createMockPost( array( 'ID' => 1 ) ) );
		$result = $this->column->prime_schedule_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}

	// -----------------------------------------------------------------------
	// render_cell
	// -----------------------------------------------------------------------

	/**
	 * For columns that are not ours, render_cell() emits nothing and does
	 * not touch the meta store.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::render_cell
	 */
	public function test_render_cell_emits_nothing_for_unrelated_column() {
		\WP_Mock::userFunction( 'get_post_meta' )->never();

		ob_start();
		$this->column->render_cell( 'date', 42 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Unlike ArchiveColumn::render_cell(), a post with no schedule record
	 * still gets a cell -- the em dash ScheduleColumnCellRenderer::render()
	 * renders for a null ScheduleMeta.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::render_cell
	 */
	public function test_render_cell_renders_a_dash_when_post_has_no_schedule() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )
			->andReturn( '' );

		ob_start();
		$this->column->render_cell( 'aps_scheduled', 42 );
		$output = ob_get_clean();

		$this->assertSame(
			'<span>—</span><span class="aps-schedule-inline-data" data-local="" aria-hidden="true" style="display:none"></span>',
			$output
		);
	}

	/**
	 * A manually-scheduled post renders the rich "Scheduled by NAME" line --
	 * end-to-end proof that render_cell() reads ScheduleMeta::for_post() and
	 * echoes ScheduleColumnCellRenderer::render()'s output without
	 * re-escaping it.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumn::render_cell
	 */
	public function test_render_cell_emits_the_rich_output_for_a_manual_schedule() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )
			->andReturn( 'manual' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )
			->andReturn( '1800000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )
			->andReturn( '7' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )
			->andReturn( '0' );

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027 at 9:00 am' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );

		$user               = new \stdClass();
		$user->display_name = 'Alice Editor';
		\WP_Mock::userFunction( 'get_userdata' )->with( 7 )->andReturn( $user );

		ob_start();
		$this->column->render_cell( 'aps_scheduled', 42 );
		$output = ob_get_clean();

		$this->assertSame(
			'<span>Scheduled by Alice Editor</span><br><span class="aps-schedule-datetime">March 3, 2027 at 9:00 am</span>'
			. '<span class="aps-schedule-inline-data" data-local="2027-01-15T08:00" aria-hidden="true" style="display:none"></span>',
			$output
		);
	}
}
