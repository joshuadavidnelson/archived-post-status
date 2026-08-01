<?php
/**
 * Frontend\AccessGuard Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Frontend\AccessGuard
 *
 * Covers the §3 #5 behaviors:
 *   - is_singular() === false short-circuits the guard
 *   - non-archive singular posts are left untouched
 *   - archived singular posts get a hard 404 when the viewer lacks capability
 *   - archived singular posts pass through when the viewer has capability
 */

/**
 * AccessGuard test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Frontend\AccessGuard
 */
class AccessGuardTest extends TestCase {

	/**
	 * @var ArchivedPostStatus\Frontend\AccessGuard
	 */
	protected $guard;

	/**
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->guard = new ArchivedPostStatus\Frontend\AccessGuard();
	}

	/**
	 * Reset the global $wp_query after each test so state doesn't leak
	 * across the suite.
	 */
	public function tear_down() {
		unset( $GLOBALS['wp_query'] );
		parent::tear_down();
	}

	/**
	 * hooks() returns a single template_redirect action descriptor bound to
	 * enforce_access() at the default priority and argument count.
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::hooks
	 */
	public function test_hooks_returns_template_redirect_action_descriptor() {
		$hooks = $this->guard->hooks();

		$this->assertCount( 1, $hooks );
		$this->assertInstanceOf( ArchivedPostStatus\Hooks\HookDescriptor::class, $hooks[0] );
		$this->assertTrue( $hooks[0]->is_action() );
		$this->assertSame( 'template_redirect', $hooks[0]->hook );
		$this->assertSame( array( $this->guard, 'enforce_access' ), $hooks[0]->callback );
		$this->assertSame( 10, $hooks[0]->priority );
		$this->assertSame( 1, $hooks[0]->accepted_args );
	}

