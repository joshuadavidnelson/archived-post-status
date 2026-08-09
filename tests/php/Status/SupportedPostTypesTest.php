<?php
/**
 * Status\SupportedPostTypes Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Status\SupportedPostTypes
 *
 * Mirrors the facade tests in FunctionsTest
 * (`test_attachment_is_excluded_from_supported_post_types_by_default`,
 * `test_excluded_post_types_filter_allows_custom_exclusions`,
 * `test_supported_post_types_filter_allows_adding_custom_types`,
 * `test_is_supported_post_type_returns_true_for_listed_types_and_false_otherwise`)
 * but exercises the lifted SUT directly.
 */

use ArchivedPostStatus\Status\SupportedPostTypes;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Status\SupportedPostTypes
 */
class SupportedPostTypesTest extends TestCase {

	/**
	 * Default-path: attachments are excluded from the supported set; the rest
	 * of the public post types remain.
	 *
	 * @covers ArchivedPostStatus\Status\SupportedPostTypes::all
	 */
	public function test_all_excludes_attachment_by_default() {
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ) );

		// The default-exclusions list is only kept if the excluded slug
		// actually exists.
		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );

		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );

		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post', 'page' => 'page' ) )
			->reply( array( 'post', 'page' ) );

		$result = SupportedPostTypes::all();

		$this->assertIsArray( $result );
		$this->assertContains( 'post', $result );
		$this->assertContains( 'page', $result );
		$this->assertNotContains( 'attachment', $result );
	}

	/**
	 * The `aps_excluded_post_types` filter accepts a custom slug — adding
	 * `'product'` removes WooCommerce products from the supported set.
	 *
	 * @covers ArchivedPostStatus\Status\SupportedPostTypes::all
	 */
	public function test_all_honours_excluded_post_types_filter_override() {
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post', 'page', 'attachment', 'product' ) );

		// Both 'attachment' and the filter-added 'product' must exist for
		// the exclusion to be kept (WooCommerce registers 'product').
		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );

		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment', 'product' ) );

		\WP_Mock::expectFilter( 'aps_supported_post_types', array( 'post', 'page' ) );

		$result = SupportedPostTypes::all();

		$this->assertEquals( array( 'post', 'page' ), $result );
		$this->assertNotContains( 'attachment', $result );
		$this->assertNotContains( 'product', $result );
	}

	/**
	 * The `aps_supported_post_types` filter can also expand the supported set
	 * — useful for sites that need an extra non-public custom type to carry
	 * the archive status.
	 *
	 * @covers ArchivedPostStatus\Status\SupportedPostTypes::all
	 */
	public function test_all_honours_supported_post_types_filter_override() {
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post', 'page', 'attachment' ) );

		// The default-exclusions list is only kept if the excluded slug
		// actually exists.
		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );

		\WP_Mock::expectFilter( 'aps_excluded_post_types', array( 'attachment' ) );

		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post', 'page' ) )
			->reply( array( 'post', 'page', 'product' ) );

		$result = SupportedPostTypes::all();

		$this->assertEquals( array( 'post', 'page', 'product' ), $result );
		$this->assertContains( 'product', $result );
	}

	/**
	 * Performance: a second `all()` call in the same request must not
	 * re-run get_post_types() or either filter — the result is memoized.
	 * The `->once()` constraint on get_post_types() is the proof: if `all()`
	 * recomputed on every call, the second invocation here would trip a
	 * Mockery "expected exactly 1 call" failure.
	 *
	 * @covers ArchivedPostStatus\Status\SupportedPostTypes::all
	 */
	public function test_all_memoizes_result_for_the_rest_of_the_request() {
		\WP_Mock::userFunction( 'get_post_types' )
			->once()
			->andReturn( array( 'post' => 'post', 'page' => 'page' ) );

		\WP_Mock::userFunction( 'post_type_exists' )->andReturn( true );

		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );

		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post', 'page' => 'page' ) )
			->reply( array( 'post', 'page' ) );

		$first  = SupportedPostTypes::all();
		$second = SupportedPostTypes::all();

		$this->assertSame( $first, $second );
	}

	/**
	 * Performance: reset() clears the memo so the next all() call
	 * recomputes from scratch. get_post_types() is constrained to `->twice()`
	 * across the two all() calls (separated by a reset()) — if reset()
	 * didn't actually clear the memo, the second all() call would return the
	 * cached value without invoking get_post_types() again, and the
	 * `->twice()` expectation would fail with only one recorded call.
	 *
	 * @covers ArchivedPostStatus\Status\SupportedPostTypes::all
	 * @covers ArchivedPostStatus\Status\SupportedPostTypes::reset
	 */
	public function test_reset_forces_all_to_recompute() {
		\WP_Mock::userFunction( 'get_post_types' )
			->twice()
			->andReturn( array( 'post' => 'post' ) );

		\WP_Mock::userFunction( 'post_type_exists' )->andReturn( true );

		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );

		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post' ) )
			->reply( array( 'post' ) );

		$first = SupportedPostTypes::all();

		SupportedPostTypes::reset();

		$second = SupportedPostTypes::all();

		$this->assertSame( array( 'post' ), $first );
		$this->assertSame( array( 'post' ), $second );
	}

	/**
	 * `includes()` is a thin convenience over `in_array(.., all(), true)`.
	 * Two true rows and one false row cover both branches.
	 *
	 * @covers ArchivedPostStatus\Status\SupportedPostTypes::includes
	 */
	public function test_includes_returns_true_for_supported_types_and_false_otherwise() {
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ) );

		// The default-exclusions list is only kept if the excluded slug
		// actually exists.
		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );

		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );

		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post', 'page' => 'page' ) )
			->reply( array( 'post', 'page' ) );

		$this->assertTrue( SupportedPostTypes::includes( 'post' ) );
		$this->assertFalse( SupportedPostTypes::includes( 'attachment' ) );
	}
}
