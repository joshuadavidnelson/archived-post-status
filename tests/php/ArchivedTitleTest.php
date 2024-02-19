<?php
/**
 * Class ArchivedTitleTest
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @subpackage ArchivedTitleTest
 *
 * @covers ArchivedPostStatus\ArchivedTitle
 */

/**
 * Archived Title Tests
 *
 * @since 0.4.0
 */
class ArchivedTitleTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->class = new ArchivedPostStatus\ArchivedTitle;

		// Mock WP post object.
		$mock_post                 = \Mockery::mock( 'WP_Post' );
		$mock_post->post_status    = 'archive';
		$mock_post->post_type      = 'post';
		$mock_post->comment_status = 'open';
		$mock_post->ping_status    = 'open';
		$mock_post->title          = 'Test Title';
		$mock_post->ID             = 86;

		$this->mock_post = $mock_post;

		// Mock the get_post() function.
		\WP_Mock::userFunction(
			'get_post', array(
				'return' => $this->mock_post,
			)
		);

		//
		\WP_Mock::userFunction(
			'is_admin', array(
				'return' => false,
			)
		);

	}

	/**
	 * Test the ArchivedTitle::filter_title() function.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\ArchivedTitle::filter_title
	 */
	public function test_filter_title() {

		// expect filters
		\WP_Mock::expectFilter( 'aps_title_label', 'Archived', $this->mock_post->ID, $this->mock_post->title );

		\WP_Mock::expectFilter( 'aps_title_label_before', true, $this->mock_post->ID );

		\WP_Mock::expectFilter( 'aps_title_separator', ': ', $this->mock_post->ID );

		// Call the function.
		$filtered_title = $this->class->filter_title( $this->mock_post->title, $this->mock_post->ID );

		// Confirm the filter is applied.
		$this->assertEquals( $filtered_title, 'Archived: '. $this->mock_post->title );

	}

	/**
	 * Test the ArchivedTitle::filter_title() filter with a custom label.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\ArchivedTitle::filter_title
	 */
	public function test_aps_title_label_filter_custom_string() {

		$string = 'Resolved';

		// Confirm the filter is applied.
		\WP_Mock::onFilter( 'aps_title_label' )
			->with( 'Archived', $this->mock_post->ID, $this->mock_post->title )
			->reply( $string );

		// Call the function.
		$filtered_title = $this->class->filter_title( $this->mock_post->title, $this->mock_post->ID );

		// Confirm the filter is applied.
		$this->assertEquals( $filtered_title, $string . ': '. $this->mock_post->title );

	}

	/**
	 * Test the ArchivedTitle::filter_title() filter can
	 * disable the label string by returning false.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\ArchivedTitle::filter_title
	 */
	public function test_aps_title_label_filter_false() {

		// Confirm the filter is applied.
		\WP_Mock::onFilter( 'aps_title_label' )
			->with( 'Archived', $this->mock_post->ID, $this->mock_post->title )
			->reply( false );

		// Call the function.
		$filtered_title = $this->class->filter_title( $this->mock_post->title, $this->mock_post->ID );

		// Confirm the filter is applied.
		$this->assertEquals( $filtered_title, $this->mock_post->title );

	}

	/**
	 * Test the title label before filter
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\ArchivedTitle::filter_title
	 */
	public function test_aps_title_label_before_filter() {

		// Confirm the filter is applied.
		\WP_Mock::onFilter( 'aps_title_label_before' )
			->with( true, $this->mock_post->ID )
			->reply( false );

		// Call the function.
		$filtered_title = $this->class->filter_title( $this->mock_post->title, $this->mock_post->ID );

		// Confirm the filter is applied.
		$this->assertEquals( $filtered_title, $this->mock_post->title . ' - Archived' );

	}

	/**
	 * Test the title separator filter.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\ArchivedTitle::filter_title
	 */
	public function test_aps_title_separator_filter() {

		$custom_sep = ' / ';

		// Confirm the filter is applied.
		\WP_Mock::onFilter( 'aps_title_separator' )
			->with( ': ', $this->mock_post->ID )
			->reply( $custom_sep );

		// Call the function.
		$filtered_title = $this->class->filter_title( $this->mock_post->title, $this->mock_post->ID );

		// Confirm the filter is applied.
		$this->assertEquals( $filtered_title, 'Archived' . $custom_sep . $this->mock_post->title );

	}
}
