<?php
/**
 * Class SavePostTest
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @subpackage SavePostTest
 */

/**
 * Sample test case.
 *
 * @since 0.4.0
 */
class SavePostTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->class = new ArchivedPostStatus\SavePost;

	}

	/**
	 * Test the aps_save_post() function.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\SavePost::save_post
	 */
	public function test_save_post() {

		// Mock WP post object.
		$mock_post                 = \Mockery::mock( 'WP_Post' );
		$mock_post->post_status    = 'archive';
		$mock_post->post_type      = 'post';
		$mock_post->comment_status = 'open';
		$mock_post->ping_status    = 'open';
		$mock_post->ID             = 86;

		// Mock the wp_is_post_revision() function.
		\WP_Mock::userFunction(
			'wp_is_post_revision', array(
				'return' => false,
			)
		);

		// Mock the aps_is_supported_post_type() function.
		\WP_Mock::userFunction(
			'aps_is_supported_post_type', array(
				'times'  => 1,
				'return' => true,
			)
		);

		// Mock the remove_action() function.
		\WP_Mock::userFunction(
			'remove_action', array(
				'return' => true,
			)
		);

		// Mock the wp_update_post() function.
		\WP_Mock::userFunction(
			'wp_update_post', array(
				'times'  => 1,
				'args'   => array(
					array(
						'ID' => $mock_post->ID,
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					),
				),
				'return' => function( $args ) use ( $mock_post ) {
					$mock_post->comment_status = $args['comment_status'];
					$mock_post->ping_status    = $args['ping_status'];
					return $mock_post->ID;
				},
			)
		);

		$this->assertEquals( 'open', $mock_post->comment_status );
		$this->assertEquals( 'open', $mock_post->ping_status );

		$this->class->save_post( $mock_post->ID, $mock_post, true );

		$this->assertEquals( 'closed', $mock_post->comment_status );
		$this->assertEquals( 'closed', $mock_post->ping_status );

	}
}
