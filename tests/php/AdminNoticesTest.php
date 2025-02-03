<?php
/**
 * Class AdminNoticesTest
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @subpackage AdminNoticesTest
 *
 * @covers ArchivedPostStatus\AdminNotices
 */

/**
 * Sample test case.
 *
 * @since 0.4.0
 */
class AdminNoticesTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->class = new ArchivedPostStatus\AdminNotices;

		$this->mock_post            = \Mockery::mock( 'WP_Post' );
		$this->mock_post->post_type = 'post';
		$this->mock_post->ID        = 86;

		\WP_Mock::userFunction(
			'get_current_screen', array(
				'return' => (object) [
					'base' => 'edit',
				],
			)
		);

		\WP_Mock::userFunction(
			'get_post_type', array(
				'return' => 'post',
			)
		);

		// \WP_Mock::userFunction(
		// 	'get_edit_post_link', array(
		// 		'return' => 'https://archivedpoststat.us/wp-admin/post.php?post=' . $this->mock_post->ID . '&action=edit',
		// 	)
		// );

		\WP_Mock::userFunction(
			'get_post_type_object', array(
				'return' => (object) [
					'labels' => (object) [
						'edit_item' => 'Edit Post',
					],
				],
			)
		);

		\WP_Mock::userFunction(
			'aps_current_user_can_archive', array(
				'return' => true,
			)
		);

		\WP_Mock::userFunction(
			'aps_current_user_can_unarchive', array(
				'return' => true,
			)
		);

	}

	// Test register method

	/**
	 * Test the AdminNotices::admin_notices() method for single archived action.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_archived_admin_notices_single_archived() {

		\WP_Mock::userFunction(
			'get_query_var', array(
				'return' => function( $var ) {
					if ( 'archived' === $var ) {
						return '1';
					}
					if ( 'ids' === $var ) {
						return $this->mock_post->ID;
					}
					return false;
				},
			)
		);

		\WP_Mock::userFunction(
			'number_format_i18n', array(
				'return' => '1',
			)
		);

		$mock_base_url = 'https://archivedpoststat.us/wp-admin/';

		// Mock the wp_nonce_url() function.
		\WP_Mock::userFunction(
			'wp_nonce_url', array(
				'return' => function( $admin_path, $nonce_key ) use ( $mock_base_url ){
					return $mock_base_url . $admin_path . '&_wp_nonce=' . $nonce_key;
				},
			)
		);

		\WP_Mock::userFunction(
			'wp_admin_notice', array(
				'times'  => 1,
				'return' => function( $message, $args ) use ( $mock_base_url ) {
					$this->assertStringContainsString( '1 post moved to the Archive.', $message );
					$this->assertStringContainsString( $mock_base_url . 'edit.php?post_type=post&doaction=undo&action=unarchive&ids=' . $this->mock_post->ID . '&_wp_nonce=bulk-posts', $message );
				},
			)
		);

		$this->class->admin_notices();

	}

	/**
	 * Test the AdminNotices::admin_notices() method for bulk archived action.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_archived_admin_notices_bulk_archived() {

		$mock_posts = array(
			(object) array( 'ID' => 86 ),
			(object) array( 'ID' => 99 ),
			(object) array( 'ID' => 1234567 ),
			(object) array( 'ID' => 2024 ),
		);

		$mock_posts_list = implode( ',', array_map( function( $post ) {
			return $post->ID;
		}, $mock_posts ) );

		\WP_Mock::userFunction(
			'get_query_var', array(
				'return' => function( $var ) use ( $mock_posts, $mock_posts_list ) {
					if ( 'archived' === $var ) {
						return count( $mock_posts );
					}
					if ( 'ids' === $var ) {
						return $mock_posts_list;
					}
					return false;
				},
			)
		);

		\WP_Mock::userFunction(
			'number_format_i18n', array(
				'return' => (string) count( $mock_posts ),
			)
		);

		$mock_base_url = 'https://archivedpoststat.us/wp-admin/';

		// Mock the wp_nonce_url() function.
		\WP_Mock::userFunction(
			'wp_nonce_url', array(
				'return' => function( $admin_path, $nonce_key ) use ( $mock_base_url ){
					return $mock_base_url . $admin_path . '&_wp_nonce=' . $nonce_key;
				},
			)
		);

		\WP_Mock::userFunction(
			'wp_admin_notice', array(
				'times'  => 1,
				'return' => function( $message, $args ) use ( $mock_posts, $mock_base_url, $mock_posts_list ) {
					$this->assertStringContainsString( count( $mock_posts ) . ' posts moved to the Archive.', $message );
					$this->assertStringContainsString( 'Undo', $message );
					$this->assertStringContainsString( $mock_base_url . 'edit.php?post_type=post&doaction=undo&action=unarchive&ids=' . $mock_posts_list . '&_wp_nonce=bulk-posts', $message );
				},
			)
		);

		$this->class->admin_notices();

	}

	/**
	 * Test the AdminNotices::admin_notices() method for single unarchived action.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_archived_admin_notices_single_unarchived() {

		\WP_Mock::userFunction(
			'get_query_var', array(
				'return' => function( $var ) {
					if ( 'unarchived' === $var ) {
						return '1';
					}
					if ( 'ids' === $var ) {
						return $this->mock_post->ID;
					}
					return false;
				},
			)
		);

		\WP_Mock::userFunction(
			'number_format_i18n', array(
				'return' => '1',
			)
		);

		$mock_base_url = 'https://archivedpoststat.us/wp-admin/';
		$mock_edit_url = $mock_base_url . 'post.php?post=' . $this->mock_post->ID . '&action=edit';

		// Mock the wp_nonce_url() function.
		\WP_Mock::userFunction(
			'get_edit_post_link', array(
				'return' => function( $post_id ) use ( $mock_edit_url ){
					return $mock_edit_url;
				},
			)
		);

		\WP_Mock::userFunction(
			'wp_admin_notice', array(
				'times'  => 1,
				'return' => function( $message, $args ) use ( $mock_base_url, $mock_edit_url ) {
					$this->assertStringContainsString( '1 post restored from the Archive.', $message );
					$this->assertStringContainsString( 'Edit Post', $message );
					$this->assertStringContainsString( $mock_edit_url, $message );
				},
			)
		);

		$this->class->admin_notices();

	}

	/**
	 * Test the AdminNotices::admin_notices() method for bulk unarchived action.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\AdminNotices::admin_notices
	 */
	public function test_archived_admin_notices_bulk_unarchived() {

		$mock_posts = array(
			(object) array( 'ID' => 86 ),
			(object) array( 'ID' => 99 ),
			(object) array( 'ID' => 1234 ),
			(object) array( 'ID' => 2024 ),
		);

		$mock_posts_list = implode( ',', array_map( function( $post ) {
			return $post->ID;
		}, $mock_posts ) );

		\WP_Mock::userFunction(
			'get_query_var', array(
				'return' => function( $var ) use ( $mock_posts, $mock_posts_list ) {
					if ( 'unarchived' === $var ) {
						return count( $mock_posts );
					}
					if ( 'ids' === $var ) {
						return $mock_posts_list;
					}
					return false;
				},
			)
		);

		\WP_Mock::userFunction(
			'number_format_i18n', array(
				'return' => (string) count( $mock_posts ),
			)
		);

		\WP_Mock::userFunction(
			'wp_admin_notice', array(
				'times'  => 1,
				'return' => function( $message, $args ) use ( $mock_posts) {
					$this->assertStringContainsString( count( $mock_posts ) . ' posts restored from the Archive.', $message );
				},
			)
		);

		$this->class->admin_notices();
	}
}
