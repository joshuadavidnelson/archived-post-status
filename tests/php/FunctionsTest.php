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
	 * Mock post object.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $mock_post;

	/**
	 * Set up the test.
	 *
	 * @since 0.3.9
	 */
	public function setUp(): void {
		parent::setUp();

		\WP_Mock::userFunction(
			'__', array(
				'return' => 'Archived',
			)
		);

		// Mock WP post object.
		$this->mock_post = \Mockery::mock( 'WP_Post' );
		$this->mock_post->post_title = 'Test Post';
		$this->mock_post->post_status = 'archive';
		$this->mock_post->post_type = 'post';
		$this->mock_post->comment_status = 'open';
		$this->mock_post->ping_status    = 'open';
		$this->mock_post->ID = 86;

	}

	/**
	 * Test archived label string function returns expected default
	 *
	 * @covers aps_archived_label_string
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
	 * @covers aps_archived_label_string
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
	 * @covers aps_get_supported_post_types
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
	 * @covers aps_is_supported_post_type
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
	 * Test the aps_current_user_can_view() filter.
	 *
	 * @since 0.3.9
	 * @covers aps_current_user_can_view
	 */
	public function test_aps_current_user_can_view_filter() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'read_private_posts';
				},
			)
		);

		// Use the filter to change the default capability.
		WP_Mock::onFilter( 'aps_default_read_capability' )
			->with( 'read_private_posts', 0 )
			->reply( 'read' );

		// Confirm the filter is applied.
		$this->assertFalse( aps_current_user_can_view() );

	}

	/**
	 * Test archive capability check with default permissions
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_archive
	 */
	public function test_current_user_can_archive_with_default_capability() {

		// Arrange - Mock user has edit_others_posts capability
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_others_posts', 0 );

		// Act & Assert - User with proper capability can archive
		$this->assertTrue( aps_current_user_can_archive() );
	}

	/**
	 * Test archive capability check with custom capability filter
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_archive
	 */
	public function test_current_user_can_archive_with_custom_capability_filter() {

		// Arrange - User only has basic read capability
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'read'; // Only has read, not edit_others_posts
				},
			)
		);

		// Filter changes required capability to 'read'
		WP_Mock::onFilter( 'aps_default_archive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'read' );

		// Act & Assert - User can now archive with reduced capability
		$this->assertTrue( aps_current_user_can_archive() );
	}

	/**
	 * Test unarchive capability check with default permissions
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_unarchive
	 */
	public function test_current_user_can_unarchive_with_default_capability() {

		// Arrange - Mock user has edit_others_posts capability
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_user_unarchive_capability', 'edit_others_posts', 0 );

		// Act & Assert - User with proper capability can unarchive
		$this->assertTrue( aps_current_user_can_unarchive() );
	}

	/**
	 * Test unarchive capability check with custom capability filter
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_unarchive
	 */
	public function test_current_user_can_unarchive_with_custom_capability_filter() {

		// Arrange - User only has basic read capability
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'read'; // Only has read, not edit_others_posts
				},
			)
		);

		// Filter changes required capability to 'read'
		WP_Mock::onFilter( 'aps_user_unarchive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'read' );

		// Act & Assert - User can now unarchive with reduced capability
		$this->assertTrue( aps_current_user_can_unarchive() );
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
	 * Test read-only mode can be disabled via filter
	 *
	 * @since 0.3.9
	 * @covers aps_is_read_only
	 */
	public function test_read_only_mode_can_be_disabled_via_filter() {

		// Arrange - Filter disables read-only mode
		WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( false );

		// Act & Assert - Read-only mode should be disabled
		$this->assertFalse( aps_is_read_only() );
	}

	/**
	 * Test the legacy aps_excluded_post_types filter still works via deprecated function
	 *
	 * @since 0.3.9
	 * @covers aps_is_excluded_post_type
	 */
	public function test_aps_excluded_post_types_filter() {

		// Mock the deprecated function notice
		\WP_Mock::userFunction( '_deprecated_function' )
			->with( 'aps_is_excluded_post_type', '0.4.0', 'aps_is_supported_post_type' )
			->twice();

		// Mock aps_is_supported_post_type to return expected values
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'attachment' )
			->andReturn( true );  // attachment is now supported

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( false ); // post is now excluded via filter

		// Act & Assert - deprecated function should return opposite of aps_is_supported_post_type
		$this->assertFalse( aps_is_excluded_post_type( 'attachment' ) ); // supported = not excluded
		$this->assertTrue( aps_is_excluded_post_type( 'post' ) ); // not supported = excluded
	}



	/**
	 * Test nonce key generation
	 *
	 * @covers _aps_nonce_key
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
	 * @covers aps_register_archive_post_status
	 * @covers aps_archived_label_string
	 * @covers aps_current_user_can_view
	 * @covers aps_get_supported_post_types
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
	 * Test supported post types returns expected defaults
	 *
	 * @covers aps_get_supported_post_types
	 */
	public function test_get_supported_post_types_returns_defaults() {

		// Arrange
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( [ 'post', 'page', 'attachment' ] );

		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );

		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( function( $value ) { return $value; } );

		// Default behavior excludes only attachments
		\WP_Mock::expectFilter( 'aps_excluded_post_types', [ 'attachment' ] );
		\WP_Mock::expectFilter( 'aps_supported_post_types', [ 'post', 'page' ] );

		// Act
		$result = aps_get_supported_post_types();

		// Assert - Should support standard content types but exclude attachments
		$this->assertEquals( [ 'post', 'page' ], $result );
		$this->assertNotContains( 'attachment', $result );
	}

	/**
	 * Test excluded post types filter allows custom exclusions
	 *
	 * @covers aps_get_supported_post_types
	 */
	public function test_excluded_post_types_filter_allows_custom_exclusions() {

		// Arrange
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( [ 'post', 'page', 'attachment', 'product' ] );

		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );

		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( function( $value ) { return $value; } );

		// Filter adds custom exclusion for product post type
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( [ 'attachment' ] )
			->reply( [ 'attachment', 'product' ] );

		\WP_Mock::expectFilter( 'aps_supported_post_types', [ 'post', 'page' ] );

		// Act
		$result = aps_get_supported_post_types();

		// Assert - Should exclude both attachment and product
		$this->assertEquals( [ 'post', 'page' ], $result );
		$this->assertNotContains( 'attachment', $result );
		$this->assertNotContains( 'product', $result );
	}

	/**
	 * Test supported post types filter allows adding custom types
	 *
	 * @covers aps_get_supported_post_types
	 */
	public function test_supported_post_types_filter_allows_adding_custom_types() {

		// Arrange
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( [ 'post', 'page', 'attachment' ] );

		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );

		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( function( $value ) { return $value; } );

		\WP_Mock::expectFilter( 'aps_excluded_post_types', [ 'attachment' ] );

		// Filter adds custom post type to supported list
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( [ 'post', 'page' ] )
			->reply( [ 'post', 'page', 'product' ] );

		// Act
		$result = aps_get_supported_post_types();

		// Assert - Should include custom product post type
		$this->assertEquals( [ 'post', 'page', 'product' ], $result );
		$this->assertContains( 'product', $result );
	}





	/**
	 * Test aps_archivable_statuses filter default
	 *
	 * @covers _aps_get_archivable_statuses
	 */
	public function test_aps_archivable_statuses_default() {

		// Arrange
		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( function( $value ) { return $value; } );

		\WP_Mock::expectFilter( 'aps_archivable_statuses', [ 'publish', 'future', 'draft', 'pending', 'private' ] );

		// Act
		$result = _aps_get_archivable_statuses();

		// Assert
		$this->assertEquals( [ 'publish', 'future', 'draft', 'pending', 'private' ], $result );
	}

	/**
	 * Test aps_archivable_statuses filter custom
	 *
	 * @covers _aps_get_archivable_statuses
	 */
	public function test_aps_archivable_statuses_custom() {

		// Arrange
		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( function( $value ) { return $value; } );

		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( [ 'publish', 'future', 'draft', 'pending', 'private' ] )
			->reply( [ 'publish', 'custom_status' ] );

		// Act
		$result = _aps_get_archivable_statuses();

		// Assert
		$this->assertEquals( [ 'publish', 'custom_status' ], $result );
	}



	/**
	 * Test deprecated function aps_is_excluded_post_type
	 *
	 * @covers aps_is_excluded_post_type
	 */
	public function test_aps_is_excluded_post_type_deprecated() {

		// Arrange
		\WP_Mock::userFunction( '_deprecated_function' )
			->with( 'aps_is_excluded_post_type', '0.4.0', 'aps_is_supported_post_type' )
			->once();

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );

		// Act
		$result = aps_is_excluded_post_type( 'post' );

		// Assert
		$this->assertFalse( $result ); // Should return opposite of aps_is_supported_post_type
	}

	/**
	 * Test aps_status_arg_public filter default behavior on frontend with viewing capability
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_aps_status_arg_public_frontend_with_capability() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Expect true when frontend user can view archived content
		\WP_Mock::expectFilter( 'aps_status_arg_public', true );
		\WP_Mock::expectFilter( 'aps_status_arg_private', true );
		\WP_Mock::expectFilter( 'aps_status_arg_protected', false );
		\WP_Mock::expectFilter( 'aps_status_arg_exclude_from_search', true );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_all_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_status_list', true );
		\WP_Mock::expectFilter( 'aps_status_arg_dashicon', 'dashicons-archive' );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies expectations
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test aps_status_arg_public filter default behavior on frontend without viewing capability
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_aps_status_arg_public_frontend_without_capability() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Expect false when frontend user cannot view archived content
		\WP_Mock::expectFilter( 'aps_status_arg_public', false );
		\WP_Mock::expectFilter( 'aps_status_arg_private', true );
		\WP_Mock::expectFilter( 'aps_status_arg_protected', false );
		\WP_Mock::expectFilter( 'aps_status_arg_exclude_from_search', true );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_all_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_status_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_dashicon', 'dashicons-archive' );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies expectations
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test aps_status_arg_private filter default behavior in admin
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_aps_status_arg_private_admin() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Expect false private when in admin
		\WP_Mock::expectFilter( 'aps_status_arg_public', false );
		\WP_Mock::expectFilter( 'aps_status_arg_private', false );
		\WP_Mock::expectFilter( 'aps_status_arg_protected', false );
		\WP_Mock::expectFilter( 'aps_status_arg_exclude_from_search', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_all_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_status_list', true );
		\WP_Mock::expectFilter( 'aps_status_arg_dashicon', 'dashicons-archive' );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies expectations
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test aps_status_arg_protected filter always defaults to false
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_aps_status_arg_protected_default() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Protected should always default to false
		\WP_Mock::expectFilter( 'aps_status_arg_protected', false );
		\WP_Mock::expectFilter( 'aps_status_arg_public', true );
		\WP_Mock::expectFilter( 'aps_status_arg_private', true );
		\WP_Mock::expectFilter( 'aps_status_arg_exclude_from_search', true );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_all_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_status_list', true );
		\WP_Mock::expectFilter( 'aps_status_arg_dashicon', 'dashicons-archive' );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies expectations
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test aps_status_arg_exclude_from_search filter behavior in admin with capability
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_aps_status_arg_exclude_from_search_admin_with_capability() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Should not exclude from search when admin user can view
		\WP_Mock::expectFilter( 'aps_status_arg_exclude_from_search', false );
		\WP_Mock::expectFilter( 'aps_status_arg_public', false );
		\WP_Mock::expectFilter( 'aps_status_arg_private', false );
		\WP_Mock::expectFilter( 'aps_status_arg_protected', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_all_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_status_list', true );
		\WP_Mock::expectFilter( 'aps_status_arg_dashicon', 'dashicons-archive' );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies expectations
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test aps_status_arg_show_in_admin_all_list filter always defaults to false
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_aps_status_arg_show_in_admin_all_list_default() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Should always default to false
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_all_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_public', false );
		\WP_Mock::expectFilter( 'aps_status_arg_private', false );
		\WP_Mock::expectFilter( 'aps_status_arg_protected', false );
		\WP_Mock::expectFilter( 'aps_status_arg_exclude_from_search', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_status_list', true );
		\WP_Mock::expectFilter( 'aps_status_arg_dashicon', 'dashicons-archive' );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies expectations
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test aps_status_arg_dashicon filter default value
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_aps_status_arg_dashicon_default() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Should default to dashicons-archive
		\WP_Mock::expectFilter( 'aps_status_arg_dashicon', 'dashicons-archive' );
		\WP_Mock::expectFilter( 'aps_status_arg_public', false );
		\WP_Mock::expectFilter( 'aps_status_arg_private', false );
		\WP_Mock::expectFilter( 'aps_status_arg_protected', false );
		\WP_Mock::expectFilter( 'aps_status_arg_exclude_from_search', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_all_list', false );
		\WP_Mock::expectFilter( 'aps_status_arg_show_in_admin_status_list', true );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies expectations
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test status argument filters can be modified via filters
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_status_argument_filters_can_be_modified() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Override default values via filters
		\WP_Mock::onFilter( 'aps_status_arg_public' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_private' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_protected' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_exclude_from_search' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_all_list' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_status_list' )->with( true )->reply( false );
		\WP_Mock::onFilter( 'aps_status_arg_dashicon' )->with( 'dashicons-archive' )->reply( 'dashicons-lock' );

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies filter modifications
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test status argument filters handle edge case with non-boolean values
	 *
	 * @covers aps_register_archive_post_status
	 */
	public function test_status_argument_filters_type_casting() {

		// Arrange
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )->once();

		// Test type casting with non-boolean values
		\WP_Mock::onFilter( 'aps_status_arg_public' )->with( false )->reply( 'yes' ); // Should cast to true
		\WP_Mock::onFilter( 'aps_status_arg_private' )->with( false )->reply( 0 ); // Should cast to false
		\WP_Mock::onFilter( 'aps_status_arg_protected' )->with( false )->reply( 1 ); // Should cast to true
		\WP_Mock::onFilter( 'aps_status_arg_exclude_from_search' )->with( false )->reply( '' ); // Should cast to false
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_all_list' )->with( false )->reply( 'false' ); // Should cast to true
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_status_list' )->with( true )->reply( null ); // Should cast to false
		\WP_Mock::onFilter( 'aps_status_arg_dashicon' )->with( 'dashicons-archive' )->reply( 123 ); // Should cast to string

		// Act
		aps_register_archive_post_status();

		// Assert - WP_Mock verifies filters received expected types
		$this->addToAssertionCount( 1 );
	}
}
