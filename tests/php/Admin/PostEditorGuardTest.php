<?php
/**
 * Admin\PostEditorGuard Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostEditorGuard
 *
 * Migration note: the historical `PostEditor::load_post_screen()` method
 * was replaced by `Admin\PostEditorGuard::enforce_read_only()`.
 */

/**
 * PostEditorGuard test case.
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
	 * `load-post.php` fires for EVERY post.php request, before post.php's own
	 * `switch ( $action )` reaches `case 'trash'` / `'untrash'` / `'delete'`.
	 * Those actions are not editor-render attempts, so the guard must let
	 * them through rather than dying — otherwise an archived post can never
	 * be trashed from wp-admin. `RowActionPolicy` deliberately keeps `trash`
	 * visible on archived rows, so this is the only path that can reach it.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::enforce_read_only
	 */
	public function test_enforce_read_only_allows_trash_untrash_and_delete_actions() {
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

		// None of these actions render the editor, so none of them may die
		// or redirect.
		\WP_Mock::userFunction( 'wp_die' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		foreach ( array( 'trash', 'untrash', 'delete' ) as $action ) {
			$_GET = array(
				'post'   => 99,
				'action' => $action,
			);

			$this->guard->enforce_read_only();
		}

		$this->addToAssertionCount( 1 );
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
	 * Core's map_meta_cap() reassigns $cap to the post type's own edit_post
	 * primitive (e.g. edit_book) — not the literal 'edit_post' — before
	 * firing this filter, whenever the post type's map_meta_cap is false
	 * (WordPress's default for any capability_type other than post/page).
	 * Without matching that primitive too, such a post type stays editable
	 * via a direct edit URL even though its row actions correctly hide Edit.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_matches_post_types_own_edit_primitive_when_map_meta_cap_is_false() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'book',
		] );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		$type_object      = new \stdClass();
		$type_object->cap = (object) array( 'edit_post' => 'edit_book' );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );

		$caps = $this->guard->deny_editing_archived( array( 'edit_book' ), 'edit_book', 7, array( 5 ) );

		$this->assertSame( array( 'edit_book', 'do_not_allow' ), $caps );
	}

	/**
	 * A post row can outlive its post type's registration (e.g. a
	 * deactivated CPT plugin) — `get_post_type_object()` returns null. The
	 * deny falls back to matching the literal 'edit_post' only, rather than
	 * fataling on a null-property access; a cap that isn't the literal
	 * 'edit_post' has nothing left to match against and is left untouched.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_leaves_caps_when_type_object_is_missing() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'book',
		] );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( null );

		$caps = $this->guard->deny_editing_archived( array( 'edit_book' ), 'edit_book', 7, array( 5 ) );

		$this->assertSame( array( 'edit_book' ), $caps );
	}

	/**
	 * A type object may exist without a full cap map — `->cap->edit_post` is
	 * unset. The `??` fallback to the literal 'edit_post' must not fatal on
	 * the missing property, and (as above) a non-'edit_post' cap then has
	 * nothing to match against.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_leaves_caps_when_cap_map_is_incomplete() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'book',
		] );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		$type_object      = new \stdClass();
		$type_object->cap = new \stdClass(); // No `edit_post` property.
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );

		$caps = $this->guard->deny_editing_archived( array( 'edit_book' ), 'edit_book', 7, array( 5 ) );

		$this->assertSame( array( 'edit_book' ), $caps );
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
	 * Missing args short-circuits before any read-only, post, or post-type
	 * lookup — $args[0] is required to resolve the post, and the post's type
	 * is what the cap has to be checked against, so there is nothing left to
	 * do without it.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_ignores_missing_args() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->never();
		\WP_Mock::userFunction( 'get_post' )->never();

		$no_args = $this->guard->deny_editing_archived( array( 'edit_posts' ), 'edit_post', 7, array() );
		$this->assertSame( array( 'edit_posts' ), $no_args );
	}

	/**
	 * A cap that matches neither the literal 'edit_post' nor the post type's
	 * own edit_post primitive is left untouched. The post still has to be
	 * resolved to know that — matching the cap requires knowing the post's
	 * type — so this is the one case where the post lookup happens before
	 * the deny is ruled out.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived
	 */
	public function test_deny_editing_archived_ignores_caps_that_match_neither_primitive() {
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		$post = new \WP_Post( [
			'ID'          => 5,
			'post_status' => 'archive',
			'post_type'   => 'book',
		] );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		$type_object      = new \stdClass();
		$type_object->cap = (object) array( 'edit_post' => 'edit_book' );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );

		$untouched = $this->guard->deny_editing_archived( array( 'delete_book' ), 'delete_book', 7, array( 5 ) );

		$this->assertSame( array( 'delete_book' ), $untouched );
	}
}
