<?php
/**
 * PostActionHandler Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostActionHandler
 *
 * Successor to the `post_action_archive` / `post_action_unarchive` /
 * `handle_post_action()` tests that lived in `tests/php/Admin/PostListTest.php`
 * before the 0.4.0 restructor split the single-post action pipeline out of
 * `PostList` into its own hookable, `PostActionHandler`. The tests below are
 * carried over verbatim from that file — only `@covers`, the SUT variable
 * and its construction, and imports changed; no scenario, stub, or
 * assertion was altered.
 *
 * SUT-mocking of plugin-owned aps_* helpers
 * (aps_is_supported_post_type, aps_is_read_only,
 * aps_current_user_can_archive/_unarchive/_view, aps_get_archive_post_link,
 * aps_get_unarchive_post_link, _aps_get_archivable_statuses) was retired
 * from this file. Each scenario stubs only the WP-boundary functions
 * those helpers traverse (current_user_can, get_post_types, get_query_var,
 * admin_url, add_query_arg, wp_nonce_url, esc_url, esc_attr) plus filter
 * callbacks on the documented extension points (`aps_supported_post_types`,
 * `aps_excluded_post_types`, `aps_archivable_statuses`, `aps_is_read_only`,
 * `aps_default_archive_capability`). The real procedural helpers execute
 * against those, so a regression in aps_is_supported_post_type now surfaces
 * here as a failed assertion rather than being silently bypassed.
 */

/**
 * PostActionHandler test case
 *
 * Tests the single-post archive/unarchive admin-action pipeline.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\PostActionHandler
 */
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

class PostActionHandlerTest extends TestCase {

	use BoundaryStubs;

	/**
	 * PostActionHandler instance under test.
	 *
	 * @var ArchivedPostStatus\Admin\PostActionHandler
	 */
	protected $handler;

	/**
	 * The BulkActionHandler instance `$handler` is constructed with. Kept
	 * accessible for parity with PostListTest's shape, even though no test
	 * in this file currently asserts against it directly — it's exercised
	 * indirectly via BulkActionHandler::get_redirect_url() in the
	 * success-path redirect tests below.
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
		$this->handler      = new ArchivedPostStatus\Admin\PostActionHandler( $this->bulk_handler );
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

	/**
	 * hooks() returns the two single-post admin-action entry points that
	 * moved here from `PostList::hooks()` in the 0.4.0 restructor:
	 * `post_action_archive` and `post_action_unarchive`. Both are plain
	 * 1-arg actions at the default priority.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::hooks
	 */
	public function test_hooks_registers_the_two_post_action_hooks() {
		$hooks = $this->handler->hooks();

		$this->assertCount( 2, $hooks );

		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'post_action_archive', $hooks[0]->hook );
		$this->assertSame( array( $this->handler, 'post_action_archive' ), $hooks[0]->callback );
		$this->assertSame( 10, $hooks[0]->priority );
		$this->assertSame( 1, $hooks[0]->accepted_args );

