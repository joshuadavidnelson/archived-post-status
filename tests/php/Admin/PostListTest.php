<?php
/**
 * PostList Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostList
 *
 * SUT-mocking of plugin-owned aps_* helpers
 * (aps_is_supported_post_type, aps_is_read_only,
 * aps_current_user_can_archive/_unarchive/_view, aps_get_archive_post_link,
 * aps_get_unarchive_post_link, _aps_get_archivable_statuses) was retired
 * from this file. Each scenario now stubs only the WP-boundary functions
 * those helpers traverse (current_user_can, get_post_types, get_query_var,
 * admin_url, add_query_arg, wp_nonce_url, esc_url, esc_attr) plus filter
 * callbacks on the documented extension points (`aps_supported_post_types`,
 * `aps_excluded_post_types`, `aps_archivable_statuses`, `aps_is_read_only`,
 * `aps_default_archive_capability`). The real procedural helpers execute
 * against those, so a regression in aps_is_supported_post_type now surfaces
 * here as a failed assertion rather than being silently bypassed.
 *
 * ->never() assertions on aps_* helpers became ->never() assertions on
 * the underlying WP boundary functions where the migration changed the
 * trust boundary — the negation contract is preserved.
 */

/**
 * PostList test case
 *
 * Tests the post list screen functionality.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\PostList
 */
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

class PostListTest extends TestCase {

	use BoundaryStubs;

	/**
	 * PostList instance
	 *
	 * @var ArchivedPostStatus\Admin\PostList
	 */
	protected $post_list;

