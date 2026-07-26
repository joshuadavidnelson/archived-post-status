<?php
/**
 * PostStatusGuard Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Status\PostStatusGuard
 */

/**
 * PostStatusGuard test case
 *
 * Tests the post status guard functionality.
 *
 * Contract (see src/Status/PostStatusGuard.php):
 *   - skips if wp_is_post_revision, wp_doing_ajax, or wp_doing_cron is true
 *   - skips if $post->post_status !== 'archive'
 *   - skips if post type is not supported
 *   - skips if comments AND pings are already closed
 *   - else: removes itself from save_post, calls wp_update_post to set
 *     comment_status='closed' and ping_status='closed', then re-adds itself
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Status\PostStatusGuard
 */
class PostStatusGuardTest extends TestCase {

	/**
	 * PostStatusGuard instance
	 *
	 * @var ArchivedPostStatus\Status\PostStatusGuard
	 */
	protected $guard;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->guard = new ArchivedPostStatus\Status\PostStatusGuard();
	}

	/**
	 * Test hooks registration returns a single save_post action descriptor.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::hooks
	 */
	public function test_hooks_returns_save_post_descriptor() {
		// Act
		$hooks = $this->guard->hooks();

		// Assert
		$this->assertIsArray( $hooks );
		$this->assertCount( 1, $hooks );
		$this->assertInstanceOf( ArchivedPostStatus\Hooks\HookDescriptor::class, $hooks[0] );
		$this->assertSame( 'save_post', $hooks[0]->hook );
		$this->assertSame( 'action', $hooks[0]->type );
	}

	/**
	 * Short-circuit: AJAX request must not trigger wp_update_post.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_skips_during_ajax() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		// Behavior: nothing past the short-circuit fires.
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'remove_action' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'archive',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->enforce_archive_state( 1, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Short-circuit: cron request must not trigger wp_update_post.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_skips_during_cron() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'archive',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->enforce_archive_state( 1, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Short-circuit: revisions must not trigger wp_update_post.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_skips_revisions() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 1 )->andReturn( true );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'archive',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->enforce_archive_state( 1, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Skip when the post is not archived (status guard only acts on archive).
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_skips_non_archived_posts() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		// Non-archive status means the supported-post-type check should never run.
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->enforce_archive_state( 1, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Skip when the post type is not supported by the plugin.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_skips_unsupported_post_types() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'attachment' )
			->andReturn( false );

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'archive',
			'post_type'      => 'attachment',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->enforce_archive_state( 1, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Skip when comments and pings are already closed
	 * (aps_archive_post() already did the work).
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_skips_when_already_closed() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );

		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'remove_action' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'archive',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->enforce_archive_state( 1, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Core behavior: when an archived post has comments/pings open the guard
	 * issues a corrective wp_update_post that closes both, and wraps the call
	 * in a remove_action / add_action pair to avoid re-entrancy.
	 *
	 * Note: the guard intentionally writes no post meta (see
	 * src/Status/PostStatusGuard.php:17 — meta is owned by ArchiveMetaListener).
	 * This test asserts the wp_update_post payload, which is the observable
	 * outcome callers care about.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_closes_comments_and_pings_when_archiving() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );

		// Guard must remove itself before issuing the corrective save.
		\WP_Mock::userFunction( 'remove_action' )
			->once()
			->with( 'save_post', [ $this->guard, 'enforce_archive_state' ] );

		// The observable outcome: comments + pings closed via wp_update_post.
		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with( [
				'ID'             => 1,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			] );

		// Guard must re-attach itself afterwards. WP_Mock owns add_action via
		// its internal hook tracker, so we use expectActionAdded() rather than
		// userFunction() — the latter conflicts with WP_Mock's interception.
		\WP_Mock::expectActionAdded( 'save_post', [ $this->guard, 'enforce_archive_state' ], 10, 2 );

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'archive',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->enforce_archive_state( 1, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}
}
