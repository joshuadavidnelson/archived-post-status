<?php
/**
 * Admin\ArchiveColumn Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ArchiveColumn
 * @covers ArchivedPostStatus\Archive\ArchiveMeta
 *
 * Covers the four observable behaviors of ArchiveColumn:
 *   - add_column() adds the 'aps_archived' key only on the archive filter,
 *     handling both scalar and array `post_status` query vars (§1.3 #11)
 *   - register_sortable() registers the column on the archive filter
 *   - render_cell() emits the right markup per archive-meta shape
 *   - handle_sort() rewrites the WP_Query orderby only when it should
 *
 * Cell rendering tests dispatch through the real ArchiveMeta::for_post()
 * (mocking get_post_meta) rather than stubbing the static — the test
 * stays at the observable HTML output level. Because the render path
 * intentionally exercises ArchiveMeta::for_post() and the handle_sort
 * path references ArchiveMeta::META_ARCHIVE_DATE, ArchiveMeta is declared
 * at class level so its coverage is credited.
 */

use ArchivedPostStatus\Admin\ArchiveColumn;
use ArchivedPostStatus\Archive\ArchiveMeta;

/**
 * ArchiveColumn test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\ArchiveColumn
 * @covers ArchivedPostStatus\Archive\ArchiveMeta
 */
class ArchiveColumnTest extends TestCase {

	/**
	 * @var ArchiveColumn
	 */
	protected $column;

	/**
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->column = new ArchiveColumn();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * hooks() registers exactly two descriptors: the `pre_get_posts`
	 * sorting action, and the `wp_loaded` deferral that later registers
	 * the per-post-type column hooks (see
	 * test_register_post_type_hooks_registers_column_hooks_per_supported_post_type()
	 * below).
	 *
	 * Before the 0.4.0 CPT-timing fix, hooks() enumerated
	 * aps_get_supported_post_types() directly and built three descriptors
	 * per type here — evaluated on `plugins_loaded`, before third-party
	 * custom post types exist. That enumeration moved to
	 * post_type_hooks(), invoked only once `wp_loaded` fires, so this test
	 * no longer needs to stub the supported-post-types boundary at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::hooks
	 */
	public function test_hooks_registers_sort_action_and_defers_column_hooks_to_wp_loaded() {
		$descriptors = $this->column->hooks();

		$this->assertCount( 2, $descriptors );

		$hook_names = array_map( static fn( $d ) => $d->hook, $descriptors );

		$this->assertContains( 'pre_get_posts', $hook_names );
		$this->assertContains( 'wp_loaded', $hook_names );
	}

