<?php
/**
 * Archive\ArchiveOperation Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchiveOperation
 */

use ArchivedPostStatus\Archive\ArchiveOperation;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ArchiveOperation
 */
class ArchiveOperationTest extends TestCase {

	/**
	 * Default-path: a publish-status post archives. The pre-archive WP_Post
	 * snapshot is returned and the `aps_archived_post` action dispatches
	 * with that same instance.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_archives_a_publish_post_and_returns_pre_archive_snapshot() {
		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'post_status'    => 'archive',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			)
			->andReturn( 42 );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );
		\WP_Mock::expectAction( 'aps_archived_post', 42, 'publish', $post );

		$result = ArchiveOperation::perform( 42 );

		$this->assertSame( $post, $result );
	}

	/**
	 * Edge case: a missing post (get_post returns null) short-circuits to
	 * `false` without firing any actions.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_returns_false_when_post_does_not_exist() {
		\WP_Mock::userFunction( 'get_post' )->with( 999 )->andReturn( null );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->assertFalse( ArchiveOperation::perform( 999 ) );
	}

	/**
	 * Edge case: a post already in the `archive` status short-circuits to
	 * `false` — re-archiving is a no-op.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_returns_false_when_post_is_already_archived() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->assertFalse( ArchiveOperation::perform( 42 ) );
	}

	/**
	 * Filter short-circuit: a non-null `aps_pre_archive_post` return value
	 * bypasses the update entirely. Sites use this to veto archival of
	 * specific posts.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_short_circuits_when_aps_pre_archive_post_filter_returns_non_null() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( false );

		$this->assertFalse( ArchiveOperation::perform( 42 ) );
	}

	/**
	 * Edge case: when `wp_update_post()` returns falsy (e.g. WP rejected the
	 * write), the SUT returns false and does NOT fire `aps_archived_post`.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_returns_false_when_wp_update_post_fails() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 0 );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );

		$this->assertFalse( ArchiveOperation::perform( 42 ) );
	}

	/**
	 * The SUT must call `get_post()` exactly once — a re-read
	 * between the update and the `aps_archived_post` action would defeat
	 * the pre-archive snapshot contract.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_calls_get_post_exactly_once_to_preserve_pre_archive_snapshot() {
		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		$get_post_calls = 0;
		\WP_Mock::userFunction( 'get_post' )
			->andReturnUsing(
				static function () use ( &$get_post_calls, $post ) {
					++$get_post_calls;
					return $post;
				}
			);

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'post_status'    => 'archive',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			)
			->andReturn( 42 );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );
		\WP_Mock::expectAction( 'aps_archived_post', 42, 'publish', $post );

		ArchiveOperation::perform( 42 );

		$this->assertSame(
			1,
			$get_post_calls,
			'ArchiveOperation::perform() must call get_post() exactly once — a second call between wp_update_post and aps_archived_post would defeat the pre-archive snapshot contract.'
		);
	}

	/**
	 * New-filter pin: `aps_archive_post_comment_status` / `aps_archive_post_ping_status`
	 * fire with the default `'closed'` value and the documented ($post_id,
	 * $previous_status) context args — the mirror of UnarchiveOperation's
	 * restore-side filters. Explicit onFilter() expectations make this a real
	 * pin rather than relying on WP_Mock's implicit apply_filters passthrough.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_applies_comment_and_ping_status_filters_with_default_closed_value() {
		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::onFilter( 'aps_archive_post_comment_status' )
			->with( 'closed', 42, 'publish' )
			->reply( 'closed' );
		\WP_Mock::onFilter( 'aps_archive_post_ping_status' )
			->with( 'closed', 42, 'publish' )
			->reply( 'closed' );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'post_status'    => 'archive',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			)
			->andReturn( 42 );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );
		\WP_Mock::expectAction( 'aps_archived_post', 42, 'publish', $post );

		$result = ArchiveOperation::perform( 42 );

		$this->assertSame( $post, $result );
	}

	/**
	 * Filter override: a site can keep comments/pings open on archive by
	 * returning `'open'` from the new comment/ping status filters — the
	 * limitation the changelog previously disclosed as unfilterable. Uses
	 * asymmetric values (open comment, closed ping) so the key mapping is
	 * unambiguous, mirroring
	 * UnarchiveOperationTest::test_perform_maps_comment_and_ping_status_to_distinct_keys_when_asymmetric.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_honors_comment_and_ping_status_filter_overrides() {
		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::onFilter( 'aps_archive_post_comment_status' )
			->with( 'closed', 42, 'publish' )
			->reply( 'open' );
		\WP_Mock::onFilter( 'aps_archive_post_ping_status' )
			->with( 'closed', 42, 'publish' )
			->reply( 'closed' );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'post_status'    => 'archive',
					'comment_status' => 'open',
					'ping_status'    => 'closed',
				)
			)
			->andReturn( 42 );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );
		\WP_Mock::expectAction( 'aps_archived_post', 42, 'publish', $post );

		$result = ArchiveOperation::perform( 42 );

		$this->assertSame( $post, $result );
	}

	/**
	 * Edge case: invalid comment/ping status filter returns are coerced back
	 * to `'closed'` — the SUT defends against typo'd filter returns the same
	 * way UnarchiveOperation::dispatch_update() does. Mirrors
	 * UnarchiveOperationTest::test_perform_coerces_invalid_comment_status_filter_return_to_closed.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_perform_coerces_invalid_comment_and_ping_status_filter_returns_to_closed() {
		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )->with( null, $post, 'publish' )->reply( null );
		\WP_Mock::onFilter( 'aps_archive_post_comment_status' )
			->with( 'closed', 42, 'publish' )
			->reply( 'gibberish' );
		\WP_Mock::onFilter( 'aps_archive_post_ping_status' )
			->with( 'closed', 42, 'publish' )
			->reply( 'also-bad' );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		ArchiveOperation::perform( 42 );

		$this->assertSame( 'closed', $captured['comment_status'] );
		$this->assertSame( 'closed', $captured['ping_status'] );
	}

	/**
	 * The in-flight flag is raised only while the archive write dispatches, so
	 * PostStatusGuard::enforce_archive_state()'s entry guard can tell the
	 * plugin's own archive write apart from an out-of-band entry into the
	 * archived status. This is what lets the
	 * aps_archive_post_comment_status / aps_archive_post_ping_status filters
	 * actually stick instead of being silently reverted by the guard's
	 * corrective wp_update_post() on the nested save_post dispatch.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::in_flight
	 */
	public function test_perform_raises_in_flight_only_during_the_archive_write() {
		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::onFilter( 'aps_pre_archive_post' )->with( null, $post, 'publish' )->reply( null );

		$in_flight_during_write = null;
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function () use ( &$in_flight_during_write ) {
				$in_flight_during_write = ArchiveOperation::in_flight();
				return 42;
			}
		);

		$this->assertFalse( ArchiveOperation::in_flight(), 'flag must start lowered' );

		ArchiveOperation::perform( 42 );

		$this->assertTrue( $in_flight_during_write, 'flag must be raised while wp_update_post runs' );
		$this->assertFalse( ArchiveOperation::in_flight(), 'flag must be lowered after perform()' );
	}

	/**
	 * The in-flight flag is lowered even when the archive write fails — the
	 * try/finally must not leak a raised flag into later transitions.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::in_flight
	 */
	public function test_perform_lowers_in_flight_when_the_archive_write_fails() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::onFilter( 'aps_pre_archive_post' )->with( null, $post, 'publish' )->reply( null );

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 0 );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );

		$result = ArchiveOperation::perform( 42 );

		$this->assertFalse( $result );
		$this->assertFalse( ArchiveOperation::in_flight(), 'flag must be lowered after a failed write' );
	}
}