	/**
	 * On non-singular views (archive pages, home, search) the guard must
	 * return immediately — it never touches get_queried_object() or the
	 * 404 stack.
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::enforce_access
	 */
	public function test_enforce_access_short_circuits_on_non_singular() {
		\WP_Mock::userFunction( 'is_singular' )->andReturn( false );

		// None of the downstream calls may fire on the early-return path.
		\WP_Mock::userFunction( 'get_queried_object' )->never();
		\WP_Mock::userFunction( 'status_header' )->never();
		\WP_Mock::userFunction( 'nocache_headers' )->never();

		$this->guard->enforce_access();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Singular posts whose status is not 'archive' pass through unchanged —
	 * the guard only intervenes on archived content.
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::enforce_access
	 */
	public function test_enforce_access_passes_through_non_archive_post() {
		$post              = new WP_Post();
		$post->ID          = 7;
		$post->post_status = 'publish';

		\WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		\WP_Mock::userFunction( 'get_queried_object' )->andReturn( $post );

		// No 404 sequence on a published singular.
		\WP_Mock::userFunction( 'status_header' )->never();
		\WP_Mock::userFunction( 'nocache_headers' )->never();

		$this->guard->enforce_access();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Archived singular post + viewer lacks the read capability — full 404
	 * sequence: set_404() on the global query, status_header(404), and
	 * nocache_headers().
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::enforce_access
	 */
	public function test_enforce_access_sets_404_when_capability_denied() {
		$post              = new WP_Post();
		$post->ID          = 7;
		$post->post_status = 'archive';
		$post->post_author = 9;

		\WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		\WP_Mock::userFunction( 'get_queried_object' )->andReturn( $post );

		// Real view chain (no facade stub): the filtered read capability is
		// denied, and the visitor (user 3) does not own author-9's post, so
		// the ownership fallback denies too.
		$this->stubStatusObject( false );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'read_private_posts', 7 )
			->andReturn( false );
		\WP_Mock::userFunction( 'get_post' )->with( 7 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );

		$wp_query = \Mockery::mock( 'WP_Query' );
		$wp_query->shouldReceive( 'set_404' )->once();
		$GLOBALS['wp_query'] = $wp_query;

		\WP_Mock::userFunction( 'status_header' )
			->once()
			->with( 404 );
		\WP_Mock::userFunction( 'nocache_headers' )->once();

		$this->guard->enforce_access();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Archived singular post + viewer has the read capability — the request
	 * proceeds normally; no 404 is issued.
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::enforce_access
	 */
	public function test_enforce_access_passes_through_when_capability_granted() {
		$post              = new WP_Post();
		$post->ID          = 7;
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		\WP_Mock::userFunction( 'get_queried_object' )->andReturn( $post );

		$this->stubStatusObject( false );

		// Real view chain: the filtered read capability is granted.
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'read_private_posts', 7 )
			->andReturn( true );

		// Capability passes — no 404 stack should be touched.
		\WP_Mock::userFunction( 'status_header' )->never();
		\WP_Mock::userFunction( 'nocache_headers' )->never();

		$this->guard->enforce_access();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Archived singular post + the post's own author — the ownership
	 * fallback grants view access even without the read capability, so
	 * authors are never 404ed off their own archived content.
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::enforce_access
	 */
	public function test_enforce_access_passes_through_for_the_posts_own_author() {
		$post              = new WP_Post();
		$post->ID          = 7;
		$post->post_status = 'archive';
		$post->post_type   = 'post';
		$post->post_author = 3;

		\WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		\WP_Mock::userFunction( 'get_queried_object' )->andReturn( $post );

		$this->stubStatusObject( false );

		// Real view chain: read capability denied, ownership fallback
		// engages — the visitor authored the post and can edit_posts.
		\WP_Mock::userFunction( 'get_post' )->with( 7 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )
			->andReturn( null );
		\WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			static function ( $capability ) {
				return 'edit_posts' === $capability;
			}
		);

		\WP_Mock::userFunction( 'status_header' )->never();
		\WP_Mock::userFunction( 'nocache_headers' )->never();

		$this->guard->enforce_access();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Stub the registered status object the guard reads to decide whether
	 * the site has declared archived content public.
	 *
	 * @param bool $public Value of the registered status's `public` flag.
	 */
	private function stubStatusObject( bool $public ) {
		$status         = new \stdClass();
		$status->public = $public;

		\WP_Mock::userFunction( 'get_post_status_object' )
			->with( 'archive' )
			->andReturn( $status );
	}

	/**
	 * A site can publish archived content with the documented recipe
	 * (`aps_status_arg_public` true, `aps_status_arg_private` false). When
	 * the status is registered public the site has decided archived posts
	 * are world-readable, so the guard stands down rather than overriding
	 * that configuration with a 404.
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::enforce_access
	 */
	public function test_enforce_access_stands_down_when_the_status_is_registered_public() {
		$post              = new WP_Post();
		$post->ID          = 7;
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		\WP_Mock::userFunction( 'get_queried_object' )->andReturn( $post );

		$this->stubStatusObject( true );

		// The guard must not even ask the capability question, let alone 404.
		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'status_header' )->never();
		\WP_Mock::userFunction( 'nocache_headers' )->never();

		$this->guard->enforce_access();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Defensive: if the status object cannot be resolved, fall through to
	 * the capability check rather than assuming public.
	 *
	 * @covers ArchivedPostStatus\Frontend\AccessGuard::enforce_access
	 */
	public function test_enforce_access_enforces_when_the_status_object_is_missing() {
		$post              = new WP_Post();
		$post->ID          = 7;
		$post->post_status = 'archive';
		$post->post_author = 9;

		\WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		\WP_Mock::userFunction( 'get_queried_object' )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_status_object' )
			->with( 'archive' )
			->andReturn( null );

		\WP_Mock::userFunction( 'current_user_can' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post' )->with( 7 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );

		$wp_query = \Mockery::mock( 'WP_Query' );
		$wp_query->shouldReceive( 'set_404' )->once();
		$GLOBALS['wp_query'] = $wp_query;

		\WP_Mock::userFunction( 'status_header' )->once()->with( 404 );
		\WP_Mock::userFunction( 'nocache_headers' )->once();

		$this->guard->enforce_access();

		$this->addToAssertionCount( 1 );
	}
}
