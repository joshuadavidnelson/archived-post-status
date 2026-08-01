<?php
/**
 * Archive\ArchiveOperation Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchiveOperation
 *
 * Mirrors `tests/php/Archive/ArchivePostContractTest.php`
 * (the C3 contract pin) plus the early-return tests previously implicit in
 * the facade's behaviour. This test file is the canonical pin for the
 * lifted mechanics.
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
	 * with that same instance (C3 contract).
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
	 * C3 invariant: the SUT must call `get_post()` exactly once — a re-read
	 * between the update and the `aps_archived_post` action would defeat
	 * the pre-archive snapshot contract. Mirrors the assertion in
	 * `ArchivePostContractTest::test_aps_archive_post_calls_get_post_exactly_once`.
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
}
