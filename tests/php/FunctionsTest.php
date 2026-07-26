<?php
/**
 * Facade-contract smoke test for the `aps_*` public global functions.
 *
 * Phase 3C of the 0.4.0 cleanup trimmed this file from a full
 * mechanics-exerciser into a thin smoke test that confirms, for every
 * `aps_*` global facade in `src/functions/functions.php` and
 * `src/functions/deprecated.php`:
 *
 *   1. `function_exists()` returns true (the require_once chain in
 *      {@see \ArchivedPostStatus\Loader::init()} successfully loaded both
 *      files).
 *   2. The symbol is `is_callable()` (no broken signature, no fatal at
 *      definition time).
 *   3. The facade routes through to the lifted static class — verified by
 *      injecting a sentinel at the WP boundary (`apply_filters`,
 *      `current_user_can`, or `get_post`) the delegate's body consults,
 *      and asserting the sentinel is what comes back to the caller.
 *
 * The full mechanics tests now live with the lifted classes:
 *
 *   - {@see \ArchivedPostStatus\Status\ArchiveLabel}        → ArchiveLabelTest
 *   - {@see \ArchivedPostStatus\Status\SupportedPostTypes}  → SupportedPostTypesTest
 *   - {@see \ArchivedPostStatus\Archive\ViewCapability}     → ViewCapabilityTest
 *   - {@see \ArchivedPostStatus\Archive\ReadOnlyPolicy}     → ReadOnlyPolicyTest
 *   - {@see \ArchivedPostStatus\Archive\ArchiveCapability}  → ArchiveCapabilityTest
 *   - {@see \ArchivedPostStatus\Archive\ArchivableStatuses} → ArchivableStatusesTest
 *   - {@see \ArchivedPostStatus\Archive\ArchiveOperation}   → ArchiveOperationTest
 *   - {@see \ArchivedPostStatus\Archive\UnarchiveOperation} → UnarchiveOperationTest
 *   - {@see \ArchivedPostStatus\Admin\ArchivePostLink}      → ArchivePostLinkTest
 *   - {@see \ArchivedPostStatus\Frontend\ArchivedPostLink}  → ArchivedPostLinkTest
 *
 * This file catches "did someone break a delegate signature" and "is this
 * facade still exposed at the documented global name" — nothing more.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

/**
 * Functions test case.
 *
 * @since 0.3.9
 */
class FunctionsTest extends TestCase {

	/**
	 * `aps_archived_label_string()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Status\ArchiveLabel::value()} — verified by
	 * injecting a sentinel on `aps_archived_label_string` (the filter
	 * `ArchiveLabel::value()` applies) and asserting the sentinel comes
	 * back through the facade.
	 *
	 * @covers ::aps_archived_label_string
	 */
	public function test_aps_archived_label_string_facade_delegates_to_archive_label() {
		$this->assertTrue( function_exists( 'aps_archived_label_string' ) );
		$this->assertTrue( is_callable( 'aps_archived_label_string' ) );

		\WP_Mock::userFunction( '__', array( 'return' => 'Archived' ) );
		\WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( 'SENTINEL-LABEL' );

		$this->assertSame( 'SENTINEL-LABEL', aps_archived_label_string() );
	}

	/**
	 * `aps_get_supported_post_types()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Status\SupportedPostTypes::all()} —
	 * verified by stubbing `get_post_types` and asserting the
	 * `aps_supported_post_types` filter result flows back to the caller.
	 *
	 * @covers ::aps_get_supported_post_types
	 */
	public function test_aps_get_supported_post_types_facade_delegates_to_supported_post_types() {
		$this->assertTrue( function_exists( 'aps_get_supported_post_types' ) );
		$this->assertTrue( is_callable( 'aps_get_supported_post_types' ) );

		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post', 'page' => 'page' ) )
			->reply( array( 'SENTINEL-TYPE' ) );

		$this->assertSame( array( 'SENTINEL-TYPE' ), aps_get_supported_post_types() );
	}

