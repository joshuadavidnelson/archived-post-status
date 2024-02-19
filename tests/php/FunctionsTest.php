<?php
/**
 * Class FunctionsTest
 *
 * @since 0.3.9
 * @package ArchivedPostStatus
 * @subpackage FunctionsTest
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

		\WP_Mock::userFunction(
			'_deprecated_function', array(
				'return' => true,
			)
		);

	}

	/**
	 * Test the aps_archived_label_string() function.
	 *
	 * @since 0.3.9
	 * @covers \aps_archived_label_string
	 */
	public function test_aps_archived_label_string() {

		$string = 'Archived';

		// Confirm the filter is applied.
		\WP_Mock::expectFilter( 'aps_archived_label_string', $string );

		// Confirm default condition is true.
		$this->assertEquals( $string, aps_archived_label_string() );

	}

	/**
	 * Test the aps_archived_label_string() function filters.
	 *
	 * @since 0.3.9
	 * @covers aps_archived_label_string
	 */
	public function test_aps_archived_label_string_filter() {

		$string = 'Resolved';

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( $string );

		// Confirm the filter is applied.
		$this->assertEquals( $string, aps_archived_label_string() );

	}

	/**
	 * Test the aps_get_supported_post_types() function.
	 *
	 * @since 0.4.0
	 * @covers aps_get_supported_post_types
	 */
	public function test_aps_get_supported_post_types() {

		// Mock the get_post_types() function.
		\WP_Mock::userFunction(
			'get_post_types', array(
				'times'  => 1,
				'return' => array( 'post', 'page', 'attachment' ),
			)
		);

		\WP_Mock::userFunction(
			'post_type_exists', array(
				'return' => true,
			)
		);

		// Confirm the filters are applied.
		\WP_Mock::expectFilter( 'aps_supported_post_types', array( 'post', 'page' ) );

		\WP_Mock::expectFilter( 'aps_excluded_post_types', array( 'attachment' ) );

		// Confirm default condition is true.
		$this->assertEquals( array( 'post', 'page' ), aps_get_supported_post_types() );

	}

	/**
	 * Test the aps_supported_post_types filter.
	 *
	 * @since 0.4.0
	 * @covers aps_get_supported_post_types
	 */
	public function test_aps_supported_post_types_filter() {

		// Mock the get_post_types() function.
		\WP_Mock::userFunction(
			'get_post_types', array(
				'return' => array( 'post', 'page', 'attachment' ),
			)
		);

		\WP_Mock::userFunction(
			'post_type_exists', array(
				'return' => true,
			)
		);

		$this->assertEquals( array( 'post', 'page' ), aps_get_supported_post_types() );

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post', 'page' ) )
			->reply( array( 'post' ) );

		// Confirm the filter is applied.
		$this->assertEquals( array( 'post' ), aps_get_supported_post_types() );

	}

	/**
	 * Test the aps_is_excluded_post_type() function filters.
	 *
	 * @since 0.4.0
	 * @covers aps_get_supported_post_types
	 */
	public function test_aps_excluded_post_type_filter() {

		$post_types = array( 'post', 'page', 'attachment' );

		\WP_Mock::userFunction(
			'get_post_types' , array(
				'times'  => 1,
				'return' => $post_types,
			)
		);

		// Pass false to the filter.
		WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( 'attachment' )
			->reply( array() );

		// Confirm the filter is applied.
		$this->assertEquals( $post_types, aps_get_supported_post_types() );

	}

	/**
	 * Test the aps_current_user_can_view() function.
	 *
	 * @since 0.3.9
	 * @covers aps_current_user_can_view
	 */
	public function test_aps_current_user_can_view() {

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

		// Pass false to the filter.
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
	 * Test the aps_is_read_only() function filters.
	 *
	 * @since 0.3.9
	 * @covers aps_is_read_only
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
	 * Test the aps_is_excluded_post_type() function.
	 *
	 * @since 0.3.9
	 * @covers aps_is_excluded_post_type
	 */
	public function test_aps_is_excluded_post_type() {

		\WP_Mock::userFunction(
			'aps_is_supported_post_type' , array(
				'times'  => 2,
				'return' => function( $type ) {
					return $type !== 'attachment';
				},
			)
		);

		\WP_Mock::userFunction(
			'_deprecated_function', array(
				// 'times'  => 1,
				// 'with'   => array( 'aps_is_excluded_post_type', '0.4.0', 'apsi_is_supported_post_type' ),
				'return' => true,
			)
		);

		// Confirm default condition is true.
		$this->assertTrue( aps_is_excluded_post_type( 'attachment' ) );
		$this->assertFalse( aps_is_excluded_post_type( 'post' ) );

	}

	/**
	 * Test the aps_display_post_states() function.
	 *
	 * @since 0.3.9
	 * @covers aps_display_post_states
	 */
	public function test_aps_display_post_states() {

		// Mock the aps_is_excluded_post_type() function.
		\WP_Mock::userFunction(
			'aps_is_supported_post_type', array(
				'times'  => 1,
				'return' => true,
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
		$mock_post              = \Mockery::mock( 'WP_Post' );
		$mock_post->post_status = 'archive';
		$mock_post->post_type   = 'post';

		$mock_post_states = array( 'some-state' => 'Some state' );
		$new_post_states  = aps_display_post_states( $mock_post_states, $mock_post );

		$this->assertArrayHasKey( 'archive', $new_post_states );
		$this->assertEquals( 'Archived', $new_post_states['archive'] );

	}
}
