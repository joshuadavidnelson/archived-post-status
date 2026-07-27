<?php
/**
 * Contract pin for aps_archive_post.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ::aps_archive_post
 *
 * C3  pin the invariant documented at
 * `src/functions.php` aps_archive_post and at
 * `src/Archive/ArchiveMetaListener::save_meta()`:
 *
 *   aps_archive_post() MUST NOT re-read the post between `wp_update_post()`
 *   and the `aps_archived_post` action firing. The pre-archive WP_Post
 *   snapshot captured at the top of the function is the canonical 3rd
 *   argument to the action — listeners (notably ArchiveMetaListener) rely
 *   on it to record the pre-archive comment_status / ping_status.
 *
 * The tests below FAIL if a regression ever introduces a second `get_post`
 * call between the update and the action. They watch two complementary
 * signals:
 *   1. The total number of `get_post()` invocations (must be exactly 1 —
 *      the initial read at the top of aps_archive_post).
 *   2. The object instance handed to `aps_archived_post`'s 3rd arg. WP_Mock's
 *      Hook::safe_offset() uses spl_object_hash() to index action `with()`
 *      args, so registering `expectAction(..., $pre_archive)` is an identity
 *      check — a different instance dispatched into do_action() never
 *      matches, the intercepted-once expectation fails, and the test trips.
 */

/**
 * @since 0.4.0
 * @covers ::aps_archive_post
 */
class ArchivePostContractTest extends TestCase {

	/**
	 * Counter-based assertion: aps_archive_post() must call get_post()
	 * exactly once. A re-read between wp_update_post and the action would
	 * push this counter to 2 — the explicit assertion below trips the
	 * regression.
	 *
	 * @covers ::aps_archive_post
	 */
	public function test_aps_archive_post_calls_get_post_exactly_once() {
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

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 42 );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );
		// Identity assertion: WP_Mock indexes object args by spl_object_hash,
		// so the action must dispatch with the same $post instance for the
		// expectation to match.
		\WP_Mock::expectAction( 'aps_archived_post', 42, 'publish', $post );

		$result = aps_archive_post( 42 );

		$this->assertNotFalse( $result, 'aps_archive_post must return the post object on success' );

		// THE CONTRACT PIN. A re-read after wp_update_post would make this 2.
		$this->assertSame(
			1,
			$get_post_calls,
			'aps_archive_post must call get_post() exactly once — a second call between wp_update_post and the action would defeat the snapshot contract.'
		);
	}

	/**
	 * Identity-based assertion (the canary): if the SUT EVER re-reads the
	 * post between wp_update_post and the action, get_post() returns a
	 * DIFFERENT instance the second time — and the action dispatch carries
	 * that sentinel instance, not the pre-archive one. We register
	 * `expectAction(..., $pre_archive)`; WP_Mock indexes the action's
	 * `with()` args by spl_object_hash, so an arbitrary other-instance
	 * dispatch never matches. The expectation's `atLeast()->once()` then
	 * fails during tearDown.
	 *
	 * The complementary assertion (the SUT dispatched with $post_update_reread)
	 * would also surface as a strict-mode warning, but the primary signal
	 * is the missed expectation on $pre_archive.
	 *
	 * @covers ::aps_archive_post
	 */
	public function test_aps_archived_post_receives_pre_update_instance_not_a_re_read() {
		$pre_archive = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		// Sentinel — a regression that re-reads the post post-update would
		// receive this instance instead.
		$post_update_reread = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'archive',
				'post_type'      => 'post',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		$call_count = 0;
		\WP_Mock::userFunction( 'get_post' )
			->andReturnUsing(
				static function () use ( &$call_count, $pre_archive, $post_update_reread ) {
					++$call_count;
					return 1 === $call_count ? $pre_archive : $post_update_reread;
				}
			);

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 42 );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $pre_archive, 'publish' )
			->reply( null );

		// THE CONTRACT PIN: the action must dispatch with $pre_archive,
		// not $post_update_reread. WP_Mock matches the object via
		// spl_object_hash — any other instance trips the
		// "should be called at least 1 times but called 0 times" failure.
		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );
		\WP_Mock::expectAction( 'aps_archived_post', 42, 'publish', $pre_archive );

		aps_archive_post( 42 );

		// Belt-and-suspenders: get_post was called exactly once.
		$this->assertSame(
			1,
			$call_count,
			'aps_archive_post must call get_post() exactly once. A second call would have served the sentinel re-read.'
		);
	}
}
