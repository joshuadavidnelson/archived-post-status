<?php
/**
 * PostList Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostList
 *
 * Phase 4.5 migration (0.4.0): SUT-mocking of plugin-owned aps_* helpers
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
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->post_list = new ArchivedPostStatus\Admin\PostList(
			new ArchivedPostStatus\Admin\BulkActionHandler()
		);
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

	// Phase 3.4: the prior `test_hooks_returns_hookable_descriptors` smoke
	// test was deleted — `assertIsArray`/`assertNotEmpty` doesn't pin any
	// behavior. The composition surface is now covered end-to-end by
	// `PluginTest::test_hookables_includes_admin_only_set_when_is_admin_is_true`.
	// The replacement below asserts the *specific* hooks the SUT registers,
	// pinning the integration to WordPress's filter/action names — those
	// are load-bearing strings that a typo would silently break.

	/**
	 * hooks() returns the bundle of post-list-table integration hooks:
	 * `query_vars` (filter), `admin_enqueue_scripts` (action),
	 * `post_action_archive` + `post_action_unarchive` (admin-action
	 * entry points), plus per-supported-post-type filters
	 * `bulk_actions-edit-{type}`, `handle_bulk_actions-edit-{type}`,
	 * `{type}_row_actions`.
	 *
	 * The minimum-viable assertion: the SUT registers the four global
	 * hooks plus one per-post-type bulk_actions entry. Stronger per-hook
	 * contracts are covered in the handler-method tests
	 * (PostListHandleActionTest, this file's enqueue tests).
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::hooks
	 */
	public function test_hooks_registers_query_vars_filter_and_post_actions() {
		// Phase 4.5: real aps_get_supported_post_types resolves through the
		// filter chain, so hooks() iterates over the real supported list.
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post' ) );

		$hooks      = $this->post_list->hooks();
		$hook_names = array_map( static fn( $h ) => $h->hook, $hooks );

		$this->assertContains( 'query_vars', $hook_names );
		$this->assertContains( 'admin_enqueue_scripts', $hook_names );
		$this->assertContains( 'post_action_archive', $hook_names );
		$this->assertContains( 'post_action_unarchive', $hook_names );
		$this->assertContains( 'bulk_actions-edit-post', $hook_names );
		$this->assertContains( 'handle_bulk_actions-edit-post', $hook_names );
		$this->assertContains( 'post_row_actions', $hook_names );
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

	// -----------------------------------------------------------------------
	// enqueue_edit_screen_js
	// -----------------------------------------------------------------------
	//
	// edit-screen.js is the small JS bundle that disables row clicks on
	// archived posts (the read-only enforcement). It must enqueue only
	// when all three preconditions are met: post type supported, plugin
	// in read-only mode, screen is edit.php. Outside those gates it's a
	// silent no-op.

	/**
	 * On the post list table (`edit.php`) for a supported post type with
	 * the plugin in read-only mode, `enqueue_edit_screen_js()` registers
	 * the `aps-edit-screen` script. The version and footer-load flag
	 * come from the production constants — we assert on the handle name
	 * (the stable hook contract).
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::enqueue_edit_screen_js
	 */
	public function test_enqueue_edit_screen_js_enqueues_script_on_supported_edit_php() {
		global $typenow;
		$typenow = 'post';

		// Phase 4.5: real aps_is_supported_post_type resolves through the
		// filter chain; aps_is_read_only resolves through its filter.
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );
		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( true );

		$enqueued = null;
		\WP_Mock::userFunction( 'wp_enqueue_script' )
			->once()
			->andReturnUsing(
				function ( $handle ) use ( &$enqueued ) {
					$enqueued = $handle;
				}
			);

		$this->post_list->enqueue_edit_screen_js( 'edit.php' );

		$this->assertSame( 'aps-edit-screen', $enqueued );
	}

	/**
	 * `enqueue_edit_screen_js()` short-circuits when the screen is not
	 * `edit.php` — e.g. on `post.php` (the single-post editor), where
	 * the row-action behavior doesn't apply.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::enqueue_edit_screen_js
	 */
	public function test_enqueue_edit_screen_js_skips_on_non_edit_php_hook() {
		global $typenow;
		$typenow = 'post';

		$this->stubSupportedPostTypesBoundary( array( 'post' ), array( 'post' ) );
		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( true );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		$this->post_list->enqueue_edit_screen_js( 'post.php' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `enqueue_edit_screen_js()` short-circuits when read-only mode is
	 * disabled (`aps_is_read_only` filter returned false). With
	 * read-only off, the JS bundle is unnecessary — archived posts
	 * remain clickable.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::enqueue_edit_screen_js
	 */
	public function test_enqueue_edit_screen_js_skips_when_not_read_only() {
		global $typenow;
		$typenow = 'post';

		$this->stubSupportedPostTypesBoundary( array( 'post' ), array( 'post' ) );
		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( false );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		$this->post_list->enqueue_edit_screen_js( 'edit.php' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `enqueue_edit_screen_js()` short-circuits when the current post
	 * type is not in the supported list — viewing the attachment list,
	 * for instance, doesn't need the row-click guard.
	 *
	 * Note: aps_is_read_only is gated behind the supported-type check (PHP
	 * short-circuit evaluation on `!a || !b || …`), so its filter never
	 * fires here. wp_enqueue_script must also never fire.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::enqueue_edit_screen_js
	 */
	public function test_enqueue_edit_screen_js_skips_for_unsupported_post_type() {
		global $typenow;
		$typenow = 'attachment';

		// supported_post_types reply excludes attachment.
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		$this->post_list->enqueue_edit_screen_js( 'edit.php' );

		$this->addToAssertionCount( 1 );
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
	 * Phase 4.5: the negation contract previously expressed as
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
	 * Phase 4.5: aps_get_archive_post_link runs unmocked here, against
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
	 * The `view` entry survives because Phase 1.3 #6 stopped removing it
	 * when the user can view archived content. Here the read capability
	 * is granted so the view link should remain.
	 *
	 * Phase 4.5: the three aps_current_user_can_* helpers all resolve via
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
	 * Phase 4.5: real `_aps_get_archivable_statuses` resolves through the
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
	 * link (`?action=archive&post=99&_wpnonce=...`). After H6, validation
	 * runs first (get_post, is_supported_post_type), then the nonce check
	 * with the action-specific nonce key (`archive-{id}`), then the
	 * capability check. We short-circuit the rest of the method by denying
	 * the capability check after the nonce passes.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_archive
	 */
	public function test_post_action_archive_checks_nonce_with_archive_post_id_key() {
		// H6 pre-nonce validation needs a real post + supported type.
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

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 99 )
			->andReturn( false ); // short-circuit before archive

		// Subsequent calls must not fire when the capability check denies.
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->post_list->post_action_archive( 99 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * post_action_archive's missing-post guard (H6 — Phase 1 of the 0.4.0
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

		// H6 contract: nonce check, cap, persistence, and redirect must
		// all be skipped when the id is invalid.
		\WP_Mock::userFunction( 'check_admin_referer' )->never();
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->post_list->post_action_archive( 50 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Unsupported post type guard (H6 — Phase 1 of the 0.4.0 cleanup):
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

	/**
	 * Mirror coverage for the unarchive entry point — nonce key shape must
	 * match the `unarchive-{id}` contract that pairs with the row-action URL.
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::post_action_unarchive
	 */
	// -----------------------------------------------------------------------
	// Phase 1 — H6 regression tests (ID validation BEFORE nonce check)
	// -----------------------------------------------------------------------

	/**
	 * H6 regression: validation runs FIRST. With a post_id that get_post()
	 * cannot resolve, the SUT must return without ever calling
	 * check_admin_referer(). This is the canary that asserts the new
	 * ordering — a regression that moved the nonce check back to the top
	 * would call check_admin_referer here.
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
	 * H6 regression (complement): the validation pipeline runs in
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

	public function test_post_action_unarchive_checks_nonce_with_unarchive_post_id_key() {
		// H6 pre-nonce validation needs a real post + supported type.
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

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 99 )
			->andReturn( false );

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->post_list->post_action_unarchive( 99 );

		$this->addToAssertionCount( 1 );
	}
}
