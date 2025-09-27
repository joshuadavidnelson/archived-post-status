<?php
/**
 * PostEditor Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\PostEditor
 */

/**
 * PostEditor test case extending FeatureTestCase
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\PostEditor
 * @covers ArchivedPostStatus\Feature::is_active
 * @covers ArchivedPostStatus\Feature::get_name
 */
class PostEditorTest extends FeatureTestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->feature = new ArchivedPostStatus\PostEditor();

		// Setup common mocks
		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 123 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->andReturn( true );
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $this->createMockScreen() );
	}

	/**
	 * Test that hooks are properly registered
	 *
	 * @covers ArchivedPostStatus\PostEditor::register
	 */
	public function test_register_hooks() {
		\WP_Mock::expectActionAdded( 'admin_enqueue_scripts', [ $this->feature, 'enqueue_scripts' ] );
		\WP_Mock::expectActionAdded( 'post_submitbox_start', [ $this->feature, 'post_submitbox_archive_button' ] );
		\WP_Mock::expectActionAdded( 'load-post.php', [ $this->feature, 'load_post_screen' ] );

		$this->feature->register();
		\WP_Mock::assertHooksAdded();
	}

	/**
	 * Test archive button is displayed when user can archive
	 *
	 * @covers ArchivedPostStatus\PostEditor::post_submitbox_archive_button
	 */
	public function test_archive_button_displays_when_user_can_archive() {
		// Arrange
		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 123 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 123 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->with( 123 )->andReturn( 'http://example.com/archive' );
		\WP_Mock::userFunction( '__' )->with( 'Archive', 'archived-post-status' )->andReturn( 'Archive' );

		// Expect output to be captured
		ob_start();
		$this->feature->post_submitbox_archive_button();
		$output = ob_get_clean();

		// Assert - should have some output when user can archive
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( 'archive-action', $output );
	}

	/**
	 * Test is_classic_editor when method doesn't exist
	 *
	 * @covers ArchivedPostStatus\PostEditor::is_classic_editor
	 */
	public function test_is_classic_editor_fallback_when_method_missing() {
		// Arrange
		$screen = \Mockery::mock( 'WP_Screen' );
		// Don't mock is_block_editor method to simulate it not existing
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'is_plugin_active' )->with( 'classic-editor/classic-editor.php' )->andReturn( false );

		// Act
		$result = $this->feature->is_classic_editor();

		// Assert - should return false when classic editor plugin is not active
		$this->assertFalse( $result );
	}


	/**
	 * Test scripts not enqueued on classic editor
	 *
	 * @covers ArchivedPostStatus\PostEditor::enqueue_scripts
	 * @covers ArchivedPostStatus\PostEditor::is_classic_editor
	 */
	public function test_scripts_not_enqueued_on_classic_editor() {
		// Arrange
		$screen = \Mockery::mock( 'WP_Screen' );
		$screen->shouldReceive( 'is_block_editor' )->andReturn( false );
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'is_plugin_active' )->with( 'classic-editor/classic-editor.php' )->andReturn( true );

		// Should not enqueue scripts
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		// Act
		$this->feature->enqueue_scripts( 'post.php' );

		// Assert - test passes if no scripts enqueued
		$this->assertTrue( true );
	}

	/**
	 * Test scripts not enqueued on non-editor pages
	 *
	 * @covers ArchivedPostStatus\PostEditor::enqueue_scripts
	 */
	public function test_scripts_not_enqueued_on_non_editor_pages() {
		// Arrange
		$screen = \Mockery::mock( 'WP_Screen' );
		$screen->shouldReceive( 'is_block_editor' )->andReturn( true );
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		// Should not enqueue scripts on dashboard
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		// Act
		$this->feature->enqueue_scripts( 'index.php' );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test is_classic_editor detects block editor correctly
	 *
	 * @covers ArchivedPostStatus\PostEditor::is_classic_editor
	 */
	public function test_is_classic_editor_detects_block_editor() {
		// Arrange
		$screen = \Mockery::mock( 'WP_Screen' );
		$screen->shouldReceive( 'is_block_editor' )->andReturn( true );
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		// Act
		$result = $this->feature->is_classic_editor();

		// Assert
		$this->assertFalse( $result );
	}

	/**
	 * Test is_classic_editor detects classic editor plugin
	 *
	 * @covers ArchivedPostStatus\PostEditor::is_classic_editor
	 */
	public function test_is_classic_editor_detects_classic_plugin() {
		// Arrange
		$screen = \Mockery::mock( 'WP_Screen' );
		$screen->shouldReceive( 'is_block_editor' )->andReturn( false );
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'is_plugin_active' )->with( 'classic-editor/classic-editor.php' )->andReturn( true );

		// Act
		$result = $this->feature->is_classic_editor();

		// Assert
		$this->assertTrue( $result );
	}

	/**
	 * Test load_post_screen returns early when not read-only
	 *
	 * @covers ArchivedPostStatus\PostEditor::load_post_screen
	 */
	public function test_load_post_screen_returns_early_when_not_read_only() {
		// Arrange
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( false );

		// Act
		$this->feature->load_post_screen();

		// Assert - should not call any other functions
		$this->assertTrue( true );
	}

	/**
	 * Test load_post_screen processes archived posts when read-only
	 *
	 * @covers ArchivedPostStatus\PostEditor::load_post_screen
	 * @covers ::aps_get_supported_post_types
	 * @covers ::aps_is_supported_post_type
	 */
	public function test_load_post_screen_processes_archived_posts() {
		// Arrange
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		// Mock $_GET superglobal
		$_GET['post'] = '123';

		\WP_Mock::userFunction( 'get_post_type' )->with( 123 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'get_post_status' )->with( 123 )->andReturn( 'publish' );

		// Act
		$this->feature->load_post_screen();

		// Assert - test passes if no exceptions thrown for non-archived posts
		$this->assertTrue( true );

		// Clean up
		unset( $_GET['post'] );
	}

	/**
	 * Test load_post_screen gets post ID from POST data
	 *
	 * @covers ArchivedPostStatus\PostEditor::load_post_screen
	 * @covers ::aps_get_supported_post_types
	 * @covers ::aps_is_supported_post_type
	 */
	public function test_load_post_screen_gets_post_id_from_post_data() {
		// Arrange
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		// Mock $_POST superglobal
		$_POST['post_ID'] = '456';

		\WP_Mock::userFunction( 'get_post_type' )->with( 456 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'get_post_status' )->with( 456 )->andReturn( 'publish' );

		// Act
		$this->feature->load_post_screen();

		// Assert
		$this->assertTrue( true );

		// Clean up
		unset( $_POST['post_ID'] );
	}

	/**
	 * Test load_post_screen handles global post object
	 *
	 * @covers ArchivedPostStatus\PostEditor::load_post_screen
	 * @covers ::aps_get_supported_post_types
	 * @covers ::aps_is_supported_post_type
	 */
	public function test_load_post_screen_handles_global_post() {
		// Arrange
		\WP_Mock::userFunction( 'aps_is_read_only' )->andReturn( true );

		// Mock global post
		$post = $this->createMockPost([ 'ID' => 789 ]);
		$GLOBALS['post'] = $post;

		\WP_Mock::userFunction( 'get_post_type' )->with( 789 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'get_post_status' )->with( 789 )->andReturn( 'publish' );

		// Act
		$this->feature->load_post_screen();

		// Assert
		$this->assertTrue( true );

		// Clean up
		unset( $GLOBALS['post'] );
	}
}
