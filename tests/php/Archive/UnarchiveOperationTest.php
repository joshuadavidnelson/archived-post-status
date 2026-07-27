<?php
/**
 * Archive\UnarchiveOperation Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\UnarchiveOperation
 *
 * Phase 3A extraction (0.4.0): mirrors the existing facade tests in
 * UnarchiveStatusFilterTest (the pure-unit `set_previous_status` contract)
 * and the bulk-unarchive integration tests. The procedural facades
 * `aps_unarchive_post()` and `aps_unarchive_post_set_previous_status()`
 * stay in place this step; Step 3B will rewire them as one-line delegates
 * to `UnarchiveOperation::perform()` and `UnarchiveOperation::set_previous_status()`.
 */

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Archive\UnarchiveOperation;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\UnarchiveOperation
 */
class UnarchiveOperationTest extends TestCase {

	/**
	 * Stub the meta-read boundary so `ArchiveMeta::for_post()` returns a
	 * realistic value object (or null when `$previous_status_meta` is empty).
	 *
	 * @param int    $post_id              The post id under test.
	 * @param string $previous_status_meta Value to return for META_PREVIOUS_STATUS.
	 *                                     Empty string makes ArchiveMeta::for_post() return null.
	 */
	private function stubArchiveMetaBoundary( int $post_id, string $previous_status_meta = 'publish' ): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( $previous_status_meta );

		if ( '' === $previous_status_meta ) {
			return;
		}

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( time() );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( 1 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( 'open' );
	}

	/**
	 * Default-path: an archived post unarchives back to its previous status
	 * recorded in archive meta. Returns the pre-unarchive WP_Post snapshot
	 * and dispatches `aps_unarchived_post` with that same instance.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_unarchives_an_archived_post_to_meta_previous_status() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->stubArchiveMetaBoundary( 42, 'publish' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'publish' )
			->reply( null );
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'publish', 42, 'publish' )
			->reply( 'publish' );
		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'open', 42, 'publish' )
			->reply( 'open' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'open', 42, 'publish' )
			->reply( 'open' );

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 42 );

		\WP_Mock::expectAction( 'aps_unarchive_post', 42, 'publish' );
		\WP_Mock::expectAction( 'aps_unarchived_post', 42, 'publish', $post );

		$result = UnarchiveOperation::perform( 42 );

		$this->assertSame( $post, $result );
	}

	/**
	 * The in-flight flag is raised only while the restore write dispatches,
	 * so PostStatusGuard's transition_post_status exit guard can tell the
	 * plugin's own unarchive apart from an out-of-band status change.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::in_flight
	 */
	public function test_perform_raises_in_flight_only_during_the_restore_write() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->stubArchiveMetaBoundary( 42, 'publish' );

