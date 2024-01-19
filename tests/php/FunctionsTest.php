<?php
/**
 * Class FunctionsTest
 *
 * @since 0.3.9
 * @package ArchivedPostStatus
 * @subpackage ClassFunctionsTest
 */

/**
 * Sample test case.
 *
 * @since 0.3.9
 */
class FunctionsTest extends TestCase {

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
	}

	/**
	 * Test the aps_post_status_slug() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_post_status_slug() {

		$string = 'archive';

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_post_status_slug', $string );

		// Confirm default condition is true.
		$this->assertEquals( $string, aps_post_status_slug() );

	}

	/**
	 * Test the aps_is_excluded_post_type() function filters.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_post_status_slug_filter() {

		$string = 'resolve';

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( $string );

		// Confirm the filter is applied.
		$this->assertEquals( $string, aps_post_status_slug() );

	}

	/**
	 * Test the aps_is_frontend() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_is_frontend() {

		// Confirm the is_admin() function is called.
		// Return false, then true.
		\WP_Mock::userFunction(
			'is_admin', array(
				'times'           => 2,
				'return_in_order' => array(
					false,
					true,
				),
			)
		);

		// Is frontend should return the opposite of is_admin().
		$this->assertTrue( aps_is_frontend() );
		$this->assertFalse( aps_is_frontend() );

	}

	/**
	 * Test the aps_current_user_can_view() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_current_user_can_view() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability ) {
					return $capability === 'read_private_posts';
				},
			)
		);

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_default_read_capability', 'read_private_posts' );

		// Confirm the default condition is true.
		$this->assertTrue( aps_current_user_can_view() );

	}

	/**
	 * Test the aps_current_user_can_view() filter.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_current_user_can_view_filter() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability ) {
					return $capability === 'read_private_posts';
				},
			)
		);

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_default_read_capability' )
			->with( 'read_private_posts' )
			->reply( 'read' );

		// Confirm the filter is applied.
		$this->assertFalse( aps_current_user_can_view() );

	}

	/**
	 * Test the aps_is_read_only() function.
	 *
	 * @since 0.3.9
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
	 */
	public function test_aps_is_read_only_filters() {

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( false );

		// Confirm the filter is applied.
		$this->assertFalse( aps_is_read_only() );

	}

	/**
	 * Test the aps_the_title() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_the_title() {

		// Mock WP post object.
		$mock_post = \Mockery::mock( 'WP_Post' );
		$mock_post->post_title = 'Test Title';
		$mock_post->post_status = 'archive';
		$mock_post->post_type = 'post';
		$mock_post->ID = 86;

		// Mock functions.
		\WP_Mock::userFunction(
			'get_post', array(
				'times'  => 1,
				'return' => $mock_post,
			)
		);
		\WP_Mock::userFunction(
			'is_admin', array(
				'return' => false,
			)
		);

		$new_title = aps_the_title( $mock_post->post_title, $mock_post->ID );

		$this->assertEquals( 'Archived: ' . $mock_post->post_title, $new_title );

	}

	/**
	 * Test the aps_is_excluded_post_type() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_is_excluded_post_type() {

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_excluded_post_types', array( 'attachment' ) );

		// Confirm default condition is true.
		$this->assertTrue( aps_is_excluded_post_type( 'attachment' ) );
		$this->assertFalse( aps_is_excluded_post_type( 'post' ) );

	}

	/**
	 * Test the aps_is_excluded_post_type() function filters.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_is_excluded_post_type_filter() {

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( 'attachment' )
			->reply( array( ) );

		// Confirm the filter is applied.
		$this->assertFalse( aps_is_excluded_post_type( 'attachment' ) );

	}

	/**
	 * Test the aps_display_post_states() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_display_post_states() {

		// Mock the aps_is_excluded_post_type() function.
		\WP_Mock::userFunction(
			'aps_is_excluded_post_type', array(
				'times'  => 1,
				'return' => false,
			)
		);

		// Mock the get_query_var() function.
		\WP_Mock::userFunction(
			'get_query_var', array(
				'times'  => 1,
				'return' => false,
			)
		);

		// Mock WP post object.
		$mock_post = \Mockery::mock( 'WP_Post' );
		$mock_post->post_status = 'archive';
		$mock_post->post_type = 'post';

		$mock_post_states = array( 'some-state' => 'Some state' );
		$new_post_states = aps_display_post_states( $mock_post_states, $mock_post );

		$this->assertArrayHasKey( 'archive', $new_post_states );
		$this->assertEquals( 'Archived', $new_post_states['archive'] );

	}

	/**
	 * Test the aps_save_post() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_save_post() {

		// Mock WP post object.
		$mock_post = \Mockery::mock( 'WP_Post' );
		$mock_post->post_status = 'archive';
		$mock_post->post_type = 'post';
		$mock_post->comment_status = 'open';
		$mock_post->ping_status    = 'open';
		$mock_post->ID = 86;

		// Mock the wp_is_post_revision() function.
		\WP_Mock::userFunction(
			'wp_is_post_revision', array(
				'return' => false,
			)
		);

		// Mock the aps_is_excluded_post_type() function.
		\WP_Mock::userFunction(
			'aps_is_excluded_post_type', array(
				'times'  => 1,
				'return' => false,
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

		aps_save_post( $mock_post->ID, $mock_post, true );

		$this->assertEquals( 'closed', $mock_post->comment_status );
		$this->assertEquals( 'closed', $mock_post->ping_status );

	}
}
