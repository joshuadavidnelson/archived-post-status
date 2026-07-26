<?php
/**
 * ArchivedTitle Tests
 *
 * Focus on testing title modification behavior rather than implementation.
 * Tests the actual output and filter integration.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Frontend\ArchiveTitle
 */

/**
 * Archived Title test case
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Frontend\ArchiveTitle
 */
class ArchiveTitleTest extends TestCase {

	/**
	 * The feature instance being tested.
	 *
	 * @var ArchivedPostStatus\Frontend\ArchiveTitle
	 */
	private $feature;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->feature = new ArchivedPostStatus\Frontend\ArchiveTitle();

		// Mock archived label string retrieval
		\WP_Mock::userFunction( 'aps_archived_label_string' )
			->andReturn( 'Archived' );

		// Mock current post ID retrieval
		\WP_Mock::userFunction( 'get_the_ID' )
			->andReturn( 123 );
	}

	/**
	 * ArchiveTitle::hooks() returns a single descriptor binding `the_title`
	 * as a filter at priority 10 with two accepted arguments. The priority
	 * is load-bearing — moving it earlier would clash with WordPress core
	 * filters that prefix `Private:` and `Protected:` to the title.
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::hooks
	 */
	public function test_register_hooks_adds_title_filter_at_priority_ten() {
		// Act
		$hooks = $this->feature->hooks();

		// Assert
		$this->assertCount( 1, $hooks );
		$this->assertEquals( 'the_title', $hooks[0]->hook );
		$this->assertEquals( 'filter', $hooks[0]->type );
		$this->assertEquals( 10, $hooks[0]->priority );
		$this->assertEquals( 2, $hooks[0]->accepted_args );
	}

	/**
	 * Test that archived posts get a label prefix by default
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_adds_label_prefix_to_archived_posts() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		// Mock post object retrieval
		\WP_Mock::userFunction( 'get_post' )
			->with( 123 )
			->andReturn( $post );

		// Mock admin context check for frontend display
		\WP_Mock::userFunction( 'is_admin' )
			->andReturn( false );

		// Mock filter for customizing archived label text
		\WP_Mock::onFilter( 'aps_title_label' )
			->with( 'Archived', 123, 'Test Post' )
			->reply( 'Archived' );

		// Mock filter for label positioning (before/after title)
		\WP_Mock::onFilter( 'aps_title_label_before' )
			->with( true, 123 )
			->reply( true );

		// Mock filter for title separator customization
		\WP_Mock::onFilter( 'aps_title_separator' )
			->with( ': ', 123 )
			->reply( ': ' );

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert
		$this->assertEquals( 'Archived: Test Post', $result );
	}

	/**
	 * Test leaves non-archived posts unchanged
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_leaves_non_archived_posts_unchanged() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'publish'
		]);

		\WP_Mock::userFunction( 'get_post' )->with( 123 )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert
		$this->assertEquals( 'Test Post', $result );
	}

	/**
	 * Test skips modification in admin context
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_skips_modification_in_admin_context() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->with( 123 )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true ); // Admin context

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert
		$this->assertEquals( 'Test Post', $result );
	}

	/**
	 * Test custom label through filter
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_custom_label_through_filter() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		\WP_Mock::onFilter( 'aps_title_label' )
			->with( 'Archived', 123, 'Test Post' )
			->reply( 'Old Content' );

		\WP_Mock::onFilter( 'aps_title_label_before' )
			->with( true, 123 )
			->reply( true );

		\WP_Mock::onFilter( 'aps_title_separator' )
			->with( ': ', 123 )
			->reply( ': ' );

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert
		$this->assertEquals( 'Old Content: Test Post', $result );
	}

	/**
	 * Test disable label through filter
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_disable_label_through_filter() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		\WP_Mock::onFilter( 'aps_title_label' )
			->with( 'Archived', 123, 'Test Post' )
			->reply( '' ); // Empty label disables modification

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert
		$this->assertEquals( 'Test Post', $result );
	}

	/**
	 * Test label after title
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_label_after_title() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		\WP_Mock::onFilter( 'aps_title_label' )
			->with( 'Archived', 123, 'Test Post' )
			->reply( 'Archived' );

		\WP_Mock::onFilter( 'aps_title_label_before' )
			->with( true, 123 )
			->reply( false ); // Label after title

		\WP_Mock::onFilter( 'aps_title_separator' )
			->with( ' - ', 123 )
			->reply( ' - ' );

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert
		$this->assertEquals( 'Test Post - Archived', $result );
	}

	/**
	 * Test uses get_the_id when post_id not provided
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_uses_get_the_id_when_post_id_not_provided() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->with( 123 )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		\WP_Mock::onFilter( 'aps_title_label' )
			->with( 'Archived', 123, 'Test Post' )
			->reply( 'Archived' );
		\WP_Mock::onFilter( 'aps_title_label_before' )
			->with( true, 123 )
			->reply( true );
		\WP_Mock::onFilter( 'aps_title_separator' )
			->with( ': ', 123 )
			->reply( ': ' );

		// Act - call without post_id parameter
		$result = $this->feature->filter_title( 'Test Post' );

		// Assert
		$this->assertEquals( 'Archived: Test Post', $result );
	}

	/**
	 * Test handles null post gracefully
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_handles_null_post_gracefully() {
		// Arrange
		\WP_Mock::userFunction( 'get_post' )->with( 123 )->andReturn( null );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert - should return unchanged title when post is null
		$this->assertEquals( 'Test Post', $result );
	}

	/**
	 * Test custom separator functionality
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_custom_separator() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->with( 123 )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		\WP_Mock::onFilter( 'aps_title_label' )->with( 'Archived', 123, 'Test Post' )->reply( 'Archived' );
		\WP_Mock::onFilter( 'aps_title_label_before' )->with( true, 123 )->reply( true );
		\WP_Mock::onFilter( 'aps_title_separator' )->with( ': ', 123 )->reply( ' | ' );

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert
		$this->assertEquals( 'Archived | Test Post', $result );
	}

	/**
	 * Test empty label handling
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_empty_label_handling() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->with( 123 )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		\WP_Mock::onFilter( 'aps_title_label' )->with( 'Archived', 123, 'Test Post' )->reply( '' );
		\WP_Mock::onFilter( 'aps_title_label_before' )->with( true, 123 )->reply( true );
		\WP_Mock::onFilter( 'aps_title_separator' )->with( ': ', 123 )->reply( ': ' );

		// Act
		$result = $this->feature->filter_title( 'Test Post', 123 );

		// Assert - should return unchanged title when label is empty
		$this->assertEquals( 'Test Post', $result );
	}

	/**
	 * Test special characters in title and label
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchiveTitle::filter_title
	 */
	public function test_special_characters_handling() {
		// Arrange
		$post = $this->createMockPost([
			'post_status' => 'archive'
		]);

		\WP_Mock::userFunction( 'get_post' )->with( 123 )->andReturn( $post );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		\WP_Mock::onFilter( 'aps_title_label' )->with( 'Archived', 123, 'Tëst Pöst & Special Chars' )->reply( 'Archivé' );
		\WP_Mock::onFilter( 'aps_title_label_before' )->with( true, 123 )->reply( true );
		\WP_Mock::onFilter( 'aps_title_separator' )->with( ': ', 123 )->reply( ': ' );

		// Act
		$result = $this->feature->filter_title( 'Tëst Pöst & Special Chars', 123 );

		// Assert
		$this->assertEquals( 'Archivé: Tëst Pöst & Special Chars', $result );
	}
}
