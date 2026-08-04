<?php
/**
 * Admin\BulkActionHandler tests.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\BulkActionHandler
 * @covers ArchivedPostStatus\Admin\BulkActionResult
 * @covers ArchivedPostStatus\Archive\ArchiveAction
 *
 * Successor to tests/php/Admin/PostListHandleActionTest.php — the bulk-action
 * dispatch + capability + persist + redirect-URL composition were extracted
 * from PostList into the standalone BulkActionHandler service in 0.4.0.
 * These tests now exercise the handler directly via `$handler->handle()`
 * rather than through PostList's filter callback. The single-post
 * `post_action_*` handlers (nonce + cap + redirect) remain on PostList and
 * are tested in PostListTest.php.
 *
 * The handlers wp_die() on capability denial and on persist failures; the
 * common.php wp_die mock translates those to thrown Exceptions, which is
 * how the deny branches surface in assertions.
 *
 * handle() delegates to BulkActionResult for counter accumulation and to
 * ArchiveAction for dispatch / nonce keys / query arg names, so both are
 * declared @covers at class level — the tests intentionally exercise
 * their public API end-to-end via BulkActionHandler.
 *
 * Each scenario stubs only the WP-boundary functions the real procedural
 * helpers traverse:
 *   - aps_current_user_can_*    → current_user_can(<resolved cap>, $id)
 *                                  with the `aps_default_(un)archive_capability`
 *                                  filter callback registered to verify
 *                                  passthrough. Enforcement is per-item;
 *                                  there is no screen-level pre-gate.
 *   - aps_archive_post          → wp_update_post + get_post (the persist
 *                                  layer the real procedural function
 *                                  delegates to). Returns of true/false
 *                                  map to wp_update_post returning the
 *                                  post id (truthy) vs 0 (falsy).
 *   - aps_unarchive_post        → wp_update_post + the five
 *                                  ArchiveMeta::for_post() get_post_meta
 *                                  calls + delete_post_meta (when meta
 *                                  delete is exercised on the unarchive
 *                                  path).
 *   - _aps_get_archivable_statuses → callback on `aps_archivable_statuses`
 *                                  filter — real function executes and
 *                                  returns whatever the filter replied.
 */

use ArchivedPostStatus\Admin\BulkActionHandler;
use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;
use WP_Mock\InvokedFilterValue;

/**
 * BulkActionHandler test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\BulkActionHandler
 * @covers ArchivedPostStatus\Admin\BulkActionResult
 * @covers ArchivedPostStatus\Archive\ArchiveAction
 */
class BulkActionHandlerTest extends TestCase {

	use BoundaryStubs;

	/**
	 * @var BulkActionHandler
	 */
	protected $handler;

	/**
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->handler = new BulkActionHandler();
	}

	/**
	 * Reset request superglobals so the undo-filter scenario doesn't bleed
	 * into later tests.
	 */
	public function tear_down() {
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}


