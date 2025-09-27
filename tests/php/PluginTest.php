<?php
/**
 * Plugin Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Plugin
 */

/**
 * Plugin test case
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Plugin
 */
class PluginTest extends TestCase {

	/**
	 * Plugin instance
	 *
	 * @var ArchivedPostStatus\Plugin
	 */
	protected $plugin;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->plugin = new ArchivedPostStatus\Plugin( 'archived-post-status', '0.4.0' );
	}

	/**
	 * Test plugin construction sets properties correctly
	 *
	 * @covers ArchivedPostStatus\Plugin::__construct
	 * @covers ArchivedPostStatus\Plugin::get_plugin_name
	 * @covers ArchivedPostStatus\Plugin::get_version
	 */
	public function test_plugin_construction() {
		// Act
		$plugin = new ArchivedPostStatus\Plugin( 'test-plugin', '1.0.0' );

		// Assert
		$this->assertEquals( 'test-plugin', $plugin->get_plugin_name() );
		$this->assertEquals( '1.0.0', $plugin->get_version() );
	}

	/**
	 * Test set_locale loads text domain
	 *
	 * @covers ArchivedPostStatus\Plugin::set_locale
	 */
	public function test_set_locale() {
		// Mock textdomain loading for internationalization
		\WP_Mock::userFunction( 'load_plugin_textdomain' )
			->with( 'archived-post-status', false, \WP_Mock\Functions::type( 'string' ) )
			->once();

		// Act
		$this->plugin->set_locale();

		// Assert - WP_Mock verifies the expectation
		$this->assertTrue( true );
	}

	/**
	 * Test query_vars adds custom variables
	 *
	 * @covers ArchivedPostStatus\Plugin::query_vars
	 */
	public function test_query_vars() {
		// Arrange
		$vars = [
			'p',
			'page_id'
		];

		// Act
		$result = $this->plugin->query_vars( $vars );

		// Assert
		$this->assertContains( 'archived', $result );
		$this->assertContains( 'unarchived', $result );
		$this->assertContains( 'ids', $result );
		$this->assertContains( 'p', $result ); // Original vars preserved
		$this->assertContains( 'page_id', $result );
	}

	/**
	 * Test edit_screen_js enqueues script on supported post types
	 *
	 * @covers ArchivedPostStatus\Plugin::edit_screen_js
	 */
	public function test_edit_screen_js_enqueues_on_edit_screen() {
		// Arrange
		global $typenow;
		$typenow = 'post';

		// Setup standard environment for supported post type
		$this->setupStandardTestEnvironment();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->once();

		// Act
		$this->plugin->edit_screen_js( 'edit.php' );

		// Assert - WP_Mock verifies the expectation
		$this->assertTrue( true );
	}

	/**
	 * Test edit_screen_js skips unsupported post types
	 *
	 * @covers ArchivedPostStatus\Plugin::edit_screen_js
	 */
	public function test_edit_screen_js_skips_unsupported_post_types() {
		// Arrange
		global $typenow;
		$typenow = 'attachment';

		// Setup environment with excluded attachment post type
		$this->setupStandardTestEnvironment(
			[ 'get_post_types' => [ 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ] ],
			[ 'excluded_post_types_return' => [ 'attachment' ] ]
		);
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		// Act
		$this->plugin->edit_screen_js( 'edit.php' );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test edit_screen_js skips when not read-only
	 *
	 * @covers ArchivedPostStatus\Plugin::edit_screen_js
	 */
	public function test_edit_screen_js_skips_when_not_read_only() {
		// Arrange
		global $typenow;
		$typenow = 'post';

		// Setup environment for non-read-only scenario
		$this->setupStandardTestEnvironment(
			[],
			[ 'is_read_only_return' => false ]
		);
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		// Act
		$this->plugin->edit_screen_js( 'edit.php' );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test edit_screen_js skips non-edit pages
	 *
	 * @covers ArchivedPostStatus\Plugin::edit_screen_js
	 */
	public function test_edit_screen_js_skips_non_edit_pages() {
		// Arrange
		global $typenow;
		$typenow = 'post';

		// Setup standard environment (won't enqueue on non-edit pages)
		$this->setupStandardTestEnvironment();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		// Act
		$this->plugin->edit_screen_js( 'index.php' );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test plugin_screen_js skips non-plugins pages
	 *
	 * @covers ArchivedPostStatus\Plugin::plugin_screen_js
	 */
	public function test_plugin_screen_js_skips_non_plugins_pages() {
		// Arrange
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		// Act
		$this->plugin->plugin_screen_js( 'index.php' );

		// Assert
		$this->assertTrue( true );
	}

	/**
	 * Test has_archived_posts returns cached result
	 *
	 * @covers ArchivedPostStatus\Plugin::has_archived_posts
	 */
	public function test_has_archived_posts_uses_cache() {
		// Use reflection to access private method
		$reflection = new ReflectionClass( $this->plugin );
		$method = $reflection->getMethod( 'has_archived_posts' );
		$method->setAccessible( true );

		// Arrange
		\WP_Mock::userFunction( 'wp_cache_get' )
			->with( 'has_archived_posts', 'archived-post-status' )
			->andReturn( true );

		// Act
		$result = $method->invoke( $this->plugin );

		// Assert
		$this->assertTrue( $result );
	}

}
