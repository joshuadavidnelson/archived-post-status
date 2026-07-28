<?php
/**
 * Procedural Public-API Contract Tests
 *
 * Locked-for-backward-compat surface of the plugin: `aps_*` functions that
 * downstream sites and integrations call. The class-based architecture
 * (Admin\PostList, Archive\ArchiveMetaListener, etc.) is the implementation
 * detail behind these. Tests here pin the procedural shape so a refactor
 * of the internals doesn't silently break consumers.
 *
 * Scope:
 *   - aps_get_archive_post_link()    — two branches (unsupported type,
 *                                       supported type). Capability is the
 *                                       caller's concern, not the link
 *                                       builder's — see Admin\ArchivePostLink.
 *   - aps_get_unarchive_post_link()  — delegates through aps_get_archive_post_link.
 *   - aps_archived_post_link filter  — alternate link function for archived
 *                                       posts in the front-end.
 *   - aps_archive_post() / aps_unarchive_post() — the `aps_pre_*_post` short
 *                                       circuit filter and the
 *                                       action→listener chain (end-to-end with
 *                                       a real ArchiveMetaListener wired in).
 *
 * Approach:
 *   This file does NOT stub plugin-owned aps_* /
 *   _aps_* helpers; instead it stubs only the WP-boundary functions those
 *   helpers traverse on the way to apply_filters. Result: a regression in
 *   aps_is_supported_post_type or _aps_nonce_key surfaces here as a failed
 *   filter assertion rather than being bypassed.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Archive\ArchiveMetaListener;

/**
 * Public-API test case.
 *
 * @since 0.4.0
 */