	/**
	 * Non-archive bulk actions (e.g. 'delete') are not ours — handle()
	 * must return the sendback unchanged so WordPress's own handler runs.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_handle_returns_sendback_unchanged_for_non_archive_action() {
		$sendback = 'http://example.com/wp-admin/edit.php';

		// No archive/unarchive plumbing should fire.
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'remove_query_arg' )->never();

		$result = $this->handler->handle( $sendback, 'delete', array( 1, 2 ) );

		$this->assertSame( $sendback, $result );
	}

	/**
	 * Empty post_ids — also a no-op; the action never runs.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_handle_returns_sendback_unchanged_when_no_post_ids() {
		$sendback = 'http://example.com/wp-admin/edit.php';

		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'current_user_can' )->never();

		$result = $this->handler->handle( $sendback, 'archive', array() );

		$this->assertSame( $sendback, $result );
	}

	/**
	 * WordPress core's `wp-admin/edit.php` `ids=` fallback path populates
	 * $post_ids via a bare `explode(',', $_REQUEST['ids'])` — no `intval` —
	 * unlike the `post[]` checkbox path, which does map to ints. A request
	 * such as `edit.php?post_type=post&action=archive&ids=1,abc` therefore
	 * hands handle() a mixed array containing non-numeric strings.
	 *
	 * Those non-numeric entries reach the strictly `int`-typed
	 * process_archive_post() call site and must NOT throw — a TypeError
	 * there would kill the whole batch, violating this class's documented
	 * "the loop continues on every per-item failure" invariant. handle()
	 * must normalize $post_ids (absint + drop non-numeric/zero junk) before
	 * dispatch so the batch completes and only the surviving numeric ids
	 * are processed.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_archive_survives_non_numeric_post_ids_from_unfiltered_ids_query_arg() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 1 )
			->andReturn( false );
		\WP_Mock::userFunction( 'get_post' )->with( 1 )->andReturn(
			$this->createMockPost( array( 'ID' => 1 ) )
		);

		// Only reached once the fix normalizes the array — proves the
		// surviving numeric id after the junk entries still gets processed.
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 2 )
			->andReturn( false );
		\WP_Mock::userFunction( 'get_post' )->with( 2 )->andReturn(
			$this->createMockPost( array( 'ID' => 2 ) )
		);

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		// '' -> absint('') = 0 (junk); '0' -> absint('0') = 0 (never a real
		// post id); 'abc' -> absint('abc') = 0 (junk). All three must be
		// dropped without ever reaching the int-typed process_archive_post().
		$result = $this->handler->handle(
			'http://example.com/wp-admin/edit.php',
			'archive',
			array( 1, 'abc', '', '0', 2 )
		);

		$this->assertIsString( $result, 'handle() must return the redirect URL, not throw a TypeError mid-batch.' );
		$this->assertSame( 2, $captured['denied'] ?? null, 'both surviving numeric ids (1 and 2) must be processed and bucketed as denied' );
		$this->assertSame( 0, $captured['archived'] ?? null, 'archived counter should be 0' );
	}

	/**
	 * Archive bulk action — per-item capability denial buckets the post
	 * into `denied` and continues the batch. The legacy
	 * mid-batch wp_die() was removed.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_denied
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::denied_count
	 */
	public function test_bulk_archive_buckets_denied_per_item_instead_of_dying() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 5 )
			->andReturn( false );

		$post = $this->createMockPost( array( 'ID' => 5 ) );
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		// Per-item cap denial must NOT trigger wp_update_post (the post is
		// never archived) AND must NOT wp_die (no exception thrown).
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		$result = $this->handler->handle( 'http://example.com/wp-admin/edit.php', 'archive', array( 5 ) );

		$this->assertSame( 1, $captured['denied'] ?? null, 'denied bucket should be 1' );
		$this->assertSame( 0, $captured['archived'] ?? null, 'archived counter should be 0 — nothing was archived' );
		$this->assertIsString( $result, 'handle() must return the redirect URL, not throw' );
	}

	/**
	 * Locked posts (wp_check_post_lock returns a user id) increment the
	 * 'locked' counter and are skipped — the URL emerges with a `locked=N`
	 * query arg appended via BulkActionResult.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_archive_records_locked_post_when_post_is_locked_by_another_user() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 7 )
			->andReturn( true );

		$post = $this->createMockPost( array( 'ID' => 7 ) );
		\WP_Mock::userFunction( 'get_post' )->with( 7 )->andReturn( $post );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 7 )
			->andReturn( 99 ); // a different user holds the lock

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		// Archive must never run on a locked post.
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'get_post_status' )->never();

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'archive', array( 7 ) );

		$this->assertSame( 1, $captured['locked'] ?? null, 'locked counter should be 1' );
		$this->assertSame( 0, $captured['archived'] ?? null, 'archived counter should be 0' );
	}

	/**
	 * A post whose status is not in the archivable list (e.g. 'trash') gets
	 * counted as 'wrong_status' and skipped. The redirect surfaces `wrong_status=N`.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_archive_records_wrong_status_when_status_not_archivable() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 8 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 8,
				'post_status' => 'trash',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 8 )->andReturn( $post );

		\WP_Mock::userFunction( 'wp_check_post_lock' )->andReturn( false );
		$this->stubArchivableStatusesBoundary( array( 'publish' ) );
		\WP_Mock::userFunction( 'get_post_status' )
			->with( 8 )
			->andReturn( 'trash' );

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		// The persist call must never run for a wrong-status post.
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'archive', array( 8 ) );

		$this->assertSame( 1, $captured['wrong_status'] ?? null, 'wrong_status counter should be 1' );
		$this->assertSame( 0, $captured['archived'] ?? null, 'archived counter should be 0' );
	}

	/**
	 * A post deleted between bulk-select and dispatch (get_post_status()
	 * returns false) gets counted as 'not_found' and skipped. The redirect
	 * surfaces `not_found=N`, distinct from the `wrong_status` bucket.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_not_found
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::not_found_count
	 */
	public function test_bulk_archive_records_not_found_when_post_status_lookup_returns_false() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 9 )
			->andReturn( true );

		$post = $this->createMockPost( array( 'ID' => 9 ) );
		\WP_Mock::userFunction( 'get_post' )->with( 9 )->andReturn( $post );

		\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 9 )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_status' )
			->with( 9 )
			->andReturn( false ); // post deleted between bulk-select and dispatch

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		// The persist call must never run for a post that no longer exists.
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'archive', array( 9 ) );

		$this->assertSame( 1, $captured['not_found'] ?? null, 'not_found bucket should be 1' );
		$this->assertSame( 0, $captured['archived'] ?? null, 'archived counter should be 0' );
		$this->assertArrayNotHasKey( 'wrong_status', $captured, 'not_found must not also bucket as wrong_status' );
	}

	/**
	 * The bulk-undo path: when WordPress posts the bulk action via the
	 * "Undo" admin notice, `$_GET['doaction']` is 'undo'. The handler must
	 * register `aps_unarchive_post_set_previous_status` on the
	 * `aps_unarchive_post_status` filter so each post is restored to its
	 * stored previous status.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_unarchive_adds_undo_filter_when_doaction_is_undo() {
		$_GET = array( 'doaction' => 'undo' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		// aps_unarchive_post needs a post in 'archive' status to do work.
		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );
		$this->stubUnarchivePersistBoundary( 10 );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		\WP_Mock::expectFilterAdded(
			'aps_unarchive_post_status',
			'aps_unarchive_post_set_previous_status',
			PHP_INT_MAX,
			3
		);

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		// WP_Mock verifies expectFilterAdded during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Regression: the undo-path remove_filter() call must target the
	 * exact same priority (`PHP_INT_MAX`) the add_filter() call registered
	 * at — mismatched priorities mean WordPress's remove_filter() silently
	 * no-ops and the override callback stays registered past this request.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_unarchive_removes_undo_filter_at_reserved_priority() {
		$_GET = array( 'doaction' => 'undo' );

		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );
		$this->stubUnarchivePersistBoundary( 10 );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$removed = array();
		\WP_Mock::userFunction( 'remove_filter' )
			->once()
			->andReturnUsing(
				static function ( $hook, $callback, $priority = 10 ) use ( &$removed ) {
					$removed = array( $hook, $callback, $priority );
					return true;
				}
			);

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$this->assertSame(
			array( 'aps_unarchive_post_status', 'aps_unarchive_post_set_previous_status', PHP_INT_MAX ),
			$removed,
			'remove_filter must target the same reserved priority the undo override was added at'
		);
	}

	/**
	 * Regression: before the fix, remove_filter() ran unconditionally
	 * at the bottom of bulk_unarchive() even when add_filter() never fired
	 * (the non-undo path) — which would have silently removed any
	 * third-party registration of the same callback on the same hook and
	 * priority. The add/remove pairing must now be symmetric: no
	 * remove_filter() call at all when this request never added anything.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_unarchive_does_not_call_remove_filter_for_regular_bulk_action() {
		$_GET = array(); // no doaction=undo

		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );
		$this->stubUnarchivePersistBoundary( 10 );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		\WP_Mock::userFunction( 'remove_filter' )->never();

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Without `$_GET['doaction']='undo'`, the undo filter must NOT be
	 * registered — a plain bulk unarchive uses each post's default unarchive
	 * status, not its stored previous status.
	 *
	 * Instruments the registration point (`add_filter`) to assert the undo
	 * callback name never appears for the `aps_unarchive_post_status` hook.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_unarchive_does_not_add_undo_filter_for_regular_bulk_action() {
		$_GET = array(); // no doaction=undo

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );
		$this->stubUnarchivePersistBoundary( 10 );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$recorded = array();
		\WP_Mock::userFunction( 'add_filter' )->andReturnUsing(
			static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$recorded ) {
				$recorded[] = array(
					'hook'     => $hook,
					'callback' => is_string( $callback ) ? $callback : 'non-string',
				);
				return true;
			}
		);

		$result = $this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$undo_registrations = array_filter(
			$recorded,
			static fn( $r ) => 'aps_unarchive_post_status' === $r['hook']
				&& 'aps_unarchive_post_set_previous_status' === $r['callback']
		);

		$this->assertSame(
			array(),
			$undo_registrations,
			'Undo callback must NOT be registered on the regular bulk-unarchive path'
		);

		// Sanity check on the SUT path: handler still completes and
		// returns the redirect URL the production code built.
		$this->assertSame( 'http://example.com/wp-admin/edit.php', $result );
	}

	/**
	 * Archive bulk action — happy path. Capability passes, post isn't
	 * locked, status is in the archivable list, aps_archive_post()
	 * returns truthy. The redirect URL must include `archived=1` to
	 * trigger the post-list success notice.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_archive_records_archived_count_on_successful_archive() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 42 )->andReturn( true );
		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 42 )->andReturn( false );
		$this->stubArchivableStatusesBoundary( array( 'publish', 'draft' ) );
		\WP_Mock::userFunction( 'get_post_status' )
			->with( 42 )->andReturn( 'publish' );

		// aps_archive_post(42) resolves through get_post + wp_update_post.
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'publish',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 42 );

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			function ( $key, $value, $url ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return $url;
			}
		);

		$this->handler->handle(
			'http://example.com/wp-admin/edit.php',
			'archive',
			array( 42 )
		);

		$this->assertSame( 1, $captured['archived'] ?? null, 'archived counter must register the successful archive' );
		$this->assertSame( array( 42 ), array_map( 'intval', explode( ',', (string) ( $captured['ids'] ?? '' ) ) ) );
	}

	/**
	 * Ownership default: the post's own author needs only the post type's
	 * edit_posts primitive to archive it via the bulk action. This test
	 * sets post_author = get_current_user_id() and asserts the *primitive*
	 * current_user_can() receives, not merely that the archive succeeds.
	 *
	 * Uses a 'book' post type (edit_books / edit_others_books) so the
	 * primitive strings differ from the generic capability every other
	 * test in this file pins.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_bulk_archive_consults_type_edit_posts_primitive_for_authors_own_post() {

		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$post = $this->createMockPost(
			array(
				'ID'          => 60,
				'post_type'   => 'book',
				'post_status' => 'publish',
				'post_author' => 7,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 60 )->andReturn( $post );

		$type_object      = new \stdClass();
		$type_object->cap = (object) array(
			'edit_posts'        => 'edit_books',
			'edit_others_posts' => 'edit_others_books',
		);
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );

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

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 60 )->andReturn( false );
		$this->stubArchivableStatusesBoundary( array( 'publish', 'draft' ) );
		\WP_Mock::userFunction( 'get_post_status' )
			->with( 60 )->andReturn( 'publish' );
		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 60 );

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			function ( $key, $value, $url ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return $url;
			}
		);

		$this->handler->handle(
			'http://example.com/wp-admin/edit.php',
			'archive',
			array( 60 )
		);

		$this->assertSame(
			'edit_books',
			$received_capability,
			"the post type's edit_posts primitive must be consulted for the author's own post"
		);
		$this->assertSame( 1, $captured['archived'] ?? null, 'archived counter must register the successful archive' );
	}

	/**
	 * Unarchive bulk action — per-item capability denial buckets into
	 * `denied` and continues the batch. The legacy mid-batch
	 * wp_die() was removed.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_denied
	 */
	public function test_bulk_unarchive_buckets_denied_per_item_instead_of_dying() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 5 )
			->andReturn( false );

		$post = $this->createMockPost(
			array(
				'ID'          => 5,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		$result = $this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 5 ) );

		$this->assertSame( 1, $captured['denied'] ?? null, 'denied bucket should be 1' );
		$this->assertSame( 0, $captured['unarchived'] ?? null, 'unarchived counter should be 0' );
		$this->assertIsString( $result, 'handle() must return the redirect URL, not throw' );
	}

	/**
	 * Regression: process_unarchive_post() must check wp_check_post_lock()
	 * exactly like its process_archive_post() sibling — a post locked by
	 * another user is bucketed as `locked` and skipped, not unarchived out
	 * from under the editing user.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_locked
	 */
	public function test_bulk_unarchive_records_locked_post_when_post_is_locked_by_another_user() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 11 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 11,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 11 )->andReturn( $post );

		\WP_Mock::userFunction( 'wp_check_post_lock' )
			->with( 11 )
			->andReturn( 99 ); // a different user holds the lock

		// Unarchive must never run on a locked post.
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 11 ) );

		$this->assertSame( 1, $captured['locked'] ?? null, 'locked counter should be 1' );
		$this->assertSame( 0, $captured['unarchived'] ?? null, 'unarchived counter should be 0' );
	}

	/**
	 * Unarchive bulk action — persistence fails. After C2 the handler
	 * buckets the failure as `wrong_status` and continues the batch
	 * rather than calling wp_die().
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_wrong_status
	 */
	public function test_bulk_unarchive_buckets_persist_failure_instead_of_dying() {

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 7 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 7,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 7 )->andReturn( $post );

		// wp_update_post returns 0 → aps_unarchive_post returns false →
		// the SUT buckets into wrong_status (closest fit; the actual cause
		// can't be distinguished without a second DB hit) and continues.
		$this->stubUnarchivePersistBoundary( 7, 0 );

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		$result = $this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 7 ) );

		$this->assertSame( 1, $captured['wrong_status'] ?? null, 'wrong_status bucket should record the persist failure' );
		$this->assertSame( 0, $captured['unarchived'] ?? null, 'unarchived counter should be 0' );
		$this->assertIsString( $result, 'handle() must return the redirect URL, not throw' );
	}

	/**
	 * The missing end-to-end test for the bulk-undo path: when
	 * $_GET['doaction']='undo', the handler must register
	 * aps_unarchive_post_set_previous_status on aps_unarchive_post_status,
	 * and that registration must cause the *previous* status (read from
	 * META_PREVIOUS_STATUS) to flow into wp_update_post — not the default
	 * 'draft'.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ::aps_unarchive_post_set_previous_status
	 */
	public function test_bulk_unarchive_undo_path_passes_previous_status_to_wp_update_post() {
		$_GET = array( 'doaction' => 'undo' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		// Pass false to skip the helper's default wp_update_post stub —
		// we declared a capturing handler above.
		$this->stubUnarchivePersistBoundary( 10, false, 'publish' );

		// Model the priority-10 default callback's effect end-to-end:
		// when aps_unarchive_post() dispatches the filter, the production
		// callback executes against the live args and returns arg-3.
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'publish', 10, 'publish' )
			->reply( new InvokedFilterValue( 'aps_unarchive_post_set_previous_status' ) );

		// Comment and ping filters pass their values through.
		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$this->assertSame(
			'publish',
			$captured['post_status'] ?? null,
			'Undo path must restore META_PREVIOUS_STATUS, not the default draft.'
		);
		$this->assertSame( 10, $captured['ID'] ?? null );
	}

	/**
	 * Defense-in-depth — when META_PREVIOUS_STATUS is missing
	 * ArchiveMeta::for_post() returns null, aps_unarchive_post()
	 * falls back to 'draft' as the new_status, and the default callback
	 * returns 'draft' too — so wp_update_post sees 'draft'.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ::aps_unarchive_post_set_previous_status
	 */
	public function test_bulk_unarchive_undo_path_falls_back_to_draft_when_previous_status_meta_missing() {
		$_GET = array( 'doaction' => 'undo' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		// Empty META_PREVIOUS_STATUS → ArchiveMeta::for_post() returns null →
		// aps_unarchive_post() takes the null-meta branch ($new_status='draft',
		// $previous_status='draft' via the `?: 'draft'` fallback).
		$this->stubUnarchivePersistBoundary( 10, false, '' );

		// Default callback receives ('draft', 10, 'draft') and returns 'draft'.
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'draft', 10, 'draft' )
			->reply( new InvokedFilterValue( 'aps_unarchive_post_set_previous_status' ) );

		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'closed', 10, 'draft' )
			->reply( 'closed' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'closed', 10, 'draft' )
			->reply( 'closed' );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$this->assertSame(
			'draft',
			$captured['post_status'] ?? null,
			'Missing previous_status meta must surface as the documented draft fallback.'
		);
	}

	/**
	 * Documents — does NOT change — that the callback itself performs no
	 * input sanitization. A non-empty, well-shaped-but-not-a-real-status
	 * string reaches wp_update_post unchanged through the bulk-undo path.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ::aps_unarchive_post_set_previous_status
	 */
	public function test_bulk_unarchive_undo_path_passes_malformed_meta_verbatim() {
		$_GET = array( 'doaction' => 'undo' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		// A truthy but non-canonical status string survives the upstream
		// `?: 'draft'` fallback and reaches the filter callback unchanged.
		$this->stubUnarchivePersistBoundary( 10, false, 'totally_made_up_status' );

		// Default callback returns arg-3 verbatim — no sanitization.
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'totally_made_up_status', 10, 'totally_made_up_status' )
			->reply( new InvokedFilterValue( 'aps_unarchive_post_set_previous_status' ) );

		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'open', 10, 'totally_made_up_status' )
			->reply( 'open' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'open', 10, 'totally_made_up_status' )
			->reply( 'open' );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$this->assertSame(
			'totally_made_up_status',
			$captured['post_status'] ?? null,
			'Malformed previous_status must reach wp_update_post verbatim — sanitization happens in WP-core wp_update_post(), not in this callback.'
		);
	}

	/**
	 * get_redirect_url() falls back to admin_url('edit.php') when
	 * wp_get_referer() returns no referer (a deep link with no Referer
	 * header). The cleaned URL strips any prior archived/unarchived/ids
	 * query args so we don't double-up the notice plumbing.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::get_redirect_url
	 */
	public function test_get_redirect_url_falls_back_to_admin_edit_when_no_referer() {
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

		$result = $this->handler->get_redirect_url( 'post' );

		$this->assertSame( 'http://example.com/wp-admin/edit.php', $result );
	}

	/**
	 * For a non-'post' post type, get_redirect_url() appends
	 * `?post_type={type}` so the user lands back on the right list table.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::get_redirect_url
	 */
	public function test_get_redirect_url_appends_post_type_query_arg_for_custom_post_type() {
		\WP_Mock::userFunction( 'wp_get_referer' )->andReturn( false );
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php' )
			->andReturn( 'http://example.com/wp-admin/edit.php' );
		\WP_Mock::userFunction( 'add_query_arg' )
			->with( 'post_type', 'book', 'http://example.com/wp-admin/edit.php' )
			->andReturn( 'http://example.com/wp-admin/edit.php?post_type=book' );
		\WP_Mock::userFunction( 'remove_query_arg' )
			->with(
				array( 'archived', 'unarchived', 'ids', 'locked', 'denied', 'not_found', 'wrong_status', 'skipped' ),
				'http://example.com/wp-admin/edit.php?post_type=book'
			)
			->andReturn( 'http://example.com/wp-admin/edit.php?post_type=book' );

		$result = $this->handler->get_redirect_url( 'book' );

		$this->assertSame( 'http://example.com/wp-admin/edit.php?post_type=book', $result );
	}

	/**
	 * is_edit_screen_url short-circuits on post.php / post-new.php — those
	 * are the single-post editor URLs, not list-table URLs. The fallback
	 * to admin_url('edit.php') must kick in so the user doesn't get
	 * bounced back to the editor of a post that may have just been
	 * archived out from under them.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::get_redirect_url
	 */
	public function test_get_redirect_url_falls_back_to_edit_when_referer_is_post_editor() {
		\WP_Mock::userFunction( 'wp_get_referer' )
			->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=edit' );
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php' )
			->andReturn( 'http://example.com/wp-admin/edit.php' );
		\WP_Mock::userFunction( 'remove_query_arg' )
			->with(
				array( 'archived', 'unarchived', 'ids', 'locked', 'denied', 'not_found', 'wrong_status', 'skipped' ),
				'http://example.com/wp-admin/edit.php'
			)
			->andReturn( 'http://example.com/wp-admin/edit.php' );

		$result = $this->handler->get_redirect_url( 'post' );

		$this->assertSame( 'http://example.com/wp-admin/edit.php', $result );
	}

	/**
	 * When the referer is a valid list-table URL (not post.php / post-new.php),
	 * get_redirect_url returns the referer with archive/unarchive/ids query
	 * args stripped — preserving the user's place in the list (filters,
	 * pagination) without doubling up the notice query args.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::get_redirect_url
	 */
	public function test_get_redirect_url_returns_cleaned_referer_when_referer_is_list_table() {
		\WP_Mock::userFunction( 'wp_get_referer' )
			->andReturn( 'http://example.com/wp-admin/edit.php?paged=2&archived=1' );
		\WP_Mock::userFunction( 'remove_query_arg' )
			->with(
				array( 'archived', 'unarchived', 'ids', 'locked', 'denied', 'not_found', 'wrong_status', 'skipped' ),
				'http://example.com/wp-admin/edit.php?paged=2&archived=1'
			)
			->andReturn( 'http://example.com/wp-admin/edit.php?paged=2' );

		$result = $this->handler->get_redirect_url( 'post' );

		$this->assertSame( 'http://example.com/wp-admin/edit.php?paged=2', $result );
	}

	/**
	 * Strong negative — the plain (non-undo) bulk-unarchive path must
	 * NOT register the default callback, so the filter dispatch returns
	 * the helper's $new_status unmodified.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ::aps_unarchive_post_set_previous_status
	 */
	public function test_plain_bulk_unarchive_does_not_invoke_default_callback() {
		$_GET = array(); // no doaction=undo

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );

		$this->stubUnarchivePersistBoundary( 10, null, 'publish' );

		// Sentinel observer-only callback. In production it would receive
		// the unmodified $new_status because the priority-10 default was
		// never registered in the non-undo path.
		$observed = null;
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'publish', 10, 'publish' )
			->reply(
				new InvokedFilterValue(
					static function ( $value ) use ( &$observed ) {
						$observed = $value;
						return $value;
					}
				)
			);

		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$this->assertSame(
			'publish',
			$observed,
			'On the non-undo path the default callback must not run; sentinel must see the unmodified $new_status.'
		);
	}

	// -----------------------------------------------------------------------
	// Batch-robustness regression tests
	// -----------------------------------------------------------------------

	/**
	 * Batch-robustness regression: a mixed batch of one denied + one wrong-status + one
	 * archivable post must complete WITHOUT calling wp_die() and surface
	 * three independent bucket counts plus one success on the redirect.
	 *
	 * Pre-Phase-1 behavior: the first per-item failure killed the batch
	 * via wp_die(). Post-Phase-1: every per-item failure routes through
	 * the reason-bucketed BulkActionResult and the loop continues.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_denied
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_wrong_status
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record
	 */
	public function test_bulk_archive_continues_loop_and_aggregates_buckets_across_mixed_outcomes() {
		$this->stubArchivableStatusesBoundary( array( 'publish' ) );

		// post 100: denied — current_user_can returns false.
		// post 200: wrong status (trash).
		// post 300: archivable + success.
		// The anonymous mock user (id 0) makes the ownership-aware default
		// resolve to the others-primitive for every id.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post' )->with( 100 )->andReturn(
			$this->createMockPost( array( 'ID' => 100, 'post_status' => 'publish' ) )
		);
		\WP_Mock::userFunction( 'get_post' )->with( 200 )->andReturn(
			$this->createMockPost( array( 'ID' => 200, 'post_status' => 'trash' ) )
		);
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 100 )->andReturn( false );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 200 )->andReturn( true );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 300 )->andReturn( true );

		\WP_Mock::userFunction( 'wp_check_post_lock' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_status' )
			->with( 200 )->andReturn( 'trash' );
		\WP_Mock::userFunction( 'get_post_status' )
			->with( 300 )->andReturn( 'publish' );

		// aps_archive_post(300) → wp_update_post returns 300 (success).
		$post_300 = $this->createMockPost(
			array( 'ID' => 300, 'post_status' => 'publish' )
		);
		\WP_Mock::userFunction( 'get_post' )->with( 300 )->andReturn( $post_300 );
		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 300 );

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				function ( $key, $value, $url ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return $url;
				}
			);

		$result = $this->handler->handle(
			'http://example.com/wp-admin/edit.php',
			'archive',
			array( 100, 200, 300 )
		);

		// One denied, one wrong_status, one archived — the batch ran to completion.
		$this->assertSame( 1, $captured['denied'] ?? null, 'denied bucket must be 1' );
		$this->assertSame( 1, $captured['wrong_status'] ?? null, 'wrong_status bucket must be 1' );
		$this->assertSame( 1, $captured['archived'] ?? null, 'archived counter must be 1' );
		$this->assertSame( '300', (string) ( $captured['ids'] ?? '' ), 'ids list must contain only the successful post id' );

		// Aggregate skipped = 2 (denied + wrong_status).
		$this->assertSame( 2, $captured['skipped'] ?? null, 'aggregate skipped count must be denied+wrong_status' );

		$this->assertIsString( $result, 'handle() must return a redirect URL — never throw mid-batch.' );
	}

}
