<?php
/**
 * Class BulkEditTest
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @subpackage BulkEditTest
 *
 * @covers ArchivedPostStatus\BulkEdit
 */

/**
 * Sample test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\BulkEdit
 */
class BulkEditTest extends TestCase {

	/**
	 * The BulkEdit instance being tested
	 * @var ArchivedPostStatus\BulkEdit
	 */
	protected $class;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();

		$this->class = new \ArchivedPostStatus\BulkEdit;

		\WP_Mock::userFunction(
			'_aps_get_archivable_statuses', array(
				'return' => [ 'publish' ],
			)
		);

	}

	// Test register method

	/**
	 * Test the BulkEdit::bulk_actions() method.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::bulk_actions
	 */
	public function test_archive_bulk_action() {

		\WP_Mock::userFunction(
			'get_query_var', array(
				'return' => false,
			)
		);

		$mock_actions = array(
			'-1' 	=> 'Bulk actions',
			'edit' 	=> 'Edit',
			'trash' => 'Move to Trash',
		);

		$new_actions = $this->class->bulk_actions( $mock_actions );

		foreach( $mock_actions as $key => $value ) {
			$this->assertArrayHasKey( $key, $new_actions );
		}
		$this->assertArrayHasKey( 'archive', $new_actions );

	}

	/**
	 * Test the BulkEdit::bulk_actions() method.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::bulk_actions
	 */
	public function test_unarchive_bulk_action() {

		\WP_Mock::userFunction(
			'get_query_var', array(
				'return' => 'archive',
			)
		);

		$mock_actions = array(
			'-1' 	=> 'Bulk actions',
			'edit' 	=> 'Edit',
			'trash' => 'Move to Trash',
		);

		$new_actions = $this->class->bulk_actions( $mock_actions );

		foreach( $mock_actions as $key => $value ) {
			$this->assertArrayHasKey( $key, $new_actions );
		}
		$this->assertArrayHasKey( 'unarchive', $new_actions );

	}

	/**
	 * Test the BulkEdit::register() method.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::register
	 */
	public function test_register_hooks() {
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post', 'page' ] );

		\WP_Mock::expectFilterAdded( 'bulk_actions-edit-post', [ $this->class, 'bulk_actions' ] );
		\WP_Mock::expectFilterAdded( 'bulk_actions-edit-page', [ $this->class, 'bulk_actions' ] );
		\WP_Mock::expectFilterAdded( 'handle_bulk_actions-edit-post', [ $this->class, 'handle_bulk_action' ], 10, 3 );
		\WP_Mock::expectFilterAdded( 'handle_bulk_actions-edit-page', [ $this->class, 'handle_bulk_action' ], 10, 3 );

		$this->class->register();
		\WP_Mock::assertHooksAdded();
	}

	/**
	 * Test the BulkEdit::handle_bulk_action() method for archive action.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_archive_bulk_action() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'archive';
		$post_ids = [ 1, 2, 3 ];

		// Mock required functions
		\WP_Mock::userFunction( 'remove_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_check_post_lock' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_status' )->andReturn( 'publish' );
		\WP_Mock::userFunction( '_aps_get_archivable_statuses' )->andReturn( [ 'publish', 'draft' ] );
		\WP_Mock::userFunction( 'aps_archive_post' )->times( 3 )->andReturn( true );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( $redirect_to );

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert
		$this->assertEquals( $redirect_to, $result );
	}

	/**
	 * Test the BulkEdit::handle_bulk_action() method for unarchive action.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_unarchive_bulk_action() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'unarchive';
		$post_ids = [ 4, 5, 6 ];

		// Mock required functions
		\WP_Mock::userFunction( 'remove_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::userFunction( 'aps_current_user_can_unarchive' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_unarchive_post' )->times( 3 )->andReturn( true );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::userFunction( 'remove_filter' )->andReturn( true );

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert
		$this->assertEquals( $redirect_to, $result );
	}

	/**
	 * Test handle_bulk_action skips unsupported actions
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_bulk_action_skips_unsupported_actions() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'delete';
		$post_ids = [ 1, 2, 3 ];

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert - should return unchanged redirect_to
		$this->assertEquals( $redirect_to, $result );
	}

	/**
	 * Test handle_bulk_action with empty post IDs
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_bulk_action_with_empty_post_ids() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'archive';
		$post_ids = [];

		// No expectations because the function returns early when post_ids is empty

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert
		$this->assertEquals( $redirect_to, $result );
	}

	/**
	 * Test bulk action handles successful archive operations
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_bulk_action_handles_permission_failures() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'archive';
		$post_ids = [ 1, 2 ];

		\WP_Mock::userFunction( 'remove_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_check_post_lock' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_status' )->andReturn( 'publish' );
		\WP_Mock::userFunction( '_aps_get_archivable_statuses' )->andReturn( [ 'publish', 'draft' ] );
		\WP_Mock::userFunction( 'aps_archive_post' )->andReturn( true );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( $redirect_to );

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert
		$this->assertEquals( $redirect_to, $result );
	}

	/**
	 * Test handle_bulk_action handles locked posts
	 *
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_bulk_action_handles_locked_posts() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'archive';
		$post_ids = [ 1, 2 ];

		\WP_Mock::userFunction( 'remove_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_check_post_lock' )->andReturn( true ); // Locked post
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( $redirect_to );

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert
		$this->assertEquals( $redirect_to, $result );
	}

	/**
	 * Test handle_bulk_action handles invalid post statuses
	 *
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_bulk_action_handles_invalid_statuses() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'archive';
		$post_ids = [ 1 ];

		\WP_Mock::userFunction( 'remove_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_check_post_lock' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_status' )->andReturn( 'private' );
		\WP_Mock::userFunction( '_aps_get_archivable_statuses' )->andReturn( [ 'publish', 'draft' ] ); // private not in list
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( $redirect_to );

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert
		$this->assertEquals( $redirect_to, $result );
	}

	/**
	 * Test handle_bulk_action with undo functionality
	 *
	 * @covers ArchivedPostStatus\BulkEdit::handle_bulk_action
	 */
	public function test_handle_bulk_action_with_undo() {
		// Arrange
		$redirect_to = 'http://example.com/wp-admin/edit.php';
		$action = 'unarchive';
		$post_ids = [ 1 ];

		// Mock $_GET superglobal
		$_GET['doaction'] = 'undo';

		\WP_Mock::userFunction( 'remove_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::userFunction( 'aps_current_user_can_unarchive' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_unarchive_post' )->andReturn( true );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( $redirect_to );
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )->with( 'aps_unarchive_post_set_previous_status', 10, 3 );

		// Act
		$result = $this->class->handle_bulk_action( $redirect_to, $action, $post_ids );

		// Assert
		$this->assertEquals( $redirect_to, $result );

		// Clean up
		unset( $_GET['doaction'] );
	}

}
