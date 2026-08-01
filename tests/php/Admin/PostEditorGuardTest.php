<?php
/**
 * Admin\PostEditorGuard Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostEditorGuard
 *
 * Migration note (0.4.0 §1.1):
 *   The historical `PostEditor::load_post_screen()` method (previously
 *   covered by a `test_load_post_screen()` test that has since been
 *   removed) was replaced by `Admin\PostEditorGuard::enforce_read_only()`.
 *   Only the migrated scenario (the guard short-circuits cleanly when
 *   there is no `post` query arg) lives here; broader coverage of the
 *   guard's other branches is the job of §3 of the finalization plan.
 */

/**
 * PostEditorGuard test case (migration coverage only).
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\PostEditorGuard
 */
class PostEditorGuardTest extends TestCase {

	/**
	 * @var ArchivedPostStatus\Admin\PostEditorGuard
	 */
	protected $guard;

	/**
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->guard = new ArchivedPostStatus\Admin\PostEditorGuard();
	}

	/**
	 * Reset request superglobals after each test so state doesn't leak.
	 */
	public function tear_down() {
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}

	/**
	 * Migrated scenario: with no `post` query var the guard returns silently —
	 * it must not call get_post(), wp_die(), or redirect, even when read-only
	 * mode is active.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::enforce_read_only
	 */
	public function test_enforce_read_only_short_circuits_without_post_query_arg() {
		$_GET = [];

		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		// Once the post id is absent the guard must not reach any of these.
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'wp_die' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->guard->enforce_read_only();

		// WP_Mock verifies the never() expectations during tearDown; register
		// the assertion explicitly so PHPUnit doesn't flag the test as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * When read-only mode is off, the guard returns immediately and never
	 * inspects the request — even if a `post` query arg is present.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::enforce_read_only
	 */
	public function test_enforce_read_only_skips_when_read_only_mode_is_off() {
		$_GET = array( 'post' => 99, 'action' => 'edit' );

		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( false );

		// None of these should be touched once the read-only check fails.
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'wp_die' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->guard->enforce_read_only();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `action=unarchive` is the row-action / post-action flow restoring the
	 * post. It must be allowed through — no wp_die, no redirect.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::enforce_read_only
	 */
	public function test_enforce_read_only_allows_unarchive_action() {
		$_GET = array( 'post' => 99, 'action' => 'unarchive' );

		$post              = new WP_Post();
		$post->ID          = 99;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );
		\WP_Mock::userFunction( 'get_post' )->with( 99 )->andReturn( $post );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			function ( $value ) {
				return $value;
			}
		);
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing(
			function ( $value ) {
				return $value;
			}
		);

		// The guard must return without dying or redirecting.
		\WP_Mock::userFunction( 'wp_die' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->guard->enforce_read_only();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * After saving a post as Archived, WordPress lands on
	 * `post.php?action=edit&message=1`. The guard must redirect to the
	 * list table (edit.php) instead of re-rendering the editor.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::enforce_read_only
	 */
	public function test_enforce_read_only_redirects_after_save_to_list_table() {
		$_GET = array( 'post' => 99, 'action' => 'edit', 'message' => 1 );

		$post              = new WP_Post();
		$post->ID          = 99;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );
		\WP_Mock::userFunction( 'get_post' )->with( 99 )->andReturn( $post );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			function ( $value ) {
				return $value;
			}
		);
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing(
			function ( $value ) {
				return $value;
			}
		);
		\WP_Mock::userFunction( 'self_admin_url' )
			->with( 'edit.php' )
			->andReturn( 'http://example.com/wp-admin/edit.php' );

		// Detect the redirect by throwing — the production code follows
		// wp_safe_redirect() with exit; which would halt PHPUnit otherwise.
		\WP_Mock::userFunction( 'wp_safe_redirect' )
			->with( 'http://example.com/wp-admin/edit.php' )
			->andReturnUsing( function () {
				throw new \RuntimeException( 'redirected' );
			} );

		// wp_die must NOT fire on the save-redirect branch.
		\WP_Mock::userFunction( 'wp_die' )->never();

		try {
			$this->guard->enforce_read_only();
			$this->fail( 'Expected redirect to short-circuit execution.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
	}

	/**
	 * Any other attempt to open an archived post in the editor is blocked
	 * with wp_die(). The common.php mock throws on wp_die, which is how
	 * we catch the deny branch.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::enforce_read_only
	 */
	public function test_enforce_read_only_blocks_direct_edit_of_archived_post() {
		$_GET = array( 'post' => 99, 'action' => 'edit' );

		$post              = new WP_Post();
		$post->ID          = 99;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );
		\WP_Mock::userFunction( 'get_post' )->with( 99 )->andReturn( $post );
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			function ( $value ) {
				return $value;
			}
		);
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing(
			function ( $value ) {
				return $value;
			}
		);
		\WP_Mock::userFunction( 'esc_html__' )->andReturnUsing(
			function ( $text ) {
				return $text;
			}
		);

		// No save-redirect should happen on the deny branch.
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();
		\WP_Mock::userFunction( 'self_admin_url' )->never();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/Archived/' );

		$this->guard->enforce_read_only();
	}

