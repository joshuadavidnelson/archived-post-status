<?php
/**
 * Class RowActionsTest
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @subpackage RowActionsTest
 * @covers ArchivedPostStatus\RowActions
 */

/**
 * Sample test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\RowActions
 */
class RowActionsTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {

		parent::setUp();

		// Load the class.
		$this->class = new ArchivedPostStatus\RowActions;

		// Mock WP post object.
		$mock_post                 = \Mockery::mock( 'WP_Post' );
		$mock_post->post_status    = 'publish';
		$mock_post->post_type      = 'post';
		$mock_post->comment_status = 'open';
		$mock_post->ping_status    = 'open';
		$mock_post->title          = 'Test Title';
		$mock_post->ID             = 86;

		$this->mock_post = $mock_post;

		// Move check_admin_referer
		\WP_Mock::userFunction(
			'check_admin_referer', array(
				'return' => true,
			)
		);

		// Mock the get_post() function.
		\WP_Mock::userFunction(
			'get_post', array(
				'return' => $this->mock_post,
			)
		);

		// mock the get_post_type_object() function.
		\WP_Mock::userFunction(
			'get_post_type_object', array(
				'return' => (object) [
					'labels' => (object) [
						'singular_name' => 'Post',
					],
					'_edit_link' => 'http://example.com/wp-admin/post.php?post=%s&action=edit',
				],
			)
		);

		// Mock the wp_nonce_url() function.
		\WP_Mock::userFunction(
			'wp_nonce_url', array(
				'return' => function( $url, $nonce_key ) {
					return $url . '&_wp_nonce=' . $nonce_key;
				},
			)
		);

		// Mock the add_query_arg() function.
		\WP_Mock::userFunction(
			'add_query_arg', array(
				'return' => function( $key, $value = null, $url = null ) {
					if ( is_array( $key ) && $url === null ) {
						// Called with (array, url) format
						$url = $value;
						$params = array();
						foreach ( $key as $k => $v ) {
							$params[] = $k . '=' . $v;
						}
						return $url . '?' . implode( '&', $params );
					} else {
						// Called with (key, value, url) format
						return $url . '?' . $key . '=' . $value;
					}
				},
			)
		);

		// Mock the admin_url() function.
		\WP_Mock::userFunction(
			'admin_url', array(
				'return' => function( $path ) {
					return 'http://example.com/wp-admin/' . $path;
				},
			)
		);

