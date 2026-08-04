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

	// The composition surface is covered by
	// `PluginTest::test_hookables_includes_admin_only_set_when_is_admin_is_true`.
	// The tests below assert the specific hooks the SUT registers, pinning the
	// WordPress filter/action names — load-bearing strings a typo would
	// silently break.

	/**
	 * hooks() returns the bundle of post-list-table integration hooks that
	 * do NOT depend on the supported-post-types list: `query_vars` +
	 * `wp_list_table_show_post_checkbox` (filters), the two REAL
	 * row-actions hooks core fires (`post_row_actions`, `page_row_actions`
	 * — registered unconditionally, not per post type; see `row_actions()`'s
	 * docblock), and the `wp_loaded` deferral that later registers the
	 * per-post-type bulk-action hooks.
	 *
	 * The single-post `post_action_archive` / `post_action_unarchive` entry
	 * points moved to `PostActionHandler::hooks()` in the 0.4.0 restructor —
	 * see `PostActionHandlerTest::test_hooks_registers_the_two_post_action_hooks()`
	 * for their coverage.
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

		$this->assertCount( 6, $hooks );

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

		$this->assertSame( 'filter', $hooks[2]->type );
		$this->assertSame( 'post_row_actions', $hooks[2]->hook );
		$this->assertSame( array( $this->post_list, 'row_actions' ), $hooks[2]->callback );
		$this->assertSame( 10, $hooks[2]->priority );
		$this->assertSame( 2, $hooks[2]->accepted_args );

		$this->assertSame( 'filter', $hooks[3]->type );
		$this->assertSame( 'page_row_actions', $hooks[3]->hook );
		$this->assertSame( array( $this->post_list, 'row_actions' ), $hooks[3]->callback );
		$this->assertSame( 10, $hooks[3]->priority );
		$this->assertSame( 2, $hooks[3]->accepted_args );

		$this->assertSame( 'action', $hooks[4]->type );
		$this->assertSame( 'wp_loaded', $hooks[4]->hook );
		$this->assertSame( array( $this->post_list, 'register_post_type_hooks' ), $hooks[4]->callback );
		$this->assertSame( 10, $hooks[4]->priority );
		$this->assertSame( 1, $hooks[4]->accepted_args );

		// removable_query_args binds to query_vars, not a same-named method
		// of its own — the two filters need the same eight names for
		// different reasons (see query_vars()'s docblock), so one callback
		// satisfies both. This assertion is the only proof the filter is
		// still wired at all.
		$this->assertSame( 'filter', $hooks[5]->type );
		$this->assertSame( 'removable_query_args', $hooks[5]->hook );
		$this->assertSame( array( $this->post_list, 'query_vars' ), $hooks[5]->callback );
		$this->assertSame( 10, $hooks[5]->priority );
		$this->assertSame( 1, $hooks[5]->accepted_args );

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
	// query_vars / removable_query_args
	// -----------------------------------------------------------------------
	//
	// Regression: with these eight args unregistered on `removable_query_args`,
	// a stale notice (and a stale skip-reason bucket from a previous, unrelated
	// action) survives in the visible URL across page refreshes. query_vars()
	// is now that filter's callback too (see hooks()), so the exact-match pin
	// below is what proves the guarantee holds for both.

	/**
	 * Exact-match pin (Phase 0c) — see docs/plans/0.4.0-refactor.md Step 0.
	 *
	 * query_vars() must append EXACTLY these eight names, in this order, on
	 * top of whatever it was handed. This is the contract the upcoming
	 * NoticeQueryArg enum migration must reproduce identically across all
	 * five call sites that currently spell these names as string literals.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::query_vars
	 */
	public function test_query_vars_pins_the_exact_resulting_array() {
		$result = $this->post_list->query_vars( array( 'p', 'page_id' ) );

		$this->assertSame(
			array(
				'p',
				'page_id',
				'archived',
				'unarchived',
				'ids',
				'locked',
				'denied',
				'not_found',
				'wrong_status',
				'skipped',
			),
			$result
		);
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

}
