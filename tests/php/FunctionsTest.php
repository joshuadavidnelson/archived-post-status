<?php
/**
 * Facade-contract smoke test for the `aps_*` public global functions.
 *
 * A thin smoke test (mechanics live in the per-class suites) that
 * confirms, for every
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
	 * `src/functions/deprecated.php`. It self-deprecates via
	 * `_deprecated_function()` and keeps its exact pre-0.4.0 semantics:
	 * membership in the filterable `aps_excluded_post_types` list — NOT
	 * the negation of `aps_is_supported_post_type()`, which would wrongly
	 * report non-public post types as excluded.
	 *
	 * @covers ::aps_is_excluded_post_type
	 */
	public function test_aps_is_excluded_post_type_keeps_its_pre_040_membership_semantics() {
		$this->assertTrue( function_exists( 'aps_is_excluded_post_type' ) );
		$this->assertTrue( is_callable( 'aps_is_excluded_post_type' ) );

		\WP_Mock::userFunction( '_deprecated_function' )
			->with( 'aps_is_excluded_post_type', '0.4.0', 'aps_is_supported_post_type' )
			->times( 2 );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->never();

		$this->assertTrue( aps_is_excluded_post_type( 'attachment' ) );

		// A non-public CPT outside the excluded list is NOT excluded, even
		// though aps_is_supported_post_type() would report it unsupported.
		$this->assertFalse( aps_is_excluded_post_type( 'internal_notes' ) );
	}
}
