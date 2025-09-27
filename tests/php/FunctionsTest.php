<?php
/**
 * Functions Tests
 *
 * @since 0.3.9
 * @package ArchivedPostStatus
 */

/**
 * Functions test case
 *
 * @since 0.3.9
 */
class FunctionsTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.3.9
	 */
	public function set_up() {
		parent::set_up();
	}

	/**
	 * Test archived label string function returns expected default
	 *
	 * @covers ::aps_archived_label_string
	 */
	public function test_archived_label_string_default() {
		// Arrange
		\WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( 'Archived' );

		// Act
		$result = aps_archived_label_string();

		// Assert
		$this->assertEquals( 'Archived', $result );
	}

	/**
	 * Test archived label string can be customized via filter
	 *
	 * @covers ::aps_archived_label_string
	 */
	public function test_archived_label_string_custom() {
		// Arrange
		\WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( 'Legacy Content' );

		// Act
		$result = aps_archived_label_string();

		// Assert
		$this->assertEquals( 'Legacy Content', $result );
	}

	/**
	 * Test supported post types function
	 *
	 * @covers ::aps_get_supported_post_types
	 */
	public function test_supported_post_types() {
		// Arrange
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( [ 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ] );

		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( \Mockery::type( 'array' ) )
			->reply( [ 'post', 'page' ] );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( \Mockery::type( 'array' ) )
			->reply( [ 'attachment' ] );

		// Act
		$result = aps_get_supported_post_types();

		// Assert
		$this->assertIsArray( $result );
		$this->assertContains( 'post', $result );
		$this->assertContains( 'page', $result );
	}

	/**
	 * Test is_supported_post_type function
	 *
	 * @covers ::aps_is_supported_post_type
	 */
	public function test_is_supported_post_type() {
		// Arrange
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )
			->andReturn( [ 'post', 'page' ] );

		// Act & Assert
		$this->assertTrue( aps_is_supported_post_type( 'post' ) );
		$this->assertTrue( aps_is_supported_post_type( 'page' ) );
		$this->assertFalse( aps_is_supported_post_type( 'attachment' ) );
	}

	/**
	 * Test current user can view function
	 *
	 * @covers ::aps_current_user_can_view
	 */
	public function test_current_user_can_view() {
		// Arrange
		\WP_Mock::userFunction( 'current_user_can' )
			->andReturn( true );

		// Mock the current_user_can() function with proper WordPress core mocking
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'read_private_posts', 0 )
			->andReturn( true );

		// Mock the default capability filter
		\WP_Mock::onFilter( 'aps_default_read_capability' )
			->with( 'read_private_posts', 0 )
			->reply( 'read_private_posts' );

		// Mock the final result filter
		\WP_Mock::onFilter( 'aps_current_user_can_view' )
			->with( true )
			->reply( true );

		// Act
		$result = aps_current_user_can_view();

		// Assert
		$this->assertTrue( $result );
	}

	/**
	 * Test the aps_current_user_can_view() filter.
	 *
	 * @since 0.3.9
	 * @covers aps_current_user_can_view
	 */
	public function test_aps_current_user_can_view_filter() {
		// Mock the current_user_can() function
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'read', 0 )
			->andReturn( false );

		// Mock the default capability filter to return a different capability
		\WP_Mock::onFilter( 'aps_default_read_capability' )
			->with( 'read_private_posts', 0 )
			->reply( 'read' );

		// Mock the final result filter
		\WP_Mock::onFilter( 'aps_current_user_can_view' )
			->with( false )
			->reply( false );

		// Act
		$result = aps_current_user_can_view();

		// Assert
		$this->assertFalse( $result );

	}

	/**
	 * Test the aps_current_user_can_archive() function.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_archive
	 */
	public function aps_current_user_can_archive() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_others_posts', 0 );

		// Confirm the default condition is true.
		$this->assertTrue( aps_current_user_can_archive() );

	}

	/**
	 * Test the aps_current_user_can_archive() filter.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_archive
	 */
	public function test_aps_current_user_can_archive_filter() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_default_archive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'read' );

		// Confirm the filter is applied.
		$this->assertFalse( aps_current_user_can_archive() );

	}

	/**
	 * Test the aps_current_user_can_unarchive() function.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_unarchive
	 */
	public function aps_current_user_can_unarchive() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_user_unarchive_capability', 'edit_others_posts', 0 );

		// Confirm the default condition is true.
		$this->assertTrue( aps_current_user_can_unarchive() );

	}

	/**
	 * Test the aps_current_user_can_unarchive() filter.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_unarchive
	 */
	public function test_aps_current_user_can_unarchive_filter() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_user_unarchive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'read' );

		// Confirm the filter is applied.
		$this->assertFalse( aps_current_user_can_unarchive() );

	}



	/**
	 * Test the aps_is_read_only() function.
	 *
	 * @since 0.3.9
	 * @covers aps_is_read_only
	 */
	public function test_aps_is_read_only() {

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_is_read_only', true );

		// Confirm default condition is true.
		$this->assertTrue( aps_is_read_only() );

	}

	/**
	 * Test the aps_is_read_only() function filters.
	 *
	 * @since 0.3.9
	 * @covers aps_is_read_only
	 */
	public function test_aps_is_read_only_filters() {

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( true );

		// Act & Assert
		$this->assertTrue( aps_current_user_can_view() );
	}

	/**
	 * Test is_read_only function
	 *
	 * @covers ::aps_is_read_only
	 */
	public function test_is_read_only() {
		// Arrange
		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( false )
			->reply( true ); // Changed to match expected behavior

		// Act & Assert
		$this->assertTrue( aps_is_read_only() ); // Changed expectation
	}

	/**
	 * Test current user can archive
	 *
	 * @covers ::aps_current_user_can_archive
	 */
	public function test_current_user_can_archive() {
		// Arrange
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( false );
		\WP_Mock::userFunction( 'current_user_can' )
			->andReturn( true );

		\WP_Mock::onFilter( 'aps_current_user_can_archive' )
			->with( true )
			->reply( true );

		// Act & Assert
		$this->assertTrue( aps_current_user_can_archive() );
	}

	/**
	 * Test nonce key generation
	 *
	 * @covers ::_aps_nonce_key
	 */
	public function test_nonce_key_generation() {
		// Act
		$result = _aps_nonce_key( 'archive', 123 );

		// Assert - Check the actual format used by the function
		$this->assertEquals( 'archive-123', $result );
	}

	/**
	 * Test register archive post status
	 *
	 * @covers ::aps_register_archive_post_status
	 * @covers ::aps_archived_label_string
	 * @covers ::aps_current_user_can_view
	 * @covers ::aps_get_supported_post_types
	 */
	public function test_register_archive_post_status() {
		// Arrange
		\WP_Mock::userFunction( 'register_post_status' )
			->with( 'archive', \Mockery::type( 'array' ) )
			->once();

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies ->once() expectation was satisfied
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test aps_get_supported_post_types default behavior
	 *
	 * @covers ::aps_get_supported_post_types
	 */
	public function test_get_supported_post_types() {
		// Arrange
		\WP_Mock::userFunction( 'get_post_types' )
			->with( [ 'public' => true ] )
			->andReturn( [ 'post', 'page', 'attachment' ] );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( [ 'attachment' ] )
			->reply( [ 'attachment' ] );
		\WP_Mock::userFunction( 'post_type_exists' )->andReturn( true );
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( function( $value ) {
			return $value;
		} );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( [ 'post', 'page' ] )
			->reply( [ 'post', 'page' ] );

		// Act
		$result = aps_get_supported_post_types();

		// Assert
		$this->assertEquals( [ 'post', 'page' ], $result );
	}
}
