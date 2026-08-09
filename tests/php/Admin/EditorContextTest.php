<?php
/**
 * Admin\EditorContext tests.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\EditorContext
 *
 * Classic-editor detection, and the `aps_is_classic_editor` filter that lets
 * other plugins trip the flag.
 *
 * Four scenarios:
 *
 *   (a) default detection true  — Classic Editor plugin active, no block
 *       editor screen.
 *   (b) default detection false — block-editor screen present.
 *   (c) filter override to true  — default would say false, filter forces true.
 *   (d) filter override to false — default would say true, filter forces false.
 */

use ArchivedPostStatus\Admin\EditorContext;

/**
 * EditorContext test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\EditorContext
 */
class EditorContextTest extends TestCase {

	/**
	 * Helper: stub a screen whose `is_block_editor()` returns the supplied flag.
	 *
	 * @param bool|null $is_block_editor `null` to stub a screen-less context
	 *                                    (`get_current_screen` returns null).
	 */
	private function stub_screen( ?bool $is_block_editor ): void {
		if ( null === $is_block_editor ) {
			\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
			return;
		}

		$screen = \Mockery::mock( 'WP_Screen' );
		$screen->shouldReceive( 'is_block_editor' )->andReturn( $is_block_editor );
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
	}

	/**
	 * (a) Default-true: a screen that is not the block editor is the classic
	 * editor, whatever turned the block editor off. Core reaches that state
	 * only when `use_block_editor_for_post()` returned false, so the Classic
	 * Editor plugin, a `use_block_editor_for_post_type` filter, and a post
	 * type without `editor` support all arrive here identically.
	 *
	 * `is_plugin_active` is asserted never to be called: probing for one
	 * plugin by name was the old detection, and it reported every other
	 * route as non-classic.
	 *
	 * @covers ArchivedPostStatus\Admin\EditorContext::is_classic_editor
	 */
	public function test_returns_true_when_screen_is_not_the_block_editor() {
		$this->stub_screen( false );
		\WP_Mock::userFunction( 'is_plugin_active' )->never();

		// Filter not registered — default flows through unchanged.
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( true )->reply( true );

		$this->assertTrue( EditorContext::is_classic_editor() );
	}

	/**
	 * Screen-less contexts fall back to the same question core asks. A post
	 * whose type has the block editor disabled is classic.
	 *
	 * @covers ArchivedPostStatus\Admin\EditorContext::is_classic_editor
	 */
	public function test_returns_true_without_a_screen_when_core_says_the_post_is_not_block_editable() {
		$post = \Mockery::mock( 'WP_Post' );
		$this->stub_screen( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'use_block_editor_for_post' )->with( $post )->andReturn( false );

		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( true )->reply( true );

		$this->assertTrue( EditorContext::is_classic_editor() );
	}

	/**
	 * The mirror of the above: screen-less, but core says the post IS block
	 * editable, so this is not the classic editor.
	 *
	 * @covers ArchivedPostStatus\Admin\EditorContext::is_classic_editor
	 */
	public function test_returns_false_without_a_screen_when_core_says_the_post_is_block_editable() {
		$post = \Mockery::mock( 'WP_Post' );
		$this->stub_screen( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'use_block_editor_for_post' )->with( $post )->andReturn( true );

		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		$this->assertFalse( EditorContext::is_classic_editor() );
	}

	/**
	 * No screen and no post — nothing to be classic about.
	 *
	 * @covers ArchivedPostStatus\Admin\EditorContext::is_classic_editor
	 */
	public function test_returns_false_without_a_screen_or_a_post() {
		$this->stub_screen( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'use_block_editor_for_post' )->never();

		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		$this->assertFalse( EditorContext::is_classic_editor() );
	}

	/**
	 * (b) Default-false: the current screen IS the block editor → returns false
	 * regardless of whether Classic Editor is installed.
	 *
	 * @covers ArchivedPostStatus\Admin\EditorContext::is_classic_editor
	 */
	public function test_returns_false_when_screen_is_block_editor() {
		$this->stub_screen( true );

		// Filter not registered — default flows through unchanged.
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		$this->assertFalse( EditorContext::is_classic_editor() );
	}

	/**
	 * (c) Filter-override to true: even when the default detection says
	 * `false` (block editor screen), the filter can force the classic-editor
	 * flag on. Models a custom-rollback plugin that wants to claim classic
	 * mode without intercepting `WP_Screen::is_block_editor()`.
	 *
	 * @covers ArchivedPostStatus\Admin\EditorContext::is_classic_editor
	 */
	public function test_filter_can_override_default_false_to_true() {
		$this->stub_screen( true );

		\WP_Mock::onFilter( 'aps_is_classic_editor' )
			->with( false )
			->reply( true );

		$this->assertTrue(
			EditorContext::is_classic_editor(),
			'aps_is_classic_editor filter must be able to flip the default-false detection to true'
		);
	}

	/**
	 * (d) Filter-override to false: even when the default detection says
	 * `true` (a screen that is not the block editor), the filter can force
	 * the classic-editor flag off. Models a site that hosts both editors and
	 * wants the plugin's classic-mode branch to stay disabled.
	 *
	 * @covers ArchivedPostStatus\Admin\EditorContext::is_classic_editor
	 */
	public function test_filter_can_override_default_true_to_false() {
		$this->stub_screen( false );

		\WP_Mock::onFilter( 'aps_is_classic_editor' )
			->with( true )
			->reply( false );

		$this->assertFalse(
			EditorContext::is_classic_editor(),
			'aps_is_classic_editor filter must be able to flip the default-true detection to false'
		);
	}
}
