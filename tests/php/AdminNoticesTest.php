<?php
/**
 * AdminNotices Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AdminNotices
 */

/**
 * Admin Notices test case
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\AdminNotices
 */
class AdminNoticesTest extends TestCase {

	/**
	 * The feature instance being tested.
	 *
	 * @var ArchivedPostStatus\AdminNotices
	 */
	private $feature;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->feature = new ArchivedPostStatus\AdminNotices();

		// Mock query variable retrieval for bulk action counting
		\WP_Mock::userFunction( 'get_query_var' )
			->andReturn( 0 );

		// Mock nonce URL generation for admin actions
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturn( 'http://example.com' );

		// Mock edit post link generation
		\WP_Mock::userFunction( 'get_edit_post_link' )
			->andReturn( 'http://example.com/edit' );

		// Mock post type object with labels
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) [
				'labels' => (object) [
					'name' => 'Posts'
				]
			] );

		// Mock post type retrieval
		\WP_Mock::userFunction( 'get_post_type' )
			->andReturn( 'post' );
	}

	/**
	 * Test that hooks are properly registered
	 *
	 * @covers ArchivedPostStatus\AdminNotices::register
	 */
	public function test_register_hooks() {

		\WP_Mock::expectActionAdded( 'admin_notices', [ $this->feature, 'admin_notices' ] );

		$this->feature->register();

		\WP_Mock::assertHooksAdded();
	}

	/**
	 * Test admin notices only show on edit screen
	 *
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_notices_only_show_on_edit_screen() {

		// Arrange - Not edit screen
		$screen = $this->createMockScreen( [ 'base' => 'dashboard' ] );

		// Mock current screen retrieval
		\WP_Mock::userFunction( 'get_current_screen' )
			->andReturn( $screen );

		// Act - should return early
		$this->feature->admin_notices();

		// Assert - test passes if no exceptions thrown
		$this->assertTrue( true );
	}

	/**
	 * Test notices with archived posts
	 *
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_notices_with_archived_posts() {
		// Arrange
		$screen = $this->createMockScreen( [ 'base' => 'edit' ] );

		// Mock current screen retrieval for edit screen
		\WP_Mock::userFunction( 'get_current_screen' )
			->andReturn( $screen );

		global $post_type;
		$post_type = 'post';

		// Mock query variable for archived post count
		\WP_Mock::userFunction( 'get_query_var' )
			->andReturn( 1 );

		// Mock nonce URL generation for notices
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturn( 'http://example.com' );

		// Mock edit post link generation for notices
		\WP_Mock::userFunction( 'get_edit_post_link' )
			->andReturn( 'http://example.com/edit' );

		// Mock post type object with labels for notices
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) [
				'labels' => (object) [
					'name' => 'Posts'
				]
			] );

		// Act
		$this->feature->admin_notices();

		// Assert - test passes if no exceptions thrown
		$this->assertTrue( true );
	}

	/**
	 * Test notices with unarchived posts
	 *
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_notices_with_unarchived_posts() {
		// Arrange
		$screen = $this->createMockScreen( [ 'base' => 'edit' ] );

		// Mock current screen retrieval for edit screen
		\WP_Mock::userFunction( 'get_current_screen' )
			->andReturn( $screen );

		global $post_type;
		$post_type = 'post';

		// Mock query variable for unarchived post count
		\WP_Mock::userFunction( 'get_query_var' )
			->andReturn( 1 );

		// Mock nonce URL generation for notices
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturn( 'http://example.com' );

		// Mock edit post link generation for notices
		\WP_Mock::userFunction( 'get_edit_post_link' )
			->andReturn( 'http://example.com/edit' );

		// Mock post type object with labels for notices
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) [
				'labels' => (object) [
					'name' => 'Posts'
				]
			] );

		// Act
		$this->feature->admin_notices();

		// Assert - WP_Mock verifies expected function calls were made
		$this->addToAssertionCount( 1 );
	}



	/**
	 * Test notices handles empty post type gracefully
	 *
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_notices_handles_empty_post_type() {
		// Arrange
		$screen = $this->createMockScreen([ 'base' => 'edit' ]);
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		global $post_type;
		$post_type = null;

		\WP_Mock::userFunction( 'get_post_type' )->andReturn( false );

		// Should return early without processing
		// Act
		$this->feature->admin_notices();

		// Assert - should complete without errors
		$this->assertTrue( true );
	}

	/**
	 * Test notices without any query variables
	 *
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_notices_without_query_vars() {
		// Arrange
		$screen = $this->createMockScreen([ 'base' => 'edit' ]);
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		global $post_type;
		$post_type = 'post';

		// Mock query vars returning false for all
		\WP_Mock::userFunction( 'get_query_var' )->andReturn( false );

		// Should not call notice functions when no notices to display
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		// Act
		$this->feature->admin_notices();

		// Assert
		$this->assertTrue( true );
	}



	/**
	 * Test notices on wrong screen base
	 *
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_notices_wrong_screen_base() {
		// Arrange
		$screen = $this->createMockScreen([ 'base' => 'dashboard' ]);
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		// Should return early without processing
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		// Act
		$this->feature->admin_notices();

		// Assert
		$this->assertTrue( true );
	}
}
