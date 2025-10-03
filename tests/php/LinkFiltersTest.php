<?php
/**
 * Link Filter Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

/**
 * Link filter test case
 *
 * @since 0.4.0
 */
class LinkFiltersTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
	}

	/**
	 * Test aps_get_archive_post_link filter
	 *
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_archive_post_link_filter() {

		// Arrange
		$post = $this->createMockPost( [ 'ID' => 123, 'post_type' => 'post' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		$this->mockLinkGenerationFunctions( [
			'action' => 'archive',
			'final_url' => 'http://example.com/wp-admin/post.php?post=123&action=edit&action=archive&_wpnonce=abc123'
		] );

		// Test the filter
		\WP_Mock::onFilter( 'aps_get_archive_post_link' )
			->with( 'http://example.com/wp-admin/post.php?post=123&action=edit&action=archive&_wpnonce=abc123', 123, 'display' )
			->reply( 'http://custom.com/archive-link' );

		// Act
		$result = aps_get_archive_post_link( 123 );

		// Assert
		$this->assertEquals( 'http://custom.com/archive-link', $result );
	}

	/**
	 * Test aps_get_unarchive_post_link filter
	 *
	 * @covers ::aps_get_archive_post_link
	 */
	public function test_aps_get_unarchive_post_link_filter() {

		// Arrange
		$post = $this->createMockPost( [ 'ID' => 123, 'post_type' => 'post' ] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		$this->mockLinkGenerationFunctions( [
			'action' => 'unarchive',
			'final_url' => 'http://example.com/wp-admin/post.php?post=123&action=edit&action=unarchive&_wpnonce=abc123'
		] );

		// Test the filter
		\WP_Mock::onFilter( 'aps_get_unarchive_post_link' )
			->with( 'http://example.com/wp-admin/post.php?post=123&action=edit&action=unarchive&_wpnonce=abc123', 123, 'display' )
			->reply( 'http://custom.com/unarchive-link' );

		// Act
		$result = aps_get_archive_post_link( 123, 'display', 'unarchive' );

		// Assert
		$this->assertEquals( 'http://custom.com/unarchive-link', $result );
	}

	/**
	 * Test aps_archived_post_link filter
	 *
	 * @covers ::aps_get_archived_post_link
	 */
	public function test_aps_archived_post_link_filter() {

		// Arrange
		$post = $this->createMockPost( [
			'ID' => 123,
			'post_type' => 'post',
			'post_status' => 'archive'
		] );

		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		$this->mockArchivedPostLinkFunctions( [
			'final_url' => 'http://example.com/post/123?preview=true'
		] );

		// Test the filter
		\WP_Mock::onFilter( 'aps_archived_post_link' )
			->with( 'http://example.com/post/123?preview=true', $post )
			->reply( 'http://custom.com/archived-view' );

		// Act
		$result = aps_get_archived_post_link( 123 );

		// Assert
		$this->assertEquals( 'http://custom.com/archived-view', $result );
	}
}