		$in_flight_during_write = null;
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function () use ( &$in_flight_during_write ) {
				$in_flight_during_write = UnarchiveOperation::in_flight();
				return 42;
			}
		);

		$this->assertFalse( UnarchiveOperation::in_flight(), 'flag must start lowered' );

		UnarchiveOperation::perform( 42 );

		$this->assertTrue( $in_flight_during_write, 'flag must be raised while wp_update_post runs' );
		$this->assertFalse( UnarchiveOperation::in_flight(), 'flag must be lowered after perform()' );
	}

	/**
	 * The in-flight flag is lowered even when the restore write fails —
	 * the try/finally must not leak a raised flag into later transitions.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::in_flight
	 */
	public function test_perform_lowers_in_flight_when_the_restore_write_fails() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->stubArchiveMetaBoundary( 42, 'publish' );

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 0 );

		$result = UnarchiveOperation::perform( 42 );

		$this->assertFalse( $result );
		$this->assertFalse( UnarchiveOperation::in_flight(), 'flag must be lowered after a failed write' );
	}

	/**
	 * Edge case: a missing post short-circuits to `false`.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_returns_false_when_post_does_not_exist() {
		\WP_Mock::userFunction( 'get_post' )->with( 999 )->andReturn( null );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->assertFalse( UnarchiveOperation::perform( 999 ) );
	}

	/**
	 * Edge case: a non-archive-status post short-circuits — unarchive is
	 * only valid for posts currently in the `'archive'` status.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_returns_false_when_post_is_not_archived() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->assertFalse( UnarchiveOperation::perform( 42 ) );
	}

	/**
	 * Filter short-circuit: a non-null `aps_pre_unarchive_post` return
	 * bypasses the update and reflects the filter return verbatim.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_short_circuits_when_aps_pre_unarchive_post_filter_returns_non_null() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->stubArchiveMetaBoundary( 42, 'publish' );

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'publish' )
			->reply( false );

		$this->assertFalse( UnarchiveOperation::perform( 42 ) );
	}

	/**
	 * Edge case: missing archive meta falls back to `'draft'` / `'closed'` /
	 * `'closed'` defaults (legacy archives that pre-date the meta listener).
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_falls_back_to_draft_when_archive_meta_is_missing() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->stubArchiveMetaBoundary( 42, '' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'draft' )
			->reply( null );
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'draft', 42, 'draft' )
			->reply( 'draft' );
		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'closed', 42, 'draft' )
			->reply( 'closed' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'closed', 42, 'draft' )
			->reply( 'closed' );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		\WP_Mock::expectAction( 'aps_unarchive_post', 42, 'draft' );
		\WP_Mock::expectAction( 'aps_unarchived_post', 42, 'draft', $post );

		UnarchiveOperation::perform( 42 );

		$this->assertSame( 'draft', $captured['post_status'] );
		$this->assertSame( 'closed', $captured['comment_status'] );
		$this->assertSame( 'closed', $captured['ping_status'] );
	}

	/**
	 * Edge case: invalid comment_status filter return is coerced back to
	 * `'closed'`. The SUT defends against typo'd filter returns by
	 * normalising to the safe default.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_coerces_invalid_comment_status_filter_return_to_closed() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->stubArchiveMetaBoundary( 42, 'publish' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )->with( null, $post, 'publish' )->reply( null );
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'publish', 42, 'publish' )
			->reply( 'publish' );
		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'open', 42, 'publish' )
			->reply( 'gibberish' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'open', 42, 'publish' )
			->reply( 'also-bad' );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		UnarchiveOperation::perform( 42 );

		$this->assertSame( 'closed', $captured['comment_status'] );
		$this->assertSame( 'closed', $captured['ping_status'] );
	}

	/**
	 * Edge case: when `wp_update_post()` returns falsy, the SUT returns
	 * false and does NOT fire `aps_unarchived_post`.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_returns_false_when_wp_update_post_fails() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->stubArchiveMetaBoundary( 42, 'publish' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )->with( null, $post, 'publish' )->reply( null );
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )->with( 'publish', 42, 'publish' )->reply( 'publish' );
		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )->with( 'open', 42, 'publish' )->reply( 'open' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )->with( 'open', 42, 'publish' )->reply( 'open' );

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 0 );

		\WP_Mock::expectAction( 'aps_unarchive_post', 42, 'publish' );

		$this->assertFalse( UnarchiveOperation::perform( 42 ) );
	}

	/**
	 * Phase 4 coverage pin: META_PREVIOUS_STATUS === '0' (or any other
	 * empty/falsy stored value) must route through the legacy "no meta"
	 * branch of `resolve_restore_values()` and reach `wp_update_post`
	 * as `post_status === 'draft'`.
	 *
	 * `ArchiveMeta::for_post()` calls `empty( $previous_status )` and
	 * returns null for the '0'-string case (`empty('0') === true` in PHP),
	 * so the SUT then takes the legacy path: previous_status =
	 * 'draft', new_status = 'draft', comment_status = 'closed', ping_status
	 * = 'closed'. This branch was uncovered before — existing tests covered
	 * either a populated `previous_status` ('publish') or a completely
	 * absent meta key (empty string returned).
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_perform_falls_back_to_draft_when_previous_status_meta_is_zero_string() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );

		// previous_status meta is the literal string '0' — empty('0') is
		// true, so ArchiveMeta::for_post() returns null and the SUT takes
		// the legacy branch.
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( '0' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'draft' )
			->reply( null );
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'draft', 42, 'draft' )
			->reply( 'draft' );
		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'closed', 42, 'draft' )
			->reply( 'closed' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'closed', 42, 'draft' )
			->reply( 'closed' );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		\WP_Mock::expectAction( 'aps_unarchive_post', 42, 'draft' );
		\WP_Mock::expectAction( 'aps_unarchived_post', 42, 'draft', $post );

		UnarchiveOperation::perform( 42 );

		$this->assertSame(
			'draft',
			$captured['post_status'] ?? null,
			'Unarchive must fall back to post_status=draft when META_PREVIOUS_STATUS is the empty/zero-string sentinel'
		);
	}

	/**
	 * Pure-unit contract for `set_previous_status()` — the WP-core-style
	 * filter callback that the bulk-undo flow registers on
	 * `aps_unarchive_post_status` to force the restored status back to the
	 * pre-archive value. Mirrors `UnarchiveStatusFilterTest::test_returns_previous_status_argument_verbatim`.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::set_previous_status
	 */
	public function test_set_previous_status_returns_third_arg_verbatim() {
		$this->assertSame(
			'publish',
			UnarchiveOperation::set_previous_status( 'draft', 99, 'publish' )
		);
	}

	/**
	 * Sister to the above — empty `$previous_status` flows through. The
	 * `?: 'draft'` fallback lives in `UnarchiveOperation::perform()`, NOT
	 * inside this filter callback. Same contract as the procedural
	 * `aps_unarchive_post_set_previous_status()`.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::set_previous_status
	 */
	public function test_set_previous_status_returns_empty_string_when_previous_status_is_empty() {
		$this->assertSame(
			'',
			UnarchiveOperation::set_previous_status( 'draft', 99, '' )
		);
	}
}