	/**
	 * The BulkActionHandler instance `$post_list` is constructed with. Kept
	 * accessible (rather than constructed inline in set_up()) so
	 * hook-registration tests can assert the exact callback array
	 * (`[ $this->bulk_handler, 'handle' ]`) PostList hands to add_filter() —
	 * see test_register_post_type_hooks_registers_bulk_action_hooks_per_supported_post_type().
	 *
	 * @var ArchivedPostStatus\Admin\BulkActionHandler
	 */
	protected $bulk_handler;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->bulk_handler = new ArchivedPostStatus\Admin\BulkActionHandler();
		$this->post_list    = new ArchivedPostStatus\Admin\PostList( $this->bulk_handler );
	}

	/**
	 * Reset request superglobals so any test that touches $_GET doesn't bleed
	 * into siblings.
	 */
	public function tear_down() {
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}

	// the prior `test_hooks_returns_hookable_descriptors` smoke
	// test was deleted — `assertIsArray`/`assertNotEmpty` doesn't pin any
	// behavior. The composition surface is now covered end-to-end by
	// `PluginTest::test_hookables_includes_admin_only_set_when_is_admin_is_true`.
	// The replacement below asserts the *specific* hooks the SUT registers,
	// pinning the integration to WordPress's filter/action names — those
	// are load-bearing strings that a typo would silently break.

	/**
	 * hooks() returns the bundle of post-list-table integration hooks that
	 * do NOT depend on the supported-post-types list: `query_vars` +
	 * `wp_list_table_show_post_checkbox` (filters), `post_action_archive` +
	 * `post_action_unarchive` (admin-action entry points), the two REAL
	 * row-actions hooks core fires (`post_row_actions`, `page_row_actions`
	 * — registered unconditionally, not per post type; see `row_actions()`'s
	 * docblock), and the `wp_loaded` deferral that later registers the
	 * per-post-type bulk-action hooks.
	 *
	 * Before the 0.4.0 CPT-timing fix, hooks() called
	 * aps_get_supported_post_types() directly and built
	 * `bulk_actions-edit-{type}` / `handle_bulk_actions-edit-{type}` /
	 * `{type}_row_actions` per type here — evaluated on `plugins_loaded`,
	 * before third-party custom post types exist. That's why this test no
	 * longer needs stubSupportedPostTypesBoundary(): hooks() itself never
	 * touches the supported-types boundary any more. The per-type bulk-
	 * action coverage moved to
	 * test_register_post_type_hooks_registers_bulk_action_hooks_per_supported_post_type()
	 * below, which exercises the actual `wp_loaded` callback.
	 *
	 * The exact count pins that nothing extra sneaks into this list — the
	 * per-type hooks in particular must NOT appear here. Each descriptor is
	 * pinned in full: hook name, type, callback, priority, and accepted
	 * args — `wp_list_table_show_post_checkbox`, `post_row_actions`, and
	 * `page_row_actions` take 2 accepted args (they receive `$actions` and
	 * the post/WP_Post_Type object); the rest default to 1.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::hooks
	 */
	public function test_hooks_registers_query_vars_filter_and_post_actions() {
		$hooks = $this->post_list->hooks();

		$this->assertCount( 8, $hooks );

		$this->assertSame( 'filter', $hooks[0]->type );
		$this->assertSame( 'query_vars', $hooks[0]->hook );
		$this->assertSame( array( $this->post_list, 'query_vars' ), $hooks[0]->callback );
		$this->assertSame( 10, $hooks[0]->priority );
		$this->assertSame( 1, $hooks[0]->accepted_args );

		$this->assertSame( 'filter', $hooks[1]->type );
		$this->assertSame( 'wp_list_table_show_post_checkbox', $hooks[1]->hook );
		$this->assertSame( array( $this->post_list, 'show_archived_row_checkbox' ), $hooks[1]->callback );
		$this->assertSame( 10, $hooks[1]->priority );
		$this->assertSame( 2, $hooks[1]->accepted_args );

		$this->assertSame( 'action', $hooks[2]->type );
		$this->assertSame( 'post_action_archive', $hooks[2]->hook );
		$this->assertSame( array( $this->post_list, 'post_action_archive' ), $hooks[2]->callback );
		$this->assertSame( 10, $hooks[2]->priority );
		$this->assertSame( 1, $hooks[2]->accepted_args );

		$this->assertSame( 'action', $hooks[3]->type );
		$this->assertSame( 'post_action_unarchive', $hooks[3]->hook );
		$this->assertSame( array( $this->post_list, 'post_action_unarchive' ), $hooks[3]->callback );
		$this->assertSame( 10, $hooks[3]->priority );
		$this->assertSame( 1, $hooks[3]->accepted_args );

		$this->assertSame( 'filter', $hooks[4]->type );
		$this->assertSame( 'post_row_actions', $hooks[4]->hook );
		$this->assertSame( array( $this->post_list, 'row_actions' ), $hooks[4]->callback );
		$this->assertSame( 10, $hooks[4]->priority );
		$this->assertSame( 2, $hooks[4]->accepted_args );

		$this->assertSame( 'filter', $hooks[5]->type );
		$this->assertSame( 'page_row_actions', $hooks[5]->hook );
		$this->assertSame( array( $this->post_list, 'row_actions' ), $hooks[5]->callback );
		$this->assertSame( 10, $hooks[5]->priority );
		$this->assertSame( 2, $hooks[5]->accepted_args );

		$this->assertSame( 'action', $hooks[6]->type );
		$this->assertSame( 'wp_loaded', $hooks[6]->hook );
		$this->assertSame( array( $this->post_list, 'register_post_type_hooks' ), $hooks[6]->callback );
		$this->assertSame( 10, $hooks[6]->priority );
		$this->assertSame( 1, $hooks[6]->accepted_args );

		$this->assertSame( 'filter', $hooks[7]->type );
		$this->assertSame( 'removable_query_args', $hooks[7]->hook );
		$this->assertSame( array( $this->post_list, 'removable_query_args' ), $hooks[7]->callback );
		$this->assertSame( 10, $hooks[7]->priority );
		$this->assertSame( 1, $hooks[7]->accepted_args );

		// The per-post-type bulk-action hooks must NOT be built eagerly —
		// they are only registered once register_post_type_hooks() runs
		// (see the wp_loaded descriptor asserted above).
		$hook_names = array_map( static fn( $h ) => $h->hook, $hooks );
		$this->assertNotContains( 'bulk_actions-edit-post', $hook_names );
		$this->assertNotContains( 'handle_bulk_actions-edit-post', $hook_names );
	}

	/**
	 * register_post_type_hooks() is the `wp_loaded` callback hooks()
	 * defers to (see its docblock and PostList's class-level rationale).
	 * Once custom post types are guaranteed to exist, it must register
	 * `bulk_actions-edit-{type}` and `handle_bulk_actions-edit-{type}` for
	 * EVERY supported post type — including a custom post type
	 * (`book`) that would not have existed yet had this run eagerly on
	 * `plugins_loaded`, which is exactly the 0.4.0 CPT-timing bug this
	 * deferral fixes.
	 *
	 * Asserts the exact callback arrays PostList hands to add_filter():
	 * `[ $this->post_list, 'bulk_actions' ]` and
	 * `[ $this->bulk_handler, 'handle' ]` — not just that "some" filter was
	 * added for each hook name.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::register_post_type_hooks
	 */
	public function test_register_post_type_hooks_registers_bulk_action_hooks_per_supported_post_type() {
		$this->stubSupportedPostTypesBoundary(
			array( 'post', 'page', 'book' ),
			array( 'post', 'page', 'book' )
		);

		\WP_Mock::expectFilterAdded( 'bulk_actions-edit-post', array( $this->post_list, 'bulk_actions' ), 10, 1 );
		\WP_Mock::expectFilterAdded( 'handle_bulk_actions-edit-post', array( $this->bulk_handler, 'handle' ), 10, 3 );
		\WP_Mock::expectFilterAdded( 'bulk_actions-edit-page', array( $this->post_list, 'bulk_actions' ), 10, 1 );
		\WP_Mock::expectFilterAdded( 'handle_bulk_actions-edit-page', array( $this->bulk_handler, 'handle' ), 10, 3 );
		\WP_Mock::expectFilterAdded( 'bulk_actions-edit-book', array( $this->post_list, 'bulk_actions' ), 10, 1 );
		\WP_Mock::expectFilterAdded( 'handle_bulk_actions-edit-book', array( $this->bulk_handler, 'handle' ), 10, 3 );

		$this->post_list->register_post_type_hooks();

		// WP_Mock verifies the expectFilterAdded() expectations during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * query_vars() extends the public query-var allowlist with the three
	 * keys the bulk-action plumbing reads back from the redirect URL:
	 * `archived`, `unarchived`, `ids`. Without those, get_query_var()
	 * would return empty on the post-list refresh and the notices would
	 * silently drop.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::query_vars
	 */
	public function test_query_vars_adds_archive_query_keys() {
		// Arrange
		$vars = [ 'p', 'page_id' ];

		// Act
		$result = $this->post_list->query_vars( $vars );

		// Assert
		$this->assertIsArray( $result );
		$this->assertContains( 'p', $result );
		$this->assertContains( 'page_id', $result );
		$this->assertContains( 'archived', $result );
		$this->assertContains( 'unarchived', $result );
		$this->assertContains( 'ids', $result );
	}

	/**
	 * query_vars() also carries the five reason-skip buckets
	 * (`locked`, `denied`, `not_found`, `wrong_status`) plus the aggregate
	 * `skipped` counter that NoticeBuilder reads back from the redirect URL.
	 * Missing any of these means a fresh page load can't recover that part
	 * of the notice.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::query_vars
	 */
	public function test_query_vars_adds_all_reason_bucket_keys() {
		$result = $this->post_list->query_vars( array() );

		$this->assertContains( 'locked', $result );
		$this->assertContains( 'denied', $result );
		$this->assertContains( 'not_found', $result );
		$this->assertContains( 'wrong_status', $result );
		$this->assertContains( 'skipped', $result );
	}

	// -----------------------------------------------------------------------
	// removable_query_args
	// -----------------------------------------------------------------------
	//
	// §2.4: none of the eight bulk-action notice query args were registered
	// on WordPress's `removable_query_args` filter, so a stale notice (and
	// a stale skip-reason bucket from a previous, unrelated action) would
	// survive in the visible URL across page refreshes instead of being
	// stripped by history.replaceState() after the notice renders once.

	/**
	 * removable_query_args() must append the exact same eight names
	 * query_vars() registers, on top of whatever WordPress core (or another
	 * plugin) already contributed — never replacing the incoming array.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::removable_query_args
	 */
	public function test_removable_query_args_adds_all_eight_notice_query_args() {
		$result = $this->post_list->removable_query_args( array( 'untrashed', 'deleted' ) );

		// Pre-existing core/third-party entries survive untouched.
		$this->assertContains( 'untrashed', $result );
		$this->assertContains( 'deleted', $result );

		foreach ( array( 'archived', 'unarchived', 'ids', 'locked', 'denied', 'not_found', 'wrong_status', 'skipped' ) as $arg ) {
			$this->assertContains( $arg, $result, "removable_query_args() must include '{$arg}'" );
		}
	}

	// -----------------------------------------------------------------------
	// show_archived_row_checkbox
	// -----------------------------------------------------------------------
	//
	// PostEditorGuard denies edit_post on archived rows in read-only mode,
	// which would also hide core's bulk checkbox. The filter restores the
	// checkbox on the unarchive capability so bulk Unarchive stays usable.

	/**
	 * When core would already show the checkbox, the filter passes true
	 * through without consulting the plugin at all.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::show_archived_row_checkbox
	 */
	public function test_show_archived_row_checkbox_passes_true_through() {
		\WP_Mock::userFunction( 'current_user_can' )->never();

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'post',
		] );

		$this->assertTrue( $this->post_list->show_archived_row_checkbox( true, $post ) );
	}

	/**
	 * Non-archived rows keep core's decision — a hidden checkbox on a
	 * draft the user cannot edit stays hidden.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::show_archived_row_checkbox
	 */
	public function test_show_archived_row_checkbox_leaves_non_archived_rows_alone() {
		\WP_Mock::userFunction( 'current_user_can' )->never();

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'draft',
			'post_type'   => 'post',
		] );

		$this->assertFalse( $this->post_list->show_archived_row_checkbox( false, $post ) );
	}

	/**
	 * A hidden checkbox on an archived row is restored exactly when the
	 * user can unarchive that row.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::show_archived_row_checkbox
	 */
	public function test_show_archived_row_checkbox_restores_checkbox_on_unarchive_capability() {
		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'post',
		] );

		// Ownership-aware default: anonymous mock user resolves to the
		// others-primitive; grant it for the positive case.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( null );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 5 )
			->andReturn( true );

		$this->assertTrue( $this->post_list->show_archived_row_checkbox( false, $post ) );
	}

	/**
	 * Ownership default: the post's own author needs only the post type's
	 * edit_posts primitive to have the checkbox restored. This test sets
	 * post_author = get_current_user_id() and asserts the *primitive*
	 * current_user_can() receives, not merely that the checkbox is
	 * restored.
	 *
	 * Uses a 'book' post type (edit_books / edit_others_books) so the
	 * primitive strings differ from the generic edit_others_posts string
	 * the test above pins.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::show_archived_row_checkbox
	 */
	public function test_show_archived_row_checkbox_consults_edit_posts_primitive_for_authors_own_post() {
		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'book',
			'post_author' => 7,
		] );

		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		$type_object      = new \stdClass();
		$type_object->cap = (object) array(
			'edit_posts'        => 'edit_books',
			'edit_others_posts' => 'edit_others_books',
		);
		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'book' )
			->andReturn( $type_object );

		$received_capability = null;
		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		$this->assertTrue( $this->post_list->show_archived_row_checkbox( false, $post ) );
		$this->assertSame(
			'edit_books',
			$received_capability,
			"the post type's edit_posts primitive must be consulted for the author's own post"
		);
	}

	/**
	 * Deny path: a hidden checkbox on an archived row must STAY hidden when
	 * the user cannot unarchive that row. The allow-path tests above only
	 * pin the restore; without this, a regression that always returned true
	 * for archived rows (dropping the capability check entirely) would pass
	 * every other test in this section.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::show_archived_row_checkbox
	 */
	public function test_show_archived_row_checkbox_stays_hidden_when_user_cannot_unarchive() {
		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'post',
		] );

		// Ownership-aware default: anonymous mock user resolves to the
		// others-primitive; deny it here.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( null );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 5 )
			->andReturn( false );

		$this->assertFalse( $this->post_list->show_archived_row_checkbox( false, $post ) );
	}

	// -----------------------------------------------------------------------
	// row_actions
	// -----------------------------------------------------------------------

	/**
	 * Unsupported post type — row_actions() must return the array exactly
	 * as WordPress handed it in, no archive or unarchive entry added.
	 * Important: even if our capability filters would say yes, the post
	 * type gate is the primary gate.
	 *
	 * the negation contract previously expressed as
	 * `aps_current_user_can_archive->never()` is now expressed as
	 * `current_user_can->never()` plus URL-builder ->never() — those are
	 * the actual side-effects skipped when the SUT early-returns.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::row_actions
	 */
	public function test_row_actions_returns_unchanged_for_unsupported_post_type() {
		// supported list excludes attachment.
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page', 'attachment' ), array( 'post', 'page' ) );

		// Capability and URL plumbing must not even fire — the post-type
		// gate is the early return. These are the trust-boundary functions
		// the deleted aps_* `->never()` stubs were proxying for.
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'admin_url' )->never();
		\WP_Mock::userFunction( 'wp_nonce_url' )->never();

		$post              = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_type'   => 'attachment',
				'post_status' => 'publish',
			)
		);
		$incoming_actions  = array( 'edit' => '<a>Edit</a>', 'view' => '<a>View</a>' );
		$result            = $this->post_list->row_actions( $incoming_actions, $post );

		$this->assertSame( $incoming_actions, $result );
	}

	/**
	 * Archivable status (e.g. 'publish') + can-archive capability →
	 * row_actions() appends an `archive` entry whose href is the result
	 * of aps_get_archive_post_link(). Edit/view/inline entries are left
	 * alone — those still make sense for unarchived posts.
	 *
	 * aps_get_archive_post_link runs unmocked here, against
	 * the same WP-boundary stubs (admin_url + add_query_arg + wp_nonce_url
	 * + esc_url + get_post + get_post_type_object) so the produced URL
	 * actually flows through the real link pipeline. The href contains
	 * `action=archive` as a direct byproduct of the real
	 * add_query_arg('action', 'archive', …) call.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::row_actions
	 */
	public function test_row_actions_adds_archive_link_for_archivable_post_when_user_can_archive() {
		$this->stubSupportedPostTypesBoundary( array( 'post' ), array( 'post' ) );
		$this->stubArchivableStatusesBoundary( array( 'publish', 'draft' ) );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		// Cap check: real aps_current_user_can_archive resolves through
		// the `aps_default_archive_capability` filter then current_user_can.
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 11 )
			->andReturn( true );

		// URL pipeline for aps_get_archive_post_link (real function).
		$post = $this->createMockPost(
			array(
				'ID'          => 11,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 11 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) array( '_edit_link' => 'post.php?post=%d&action=edit' ) );
		\WP_Mock::userFunction( 'admin_url' )
			->andReturn( 'http://example.com/wp-admin/post.php?post=11&action=edit' );
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				static function ( $arg, $value, $url ) {
					return $url . '&' . $arg . '=' . $value;
				}
			);
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=abc123' );
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => $url );

		$input = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<a>Quick Edit</a>',
			'view'                 => '<a>View</a>',
		);

		$result = $this->post_list->row_actions( $input, $post );

		$this->assertArrayHasKey( 'archive', $result );
		$this->assertStringContainsString(
			'post=11',
			$result['archive'],
			'archive entry must link to the per-post archive URL produced by the real link pipeline'
		);
		$this->assertStringContainsString(
			'action=archive',
			$result['archive'],
			'archive entry must carry the archive action in its href'
		);
		$this->assertStringContainsString( '_wpnonce=abc123', $result['archive'] );

		// Existing entries should still be present — archivable posts
		// can still be edited / viewed normally.
		$this->assertArrayHasKey( 'edit', $result );
		$this->assertArrayHasKey( 'view', $result );
		$this->assertArrayHasKey( 'inline hide-if-no-js', $result );
	}

	/**
	 * Already-archived post + can-unarchive capability → row_actions()
	 * REPLACES the row-action surface: it drops `edit` and the JS quick-
	 * edit handle (they don't make sense on archived posts), and adds an
	 * `unarchive` entry pointing at the unarchive URL.
	 *
	 * The `view` entry survives because the policy stopped removing it
	 * when the user can view archived content. Here the read capability
	 * is granted so the view link should remain.
	 *
	 * the three aps_current_user_can_* helpers all resolve via
	 * current_user_can — each receives a different capability string, so
	 * the cap argument is the only thing that varies. The real
	 * aps_get_unarchive_post_link delegates to aps_get_archive_post_link
	 * with action='unarchive', so the URL pipeline below mirrors the test
	 * above with the action arg swapped.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::row_actions
	 */
	public function test_row_actions_replaces_edit_and_inline_with_unarchive_for_archived_post_when_user_can_unarchive() {
		$this->stubSupportedPostTypesBoundary( array( 'post' ), array( 'post' ) );
		// Status is 'archive' — first branch (archivable + can-archive) is
		// false because 'archive' is not in the archivable list.
		$this->stubArchivableStatusesBoundary( array( 'publish', 'draft' ) );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		// Cap routing: the SUT calls three different aps_current_user_can_*
		// helpers, each of which calls current_user_can with a distinct
		// capability string. Pin each one to its expected cap so the
		// assertion below pins the SUT's branch order.
		// (1) archive cap check returns false (post is 'archive', not in
		// archivable list, so this branch is gated by the status check
		// before the cap check — but cap is short-circuited here for safety).
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 12 )
			->andReturn( true );

		// View cap check (read_private_posts is the default).
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'read_private_posts', 12 )
			->andReturn( true );

		// URL pipeline for aps_get_unarchive_post_link (real function,
		// delegates to aps_get_archive_post_link with action='unarchive').
		$post = $this->createMockPost(
			array(
				'ID'          => 12,
				'post_type'   => 'post',
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 12 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) array( '_edit_link' => 'post.php?post=%d&action=edit' ) );
		\WP_Mock::userFunction( 'admin_url' )
			->andReturn( 'http://example.com/wp-admin/post.php?post=12&action=edit' );
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				static function ( $arg, $value, $url ) {
					return $url . '&' . $arg . '=' . $value;
				}
			);
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=def' );
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => $url );

		$input = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<a>Quick Edit</a>',
			'view'                 => '<a>View</a>',
			'trash'                => '<a>Trash</a>',
		);

		$result = $this->post_list->row_actions( $input, $post );

		// edit and quick-edit get stripped — they're nonsensical on archived
		// posts where the editor is locked.
		$this->assertArrayNotHasKey( 'edit', $result );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $result );

		// view survives because the user has view capability.
		$this->assertArrayHasKey( 'view', $result );

		// Non-archive-related entries (like trash) are untouched.
		$this->assertArrayHasKey( 'trash', $result );

		// unarchive entry appended.
		$this->assertArrayHasKey( 'unarchive', $result );
		$this->assertStringContainsString(
			'post=12',
			$result['unarchive'],
			'unarchive entry must link to the per-post unarchive URL produced by the real link pipeline'
		);
		$this->assertStringContainsString(
			'action=unarchive',
			$result['unarchive'],
			'unarchive entry must carry the unarchive action in its href'
		);
		$this->assertStringContainsString( '_wpnonce=def', $result['unarchive'] );
	}

	/**
	 * bulk_actions() registers the `archive` bulk action when no post_status
	 * filter is active (the "All" view). The unarchive entry only appears
	 * when viewing the `?post_status=archive` filter (see next test).
	 *
	 * real `_aps_get_archivable_statuses` resolves through the
	 * `aps_archivable_statuses` filter boundary (no SUT stub).
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::bulk_actions
	 */
	public function test_bulk_actions_registers_archive_when_no_post_status_filter_active() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status', false )
			->andReturn( false ); // "All" view — no filter
		$this->stubArchivableStatusesBoundary();

		$result = $this->post_list->bulk_actions( array( 'delete' => 'Delete' ) );

		$this->assertArrayHasKey( 'delete', $result );
		$this->assertArrayHasKey( 'archive', $result );
	}

	/**
	 * When the user is viewing `?post_status=archive`, `bulk_actions()`
	 * must add an `unarchive` entry — the bulk-restore counterpart to
	 * the archive action. The archive entry should NOT appear in this
	 * view (you can't archive an already-archived post).
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::bulk_actions
	 */
	public function test_bulk_actions_adds_unarchive_entry_when_viewing_archive_filter() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status', false )
			->andReturn( 'archive' );

		$this->stubArchivableStatusesBoundary( array( 'publish', 'draft' ) );

		$result = $this->post_list->bulk_actions( array( 'delete' => 'Delete' ) );

		$this->assertArrayHasKey( 'unarchive', $result );
		// 'archive' must not be added — archive status is not in the
		// archivable list, so the "show archive" branch falls through.
		$this->assertArrayNotHasKey( 'archive', $result );
	}

	// -----------------------------------------------------------------------
	// post_action_archive / post_action_unarchive
	// -----------------------------------------------------------------------
	//
	// Migrated from the deleted PostListHandleActionTest.php in 0.4.0 — these
	// exercise the single-post (non-bulk) action handlers, which remained on
	// PostList after the bulk-action extraction. The handlers nonce-check,
	// capability-gate, then dispatch through ArchiveAction::perform().

	/**
	 * `post_action_archive` is the entry point for the single-post archive
	 * link (`?action=archive&post=99&_wpnonce=...`). Validation
	 * runs first (get_post, is_supported_post_type), then the nonce check
	 * with the action-specific nonce key (`archive-{id}`), then the
	 * capability check.
	 *
	 * Regression (§2.1): a denied capability check used to return silently —
	 * the user clicks Archive, the page reloads, and nothing explains why.
	 * It must now wp_die() with the archive-specific permission message.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_checks_nonce_with_archive_post_id_key() {
		// Pre-nonce validation needs a real post + supported type.
		$post              = $this->createMockPost(
			array(
				'ID'          => 99,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 99 )->andReturn( $post );
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( 'archive-99' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 99 )
			->andReturn( false ); // denied -> wp_die(), never reaches archive

		// Subsequent calls must not fire when the capability check denies.
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/permission to archive this item/' );

		$this->post_list->post_action_archive( 99 );
	}

	/**
	 * post_action_archive's missing-post guard (the 0.4.0 refactor
	 * cleanup): when get_post() returns null (post deleted between
	 * row-action render and click), the handler now silently returns
	 * BEFORE calling check_admin_referer(). This rejects bogus payloads
	 * without surfacing a WP-core "Are you sure?" dialog from a missing
	 * nonce.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_returns_silently_when_post_no_longer_exists() {
		\WP_Mock::userFunction( 'get_post' )
			->with( 50 )
			->andReturn( null );

		// Pre-nonce-validation contract: nonce check, cap, persistence, and redirect must
		// all be skipped when the id is invalid.
		\WP_Mock::userFunction( 'check_admin_referer' )->never();
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->post_list->post_action_archive( 50 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Unsupported post type guard (the 0.4.0 refactor):
	 * a post in an unsupported post type now silently returns BEFORE
	 * check_admin_referer() fires. The original `wp_die('Invalid post
	 * type')` branch became unreachable once the upstream supported-type
	 * check moved ahead of the nonce check — leaving it would have
	 * surfaced a fatal-style dialog on a request the SUT can now reject
	 * without one.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_returns_silently_for_unsupported_post_type() {
		// supported list excludes orphan_cpt.
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		$post              = new \stdClass();
		$post->ID          = 51;
		$post->post_type   = 'orphan_cpt';
		$post->post_status = 'publish';
		\WP_Mock::userFunction( 'get_post' )->with( 51 )->andReturn( $post );

		\WP_Mock::userFunction( 'check_admin_referer' )->never();
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->post_list->post_action_archive( 51 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Persistence-failure guard: when aps_archive_post() returns false
	 * (DB error, ArchiveMetaListener veto, etc.), the handler wp_die()s
	 * with "Error in archiving" rather than silently redirecting with
	 * `archived=1`.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_dies_when_aps_archive_post_returns_false() {
		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 52 )
			->andReturn( true );

		$post              = new \stdClass();
		$post->ID          = 52;
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		\WP_Mock::userFunction( 'get_post' )->with( 52 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 52 )
			->andReturn( false );

		// aps_archive_post(52) reaches wp_update_post which returns 0.
		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 0 );

		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Error in archiving/' );

		$this->post_list->post_action_archive( 52 );
	}

	// -----------------------------------------------------------------------
	// wp_die() escaping (§1.7)
	// -----------------------------------------------------------------------
	//
	// Three wp_die() calls in handle_post_action() used to pass translated
	// strings straight through with no esc_html() at all (compare
	// PostEditorGuard::enforce_read_only(), which wraps its wp_die() message
	// in esc_html()). A content-only assertion (e.g. a regex on the English
	// text) can't tell an escaped call from an unescaped one, because the
	// wp_die() test polyfill (tests/php/Support/WpPolyfills.php) itself
	// always runs the final message through esc_html() once before throwing.
	// So these assert the exact *call count* on esc_html(): the polyfill
	// contributes one call no matter what; anything beyond that one must
	// come from the SUT.

	/**
	 * The "Invalid post type" wp_die() message must be escaped: one call
	 * from the SUT plus the polyfill's own defensive call = 2 total.
	 * Pre-fix, only the polyfill's call happens = 1 total.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_escapes_invalid_post_type_message() {
		$post              = $this->createMockPost(
			array(
				'ID'          => 70,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 70 )->andReturn( $post );
		$this->stubSupportedPostTypesBoundary( array( 'post' ), array( 'post' ) );

		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 70 )
			->andReturn( true );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( null );

		\WP_Mock::userFunction( 'esc_html' )
			->times( 2 )
			->andReturnUsing( static fn( $text ) => $text );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Invalid post type/' );

		$this->post_list->post_action_archive( 70 );
	}

	/**
	 * The locked-post wp_die() message must escape BOTH the translated
	 * template and the interpolated display name, without an extra outer
	 * esc_html() wrapping the composed sprintf() result (which would
	 * double-escape the already-escaped name). Expected calls: template +
	 * name from the SUT, plus the polyfill's own call on the final composed
	 * string = 3 total. Pre-fix, only the name and the polyfill's call
	 * happen = 2 total.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_escapes_locked_message_exactly_once_per_part() {
		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 55 )
			->andReturn( true );

		$post              = new \stdClass();
		$post->ID          = 55;
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		\WP_Mock::userFunction( 'get_post' )->with( 55 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 55 )
			->andReturn( 7 );

		$locking_user               = new \stdClass();
		$locking_user->display_name = 'Jane Doe';
		\WP_Mock::userFunction( 'get_userdata' )
			->with( 7 )
			->andReturn( $locking_user );

		\WP_Mock::userFunction( 'esc_html' )
			->times( 3 )
			->andReturnUsing( static fn( $text ) => $text );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Jane Doe is currently editing/' );

		$this->post_list->post_action_archive( 55 );
	}

	/**
	 * The persist-failure wp_die() message must be escaped: one call from
	 * the SUT plus the polyfill's own call = 2 total. Pre-fix, only the
	 * polyfill's call happens = 1 total.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_escapes_failure_message() {
		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 56 )
			->andReturn( true );

		$post              = new \stdClass();
		$post->ID          = 56;
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		\WP_Mock::userFunction( 'get_post' )->with( 56 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 56 )
			->andReturn( false );

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 0 );

		\WP_Mock::userFunction( 'esc_html' )
			->times( 2 )
			->andReturnUsing( static fn( $text ) => $text );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Error in archiving/' );

		$this->post_list->post_action_archive( 56 );
	}

	// -----------------------------------------------------------------------
	// post_action_archive — wp_check_post_lock branch
	// -----------------------------------------------------------------------

	/**
	 * Pin the normal locked-post case: wp_check_post_lock() returns the
	 * editing user's id, get_userdata() resolves a real user, and the
	 * wp_die() message names that user by their display_name.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_dies_when_post_is_locked_by_an_existing_user() {
		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 53 )
			->andReturn( true );

		$post              = new \stdClass();
		$post->ID          = 53;
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		\WP_Mock::userFunction( 'get_post' )->with( 53 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 53 )
			->andReturn( 7 );

		$locking_user               = new \stdClass();
		$locking_user->display_name = 'Jane Doe';
		\WP_Mock::userFunction( 'get_userdata' )
			->with( 7 )
			->andReturn( $locking_user );

		// The method must die here — persistence and redirect never fire.
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Jane Doe is currently editing/' );

		$this->post_list->post_action_archive( 53 );
	}

	/**
	 * Deleted-user regression guard: when the user holding the edit lock no
	 * longer exists, get_userdata() returns false. handle_post_action() must
	 * degrade to the "Another user" fallback rather than dereferencing
	 * display_name on false — and without leaving an empty gap where the
	 * name would have been.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_dies_when_post_is_locked_by_a_deleted_user() {
		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 54 )
			->andReturn( true );

		$post              = new \stdClass();
		$post->ID          = 54;
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		\WP_Mock::userFunction( 'get_post' )->with( 54 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 54 )
			->andReturn( 8 );

		// The locking user's account no longer exists.
		\WP_Mock::userFunction( 'get_userdata' )
			->with( 8 )
			->andReturn( false );

		// The method must die here — persistence and redirect never fire.
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Another user is currently editing/' );

		$this->post_list->post_action_archive( 54 );
	}

	/**
	 * Mirror coverage for the unarchive entry point — nonce key shape must
	 * match the `unarchive-{id}` contract that pairs with the row-action URL.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_unarchive
	 */
	// -----------------------------------------------------------------------
	// Pre-nonce-validation regression tests (ID validation BEFORE nonce check)
	// -----------------------------------------------------------------------

	/**
	 * Pre-nonce-validation regression: validation runs FIRST. With a post_id that get_post()
	 * cannot resolve, the SUT must return without ever calling
	 * check_admin_referer().
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_validates_id_before_calling_nonce_check() {
		\WP_Mock::userFunction( 'get_post' )
			->with( 50 )
			->andReturn( null );

		// check_admin_referer MUST NOT be called for an invalid post id.
		\WP_Mock::userFunction( 'check_admin_referer' )->never();

		// No downstream calls either.
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->post_list->post_action_archive( 50 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Pre-nonce-validation regression (complement): the validation pipeline runs in
	 * dependency order before the nonce check fires. Post id ≤ 0 must
	 * be rejected before any of get_post / aps_is_supported_post_type /
	 * check_admin_referer runs.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_rejects_nonpositive_id_before_any_downstream_call() {
		// Every downstream surface must be untouched.
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'check_admin_referer' )->never();
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->post_list->post_action_archive( 0 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Mirror of the archive nonce-key test above, for the unarchive
	 * direction. Regression (§2.1): a denied capability check must
	 * wp_die() with the *unarchive*-specific permission message, not the
	 * archive-only copy the shared handle_post_action() used to emit
	 * regardless of direction.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_unarchive
	 */
	public function test_post_action_unarchive_checks_nonce_with_unarchive_post_id_key() {
		// Pre-nonce validation needs a real post + supported type.
		$post              = $this->createMockPost(
			array(
				'ID'          => 99,
				'post_type'   => 'post',
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 99 )->andReturn( $post );
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( 'unarchive-99' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 99 )
			->andReturn( false );

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/permission to unarchive this item/' );

		$this->post_list->post_action_unarchive( 99 );
	}

	/**
	 * Direction-correct copy (§2.1): a locked post blocks unarchiving with
	 * "You cannot unarchive this item..." — not the archive-only wording the
	 * shared handle_post_action() used to emit for both directions.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_unarchive
	 */
	public function test_post_action_unarchive_dies_with_unarchive_specific_message_when_locked() {
		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 63 )
			->andReturn( true );

		$post              = new \stdClass();
		$post->ID          = 63;
		$post->post_type   = 'post';
		$post->post_status = 'archive';
		\WP_Mock::userFunction( 'get_post' )->with( 63 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 63 )
			->andReturn( 7 );

		$locking_user               = new \stdClass();
		$locking_user->display_name = 'Jane Doe';
		\WP_Mock::userFunction( 'get_userdata' )
			->with( 7 )
			->andReturn( $locking_user );

		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/cannot unarchive this item\. Jane Doe is currently editing/' );

		$this->post_list->post_action_unarchive( 63 );
	}

	/**
	 * Direction-correct copy (§2.1): a persist failure on unarchive dies
	 * with "Error in unarchiving this item." — not the archive-only
	 * "Error in archiving this item." the shared handle_post_action() used
	 * to emit for both directions.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_unarchive
	 */
	public function test_post_action_unarchive_dies_with_unarchive_specific_message_on_failure() {
		\WP_Mock::userFunction( 'check_admin_referer' )->once();
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 64 )
			->andReturn( true );

		// UnarchiveOperation::validate() type-hints its return \WP_Post|false
		// and re-reads via get_post() internally, so — unlike the archive
		// counterpart above — the fixture must be a real WP_Post, not a bare
		// stdClass.
		$post = $this->createMockPost(
			array(
				'ID'          => 64,
				'post_type'   => 'post',
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 64 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 64 )
			->andReturn( false );

		// aps_unarchive_post(64) reaches wp_update_post which returns 0.
		$this->stubUnarchivePersistBoundary( 64, 0 );

		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Error in unarchiving this item/' );

		$this->post_list->post_action_unarchive( 64 );
	}

	// -----------------------------------------------------------------------
	// handle_post_action — success-path redirect (archive + unarchive)
	// -----------------------------------------------------------------------
	//
	// No test above reaches the success branch at the end of
	// handle_post_action(): every scenario exercises a rejection or a
	// wp_die() path. Both actions are covered — not just one — because
	// ArchiveAction::query_arg() differs between them ('archived' vs
	// 'unarchived'). Each test asserts the *exact* array passed to
	// add_query_arg() (the action's query arg + the post id under 'ids')
	// and the exact base URL it's applied to
	// (BulkActionHandler::get_redirect_url()'s result).
	//
	// Uses the same throw-on-redirect technique as
	// PostEditorGuardTest::test_enforce_read_only_redirects_after_save_to_list_table()
	// to observe state past the `exit` that follows wp_safe_redirect().

	/**
	 * Success path for the single-post archive action.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_redirects_with_archived_flag_and_post_id_on_success() {
		$post              = $this->createMockPost(
			array(
				'ID'          => 60,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 60 )->andReturn( $post );

		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( 'archive-60' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 60 )
			->andReturn( true );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 60 )
			->andReturn( false );

		// perform() runs for real; this test trusts its return value to reach
		// the redirect below. Internals are pinned by ArchiveOperationTest.
		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 60 );

		// get_redirect_url('post'): no referer, so it falls back through
		// PostListUrlBuilder to admin_url('edit.php'), then the
		// strip-query-args pass-through leaves that URL untouched.
		\WP_Mock::userFunction( 'wp_get_referer' )->andReturn( false );
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php' )
			->andReturn( 'http://example.com/wp-admin/edit.php' );
		\WP_Mock::userFunction( 'remove_query_arg' )
			->with(
				array( 'archived', 'unarchived', 'ids', 'locked', 'denied', 'not_found', 'wrong_status', 'skipped' ),
				'http://example.com/wp-admin/edit.php'
			)
			->andReturn( 'http://example.com/wp-admin/edit.php' );

		$captured_args = null;
		$captured_url  = null;
		\WP_Mock::userFunction( 'add_query_arg' )
			->once()
			->andReturnUsing(
				function ( $args, $url ) use ( &$captured_args, &$captured_url ) {
					$captured_args = $args;
					$captured_url  = $url;
					return 'http://example.com/wp-admin/edit.php?archived=1&ids=60';
				}
			);

		\WP_Mock::userFunction( 'wp_die' )->never();

		// Detect the redirect by throwing — the production code follows
		// wp_safe_redirect() with exit; which would halt PHPUnit otherwise.
		\WP_Mock::userFunction( 'wp_safe_redirect' )
			->with( 'http://example.com/wp-admin/edit.php?archived=1&ids=60' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'redirected' );
			} );

		try {
			$this->post_list->post_action_archive( 60 );
			$this->fail( 'Expected redirect to short-circuit execution.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame(
			array(
				'archived' => 1,
				'ids'      => 60,
			),
			$captured_args,
			'add_query_arg must receive the archive query arg set to 1 and the post id under "ids"'
		);
		$this->assertSame(
			'http://example.com/wp-admin/edit.php',
			$captured_url,
			'add_query_arg must be applied to the URL get_redirect_url() produced'
		);
	}

	/**
	 * Mirror of the archive success-path test above for unarchive.
	 * ArchiveAction::query_arg() returns 'unarchived' — not 'archived' — for
	 * this action, so a regression that reused the archive literal (or
	 * hardcoded the redirect target) would pass the archive test above while
	 * failing here.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_unarchive
	 */
	public function test_post_action_unarchive_redirects_with_unarchived_flag_and_post_id_on_success() {
		$post              = $this->createMockPost(
			array(
				'ID'          => 61,
				'post_type'   => 'post',
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 61 )->andReturn( $post );

		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( 'unarchive-61' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 61 )
			->andReturn( true );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( (object) array( 'name' => 'post' ) );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 61 )
			->andReturn( false );

		// perform() runs for real; this test trusts its return value to reach
		// the redirect below. Internals are pinned by UnarchiveOperationTest.
		$this->stubUnarchivePersistBoundary( 61 );

		// get_redirect_url('post'): no referer, so it falls back through
		// PostListUrlBuilder to admin_url('edit.php'), then the
		// strip-query-args pass-through leaves that URL untouched.
		\WP_Mock::userFunction( 'wp_get_referer' )->andReturn( false );
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php' )
			->andReturn( 'http://example.com/wp-admin/edit.php' );
		\WP_Mock::userFunction( 'remove_query_arg' )
			->with(
				array( 'archived', 'unarchived', 'ids', 'locked', 'denied', 'not_found', 'wrong_status', 'skipped' ),
				'http://example.com/wp-admin/edit.php'
			)
			->andReturn( 'http://example.com/wp-admin/edit.php' );

		$captured_args = null;
		$captured_url  = null;
		\WP_Mock::userFunction( 'add_query_arg' )
			->once()
			->andReturnUsing(
				function ( $args, $url ) use ( &$captured_args, &$captured_url ) {
					$captured_args = $args;
					$captured_url  = $url;
					return 'http://example.com/wp-admin/edit.php?unarchived=1&ids=61';
				}
			);

		\WP_Mock::userFunction( 'wp_die' )->never();

		// Detect the redirect by throwing — the production code follows
		// wp_safe_redirect() with exit; which would halt PHPUnit otherwise.
		\WP_Mock::userFunction( 'wp_safe_redirect' )
			->with( 'http://example.com/wp-admin/edit.php?unarchived=1&ids=61' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'redirected' );
			} );

		try {
			$this->post_list->post_action_unarchive( 61 );
			$this->fail( 'Expected redirect to short-circuit execution.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame(
			array(
				'unarchived' => 1,
				'ids'        => 61,
			),
			$captured_args,
			'add_query_arg must receive the unarchive query arg set to 1 and the post id under "ids"'
		);
		$this->assertSame(
			'http://example.com/wp-admin/edit.php',
			$captured_url,
			'add_query_arg must be applied to the URL get_redirect_url() produced'
		);
	}
}
