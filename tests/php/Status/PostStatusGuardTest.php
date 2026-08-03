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
	 * Test hooks registration returns the entry (save_post) and exit
	 * (transition_post_status) action descriptors, each pinned in full:
	 * hook name, callback, priority, and accepted args.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::hooks
	 */
	public function test_hooks_returns_entry_and_exit_descriptors() {
		// Act
		$hooks = $this->guard->hooks();

		// Assert
		$this->assertIsArray( $hooks );
		$this->assertCount( 2, $hooks );
		$this->assertInstanceOf( ArchivedPostStatus\Hooks\HookDescriptor::class, $hooks[0] );
		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'save_post', $hooks[0]->hook );
		$this->assertSame( array( $this->guard, 'enforce_archive_state' ), $hooks[0]->callback );
		$this->assertSame( 10, $hooks[0]->priority );
		$this->assertSame( 2, $hooks[0]->accepted_args );
		$this->assertInstanceOf( ArchivedPostStatus\Hooks\HookDescriptor::class, $hooks[1] );
		$this->assertSame( 'action', $hooks[1]->type );
		$this->assertSame( 'transition_post_status', $hooks[1]->hook );
		$this->assertSame( array( $this->guard, 'restore_state_on_exit' ), $hooks[1]->callback );
		$this->assertSame( 10, $hooks[1]->priority );
		$this->assertSame( 3, $hooks[1]->accepted_args );
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

	/**
	 * The plugin's own archive path must not double-fire the entry guard:
	 * while ArchiveOperation reports in_flight(), the guard is a no-op. This
	 * is what lets the archive-side aps_archive_post_comment_status /
	 * aps_archive_post_ping_status filters actually take effect — without
	 * this guard, the corrective wp_update_post() fired by
	 * enforce_archive_state() during the nested save_post dispatch would
	 * silently force comments/pings back to closed/closed, overriding any
	 * non-default filtered value.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_enforce_archive_state_skips_while_archive_operation_in_flight() {
		\WP_Mock::userFunction( 'wp_doing_ajax' )->never();
		\WP_Mock::userFunction( 'wp_doing_cron' )->never();
		\WP_Mock::userFunction( 'wp_is_post_revision' )->never();
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'remove_action' )->never();

		$flag = new \ReflectionProperty( ArchivedPostStatus\Archive\ArchiveOperation::class, 'in_flight' );
		$flag->setAccessible( true );
		$flag->setValue( null, true );

		try {
			$post = new \WP_Post( [
				'ID'             => 1,
				'post_status'    => 'archive',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			] );

			$this->guard->enforce_archive_state( 1, $post );
		} finally {
			$flag->setValue( null, false );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Stub the archive-meta read boundary for the exit guard: META_PREVIOUS_STATUS
	 * first (ArchiveMeta::for_post() short-circuits to null on empty), then the
	 * remaining four keys when a full value object will be built.
	 *
	 * @param int    $post_id         The post id under test.
	 * @param string $previous_status Value for META_PREVIOUS_STATUS ('' → null object).
	 * @param string $comment_status  Value for META_COMMENT_STATUS.
	 * @param string $ping_status     Value for META_PING_STATUS.
	 */
	private function stubExitMetaBoundary( int $post_id, string $previous_status, string $comment_status = 'open', string $ping_status = 'open' ) {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchivedPostStatus\Archive\ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( $previous_status );

		if ( '' === $previous_status ) {
			return;
		}

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchivedPostStatus\Archive\ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( 1700000000 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchivedPostStatus\Archive\ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchivedPostStatus\Archive\ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( $comment_status );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchivedPostStatus\Archive\ArchiveMeta::META_PING_STATUS, true )
			->andReturn( $ping_status );
	}

	/**
	 * Expect all five archive meta keys to be deleted for the post.
	 *
	 * @param int $post_id The post id under test.
	 */
	private function expectMetaDeleted( int $post_id ) {
		foreach ( array(
			ArchivedPostStatus\Archive\ArchiveMeta::META_PREVIOUS_STATUS,
			ArchivedPostStatus\Archive\ArchiveMeta::META_ARCHIVE_DATE,
			ArchivedPostStatus\Archive\ArchiveMeta::META_ARCHIVE_USER,
			ArchivedPostStatus\Archive\ArchiveMeta::META_COMMENT_STATUS,
			ArchivedPostStatus\Archive\ArchiveMeta::META_PING_STATUS,
		) as $key ) {
			\WP_Mock::userFunction( 'delete_post_meta' )
				->once()
				->with( $post_id, $key )
				->andReturn( true );
		}
	}

	/**
	 * Core behavior: an out-of-band exit from the archived status (core Bulk
	 * Edit, direct wp_update_post) restores comment/ping from archive meta
	 * and deletes the meta rows.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_restores_comment_ping_and_deletes_meta() {
		$this->stubExitMetaBoundary( 1, 'publish', 'open', 'open' );
		$this->expectMetaDeleted( 1 );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with( [
				'ID'             => 1,
				'comment_status' => 'open',
				'ping_status'    => 'open',
			] )
			->andReturn( 1 );

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'draft', 'archive', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Key-to-value mapping pin: the corrective wp_update_post() payload must
	 * place comment_status and ping_status under their own keys, not
	 * transpose them. This test uses asymmetric meta so the mapping is
	 * unambiguous.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_maps_comment_and_ping_status_to_distinct_keys_when_asymmetric() {
		$this->stubExitMetaBoundary( 1, 'publish', 'open', 'closed' );
		$this->expectMetaDeleted( 1 );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with( [
				'ID'             => 1,
				'comment_status' => 'open',
				'ping_status'    => 'closed',
			] )
			->andReturn( 1 );

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'draft', 'archive', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Trashing an archived post defers the exit: core records the status in
	 * _wp_trash_meta_status, and the archive meta must survive so whatever
	 * concludes the trash round-trip can still restore faithfully.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_ignores_trash_transitions() {
		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'trash',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'trash', 'archive', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Untrash concludes the deferred exit: core lands untrashed posts on
	 * draft by default, so a trash→draft transition on a post still
	 * carrying archive meta restores comment/ping and clears the meta.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_concludes_the_trash_round_trip() {
		$this->stubExitMetaBoundary( 1, 'publish', 'open', 'open' );
		$this->expectMetaDeleted( 1 );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with( [
				'ID'             => 1,
				'comment_status' => 'open',
				'ping_status'    => 'open',
			] )
			->andReturn( 1 );

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'draft', 'trash', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Untrash back to the archived status (a site hooking
	 * wp_untrash_post_set_previous_status) stays inside the lifecycle —
	 * the meta is untouched.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_ignores_untrash_back_to_archive() {
		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'archive',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'archive', 'trash', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A trash exit on a post with no archive meta (ordinary trashed
	 * content) is none of the plugin's business.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_ignores_trash_exits_without_archive_meta() {
		$this->stubExitMetaBoundary( 1, '' );

		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->restore_state_on_exit( 'draft', 'trash', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A failed corrective write must not cost the recorded state: when
	 * wp_update_post() returns falsy the meta rows survive so a later exit
	 * (or unarchive) can still restore from them.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_keeps_meta_when_restore_write_fails() {
		$this->stubExitMetaBoundary( 1, 'publish', 'open', 'open' );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->andReturn( 0 );
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'draft', 'archive', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The plugin's own unarchive path must not double-fire the exit guard:
	 * while UnarchiveOperation reports in_flight(), the guard is a no-op.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_skips_while_unarchive_operation_in_flight() {
		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$flag = new \ReflectionProperty( ArchivedPostStatus\Archive\UnarchiveOperation::class, 'in_flight' );
		$flag->setAccessible( true );
		$flag->setValue( null, true );

		try {
			$post = new \WP_Post( [
				'ID'             => 1,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			] );

			$this->guard->restore_state_on_exit( 'publish', 'archive', $post );
		} finally {
			$flag->setValue( null, false );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Legacy archives (no meta recorded, pre-0.4.0) exit silently: nothing
	 * to restore, nothing to delete.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_noops_for_meta_less_legacy_posts() {
		$this->stubExitMetaBoundary( 1, '' );

		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'draft', 'archive', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Transitions that do not leave the archived status are ignored: between
	 * two non-archive statuses, and entering the archived status (owned by
	 * enforce_archive_state), and archive→archive no-op writes.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_ignores_non_exit_transitions() {
		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		] );

		$this->guard->restore_state_on_exit( 'publish', 'draft', $post );
		$this->guard->restore_state_on_exit( 'archive', 'draft', $post );
		$this->guard->restore_state_on_exit( 'archive', 'archive', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * When the post's comment/ping already match the recorded meta the
	 * corrective write is skipped, but the meta rows are still cleaned up.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_restore_state_on_exit_skips_write_when_state_matches_but_still_deletes_meta() {
		$this->stubExitMetaBoundary( 1, 'publish', 'closed', 'closed' );
		$this->expectMetaDeleted( 1 );

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$post = new \WP_Post( [
			'ID'             => 1,
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		] );

		$this->guard->restore_state_on_exit( 'draft', 'archive', $post );

		$this->addToAssertionCount( 1 );
	}
}
