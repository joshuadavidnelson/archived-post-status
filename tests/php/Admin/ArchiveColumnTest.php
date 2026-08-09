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
 *     handling both scalar and array `post_status` query vars
 *   - register_sortable() registers the column on the archive filter
 *   - render_cell() emits the right markup per archive-meta shape
 *   - prime_archive_user_cache() warms the user cache once per page instead
 *     of once per row
 *
 * Cell rendering tests dispatch through the real ArchiveMeta::for_post()
 * (mocking get_post_meta) rather than stubbing the static — the test
 * stays at the observable HTML output level. Because the render path
 * intentionally exercises ArchiveMeta::for_post(), ArchiveMeta is declared
 * at class level so its coverage is credited.
 *
 * The meta-aware sorting cluster (handle_sort() / filter_sort_join() /
 * filter_sort_orderby()) moved to ArchivedPostStatus\Admin\ArchiveColumnSort
 * as part of the 0.4.0 restructure; its tests moved to
 * ArchiveColumnSortTest.
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
	 * hooks() registers exactly two descriptors: the `the_posts`
	 * user-cache-priming filter, and the `wp_loaded` deferral that later
	 * registers the per-post-type column hooks (see
	 * test_register_post_type_hooks_registers_column_hooks_per_supported_post_type()
	 * below). The `pre_get_posts` sorting action moved to
	 * ArchiveColumnSort::hooks() as part of the 0.4.0 restructure — see
	 * ArchiveColumnSortTest::test_hooks_registers_the_pre_get_posts_sort_action().
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
	public function test_hooks_registers_user_cache_priming_and_defers_column_hooks_to_wp_loaded() {
		$descriptors = $this->column->hooks();

		$this->assertCount( 2, $descriptors );

		$hook_names = array_map( static fn( $d ) => $d->hook, $descriptors );

		$this->assertContains( 'the_posts', $hook_names );
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
	 * Regression: add_column() writes the column label directly into
	 * the `manage_..._posts_columns` filter's return value, which core's
	 * WP_List_Table::print_column_headers() echoes as raw HTML with no
	 * escaping of its own -- so add_column() itself must escape the label
	 * for HTML text context. Before the fix this happened to look correct
	 * only because ArchiveLabel::value() escaped with esc_attr()
	 * internally; once that internal escaping is removed, the header must
	 * escape itself instead.
	 *
	 * Distinguishable esc_attr()/esc_html() markers (rather than WP_Mock's
	 * inert passthrough defaults) prove which function actually produced
	 * the final string: the header must carry exactly one esc_html()
	 * marker and no esc_attr() marker.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::add_column
	 */
	public function test_add_column_header_label_is_escaped_by_add_column_itself() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'archive' );

		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $s ) => "(({$s}))"
		);
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => "[[{$s}]]"
		);

		$result = $this->column->add_column( array( 'cb' => '' ) );

		$this->assertSame( '[[Archived]]', $result['aps_archived'] );
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
	 * Regression: the legacy-meta branch already wraps
	 * `column_label()` in esc_html() (see render_archive_cell()), but
	 * before the fix ArchiveLabel::value() ALSO escaped with esc_attr()
	 * internally -- double-escaping the label. Distinguishable
	 * esc_attr()/esc_html() markers prove the final output carries exactly
	 * one escaping pass (esc_html()'s marker), not a nested pair.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_escapes_legacy_label_exactly_once() {
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

		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $s ) => "(({$s}))"
		);
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => "[[{$s}]]"
		);

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertSame( '<span>[[Archived]]</span>', $output );
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
	// Exact-match pins (Phase 0b) — see docs/plans/0.4.0-refactor.md Step 0.
	//
	// The tests above assert substrings, so a printf() -> return-string
	// conversion (Step 3 of the refactor) could reorder the name/date spans
	// or change whitespace / <br> placement and still pass every one of
	// them. These pins assert the COMPLETE output string for each of the
	// three render_archive_cell() states plus the deleted-user fallback and
	// the XSS-escaping case, capturing the current bytes exactly as they
	// are. The legacy no-date state is already exact-pinned above by
	// test_render_cell_escapes_legacy_label_exactly_once() (assertSame on
	// '<span>[[Archived]]</span>'), so it is not duplicated here.
	// -----------------------------------------------------------------------

	/**
	 * Exact-match pin for render_cell()'s complete output when both an
	 * archive date and a resolvable user are present. WP_Mock's default
	 * esc_html()/__() passthrough (unstubbed here) returns its input
	 * unchanged, so the pinned string is exactly what production emits
	 * today with no escaping transformation applied.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_pins_the_exact_output_for_date_and_user() {
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

		$this->assertSame(
			'<span>Archived by Alice Editor</span><br><span class="aps-archive-datetime">November 14, 2023 at 10:13 pm</span>',
			$output
		);
	}

	/**
	 * Exact-match pin for render_cell()'s complete output for a
	 * system-context archive (archive_date present, archive_user 0).
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_pins_the_exact_output_for_date_and_system_context() {
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

		\WP_Mock::userFunction( 'get_userdata' )->never();

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertSame(
			'<span>Archived by system</span><br><span class="aps-archive-datetime">November 14, 2023 at 10:13 pm</span>',
			$output
		);
	}

	/**
	 * Exact-match pin for render_cell()'s complete output when the
	 * archiving user's account has since been deleted — the "Unknown"
	 * fallback.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_pins_the_exact_output_for_deleted_user_fallback() {
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

		$this->assertSame(
			'<span>Archived by Unknown</span><br><span class="aps-archive-datetime">November 14, 2023 at 10:13 pm</span>',
			$output
		);
	}

	/**
	 * Exact-match pin for render_cell()'s complete output when the
	 * archiving user's display_name contains HTML. Overrides esc_html()
	 * with a real htmlspecialchars() implementation (as the existing
	 * substring-based regression test above does) so the pin proves the
	 * exact escaped bytes, not merely that escaping happened somewhere.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_pins_the_exact_output_for_the_xss_escaping_case() {
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

		$this->assertSame(
			'<span>Archived by &lt;script&gt;alert(1)&lt;/script&gt;</span><br><span class="aps-archive-datetime">November 14, 2023</span>',
			$output
		);
	}

	/**
	 * Exact-match pin for the double esc_html() layer inside
	 * render_archive_cell()'s populated branch: production wraps
	 * esc_html() around BOTH the attribution_template() format string
	 * itself AND each substituted value ($name, $date_time) separately
	 * (see ArchiveColumn.php render_archive_cell(), the printf() call).
	 * The refactor plan calls this double layer a "preserve exactly" item
	 * for Step 3, which converts that printf() into a returned string.
	 *
	 * No test above can observe the format-string layer:
	 * attribution_template() returns 'Archived by %1$s', which contains no
	 * HTML metacharacters, so esc_html() applied to it is a no-op on the
	 * actual bytes -- deleting that wrapper leaves every substring/exact
	 * assertion elsewhere in this file unchanged. Stubbing esc_html() with
	 * a distinguishable `[[...]]` marker (the same technique
	 * test_render_cell_escapes_legacy_label_exactly_once() uses) is what
	 * makes the format-string layer observable at all: it puts one pair of
	 * marker brackets around the entire "Archived by %1$s" template, with
	 * the name's own `[[...]]` marker landing at the `%1$s` position
	 * inside those outer brackets, rather than at the edges of the
	 * template. esc_attr() is also stubbed with a distinguishable
	 * `((...))` marker and never appears in the expected output, so the
	 * pin additionally proves esc_attr() is not used anywhere in this
	 * path.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::render_cell
	 */
	public function test_render_cell_pins_both_esc_html_layers_around_the_attribution_template() {
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

		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $s ) => "(({$s}))"
		);
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => "[[{$s}]]"
		);

		ob_start();
		$this->column->render_cell( 'aps_archived', 42 );
		$output = ob_get_clean();

		$this->assertSame(
			'<span>[[Archived by [[Alice Editor]]]]</span><br><span class="aps-archive-datetime">[[November 14, 2023 at 10:13 pm]]</span>',
			$output
		);
	}

	// -----------------------------------------------------------------------
	// prime_archive_user_cache
	// -----------------------------------------------------------------------

	/**
	 * Performance: on the admin archived-list main query, every row's
	 * archive-user id is collected and warmed with a single cache_users()
	 * call — a duplicate id (two posts archived by the same user) proves
	 * the call is deduped, not one cache_users() per row. The filter must
	 * also return $posts completely unchanged (the `the_posts` contract).
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::prime_archive_user_cache
	 */
	public function test_prime_archive_user_cache_warms_the_cache_for_archived_user_ids_on_the_archived_list_view() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( 'archive' );

		$posts = array(
			$this->createMockPost( array( 'ID' => 1 ) ),
			$this->createMockPost( array( 'ID' => 2 ) ),
			$this->createMockPost( array( 'ID' => 3 ) ),
		);

		\WP_Mock::userFunction( 'get_post_meta' )->with( 1, ArchiveMeta::META_ARCHIVE_USER, true )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_meta' )->with( 2, ArchiveMeta::META_ARCHIVE_USER, true )->andReturn( 7 ); // duplicate on purpose -- proves dedup.
		\WP_Mock::userFunction( 'get_post_meta' )->with( 3, ArchiveMeta::META_ARCHIVE_USER, true )->andReturn( 9 );

		$captured = null;
		\WP_Mock::userFunction( 'cache_users' )
			->once()
			->andReturnUsing(
				static function ( $ids ) use ( &$captured ) {
					$captured = $ids;
				}
			);

		$result = $this->column->prime_archive_user_cache( $posts, $query );

		$this->assertSame( $posts, $result, 'the filter must return $posts unchanged' );
		$this->assertSame( array( 7, 9 ), array_values( $captured ) );
	}

	/**
	 * A post archived in a system context (anonymous WP-CLI/cron, no
	 * archive_user recorded) has nothing to prime — cache_users() must not
	 * fire when every row's archive-user id resolves to 0.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::prime_archive_user_cache
	 */
	public function test_prime_archive_user_cache_skips_posts_with_no_archive_user() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( 'archive' );

		$posts = array( $this->createMockPost( array( 'ID' => 1 ) ) );

		\WP_Mock::userFunction( 'get_post_meta' )->with( 1, ArchiveMeta::META_ARCHIVE_USER, true )->andReturn( 0 );
		\WP_Mock::userFunction( 'cache_users' )->never();

		$result = $this->column->prime_archive_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}

	/**
	 * An empty page (no rows at all) has nothing to prime either.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::prime_archive_user_cache
	 */
	public function test_prime_archive_user_cache_does_nothing_for_an_empty_page() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( 'archive' );

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$result = $this->column->prime_archive_user_cache( array(), $query );

		$this->assertSame( array(), $result );
	}

	/**
	 * Outside the admin (`is_admin()` false), priming never consults the
	 * query or the posts at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::prime_archive_user_cache
	 */
	public function test_prime_archive_user_cache_does_nothing_outside_admin() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->never();

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$posts  = array( $this->createMockPost( array( 'ID' => 1 ) ) );
		$result = $this->column->prime_archive_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}

	/**
	 * On a secondary query (is_main_query() === false), priming does
	 * nothing — only the main admin list-table query is primed.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::prime_archive_user_cache
	 */
	public function test_prime_archive_user_cache_does_nothing_for_secondary_query() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( false );

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$posts  = array( $this->createMockPost( array( 'ID' => 1 ) ) );
		$result = $this->column->prime_archive_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}

	/**
	 * Outside the archived-status filter view, priming does nothing — the
	 * archived-by cell never renders there, so warming the user cache would
	 * spend a query for no reader. Mirrors add_column() / register_sortable()'s
	 * own gate.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumn::prime_archive_user_cache
	 */
	public function test_prime_archive_user_cache_does_nothing_outside_archived_view() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$query = \Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_status' )->once()->andReturn( 'publish' );

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'cache_users' )->never();

		$posts  = array( $this->createMockPost( array( 'ID' => 1 ) ) );
		$result = $this->column->prime_archive_user_cache( $posts, $query );

		$this->assertSame( $posts, $result );
	}
}