class PublicApiTest extends TestCase {

	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();
	}

	/**
	 * Stub the WP-boundary functions that aps_get_archive_post_link()
	 * traverses on its way to apply_filters( 'aps_get_archive_post_link', ... ).
	 *
	 * Crucially does NOT stub aps_is_supported_post_type or _aps_nonce_key
	 * — those are SUT-owned and must execute against the real production
	 * code, with their own dependencies (get_post_types, the
	 * aps_supported_post_types / aps_excluded_post_types filters) supplied
	 * here as boundary stubs.
	 *
	 * Also does NOT stub current_user_can() — aps_get_archive_post_link()
	 * consults no capability; ArchivePostLink::build() only decides whether
	 * a link is constructible (post exists, post type is supported), not
	 * whether the caller is authorized. See Admin\ArchivePostLink.
	 */
	private function mockArchiveLinkWpBoundary(): void {
		// aps_get_supported_post_types() depends on get_post_types + the
		// aps_excluded_post_types and aps_supported_post_types filters.
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post', 'page' => 'page' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post', 'page' ) )
			->reply( array( 'post', 'page' ) );

		// admin_url + add_query_arg + wp_nonce_url + esc_url form the URL
		// pipeline aps_get_archive_post_link() pushes its output through.
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) array( '_edit_link' => 'post.php?post=%d&action=edit' ) );
		\WP_Mock::userFunction( 'admin_url' )
			->andReturn( 'http://example.com/wp-admin/post.php?post=123&action=edit' );
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing(
				static function ( $arg, $value, $url ) {
					return $url . '&' . $arg . '=' . $value;
				}
			);
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing(
				static fn( $url ) => $url . '&_wpnonce=abc123'
			);
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => $url );
	}

	// -----------------------------------------------------------------------
	// aps_get_archive_post_link — filter pass-through
	// -----------------------------------------------------------------------

	/**
	 * The `aps_get_archive_post_link` filter receives the URL produced by
	 * the real link pipeline and can replace it. Verifies the URL handed
	 * to the filter contains the action and the nonce — so a regression
	 * upstream (e.g. wp_nonce_url no longer called, or action sanitization
	 * dropped) surfaces as a `with()` mismatch.
	 *
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_archive_post_link_filter_replaces_returned_url() {
		$post = $this->createMockPost( array( 'ID' => 123, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		$this->mockArchiveLinkWpBoundary();

		$expected_url = 'http://example.com/wp-admin/post.php?post=123&action=edit&action=archive&_wpnonce=abc123';

		\WP_Mock::onFilter( 'aps_get_archive_post_link' )
			->with( $expected_url, 123, 'display' )
			->reply( 'http://custom.com/archive-link' );

		$result = aps_get_archive_post_link( 123 );

		$this->assertSame( 'http://custom.com/archive-link', $result );
	}

	/**
	 * When called with action='unarchive', the filter name is
	 * `aps_get_unarchive_post_link` — the action segment is interpolated
	 * into the filter name in the SUT.
	 *
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_unarchive_post_link_filter_replaces_returned_url() {
		$post = $this->createMockPost( array( 'ID' => 123, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		$this->mockArchiveLinkWpBoundary();

		$expected_url = 'http://example.com/wp-admin/post.php?post=123&action=edit&action=unarchive&_wpnonce=abc123';

		\WP_Mock::onFilter( 'aps_get_unarchive_post_link' )
			->with( $expected_url, 123, 'display' )
			->reply( 'http://custom.com/unarchive-link' );

		$result = aps_get_archive_post_link( 123, 'display', 'unarchive' );

		$this->assertSame( 'http://custom.com/unarchive-link', $result );
	}

	// -----------------------------------------------------------------------
	// aps_get_archive_post_link — branch coverage
	// -----------------------------------------------------------------------

	/**
	 * Unsupported post type → aps_get_archive_post_link() returns false
	 * before ever building a URL. The `attachment` type is in the default
	 * excluded list; we let the real aps_is_supported_post_type() resolve
	 * against the filter boundary stubs.
	 *
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_archive_post_link_returns_false_for_unsupported_post_type() {
		$post = $this->createMockPost( array( 'ID' => 200, 'post_type' => 'attachment' ) );

		\WP_Mock::userFunction( 'get_post' )
			->with( 200 )
			->andReturn( $post );

		// Real aps_is_supported_post_type() resolves through the filter chain
		// → returns false for 'attachment'.
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ) );
		// The default-exclusions list is only kept if the excluded slug
		// actually exists — see SupportedPostTypes::all().
		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post', 'page' ) )
			->reply( array( 'post', 'page' ) );

		// The post type object lookup happens before the supported-type check.
		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'attachment' )
			->andReturn( (object) array( '_edit_link' => 'post.php?post=%d&action=edit' ) );

		// Downstream URL plumbing must never fire on the unsupported branch.
		\WP_Mock::userFunction( 'admin_url' )->never();
		\WP_Mock::userFunction( 'wp_nonce_url' )->never();
		\WP_Mock::userFunction( 'current_user_can' )->never();

		$result = aps_get_archive_post_link( 200 );

		$this->assertFalse( $result );
	}

	/**
	 * Supported post type + capability granted → aps_get_archive_post_link()
	 * returns a non-empty string URL that contains the nonce token. This
	 * complements the filter-replacement tests above by asserting on the
	 * pre-filter URL shape (no filter registered → the value flows
	 * through unchanged).
	 *
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_archive_post_link_returns_url_with_nonce_when_user_can_archive() {
		$post = $this->createMockPost( array( 'ID' => 202, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )
			->with( 202 )
			->andReturn( $post );

		$this->mockArchiveLinkWpBoundary();

		$result = aps_get_archive_post_link( 202 );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'action=archive', $result );
		$this->assertStringContainsString( '_wpnonce=abc123', $result );
	}

	// -----------------------------------------------------------------------
	// aps_get_unarchive_post_link — delegation 
	// -----------------------------------------------------------------------

	/**
	 * aps_get_unarchive_post_link() is a thin alias around
	 * aps_get_archive_post_link($post, $context, 'unarchive'). This test
	 * pins the alias by asserting the URL it returns contains the
	 * unarchive action (not archive) and the nonce.
	 *
	 * Without this, the alias function (currently at 0% coverage in
	 * src/functions.php) is unreachable from the test suite.
	 *
	 * @covers ::aps_get_unarchive_post_link
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_unarchive_post_link_returns_url_with_unarchive_action() {
		$post = $this->createMockPost( array( 'ID' => 203, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )
			->with( 203 )
			->andReturn( $post );

		$this->mockArchiveLinkWpBoundary();

		$result = aps_get_unarchive_post_link( 203 );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'action=unarchive', $result );
		$this->assertStringContainsString( '_wpnonce=abc123', $result );
	}

	// -----------------------------------------------------------------------
	// aps_archived_post_link — preview-style link
	// -----------------------------------------------------------------------

	/**
	 * `aps_archived_post_link` filter — the alternate link function for
	 * front-end previews of archived posts. Mirrors the earlier
	 * LinkFiltersTest::test_aps_archived_post_link_filter contract.
	 *
	 * @covers ::aps_get_archived_post_link
	 */
	public function test_aps_archived_post_link_filter_replaces_returned_url() {
		$post = $this->createMockPost(
			array(
				'ID'          => 123,
				'post_type'   => 'post',
				'post_status' => 'archive',
			)
		);

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		// aps_get_archived_post_link() boundary mocks. Again no aps_* stub
		// — the real aps_is_supported_post_type runs against the
		// get_post_types/filter stubs below.
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' ) )
			->reply( array( 'post' ) );

		\WP_Mock::userFunction( 'is_post_status_viewable' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) array( 'public' => true ) );
		\WP_Mock::userFunction( 'is_post_type_viewable' )->andReturn( false );
		\WP_Mock::userFunction( 'get_permalink' )->andReturn( 'http://example.com/post/123' );
		\WP_Mock::userFunction( 'set_url_scheme' )->andReturn( 'http://example.com/post/123' );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/post/123?preview=true' );
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn( $url ) => $url );

		\WP_Mock::onFilter( 'aps_archived_post_link' )
			->with( 'http://example.com/post/123?preview=true', $post )
			->reply( 'http://custom.com/archived-view' );

		$result = aps_get_archived_post_link( 123 );

		$this->assertSame( 'http://custom.com/archived-view', $result );
	}

	// -----------------------------------------------------------------------
	// aps_archive_post / aps_unarchive_post — pre-filter contract
	// -----------------------------------------------------------------------

	/**
	 * The default path through aps_archive_post(): pre-filter returns null
	 * (no short-circuit), wp_update_post succeeds, both `aps_archive_post`
	 * and `aps_archived_post` actions fire, the function returns the
	 * pre-update WP_Post. The 3rd `aps_archived_post` arg is the original
	 * WP_Post object — listeners depend on it.
	 *
	 * @covers ::aps_archive_post
	 */
	public function test_aps_archive_post_fires_action_chain_when_pre_filter_returns_null() {
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'publish' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 123,
					'post_status'    => 'archive',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			)
			->andReturn( 123 );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_archive_post', 123, 'publish' );
		// `aps_archived_post` fires with 3 args — the original WP_Post is
		// passed so listeners like ArchiveMetaListener can read
		// comment/ping status before the update lands.
		\WP_Mock::expectAction( 'aps_archived_post', 123, 'publish', $post );

		$result = aps_archive_post( 123 );

		$this->assertEquals( $post, $result );
	}

	/**
	 * The `aps_pre_archive_post` filter is the documented short-circuit:
	 * any non-null return aborts the archive and is returned to the
	 * caller. Verifies the contract by returning `false` from the filter
	 * and asserting the SUT propagates it without invoking wp_update_post.
	 *
	 * @covers ::aps_archive_post
	 */
	public function test_aps_pre_archive_post_filter_short_circuits_with_returned_value() {
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'publish' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( false );

		// Short-circuit means no update + no archived action.
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$result = aps_archive_post( 123 );

		$this->assertFalse( $result );
	}

	/**
	 * The default path through aps_unarchive_post(): pre-filter returns
	 * null, the three configurable filters fire with the meta values, and
	 * the action chain completes including `aps_unarchived_post` with the
	 * pre-update WP_Post as the 3rd arg.
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_unarchive_post_fires_filter_and_action_chain_when_pre_filter_returns_null() {
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'archive' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		// ArchiveMeta::for_post() reads the five meta keys.
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( time() );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( 1 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( 'open' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_unarchive_post', 123, 'publish' );
		\WP_Mock::expectFilter( 'aps_unarchive_post_status', 'publish', 123, 'publish' );
		\WP_Mock::expectFilter( 'aps_unarchive_post_comment_status', 'open', 123, 'publish' );
		\WP_Mock::expectFilter( 'aps_unarchive_post_ping_status', 'open', 123, 'publish' );

		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 123,
					'post_status'    => 'publish',
					'comment_status' => 'open',
					'ping_status'    => 'open',
				)
			)
			->andReturn( 123 );
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
		\WP_Mock::expectAction( 'aps_unarchived_post', 123, 'publish', $post );

		$result = aps_unarchive_post( 123 );

		$this->assertEquals( $post, $result );
	}

	/**
	 * The `aps_pre_unarchive_post` filter short-circuit — returning any
	 * non-null value aborts the SUT and propagates the value back to the
	 * caller. A string sentinel verifies the value flows through unchanged.
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_pre_unarchive_post_filter_short_circuits_with_returned_value() {
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'archive' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_meta' )
			->andReturn( 'publish' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'publish' )
			->reply( 'custom_return' );

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$result = aps_unarchive_post( 123 );

		$this->assertEquals( 'custom_return', $result );
	}

	// -----------------------------------------------------------------------
	// End-to-end listener chain 
	// -----------------------------------------------------------------------

	/**
	 * The action→listener→meta chain: calling aps_archive_post() must fire
	 * the `aps_archived_post` action which a real ArchiveMetaListener
	 * (wired in via WP_Mock::onAction) consumes by writing the five
	 * `_aps_archive_meta_*` keys.
	 *
	 * Previous archive-process tests asserted only `WP_Mock::expectAction`
	 * — i.e., "the action name fired with these args". That contract
	 * survives a renamed action but does NOT survive an ArchiveMetaListener
	 * that silently stops persisting meta. This test instantiates a real
	 * listener, hooks it to the action through WP_Mock's onAction bridge,
	 * and asserts on the meta writes the listener performs end-to-end.
	 *
	 * Trust boundary: only add_post_meta is mocked (the WP DB layer the
	 * listener delegates to). aps_archive_post, ArchiveMetaListener, and
	 * ArchiveMeta::from_post()/save() all run unmocked.
	 *
	 * @covers ::aps_archive_post
	 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta
	 */
	public function test_aps_archive_post_invokes_listener_which_writes_five_meta_keys() {
		$post                 = new WP_Post();
		$post->ID             = 555;
		$post->post_status    = 'publish';
		$post->comment_status = 'open';
		$post->ping_status    = 'closed';

		\WP_Mock::userFunction( 'get_post' )
			->with( 555 )
			->andReturn( $post );

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 555 );

		// Wire a real ArchiveMetaListener to the action. WP_Mock::onAction's
		// perform-callback signature is argument-less (see vendor/10up/wp_mock
		// Action_Responder::react which calls call_user_func without
		// forwarding the action args), so the closure below captures the
		// expected args from the outer scope and dispatches them into the
		// real listener method. The `with()` constraint above still asserts
		// that the production code emitted exactly (555, 'publish', $post).
		$listener = new ArchiveMetaListener();
		\WP_Mock::onAction( 'aps_archived_post' )
			->with( 555, 'publish', $post )
			->perform(
				static function () use ( $listener, $post ) {
					$listener->save_meta( 555, 'publish', $post );
				}
			);

		// Capture every update_post_meta call the listener dispatches via
		// ArchiveMeta::from_post()->save(). The map of key → value is the
		// behavioral assertion (vs. an opaque `expectAction` check).
		$writes = array();
		\WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$writes ) {
					$writes[] = array( $post_id, $key, $value );
					return true;
				}
			);

		$result = aps_archive_post( 555 );

		$this->assertEquals( $post, $result, 'aps_archive_post returns the pre-update WP_Post' );

		// The five meta keys ArchiveMeta declares as its public contract.
		$by_key = array();
		foreach ( $writes as $write ) {
			$this->assertSame( 555, $write[0], 'meta writes must target the archived post id' );
			$by_key[ $write[1] ] = $write[2];
		}

		$this->assertArrayHasKey( ArchiveMeta::META_PREVIOUS_STATUS, $by_key );
		$this->assertArrayHasKey( ArchiveMeta::META_ARCHIVE_DATE, $by_key );
		$this->assertArrayHasKey( ArchiveMeta::META_ARCHIVE_USER, $by_key );
		$this->assertArrayHasKey( ArchiveMeta::META_COMMENT_STATUS, $by_key );
		$this->assertArrayHasKey( ArchiveMeta::META_PING_STATUS, $by_key );

		// Spot-check the snapshot — these are the load-bearing values the
		// listener captures *from the original post* before wp_update_post
		// changed them. A regression where the listener reads them after
		// the update would show 'archive' / 'closed' / 'closed' here instead.
		$this->assertSame( 'publish', $by_key[ ArchiveMeta::META_PREVIOUS_STATUS ] );
		$this->assertSame( 'open', $by_key[ ArchiveMeta::META_COMMENT_STATUS ] );
		$this->assertSame( 'closed', $by_key[ ArchiveMeta::META_PING_STATUS ] );
		$this->assertIsInt( $by_key[ ArchiveMeta::META_ARCHIVE_DATE ] );
		$this->assertGreaterThan( 0, $by_key[ ArchiveMeta::META_ARCHIVE_DATE ] );
	}
}
