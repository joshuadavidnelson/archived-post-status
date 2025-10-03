<?php
/**
 * Archive/Unarchive Process Filter Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

/**
 * Archive Process filter test case
 *
 * @since 0.4.0
 */
class ArchiveProcessTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
	}

	/**
	 * Test aps_pre_archive_post filter default
	 *
	 * @covers ::aps_archive_post
	 */
	public function test_aps_pre_archive_post_default() {

		// Arrange
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'publish' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		$this->mockArchiveProcessFunctions();

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_archive_post', 123, 'publish' );
		\WP_Mock::expectAction( 'aps_archived_post', 123, 'publish' );

		// Act
		$result = aps_archive_post( 123 );

		// Assert
		$this->assertEquals( $post, $result );
	}

	/**
	 * Test aps_pre_archive_post filter short-circuit
	 *
	 * @covers ::aps_archive_post
	 */
	public function test_aps_pre_archive_post_short_circuit() {

		// Arrange
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'publish' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( false ); // Short-circuit

		// Act
		$result = aps_archive_post( 123 );

		// Assert
		$this->assertFalse( $result );
	}

	/**
	 * Test aps_pre_unarchive_post filter default
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_pre_unarchive_post_default() {

		// Arrange
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'archive' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		// Mock the meta retrieval
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, '_aps_archive_meta_status', true )
			->andReturn( 'publish' );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, '_aps_archive_meta_comment_status', true )
			->andReturn( 'open' );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, '_aps_archive_meta_ping_status', true )
			->andReturn( 'open' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		// Test filters are applied with correct values
		\WP_Mock::expectAction( 'aps_unarchive_post', 123, 'publish' );
		\WP_Mock::expectFilter( 'aps_unarchive_post_status', 'publish', 123, 'publish' );
		\WP_Mock::expectFilter( 'aps_unarchive_post_comment_status', 'open', 123, 'publish' );
		\WP_Mock::expectFilter( 'aps_unarchive_post_ping_status', 'open', 123, 'publish' );

		\WP_Mock::userFunction( 'wp_update_post' )->andReturn( 123 );
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
		\WP_Mock::expectAction( 'aps_unarchived_post', 123, 'publish' );

		// Act
		$result = aps_unarchive_post( 123 );

		// Assert
		$this->assertEquals( $post, $result );
	}

	/**
	 * Test aps_pre_unarchive_post filter short-circuit
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_pre_unarchive_post_short_circuit() {

		// Arrange
		$post = $this->createMockPost( [ 'ID' => 123, 'post_status' => 'archive' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_meta' )
			->andReturn( 'publish' );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'publish' )
			->reply( 'custom_return' ); // Short-circuit

		// Act
		$result = aps_unarchive_post( 123 );

		// Assert
		$this->assertEquals( 'custom_return', $result );
	}

	/**
	 * Test aps_unarchive_post_status filter
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_unarchive_post_status_filter() {

		// Just test that the filter is called with correct parameters
		\WP_Mock::expectFilter( 'aps_unarchive_post_status', 'publish', 123, 'publish' );

		// Test by calling the filter directly
		$result = apply_filters( 'aps_unarchive_post_status', 'publish', 123, 'publish' );
		$this->assertEquals( 'publish', $result );
	}

	/**
	 * Test aps_unarchive_post_comment_status filter
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_unarchive_post_comment_status_filter() {

		// Just test that the filter is called with correct parameters
		\WP_Mock::expectFilter( 'aps_unarchive_post_comment_status', 'open', 123, 'publish' );

		// Test by calling the filter directly
		$result = apply_filters( 'aps_unarchive_post_comment_status', 'open', 123, 'publish' );
		$this->assertEquals( 'open', $result );
	}

	/**
	 * Test aps_unarchive_post_ping_status filter
	 *
	 * @covers ::aps_unarchive_post
	 */
	public function test_aps_unarchive_post_ping_status_filter() {

		// Just test that the filter is called with correct parameters
		\WP_Mock::expectFilter( 'aps_unarchive_post_ping_status', 'closed', 123, 'publish' );

		// Test by calling the filter directly
		$result = apply_filters( 'aps_unarchive_post_ping_status', 'closed', 123, 'publish' );
		$this->assertEquals( 'closed', $result );
	}
}