	/**
	 * register_post_type_hooks() is the `wp_loaded` callback hooks()
	 * defers to. Once custom post types are guaranteed to exist, it must
	 * register the column-header filter, the cell-render action, and the
	 * sortable-column filter for EVERY supported post type — including a
	 * custom post type (`book`) that would not have existed yet had this
	 * run eagerly on `plugins_loaded`, which is exactly the 0.4.0
	 * CPT-timing bug this deferral fixes.
	 *
	 * Asserting against the hook names AND the exact callback array is the
	 * strongest contract here: those strings are baked into WordPress
	 * core's filter dispatch and a typo silently drops the column from the
	 * table.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::register_post_type_hooks
	 */
	public function test_register_post_type_hooks_registers_column_hooks_per_supported_post_type() {
		// drive aps_get_supported_post_types() through its real
		// WP-boundary + filter dependencies rather than stubbing the
		// plugin-owned function itself. A regression in the real function
		// (e.g. forgetting to apply the supported filter) now surfaces here.
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post', 'page' => 'page', 'book' => 'book' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post', 'page', 'book' ) )
			->reply( array( 'post', 'page', 'book' ) );

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

		// WP_Mock verifies the expectFilterAdded()/expectActionAdded()
		// expectations during tearDown.
		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// add_column
	// -----------------------------------------------------------------------

	/**
	 * On `?post_status=archive` (scalar), add_column() injects the
	 * 'aps_archived' header. The label text comes from __() which the
	 * WP_Mock passthrough returns as-is.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::add_column
	 */
	public function test_add_column_adds_archived_header_on_scalar_archive_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'archive' );

		$result = $this->column->add_column( array( 'cb' => '', 'title' => 'Title' ) );

		$this->assertArrayHasKey( 'aps_archived', $result );
		$this->assertSame( 'Archived', $result['aps_archived'] );
	}

	/**
	 * In the archived view, core's Date column is replaced by the Archived
	 * column: "Published"/"Last Modified" labels are misleading for
	 * archived rows, and the archive date is the one that matters there.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::add_column
	 */
	public function test_add_column_removes_the_core_date_column_in_the_archived_view() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'archive' );

		$result = $this->column->add_column(
			array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' )
		);

		$this->assertArrayNotHasKey( 'date', $result );
		$this->assertArrayHasKey( 'aps_archived', $result );
	}

	/**
	 * Outside the archived view the Date column is untouched.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::add_column
	 */
	public function test_add_column_keeps_the_core_date_column_elsewhere() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'publish' );

		$result = $this->column->add_column(
			array( 'cb' => '', 'date' => 'Date' )
		);

		$this->assertArrayHasKey( 'date', $result );
		$this->assertArrayNotHasKey( 'aps_archived', $result );
	}

	/**
	 * Multi-status filters (`?post_status[]=publish&post_status[]=archive`)
	 * expose `post_status` as an array. The multi-status-filter fix wraps it through
	 * (array) + in_array so the column header still appears.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::add_column
	 */
	public function test_add_column_adds_archived_header_on_array_post_status_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( array( 'archive', 'publish' ) );

		$result = $this->column->add_column( array( 'cb' => '' ) );

		$this->assertArrayHasKey( 'aps_archived', $result );
	}

	/**
	 * On any other filter (or the 'All' view with empty post_status),
	 * add_column() returns the columns unchanged.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::add_column
	 */
	public function test_add_column_returns_columns_unchanged_when_not_archive_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'publish' );

		$columns = array( 'cb' => '', 'title' => 'Title' );
		$result  = $this->column->add_column( $columns );

		$this->assertSame( $columns, $result );
		$this->assertArrayNotHasKey( 'aps_archived', $result );
	}

	// -----------------------------------------------------------------------
	// register_sortable
	// -----------------------------------------------------------------------

	/**
	 * On the archive filter, register_sortable() exposes the column key
	 * to WordPress so the header is clickable for ordering.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::register_sortable
	 */
	public function test_register_sortable_adds_archived_column_on_archive_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'archive' );

		$result = $this->column->register_sortable( array( 'title' => 'title' ) );

		$this->assertArrayHasKey( 'aps_archived', $result );
		$this->assertSame( 'aps_archived', $result['aps_archived'] );
	}

	/**
	 * Off the archive filter, register_sortable() returns the sortable map
	 * unchanged — no sort handle is published for the (missing) column.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::register_sortable
	 */
	public function test_register_sortable_returns_unchanged_when_not_archive_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'publish' );

		$sortable = array( 'title' => 'title' );
		$result   = $this->column->register_sortable( $sortable );

		$this->assertSame( $sortable, $result );
	}

	// -----------------------------------------------------------------------
	// render_cell
	// -----------------------------------------------------------------------

	/**
	 * For columns that are not ours, render_cell() emits nothing and does
	 * not touch the meta store.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_emits_nothing_for_unrelated_column() {
		// If render_cell falls through to ArchiveMeta::for_post() it would
		// call get_post_meta — verify it never reaches that code path.
		\WP_Mock::userFunction( 'get_post_meta' )->never();

		ob_start();
		$this->column->render_cell( 'date', 42 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * For a non-archived post (ArchiveMeta::for_post returns null), the
	 * cell is empty even when our column key is in play.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_emits_nothing_when_post_is_not_archived() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( '' );

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Legacy archives (archive_date / archive_user are 0) render the simple
	 * 'Archived' string — no rich form with name / date.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_emits_plain_archived_text_for_legacy_meta() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( '' );

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<span>Archived</span>', $output );
		$this->assertStringNotContainsString( 'Archived by', $output );
		$this->assertStringNotContainsString( 'aps-archive-datetime', $output );
	}

	/**
	 * A fully-populated archive row renders the rich "Archived by NAME"
	 * line plus the timestamp span. We mock wp_date and get_userdata so
	 * the test is deterministic regardless of the system clock / locale.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_emits_user_and_date_for_populated_meta() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( '7' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( 'closed' );

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key ) {
				return 'date_format' === $key ? 'F j, Y' : 'g:i a';
			}
		);
		\WP_Mock::userFunction( 'wp_date' )
			->andReturn( 'November 14, 2023 at 10:13 pm' );

		$user                = new \stdClass();
		$user->display_name = 'Alice Editor';
		\WP_Mock::userFunction( 'get_userdata' )
			->with( 7 )
			->andReturn( $user );

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Archived by Alice Editor', $output );
		$this->assertStringContainsString( 'November 14, 2023 at 10:13 pm', $output );
		$this->assertStringContainsString( 'aps-archive-datetime', $output );
	}

	/**
	 * When the archiving user's account has since been deleted,
	 * get_userdata() returns false and the cell falls back to the
	 * localised "Unknown" attribution instead of a fatal or blank name.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_renders_unknown_attribution_when_user_is_deleted() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( '7' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( 'closed' );

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key ) {
				return 'date_format' === $key ? 'F j, Y' : 'g:i a';
			}
		);
		\WP_Mock::userFunction( 'wp_date' )
			->andReturn( 'November 14, 2023 at 10:13 pm' );

		\WP_Mock::userFunction( 'get_userdata' )
			->with( 7 )
			->andReturn( false );

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Archived by Unknown', $output );
		$this->assertStringContainsString( 'November 14, 2023 at 10:13 pm', $output );
	}

	/**
	 * System-context archives (archive_date > 0, archive_user === 0)
	 * happen on anonymous WP-CLI / cron / server-side aps_archive_post()
	 * calls where get_current_user_id() returns 0. The cell should still
	 * surface the date alongside an "Archived by system" attribution,
	 * not collapse to the pre-0.4.0 legacy bare-label rendering.
	 *
	 * get_userdata() must never be called on this branch — verifying
	 * that pins the rendering path to the system branch and rules out a
	 * silent regression that falls into the named-user lookup with a
	 * failing user.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_renders_system_and_date_for_anonymous_archive() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( '' );

		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $key ) {
				return 'date_format' === $key ? 'F j, Y' : 'g:i a';
			}
		);
		\WP_Mock::userFunction( 'wp_date' )
			->andReturn( 'November 14, 2023 at 10:13 pm' );

		// The system branch must not perform a user lookup.
		\WP_Mock::userFunction( 'get_userdata' )->never();

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Archived by system', $output );
		$this->assertStringContainsString( 'November 14, 2023 at 10:13 pm', $output );
		$this->assertStringContainsString( 'aps-archive-datetime', $output );
	}

	/**
	 * XSS regression: a user with HTML in their display_name (set via
	 * profile.php on a permissive install, or via a custom user_meta
	 * filter) must never have that HTML rendered into the column. The
	 * 0.4.0 release added an esc_html() wrapper around the `%1$s` arg —
	 * this test pins that escaping so a future refactor that drops the
	 * wrapper fails loudly.
	 *
	 * If esc_html stopped being applied to the user name, the literal
	 * `<script>` would appear unescaped in the rendered output.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_escapes_html_in_user_display_name() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( '13' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( '' );

		\WP_Mock::userFunction( 'get_option' )->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'November 14, 2023' );

		// Override the WP_Mock default esc_html passthrough with a real
		// htmlspecialchars escape — production's esc_html() is exactly
		// this. Without this override the assertion below couldn't tell
		// "production wrapped the name in esc_html()" from "production
		// emitted the raw string". With it, the test is genuinely a
		// regression check for the 0.4.0 XSS fix.
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);

		$user                = new \stdClass();
		$user->display_name = '<script>alert(1)</script>';
		\WP_Mock::userFunction( 'get_userdata' )
			->with( 13 )
			->andReturn( $user );

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		// The raw `<script>` tag must never appear; the escaped form must.
		// We assert against both directions so a partial regression
		// (e.g. dropping the esc_html wrapper) is caught either way.
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
	}

	// -----------------------------------------------------------------------
	// handle_sort
	// -----------------------------------------------------------------------

	/**
	 * On an admin main query with `orderby=aps_archived`, handle_sort()
	 * rewrites meta_key + orderby so WP_Query orders by the archive date.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::handle_sort
	 */
	public function test_handle_sort_rewrites_orderby_on_admin_main_query() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->once()->andReturn( 'aps_archived' );
		$query->shouldReceive( 'set' )->once()->with( 'meta_key', ArchiveMeta::META_ARCHIVE_DATE );
		$query->shouldReceive( 'set' )->once()->with( 'orderby', 'meta_value_num' );

		$this->column->handle_sort( $query );

		// Mockery verifies the once() expectations during tearDown; record a
		// synthetic assertion so PHPUnit doesn't flag this test as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * On a secondary query (is_main_query() === false), handle_sort()
	 * returns without touching meta_key/orderby — only the main admin
	 * list-table query is rewritten.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::handle_sort
	 */
	public function test_handle_sort_does_not_rewrite_for_secondary_query() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( false );
		$query->shouldReceive( 'set' )->never();

		$this->column->handle_sort( $query );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * When the orderby query var isn't ours, handle_sort() leaves the
	 * query alone — even when the main-query / admin gates pass.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::handle_sort
	 */
	public function test_handle_sort_does_not_rewrite_for_unrelated_orderby() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'orderby' )->andReturn( 'date' );
		$query->shouldReceive( 'set' )->never();

		$this->column->handle_sort( $query );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Outside the admin (`is_admin()` false), handle_sort() returns without
	 * consulting the query at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::handle_sort
	 */
	public function test_handle_sort_does_not_rewrite_outside_admin() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->never();
		$query->shouldReceive( 'set' )->never();

		$this->column->handle_sort( $query );

		$this->addToAssertionCount( 1 );
	}
}