		$this->assertSame( 'action', $hooks[1]->type );
		$this->assertSame( 'post_action_unarchive', $hooks[1]->hook );
		$this->assertSame( array( $this->handler, 'post_action_unarchive' ), $hooks[1]->callback );
		$this->assertSame( 10, $hooks[1]->priority );
		$this->assertSame( 1, $hooks[1]->accepted_args );
	}

	// -----------------------------------------------------------------------
	// post_action_archive / post_action_unarchive
	// -----------------------------------------------------------------------
	//
	// The single-post (non-bulk) action handlers: nonce-check,
	// capability-gate, then dispatch through ArchiveAction::perform().

	/**
	 * `post_action_archive` is the entry point for the single-post archive
	 * link (`?action=archive&post=99&_wpnonce=...`). Validation
	 * runs first (get_post, is_supported_post_type), then the nonce check
	 * with the action-specific nonce key (`archive-{id}`), then the
	 * capability check.
	 *
	 * Regression: a denied capability check used to return silently —
	 * the user clicks Archive, the page reloads, and nothing explains why.
	 * It must now wp_die() with the archive-specific permission message.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 99 );
	}

	/**
	 * post_action_archive's missing-post guard: when get_post() returns null
	 * (post deleted between row-action render and click), the handler returns
	 * silently BEFORE check_admin_referer(). This rejects bogus payloads
	 * without surfacing a WP-core "Are you sure?" dialog from a missing nonce.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 50 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Unsupported post type guard:
	 * a post in an unsupported post type now silently returns BEFORE
	 * check_admin_referer() fires. The original `wp_die('Invalid post
	 * type')` branch became unreachable once the upstream supported-type
	 * check moved ahead of the nonce check — leaving it would have
	 * surfaced a fatal-style dialog on a request the SUT can now reject
	 * without one.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 51 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Persistence-failure guard: when aps_archive_post() returns false
	 * (DB error, ArchiveMetaListener veto, etc.), the handler wp_die()s
	 * with "Error in archiving" rather than silently redirecting with
	 * `archived=1`.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 52 );
	}

	// -----------------------------------------------------------------------
	// wp_die() escaping
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
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 70 );
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
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 55 );
	}

	/**
	 * The persist-failure wp_die() message must be escaped: one call from
	 * the SUT plus the polyfill's own call = 2 total. Pre-fix, only the
	 * polyfill's call happens = 1 total.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 56 );
	}

	// -----------------------------------------------------------------------
	// post_action_archive — wp_check_post_lock branch
	// -----------------------------------------------------------------------

	/**
	 * Pin the normal locked-post case: wp_check_post_lock() returns the
	 * editing user's id, get_userdata() resolves a real user, and the
	 * wp_die() message names that user by their display_name.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 53 );
	}

	/**
	 * Deleted-user regression guard: when the user holding the edit lock no
	 * longer exists, get_userdata() returns false. handle_post_action() must
	 * degrade to the "Another user" fallback rather than dereferencing
	 * display_name on false — and without leaving an empty gap where the
	 * name would have been.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 54 );
	}

	/**
	 * Mirror coverage for the unarchive entry point — nonce key shape must
	 * match the `unarchive-{id}` contract that pairs with the row-action URL.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_unarchive
	 */
	// -----------------------------------------------------------------------
	// Pre-nonce-validation regression tests (ID validation BEFORE nonce check)
	// -----------------------------------------------------------------------

	/**
	 * Pre-nonce-validation regression: validation runs FIRST. With a post_id that get_post()
	 * cannot resolve, the SUT must return without ever calling
	 * check_admin_referer().
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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

		$this->handler->post_action_archive( 50 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Pre-nonce-validation regression (complement): the validation pipeline runs in
	 * dependency order before the nonce check fires. Post id ≤ 0 must
	 * be rejected before any of get_post / aps_is_supported_post_type /
	 * check_admin_referer runs.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
	 */
	public function test_post_action_archive_rejects_nonpositive_id_before_any_downstream_call() {
		// Every downstream surface must be untouched.
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'check_admin_referer' )->never();
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$this->handler->post_action_archive( 0 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Mirror of the archive nonce-key test above, for the unarchive
	 * direction. Regression: a denied capability check must
	 * wp_die() with the *unarchive*-specific permission message, not the
	 * archive-only copy the shared handle_post_action() used to emit
	 * regardless of direction.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_unarchive
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

		$this->handler->post_action_unarchive( 99 );
	}

	/**
	 * Direction-correct copy: a locked post blocks unarchiving with
	 * "You cannot unarchive this item..." — not the archive-only wording the
	 * shared handle_post_action() used to emit for both directions.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_unarchive
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

		$this->handler->post_action_unarchive( 63 );
	}

	/**
	 * Direction-correct copy: a persist failure on unarchive dies
	 * with "Error in unarchiving this item." — not the archive-only
	 * "Error in archiving this item." the shared handle_post_action() used
	 * to emit for both directions.
	 *
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_unarchive
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

		$this->handler->post_action_unarchive( 64 );
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
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_archive
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
			$this->handler->post_action_archive( 60 );
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
	 * @covers ArchivedPostStatus\Admin\PostActionHandler::post_action_unarchive
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
			$this->handler->post_action_unarchive( 61 );
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