	/**
	 * hooks() registers the editor-access action and the map_meta_cap
	 * editing deny, each pinned in full: hook name, callback, priority,
	 * and accepted args.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::hooks
	 */
	public function test_hooks_registers_load_post_action_and_map_meta_cap_filter() {
		$hooks = $this->guard->hooks();

		$this->assertCount( 2, $hooks );
		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'load-post.php', $hooks[0]->hook );
		$this->assertSame( array( $this->guard, 'enforce_read_only' ), $hooks[0]->callback );
		$this->assertSame( 10, $hooks[0]->priority );
		$this->assertSame( 1, $hooks[0]->accepted_args );
		$this->assertSame( 'filter', $hooks[1]->type );
		$this->assertSame( 'map_meta_cap', $hooks[1]->hook );
		$this->assertSame( array( $this->guard, 'deny_editing_archived' ), $hooks[1]->callback );
		$this->assertSame( 10, $hooks[1]->priority );
		$this->assertSame( 4, $hooks[1]->accepted_args );
	}

	/**
	 * Read-only mode denies edit_post on archived posts, so core drops its
	 * edit affordances (title link, Edit/Quick Edit) server-side.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_appends_do_not_allow_for_archived_post_when_read_only() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'post',
		] );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		$caps = $this->guard->deny_editing_archived( array( 'edit_posts' ), 'edit_post', 7, array( 5 ) );

		$this->assertSame( array( 'edit_posts', 'do_not_allow' ), $caps );
	}

	/**
	 * With read-only mode off, archived posts stay editable — the deny
	 * never inspects the post.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_leaves_caps_when_read_only_off() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post' )->never();

		$caps = $this->guard->deny_editing_archived( array( 'edit_posts' ), 'edit_post', 7, array( 5 ) );

		$this->assertSame( array( 'edit_posts' ), $caps );
	}

	/**
	 * Non-archived posts are untouched regardless of read-only mode.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_leaves_caps_for_non_archived_post() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'publish',
			'post_type'   => 'post',
		] );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		$caps = $this->guard->deny_editing_archived( array( 'edit_posts' ), 'edit_post', 7, array( 5 ) );

		$this->assertSame( array( 'edit_posts' ), $caps );
	}

	/**
	 * Other meta caps pass through before any read-only or post lookup.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_ignores_other_caps_and_missing_args() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->never();
		\WP_Mock::userFunction( 'get_post' )->never();

		$untouched = $this->guard->deny_editing_archived( array( 'delete_posts' ), 'delete_post', 7, array( 5 ) );
		$this->assertSame( array( 'delete_posts' ), $untouched );

		$no_args = $this->guard->deny_editing_archived( array( 'edit_posts' ), 'edit_post', 7, array() );
		$this->assertSame( array( 'edit_posts' ), $no_args );
	}
}
