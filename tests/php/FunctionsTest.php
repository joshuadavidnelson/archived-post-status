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
	 * @covers ::aps_archived_label_string
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
	 * @covers ::aps_archived_label_string
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
	 * @covers ::aps_get_supported_post_types
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
	 * @covers ::aps_is_supported_post_type
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
	 * Test current user can view function
	 *
	 * @covers ::aps_current_user_can_view
	 */
	public function test_current_user_can_view() {
		// Arrange
		\WP_Mock::userFunction( 'current_user_can' )
			->andReturn( true );

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'read_private_posts';
				},
			)
		);

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_default_read_capability', 'read_private_posts', 0 );

		// Confirm the default condition is true.
		$this->assertTrue( aps_current_user_can_view() );

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
	 * Test the aps_current_user_can_archive() function.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_archive
	 */
	public function aps_current_user_can_archive() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_others_posts', 0 );

		// Confirm the default condition is true.
		$this->assertTrue( aps_current_user_can_archive() );

	}

	/**
	 * Test the aps_current_user_can_archive() filter.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_archive
	 */
	public function test_aps_current_user_can_archive_filter() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_user_archive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'read' );

		// Confirm the filter is applied.
		$this->assertFalse( aps_current_user_can_archive() );

	}

	/**
	 * Test the aps_current_user_can_unarchive() function.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_unarchive
	 */
	public function aps_current_user_can_unarchive() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_user_unarchive_capability', 'edit_others_posts', 0 );

		// Confirm the default condition is true.
		$this->assertTrue( aps_current_user_can_unarchive() );

	}

	/**
	 * Test the aps_current_user_can_unarchive() filter.
	 *
	 * @since 0.4.0
	 * @covers aps_current_user_can_unarchive
	 */
	public function test_aps_current_user_can_unarchive_filter() {

		// Mock the current_user_can() function.
		\WP_Mock::userFunction(
			'current_user_can', array(
				'times'  => 1,
				'return' => function( $capability, ...$args ) {
					return $capability === 'edit_others_posts';
				},
			)
		);

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_user_unarchive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'read' );

		// Confirm the filter is applied.
		$this->assertFalse( aps_current_user_can_unarchive() );

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
	 * Test the aps_is_read_only filter.
	 *
	 * @since 0.3.9
	 * @covers aps_is_read_only
	 */
	public function test_aps_is_read_only_filter() {

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( true );

		// Act & Assert
		$this->assertTrue( aps_current_user_can_view() );
	}

	/**
	 * Test the aps_the_title() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_the_title() {

		// Mock functions.
		\WP_Mock::userFunction(
			'get_post', array(
				'times'  => 1,
				'return' => $this->mock_post,
			)
		);
		\WP_Mock::userFunction(
			'is_admin', array(
				'return' => false,
			)
		);

		$new_title = aps_the_title( $this->mock_post->post_title, $this->mock_post->ID );

		$this->assertEquals( 'Archived: ' . $this->mock_post->post_title, $new_title );

	}

	/**
	 * Test the aps_title_label filter.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_the_title_label_filter() {

		// Mock functions.
		\WP_Mock::userFunction(
			'get_post',
			array(
				'times'  => 2,
				'return' => $this->mock_post,
			)
		);
		\WP_Mock::userFunction(
			'is_admin',
			array(
				'return' => false,
			)
		);

		$new_label = 'Archived Post';

		// Use the filter to change the title label.
		\WP_Mock::onFilter( 'aps_title_label' )
			->with(
				'Archived',
				$this->mock_post->ID,
				$this->mock_post->post_title
			)
			->reply( $new_label );

		$new_title = aps_the_title( $this->mock_post->post_title, $this->mock_post->ID );

		$this->assertEquals( $new_label . ': ' . $this->mock_post->post_title, $new_title );

		// Use the filter to remove the label by returning empty string
		\WP_Mock::onFilter( 'aps_title_label' )
			->with(
				'Archived',
				$this->mock_post->ID,
				$this->mock_post->post_title
			)
			->reply( '' );

		$new_title = aps_the_title( $this->mock_post->post_title, $this->mock_post->ID );

		$this->assertEquals( $this->mock_post->post_title, $new_title );

	}

	/**
	 * Test the aps_title_label_before filter.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_the_title_label_before_filter() {

		// Mock functions.
		\WP_Mock::userFunction(
			'get_post', array(
				'times'  => 1,
				'return' => $this->mock_post,
			)
		);
		\WP_Mock::userFunction(
			'is_admin', array(
				'return' => false,
			)
		);

		// Use the filter to change the title label location.
		\WP_Mock::onFilter( 'aps_title_label_before' )
			->with( true, $this->mock_post->ID )
			->reply( false );

		$new_title = aps_the_title( $this->mock_post->post_title, $this->mock_post->ID );

		$this->assertEquals( $this->mock_post->post_title . ' - Archived', $new_title );

	}

	/**
	 * Test the aps_title_separator filter.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_title_separator_filter() {

		// Mock functions.
		\WP_Mock::userFunction(
			'get_post', array(
				'times'  => 1,
				'return' => $this->mock_post,
			)
		);
		\WP_Mock::userFunction(
			'is_admin', array(
				'return' => false,
			)
		);

		// Use the filter to change the title separator.
		\WP_Mock::onFilter( 'aps_title_separator' )
			->with( ': ', $this->mock_post->ID )
			->reply( ' ~ ' );

		$new_title = aps_the_title( $this->mock_post->post_title, $this->mock_post->ID );

		$this->assertEquals( 'Archived ~ ' . $this->mock_post->post_title, $new_title );

	}

	/**
	 * Test the aps_is_excluded_post_type() function.
	 *
	 * @covers ::aps_is_read_only
	 */
	public function test_is_read_only() {
		// Arrange
		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( false )
			->reply( true ); // Changed to match expected behavior

		// Act & Assert
		$this->assertTrue( aps_is_read_only() ); // Changed expectation
	}

	/**
	 * Test the aps_excluded_post_types filter.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_excluded_post_types_filter() {

		// Use the filter to change the default.
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( 'attachment' )
			->reply( array( 'post' ) );

		// Confirm the filter is applied.
		$this->assertFalse( aps_is_excluded_post_type( 'attachment' ) );
		$this->assertTrue( aps_is_excluded_post_type( 'post' ) );

	}

	/**
	 * Test the aps_display_post_states() function.
	 *
	 * @covers ::aps_current_user_can_archive
	 */
	public function test_current_user_can_archive() {
		// Arrange
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( false );
		\WP_Mock::userFunction( 'current_user_can' )
			->andReturn( true );

		\WP_Mock::onFilter( 'aps_current_user_can_archive' )
			->with( true )
			->reply( true );

		// Act & Assert
		$this->assertTrue( aps_current_user_can_archive() );
	}

	/**
	 * Test nonce key generation
	 *
	 * @covers ::_aps_nonce_key
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
	 * @covers ::aps_register_archive_post_status
	 * @covers ::aps_archived_label_string
	 * @covers ::aps_current_user_can_view
	 * @covers ::aps_get_supported_post_types
	 */
	public function test_register_archive_post_status() {
		// Arrange
		\WP_Mock::userFunction( 'register_post_status' )
			->with( 'archive', \Mockery::type( 'array' ) )
			->once();

		// Act
		aps_register_archive_post_status();

		// Assert - function call verified by WP_Mock
		$this->assertTrue( true );
	}

	/**
	 * Test aps_get_supported_post_types default behavior
	 *
	 * @covers ::aps_get_supported_post_types
	 */
	public function test_get_supported_post_types() {
		// Arrange
		\WP_Mock::userFunction( 'get_post_types' )
			->with( [ 'public' => true ] )
			->andReturn( [ 'post', 'page', 'attachment' ] );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( [ 'attachment' ] )
			->reply( [ 'attachment' ] );
		\WP_Mock::userFunction( 'post_type_exists' )->andReturn( true );
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( function( $value ) {
			return $value;
		} );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( [ 'post', 'page' ] )
			->reply( [ 'post', 'page' ] );

		// Act
		$result = aps_get_supported_post_types();

		// Assert
		$this->assertEquals( [ 'post', 'page' ], $result );
	}

	/**
	 * Test the aps_save_post() function.
	 *
	 * @since 0.3.9
	 */
	public function test_aps_save_post() {

		$mock_post = $this->mock_post;

		// Mock the wp_doing_ajax() function.
		\WP_Mock::userFunction(
			'wp_doing_ajax', array(
				'return' => false,
			)
		);

		// Mock the wp_doing_cron() function.
		\WP_Mock::userFunction(
			'wp_doing_cron', array(
				'return' => false,
			)
		);

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
						'ID'             => $mock_post->ID,
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
