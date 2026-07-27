<?php
/**
 * PluginScreen Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PluginScreen
 */

/**
 * PluginScreen test case
 *
 * Tests the deactivation-warning script enqueue on the Plugins screen.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\PluginScreen
 */
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

class PluginScreenTest extends TestCase {

	use BoundaryStubs;

	/**
	 * PluginScreen instance
	 *
	 * @var ArchivedPostStatus\Admin\PluginScreen
	 */
	protected $plugin_screen;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->plugin_screen = new ArchivedPostStatus\Admin\PluginScreen();
	}

	/**
	 * hooks() registers a single `admin_enqueue_scripts` action. Pinning the
	 * hook name is the strongest contract — a typo silently drops the
	 * deactivation warning entirely.
	 *
	 * @covers ArchivedPostStatus\Admin\PluginScreen::hooks
	 */
	public function test_hooks_registers_admin_enqueue_scripts_action() {
		$hooks = $this->plugin_screen->hooks();

		$this->assertCount( 1, $hooks );
		$this->assertSame( 'admin_enqueue_scripts', $hooks[0]->hook );
		$this->assertSame( 'action', $hooks[0]->type );
	}

	/**
	 * On the Plugins screen (`plugins.php`) with archived content present,
	 * `enqueue_scripts()` enqueues the script (with the `wp-i18n` dependency
	 * the JS needs for its confirm() message), wires up script translations,
	 * and localizes `archivedPostStatus.hasArchivedPosts` as `true`.
	 *
	 * @covers ArchivedPostStatus\Admin\PluginScreen::enqueue_scripts
	 */
	public function test_enqueue_scripts_enqueues_and_localizes_true_when_archived_posts_exist() {
		\WP_Mock::userFunction( 'get_posts' )
			->once()
			->with(
				array(
					'post_status'    => 'archive',
					'post_type'      => array( 'post', 'page' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
			->andReturn( array( 42 ) );

		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		$enqueued = null;
		\WP_Mock::userFunction( 'wp_enqueue_script' )
			->once()
			->andReturnUsing(
				function ( $handle, $src, $deps, $ver, $in_footer ) use ( &$enqueued ) {
					$enqueued = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
				}
			);

		\WP_Mock::userFunction( 'wp_set_script_translations' )
			->once()
			->with( 'aps-plugin-screen', 'archived-post-status', \WP_Mock\Functions::type( 'string' ) );

		$localized = null;
		\WP_Mock::userFunction( 'wp_localize_script' )
			->once()
			->andReturnUsing(
				function ( $handle, $object_name, $l10n ) use ( &$localized ) {
					$localized = compact( 'handle', 'object_name', 'l10n' );
				}
			);

		$this->plugin_screen->enqueue_scripts( 'plugins.php' );

		$this->assertSame( 'aps-plugin-screen', $enqueued['handle'] );
		$this->assertSame( ARCHIVED_POST_STATUS_URL . 'assets/js/plugin-screen.js', $enqueued['src'] );
		$this->assertSame( array( 'wp-i18n' ), $enqueued['deps'] );
		$this->assertSame( ARCHIVED_POST_STATUS_VERSION, $enqueued['ver'] );
		$this->assertTrue( $enqueued['in_footer'] );

		$this->assertSame( 'aps-plugin-screen', $localized['handle'] );
		$this->assertSame( 'archivedPostStatus', $localized['object_name'] );
		$this->assertTrue( $localized['l10n']['hasArchivedPosts'] );
	}

	/**
	 * Mirror: with no archived content, `hasArchivedPosts` localizes as
	 * `false` — the JS reads this flag to skip the confirm() prompt
	 * entirely, so a false positive here would nag every site with no
	 * archived content.
	 *
	 * @covers ArchivedPostStatus\Admin\PluginScreen::enqueue_scripts
	 */
	public function test_enqueue_scripts_localizes_false_when_no_archived_posts_exist() {
		\WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array() );
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->once();
		\WP_Mock::userFunction( 'wp_set_script_translations' )->once();

		$localized = null;
		\WP_Mock::userFunction( 'wp_localize_script' )
			->once()
			->andReturnUsing(
				function ( $handle, $object_name, $l10n ) use ( &$localized ) {
					$localized = $l10n;
				}
			);

		$this->plugin_screen->enqueue_scripts( 'plugins.php' );

		$this->assertFalse( $localized['hasArchivedPosts'] );
	}

	/**
	 * On any screen other than `plugins.php`, `enqueue_scripts()` must be a
	 * silent no-op — including skipping the `get_posts()` existence check,
	 * which would otherwise run an unnecessary query on every admin page
	 * load.
	 *
	 * @covers ArchivedPostStatus\Admin\PluginScreen::enqueue_scripts
	 */
	public function test_enqueue_scripts_skips_on_non_plugins_php_hook() {
		\WP_Mock::userFunction( 'get_posts' )->never();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();
		\WP_Mock::userFunction( 'wp_localize_script' )->never();
		\WP_Mock::userFunction( 'wp_set_script_translations' )->never();

		$this->plugin_screen->enqueue_scripts( 'edit.php' );

		$this->addToAssertionCount( 1 );
	}
}
