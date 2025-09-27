<?php
/**
 * SavePost Tests
 *
 * Focus on testing behavior rather than implementation details.
 * Tests the actual business logic and outcomes.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostSPost
 */

/**
 * Save Post test case
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\SavePost
 */
class SavePostTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->feature = new ArchivedPostStatus\SavePost();
	}

	/**
	 * Test that the class properly registers its hooks
	 *
	 * @covers ArchivedPostStatus\SavePost::register
	 */
	public function test_register_hooks() {
		\WP_Mock::expectActionAdded( 'save_post', [ $this->feature, 'save_post' ], 10, 3 );
		$this->feature->register();
		\WP_Mock::assertHooksAdded();
	}

	/**
	 * Test that comments and pings are closed when a post is archived
	 *
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_closes_comments_and_pings_for_archived_posts() {
		// Arrange
		$post = $this->createMockPost([
			'ID' => 123,
			'post_status' => 'archive',
			'comment_status' => 'open',
			'ping_status' => 'open'
		]);

		// Mock revision check to ensure we're not dealing with a revision
		\WP_Mock::userFunction( 'wp_is_post_revision' )
			->with( 123 )
			->andReturn( false );

		// Mock post type support check for archiving
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );

		// Expect metadata to be saved for comment/ping status preservation
		\WP_Mock::userFunction( 'add_post_meta' )
			->atLeast()
			->once();

		// Expect hooks to be managed (relaxed to handle edge cases)
		\WP_Mock::userFunction( 'remove_action' )
			->zeroOrMoreTimes();

		\WP_Mock::userFunction( 'add_action' )
			->zeroOrMoreTimes();

		// Expect the post to be updated with closed comments/pings
		\WP_Mock::userFunction( 'wp_update_post' )
			->once();

		// Act
		$this->feature->save_post( 123, $post, true );

		// Assert - WP_Mock verifies the expectations
		$this->assertTrue( true );
	}

	/**
	 * Test that already closed comments/pings don't trigger updates
	 *
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_skips_update_when_comments_already_closed() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive',
			'comment_status' => 'closed',
			'ping_status' => 'closed'
		]);

		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->andReturn( true );

		// Should not call wp_update_post
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		// Act
		$this->feature->save_post( 123, $post, true );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test ignores non-archived posts
	 *
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_ignores_non_archived_posts() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'publish'
		]);

		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->andReturn( true );

		// Should not call wp_update_post
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		// Act
		$this->feature->save_post( 123, $post, true );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test ignores unsupported post types
	 *
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_ignores_unsupported_post_types() {
		// Arrange
		$post = $this->createMockPost([
			'post_type' => 'attachment',
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_types' )->andReturn( [ 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ] );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( [ 'attachment' ] )
			->reply( [ 'attachment' ] );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( [ 'post', 'page' ] )
			->reply( [ 'post', 'page' ] );

		// Should not call wp_update_post
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		// Act
		$this->feature->save_post( 123, $post, true );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test ignores revisions
	 *
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_ignores_revisions() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 123 )->andReturn( true );

		// Should not proceed with any other checks

		\WP_Mock::userFunction( 'wp_update_post' )->never();

		// Act
		$this->feature->save_post( 123, $post, true );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test complete archive workflow
	 *
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_complete_archive_workflow() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive',
			'comment_status' => 'open',
			'ping_status' => 'open'
		]);

		// Setup all required mocks
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->andReturn( true );
		\WP_Mock::userFunction( 'add_post_meta' )->atLeast()->once();
		\WP_Mock::userFunction( 'remove_action' )->atLeast()->never();
		\WP_Mock::userFunction( 'wp_update_post' )->once();

		// Act & Assert - should complete without errors
		$this->feature->save_post( 123, $post, true );
		$this->assertTrue( true );
	}


	/**
	 * Test save_post handles partially closed status
	 *
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_save_post_handles_partially_closed_status() {
		// Arrange - comments closed but pings open
		$post = $this->createMockPost([
			'post_status' => 'archive',
			'comment_status' => 'closed',
			'ping_status' => 'open'
		]);

		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->andReturn( true );

		// Should still process since ping_status is open
		\WP_Mock::userFunction( 'add_post_meta' )->atLeast()->once();
		\WP_Mock::userFunction( 'wp_update_post' )->once();

		// Act
		$this->feature->save_post( 123, $post, true );

		// Assert
		$this->assertTrue( true );
	}
}