		// Mock aps functions that are used in the class.
		\WP_Mock::userFunction(
			'aps_is_supported_post_type', array(
				'return' => true,
			)
		);
		\WP_Mock::userFunction(
			'aps_current_user_can_archive', array(
				'return' => true,
			)
		);
		\WP_Mock::userFunction(
			'aps_get_unarchive_post_link', array(
				'return' => 'http://example.com/86/',
			)
		);
		\WP_Mock::userFunction(
			'aps_current_user_can_unarchive', array(
				'return' => true,
			)
		);
		\WP_Mock::userFunction(
			'_aps_get_archivable_statuses', array(
				'return' => [ 'publish' ],
			)
		);
		\WP_Mock::userFunction(
			'_aps_nonce_key', array(
				'return' => 'row-action-test',
			)
		);
		\WP_Mock::userFunction(
			'aps_get_archive_post_link', array(
				'return' => 'http://example.com/wp-admin/post.php?id=86&action=archive',
			)
		);

	}

	/**
	 * Test the RowActions::register() method.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\RowActions::register
	 */
	public function test_register_hooks() {
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post', 'page' ] );

		\WP_Mock::expectFilterAdded( 'post_row_actions', [ $this->class, 'row_actions' ], 10, 2 );
		\WP_Mock::expectFilterAdded( 'page_row_actions', [ $this->class, 'row_actions' ], 10, 2 );
		\WP_Mock::expectActionAdded( 'post_action_archive', [ $this->class, 'post_action_archive' ] );
		\WP_Mock::expectActionAdded( 'post_action_unarchive', [ $this->class, 'post_action_unarchive' ] );

		$this->class->register();
		\WP_Mock::assertHooksAdded();
	}

	/**
	 * Test the RowActions::row_actions() method.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\RowActions::row_actions
	 */
	public function test_row_actions_for_archivable_post() {

		$mock_actions = [
			'inline hide-if-no-js' => 'Quick Edit',
			'view'                 => 'View',
			'trash'                => 'Trash',
			'edit'                 => 'Edit',
		];

		// actions for archived post.
		$this->mock_post->post_status = 'publish';
		$new_actions = $this->class->row_actions( $mock_actions, $this->mock_post );

		// All the original keys should be there
		foreach ( $mock_actions as $action => $label ) {
			$this->assertArrayHasKey( $action, $new_actions );
		}

		// and this one too.
		$this->assertArrayHasKey( 'archive', $new_actions );

	}

	/**
	 * Test the RowActions::row_actions() method.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\RowActions::row_actions
	 */
	public function test_row_actions_for_unarchivable_post() {

		\WP_Mock::userFunction(
			'aps_current_user_can_view', array(
				'return_in_order' => [ true, false ],
			)
		);

		$mock_actions = [
			'inline hide-if-no-js' => 'Quick Edit',
			'view'                 => 'View',
			'trash'                => 'Trash',
			'edit'                 => 'Edit',
		];

		// actions for archived post.
		$this->mock_post->post_status = 'archive';
		$new_actions = $this->class->row_actions( $mock_actions, $this->mock_post );

		// Shouldn't have these keys
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $new_actions );
		$this->assertArrayNotHasKey( 'edit', $new_actions );
		$this->assertArrayHasKey( 'view', $new_actions );
		$this->assertArrayHasKey( 'unarchive', $new_actions );
		$this->assertArrayHasKey( 'trash', $new_actions );

		// Second round should not have the view link.
		$new_actions = $this->class->row_actions( $mock_actions, $this->mock_post );

		// Shouldn't have these keys
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $new_actions );
		$this->assertArrayNotHasKey( 'edit', $new_actions );
		$this->assertArrayNotHasKey( 'view', $new_actions );
		$this->assertArrayHasKey( 'unarchive', $new_actions );
		$this->assertArrayHasKey( 'trash', $new_actions );

	}



	/**
	 * Test row_actions with archived post status
	 *
	 * @covers ArchivedPostStatus\RowActions::row_actions
	 */
	public function test_row_actions_archived_post() {
		// Arrange - set up an archived post
		$actions = [ 'edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit' ];
		$archived_post = \Mockery::mock( 'WP_Post' );
		$archived_post->post_status = 'archive'; // Note: correct status is 'archive' not 'archived'
		$archived_post->post_type = 'post';
		$archived_post->ID = 86;

		// Override setUp mocks for archived post scenario
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true )->zeroOrMoreTimes();
		\WP_Mock::userFunction( 'aps_current_user_can_unarchive' )->with( 86 )->andReturn( true )->zeroOrMoreTimes();
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->with( 86 )->andReturn( true )->zeroOrMoreTimes();
		\WP_Mock::userFunction( 'aps_get_unarchive_post_link' )->with( 86 )->andReturn( 'http://example.com/unarchive' )->zeroOrMoreTimes();
		\WP_Mock::userFunction( '__' )->andReturn( 'Unarchive' )->zeroOrMoreTimes();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( function( $value ) { return $value; } )->zeroOrMoreTimes();

		// Act
		$result = $this->class->row_actions( $actions, $archived_post );

		// Assert - should contain unarchive action and some actions should be removed
		$this->assertArrayHasKey( 'unarchive', $result );
		$this->assertArrayNotHasKey( 'edit', $result ); // Should be removed for archived posts
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $result ); // Should be removed for archived posts
	}
}