	/**
	 * `aps_is_supported_post_type()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Status\SupportedPostTypes::includes()} —
	 * verified by stubbing the underlying supported-list resolution and
	 * asserting both true/false branches flow back through the facade.
	 *
	 * @covers ::aps_is_supported_post_type
	 */
	public function test_aps_is_supported_post_type_facade_delegates_to_supported_post_types() {
		$this->assertTrue( function_exists( 'aps_is_supported_post_type' ) );
		$this->assertTrue( is_callable( 'aps_is_supported_post_type' ) );

		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array() );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( \Mockery::type( 'array' ) )
			->reply( array( 'post' ) );

		$this->assertTrue( aps_is_supported_post_type( 'post' ) );
		$this->assertFalse( aps_is_supported_post_type( 'attachment' ) );
	}

	/**
	 * `aps_current_user_can_view()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Archive\ViewCapability::granted()} —
	 * verified by stubbing `current_user_can` and asserting its return flows
	 * back through the facade.
	 *
	 * @covers ::aps_current_user_can_view
	 */
	public function test_aps_current_user_can_view_facade_delegates_to_view_capability() {
		$this->assertTrue( function_exists( 'aps_current_user_can_view' ) );
		$this->assertTrue( is_callable( 'aps_current_user_can_view' ) );

		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$this->assertTrue( aps_current_user_can_view() );
	}

	/**
	 * `aps_is_read_only()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Archive\ReadOnlyPolicy::enabled()} —
	 * verified by injecting a sentinel boolean on the `aps_is_read_only`
	 * filter and asserting it round-trips through the facade.
	 *
	 * @covers ::aps_is_read_only
	 */
	public function test_aps_is_read_only_facade_delegates_to_read_only_policy() {
		$this->assertTrue( function_exists( 'aps_is_read_only' ) );
		$this->assertTrue( is_callable( 'aps_is_read_only' ) );

		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( false );

		$this->assertFalse( aps_is_read_only() );
	}

	/**
	 * `aps_current_user_can_archive()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Archive\ArchiveCapability::can_archive()} —
	 * verified by stubbing `current_user_can` and asserting its return flows
	 * back through the facade.
	 *
	 * @covers ::aps_current_user_can_archive
	 */
	public function test_aps_current_user_can_archive_facade_delegates_to_archive_capability() {
		$this->assertTrue( function_exists( 'aps_current_user_can_archive' ) );
		$this->assertTrue( is_callable( 'aps_current_user_can_archive' ) );

		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$this->assertTrue( aps_current_user_can_archive() );
	}

	/**
	 * `aps_current_user_can_unarchive()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Archive\ArchiveCapability::can_unarchive()} —
	 * verified by stubbing `current_user_can` and asserting its return flows
	 * back through the facade.
	 *
	 * @covers ::aps_current_user_can_unarchive
	 */
	public function test_aps_current_user_can_unarchive_facade_delegates_to_archive_capability() {
		$this->assertTrue( function_exists( 'aps_current_user_can_unarchive' ) );
		$this->assertTrue( is_callable( 'aps_current_user_can_unarchive' ) );

		\WP_Mock::userFunction( 'current_user_can' )->andReturn( false );

		$this->assertFalse( aps_current_user_can_unarchive() );
	}

	/**
	 * `aps_current_user_can_edit()` is exposed and applies the
	 * `aps_default_edit_capability` filter before delegating to
	 * `current_user_can` — verified by sentinel-filter + cap capture.
	 *
	 * Unlike the other capability helpers this one lives inline in
	 * `src/functions/functions.php` (no lifted class) — it's still a public
	 * facade though, so the smoke test exercises the same shape.
	 *
	 * @covers ::aps_current_user_can_edit
	 */
	public function test_aps_current_user_can_edit_facade_routes_through_default_edit_capability_filter() {
		$this->assertTrue( function_exists( 'aps_current_user_can_edit' ) );
		$this->assertTrue( is_callable( 'aps_current_user_can_edit' ) );

		$received_capability = null;

		\WP_Mock::userFunction(
			'current_user_can', array(
				'return' => function ( $capability, ...$args ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::onFilter( 'aps_default_edit_capability' )
			->with( 'edit_post', 7 )
			->reply( 'SENTINEL-CAP' );

		$this->assertTrue( aps_current_user_can_edit( 7 ) );
		$this->assertSame( 'SENTINEL-CAP', $received_capability );
	}

	/**
	 * `aps_get_archive_post_link()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Admin\ArchivePostLink::build()} — verified
	 * by exercising the "post does not exist" short-circuit which returns
	 * `false` directly without further WP calls.
	 *
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_archive_post_link_facade_delegates_to_archive_post_link() {
		$this->assertTrue( function_exists( 'aps_get_archive_post_link' ) );
		$this->assertTrue( is_callable( 'aps_get_archive_post_link' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 999 )->andReturn( null );

		$this->assertFalse( aps_get_archive_post_link( 999 ) );
	}

	/**
	 * `aps_get_unarchive_post_link()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Admin\ArchivePostLink::build()} — verified
	 * by the same "post does not exist" short-circuit.
	 *
	 * @covers ::aps_get_unarchive_post_link
	 */
	public function test_aps_get_unarchive_post_link_facade_delegates_to_archive_post_link() {
		$this->assertTrue( function_exists( 'aps_get_unarchive_post_link' ) );
		$this->assertTrue( is_callable( 'aps_get_unarchive_post_link' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 999 )->andReturn( null );

		$this->assertFalse( aps_get_unarchive_post_link( 999 ) );
	}

	/**
	 * `aps_get_archived_post_link()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Frontend\ArchivedPostLink::build()} —
	 * verified by the "no post" short-circuit.
	 *
	 * @covers ::aps_get_archived_post_link
	 */
	public function test_aps_get_archived_post_link_facade_delegates_to_archived_post_link() {
		$this->assertTrue( function_exists( 'aps_get_archived_post_link' ) );
		$this->assertTrue( is_callable( 'aps_get_archived_post_link' ) );

		\WP_Mock::userFunction( 'get_post' )->andReturn( null );

		$this->assertFalse( aps_get_archived_post_link( 999 ) );
	}

	/**
	 * `aps_archive_post()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Archive\ArchiveOperation::perform()} —
	 * verified by the "post does not exist" short-circuit which returns
	 * `false`.
	 *
	 * @covers ::aps_archive_post
	 */
	public function test_aps_archive_post_facade_delegates_to_archive_operation() {
		$this->assertTrue( function_exists( 'aps_archive_post' ) );
		$this->assertTrue( is_callable( 'aps_archive_post' ) );

		\WP_Mock::userFunction( 'get_post' )->andReturn( null );

		$this->assertFalse( aps_archive_post( 999 ) );
	}

	/**
	 * `aps_unarchive_post()` is exposed and routes through
	 * {@see \ArchivedPostStatus\Archive\UnarchiveOperation::perform()} —
	 * verified by the "post does not exist" short-circuit.
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_unarchive_post_facade_delegates_to_unarchive_operation() {
		$this->assertTrue( function_exists( 'aps_unarchive_post' ) );
		$this->assertTrue( is_callable( 'aps_unarchive_post' ) );

		\WP_Mock::userFunction( 'get_post' )->andReturn( null );

		$this->assertFalse( aps_unarchive_post( 999 ) );
	}

	/**
	 * `aps_unarchive_post_set_previous_status()` is exposed and routes
	 * through {@see \ArchivedPostStatus\Archive\UnarchiveOperation::set_previous_status()} —
	 * verified by passing a sentinel through and asserting it returns
	 * verbatim (the helper's contract is to echo the third argument).
	 *
	 * @covers ::aps_unarchive_post_set_previous_status
	 */
	public function test_aps_unarchive_post_set_previous_status_facade_delegates_to_unarchive_operation() {
		$this->assertTrue( function_exists( 'aps_unarchive_post_set_previous_status' ) );
		$this->assertTrue( is_callable( 'aps_unarchive_post_set_previous_status' ) );

		$result = aps_unarchive_post_set_previous_status( 'draft', 7, 'SENTINEL-PREVIOUS' );

		$this->assertSame( 'SENTINEL-PREVIOUS', $result );
	}

	/**
	 * `aps_is_excluded_post_type()` is the lone resident of
	 * `src/functions/deprecated.php` (Phase 3C). It self-deprecates via
	 * `_deprecated_function()` and returns the boolean negation of
	 * `aps_is_supported_post_type`. The smoke test confirms it's exposed,
	 * the deprecation notice fires, and the delegation produces the
	 * inverted boolean.
	 *
	 * @covers ::aps_is_excluded_post_type
	 */
	public function test_aps_is_excluded_post_type_deprecated_facade_inverts_supported_post_type() {
		$this->assertTrue( function_exists( 'aps_is_excluded_post_type' ) );
		$this->assertTrue( is_callable( 'aps_is_excluded_post_type' ) );

		\WP_Mock::userFunction( '_deprecated_function' )
			->with( 'aps_is_excluded_post_type', '0.4.0', 'aps_is_supported_post_type' )
			->once();
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'attachment' )
			->andReturn( false );

		$this->assertTrue( aps_is_excluded_post_type( 'attachment' ) );
	}

	/**
	 * Test aps_load_post_screen() blocks editing an Archived post.
	 *
	 * Regression test: the post ID has to come from the request, because
	 * get_query_var() is never populated on load-post.php.
	 *
	 * @since 0.3.13
	 */
	public function test_aps_load_post_screen_blocks_edit() {

		$mock_post = $this->mock_post;

		$_GET = array(
			'post'   => (string) $mock_post->ID,
			'action' => 'edit',
		);

		// On load-post.php the query vars are empty; the handler must not rely on them.
		\WP_Mock::userFunction(
			'get_query_var', array(
				'return' => '',
			)
		);

		\WP_Mock::userFunction(
			'get_post', array(
				'times'  => 1,
				'args'   => array( $mock_post->ID ),
				'return' => $mock_post,
			)
		);

		\WP_Mock::userFunction(
			'sanitize_key', array(
				'return_arg' => 0,
			)
		);

		\WP_Mock::userFunction(
			'esc_html', array(
				'return_arg' => 0,
			)
		);

		// The save redirect must not fire on a plain edit request.
		\WP_Mock::userFunction(
			'wp_safe_redirect', array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_die', array(
				'times'  => 1,
				'return' => function() {
					throw new \RuntimeException( 'wp_die' );
				},
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );

		aps_load_post_screen();

	}

	/**
	 * Test aps_load_post_screen() redirects to the list table after saving as Archived.
	 *
	 * @since 0.3.13
	 */
	public function test_aps_load_post_screen_redirects_after_save() {

		$mock_post = $this->mock_post;
		$edit_url  = 'https://example.org/wp-admin/edit.php';

		$_GET = array(
			'post'    => (string) $mock_post->ID,
			'action'  => 'edit',
			'message' => '1',
		);

		\WP_Mock::userFunction(
			'get_post', array(
				'times'  => 1,
				'args'   => array( $mock_post->ID ),
				'return' => $mock_post,
			)
		);

		\WP_Mock::userFunction(
			'sanitize_key', array(
				'return_arg' => 0,
			)
		);

		\WP_Mock::userFunction(
			'self_admin_url', array(
				'times'  => 1,
				'args'   => array( 'edit.php' ),
				'return' => $edit_url,
			)
		);

		\WP_Mock::userFunction(
			'add_query_arg', array(
				'times'  => 1,
				'args'   => array( 'post_type', $mock_post->post_type, $edit_url ),
				'return' => $edit_url . '?post_type=post',
			)
		);

		// Throw to stop before the exit that follows wp_safe_redirect().
		\WP_Mock::userFunction(
			'wp_safe_redirect', array(
				'times'  => 1,
				'args'   => array( $edit_url . '?post_type=post', 302 ),
				'return' => function() {
					throw new \RuntimeException( 'redirected' );
				},
			)
		);

		\WP_Mock::userFunction(
			'wp_die', array(
				'times' => 0,
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirected' );

		aps_load_post_screen();

	}

	/**
	 * Test aps_load_post_screen() lets non-editor actions like trash through.
	 *
	 * @since 0.3.13
	 */
	public function test_aps_load_post_screen_allows_trash() {

		$mock_post = $this->mock_post;

		$_GET = array(
			'post'   => (string) $mock_post->ID,
			'action' => 'trash',
		);

		\WP_Mock::userFunction(
			'get_post', array(
				'times'  => 1,
				'args'   => array( $mock_post->ID ),
				'return' => $mock_post,
			)
		);

		\WP_Mock::userFunction(
			'sanitize_key', array(
				'return_arg' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_safe_redirect', array(
				'times' => 0,
			)
		);

		\WP_Mock::userFunction(
			'wp_die', array(
				'times' => 0,
			)
		);

		$this->assertNull( aps_load_post_screen() );

	}
}
