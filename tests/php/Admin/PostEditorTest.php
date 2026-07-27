<?php
/**
 * PostEditor Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostEditor
 */

/**
 * PostEditor test case
 *
 * Tests the post editor functionality.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\PostEditor
 */
class PostEditorTest extends TestCase {

	/**
	 * PostEditor instance
	 *
	 * @var ArchivedPostStatus\Admin\PostEditor
	 */
	protected $post_editor;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->post_editor = new ArchivedPostStatus\Admin\PostEditor();
	}

	// the prior `test_hooks_returns_hookable_descriptors` smoke
	// test was deleted — `assertIsArray`/`assertNotEmpty` doesn't pin any
	// behavior. The composition surface is now covered end-to-end by
	// `PluginTest::test_hookables_always_registers_*` which asserts the
	// PostEditor instance actually appears in Plugin::hookables().

	/**
	 * hooks() registers the two admin actions the PostEditor needs:
	 * `admin_enqueue_scripts` (for the block-editor JS bundle) and
	 * `post_submitbox_start` (for the classic-editor archive button).
	 * Pinning the hook names is the strongest contract — a typo silently
	 * drops the integration.
	 *
	 * Replaces the prior smoke `test_hooks_returns_hookable_descriptors`
	 *  which only asserted the array was non-empty.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::hooks
	 */
	public function test_hooks_registers_enqueue_scripts_and_submitbox_actions() {
		$hooks = $this->post_editor->hooks();

		$this->assertCount( 2, $hooks );
		$hook_names = array_map( static fn( $h ) => $h->hook, $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hook_names );
		$this->assertContains( 'post_submitbox_start', $hook_names );

		foreach ( $hooks as $hook ) {
			$this->assertSame( 'action', $hook->type, 'PostEditor hooks must be actions' );
		}
	}

	/**
	 * post_submitbox_archive_button() prints an `<a class="submitdelete
	 * deletion">` linking to the per-post archive URL. The href value is
	 * load-bearing: it's what classic-editor users click to send a post
	 * to the archive. Asserting on the anchor structure pins both the
	 * presence of the button and that aps_get_archive_post_link() routed
	 * through the esc_url + render pipeline.
	 *
	 * Replaces the prior `assertIsString($output)` smoke test :
	 * that earlier assertion fired even when production emitted an empty
	 * string or a malformed tag.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::post_submitbox_archive_button
	 */
	public function test_post_submitbox_archive_button_renders_anchor_to_archive_url() {
		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )
			->with( 7 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )
			->with( 7 )
			->andReturn( 'http://example.com/wp-admin/post.php?post=7&action=archive&_wpnonce=abc' );
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn( $url ) => (string) $url );
		\WP_Mock::userFunction( 'esc_html__' )
			->with( 'Archive', 'archived-post-status' )
			->andReturn( 'Archive' );

		ob_start();
		$this->post_editor->post_submitbox_archive_button();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output, 'Button must render when the user can archive' );

		// Parse the emitted markup and assert on the anchor element
		// rather than substring-matching the literal classnames the
		// production code emits.
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $output, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$anchors = $doc->getElementsByTagName( 'a' );
		$this->assertSame( 1, $anchors->length, 'Exactly one anchor element should be rendered' );

		$anchor = $anchors->item( 0 );
		$this->assertSame(
			'http://example.com/wp-admin/post.php?post=7&action=archive&_wpnonce=abc',
			$anchor->getAttribute( 'href' ),
			'Button href must match the per-post archive URL from aps_get_archive_post_link()'
		);
		$this->assertSame( 'Archive', trim( $anchor->textContent ) );
	}

	/**
	 * Mirror branch: when the user does NOT have archive capability,
	 * post_submitbox_archive_button() emits nothing. The render must not
	 * leak the partial button (the early return is the protection
	 * mechanism for unauthorized users seeing a non-functional control).
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::post_submitbox_archive_button
	 */
	public function test_post_submitbox_archive_button_emits_nothing_when_user_cannot_archive() {
		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )
			->with( 7 )->andReturn( false );

		// These must never run on the early-return branch.
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->never();
		\WP_Mock::userFunction( 'esc_url' )->never();

		ob_start();
		$this->post_editor->post_submitbox_archive_button();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	// NOTE (0.4.0 §1.1): test_load_post_screen() was deleted from this file.
	// load_post_screen() no longer exists on PostEditor — the
	// archived-post access-enforcement responsibility moved to
	// Admin\PostEditorGuard::enforce_read_only(). The scenario is now
	// covered in tests/php/Admin/PostEditorGuardTest.php.

	/**
	 * enqueue_scripts() registers the 'aps-block-editor' handle with
	 * exactly the script modules assets/js/block-editor.js calls at
	 * runtime: `wp.element.createElement`, `wp.plugins.registerPlugin`,
	 * `wp.editPost.PluginPostStatusInfo`, and `wp.i18n.__` require
	 * `wp-element`, `wp-plugins`, `wp-edit-post`, and `wp-i18n`
	 * respectively. The previous `wp-blocks`, `wp-dom-ready`, `wp-hooks`
	 * handles are dropped — block-editor.js calls none of
	 * wp.blocks/wp.domReady/wp.hooks, and WP core's own `wp-edit-post`
	 * handle already carries whatever transitive dependencies it needs. A
	 * missing dependency here means the bundle can throw a ReferenceError
	 * before the script boots; a stale one means dead weight loads on
	 * every post editor screen for nothing.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_registers_block_editor_script_with_required_dependencies() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 7 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )
			->with( 7 )
			->andReturn( 'http://example.com/wp-admin/post.php?post=7&action=archive&_wpnonce=abc' );

		$deps = null;
		\WP_Mock::userFunction( 'wp_enqueue_script' )
			->once()
			->andReturnUsing(
				static function ( $handle, $src, $script_deps ) use ( &$deps ) {
					$deps = $script_deps;
				}
			);

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->assertSame(
			array( 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-i18n' ),
			$deps
		);
	}
}
