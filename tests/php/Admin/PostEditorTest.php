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

	/**
	 * Stub the full chain `enqueue_schedule_panel()` consults whenever the
	 * current user CAN schedule a post — the branch that reaches
	 * `ScheduleOutcome::describe()` and the real cascade. Resolves to the
	 * simplest possible ResolvedRule shape ("nothing currently scheduled,
	 * nothing the cascade would currently apply"), which is sufficient for
	 * every test in this file focused on something other than the panel's
	 * own resolved-outcome content — that content is `ScheduleOutcome`'s own
	 * responsibility, covered by `ScheduleOutcomeTest`.
	 *
	 * @param int $post_id
	 */
	private function stubScheduleOutcomeAsUnscheduled( int $post_id ) {
		\WP_Mock::onFilter( 'aps_scheduled_archive_post_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( $post_id, 'category' )->andReturn( array() );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchivedPostStatus\Schedule\ScheduleMeta::META_SOURCE, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::META_DAYS, true )
			->andReturn( '' );
	}

	// The composition surface is covered by
	// `PluginTest::test_hookables_always_registers_*`, which asserts the
	// PostEditor instance actually appears in Plugin::hookables().

	/**
	 * hooks() registers the two admin actions the PostEditor needs:
	 * `admin_enqueue_scripts` (for the block-editor JS bundle) and
	 * `post_submitbox_start` (for the classic-editor archive button), each
	 * pinned in full: hook name, callback, priority, and accepted args.
	 *
	 * Replaces the prior smoke `test_hooks_returns_hookable_descriptors`
	 *  which only asserted the array was non-empty.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::hooks
	 */
	public function test_hooks_registers_enqueue_scripts_and_submitbox_actions() {
		$hooks = $this->post_editor->hooks();

		$this->assertCount( 2, $hooks );

		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'admin_enqueue_scripts', $hooks[0]->hook );
		$this->assertSame( array( $this->post_editor, 'enqueue_scripts' ), $hooks[0]->callback );
		$this->assertSame( 10, $hooks[0]->priority );
		$this->assertSame( 1, $hooks[0]->accepted_args );

		$this->assertSame( 'action', $hooks[1]->type );
		$this->assertSame( 'post_submitbox_start', $hooks[1]->hook );
		$this->assertSame( array( $this->post_editor, 'post_submitbox_archive_button' ), $hooks[1]->callback );
		$this->assertSame( 10, $hooks[1]->priority );
		$this->assertSame( 1, $hooks[1]->accepted_args );
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
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
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
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
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

	/**
	 * Regression: the post-type gate must be checked BEFORE the
	 * capability check — mirroring RowActionPolicy::for_post() and
	 * ArchivePostLink::build(), both of which reject an unsupported post
	 * type without ever consulting the archive capability. Prior to the
	 * fix, this method gated only on capability, so the classic-editor
	 * Archive button rendered for post types the plugin does not support.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::post_submitbox_archive_button
	 */
	public function test_post_submitbox_archive_button_emits_nothing_for_unsupported_post_type() {
		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'attachment' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'attachment' )->andReturn( false );

		// The capability check and the render must never run once the
		// post-type gate rejects — proves the ordering, not just the outcome.
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->never();
		\WP_Mock::userFunction( 'esc_url' )->never();

		ob_start();
		$this->post_editor->post_submitbox_archive_button();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	// Archived-post access enforcement lives on PostEditorGuard; see
	// tests/php/Admin/PostEditorGuardTest.php.

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
		// EditorContext falls back to get_post()/use_block_editor_for_post()
		// when there is no screen; null is never a WP_Post, so this reads as
		// "block editor" without needing use_block_editor_for_post() at all.
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 7 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )
			->with( 7 )
			->andReturn( 'http://example.com/wp-admin/post.php?post=7&action=archive&_wpnonce=abc' );

		$this->stubScheduleOutcomeAsUnscheduled( 7 );

		$deps_by_handle = array();
		\WP_Mock::userFunction( 'wp_enqueue_script' )
			->times( 2 )
			->andReturnUsing(
				static function ( $handle, $src, $script_deps ) use ( &$deps_by_handle ) {
					$deps_by_handle[ $handle ] = $script_deps;
				}
			);

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->assertSame(
			array( 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-i18n' ),
			$deps_by_handle['aps-block-editor']
		);
		$this->assertSame(
			array( 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-data', 'wp-i18n' ),
			$deps_by_handle['aps-schedule-panel']
		);
	}

	/**
	 * enqueue_scripts() bails before touching the editor-context or
	 * capability boundary when the current admin hook isn't the post
	 * editor — the block-editor bundle must not load on unrelated admin
	 * screens.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_does_nothing_on_unrelated_admin_hook() {
		\WP_Mock::userFunction( 'get_current_screen' )->never();
		\WP_Mock::userFunction( 'is_plugin_active' )->never();
		\WP_Mock::userFunction( 'get_the_ID' )->never();
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();
		\WP_Mock::userFunction( 'wp_localize_script' )->never();

		$this->post_editor->enqueue_scripts( 'edit.php' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * enqueue_scripts() bails when the classic editor is active — the
	 * classic-editor submit-box button (post_submitbox_archive_button())
	 * is the archive entry point there, not the block-editor bundle.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_does_nothing_when_classic_editor_is_active() {
		// No screen, so EditorContext falls back to core's own question.
		$post     = \Mockery::mock( 'WP_Post' );
		$post->ID = 99;
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'use_block_editor_for_post' )
			->with( $post )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( true )->reply( true );

		\WP_Mock::userFunction( 'get_the_ID' )->never();
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();
		\WP_Mock::userFunction( 'wp_localize_script' )->never();

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Regression: enqueue_scripts() bails for a post type the plugin
	 * does not support — assets/js/block-editor.js's docblock already
	 * documents "the server side (Admin\PostEditor) enqueues this for
	 * supported post types" as the contract; prior to the fix nothing
	 * actually enforced it, so the block-editor bundle (and a working,
	 * capability-gated archiveUrl) loaded on every post type regardless of
	 * plugin support. Mirrors
	 * test_post_submitbox_archive_button_emits_nothing_for_unsupported_post_type.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_does_nothing_for_unsupported_post_type() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'attachment' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'attachment' )->andReturn( false );

		// The capability check and both script calls must never run once
		// the post-type gate rejects.
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();
		\WP_Mock::userFunction( 'wp_localize_script' )->never();

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Denied-capability branch of enqueue_scripts(): `ArchivePostLink::build()`
	 * no longer checks capability itself, so a user who cannot archive must
	 * not receive a working, nonce-signed `archiveUrl` in the localized
	 * script data — that data is serialized straight into the page source
	 * via `wp_localize_script()`, readable by anyone who can view source,
	 * regardless of what the block-editor JS does with `canArchive`
	 * client-side. This is the safety net for that gate: asserts both that
	 * `aps_get_archive_post_link()` never runs on the denied branch (mirrors
	 * `test_post_submitbox_archive_button_emits_nothing_when_user_cannot_archive`'s
	 * `->never()` pattern) and that the localized `archiveUrl` value itself
	 * is `false`.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_omits_archive_url_when_user_cannot_archive() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 7 )->andReturn( false );

		// Must never run on the denied branch — a call here would mean a
		// working URL was built regardless of the gate.
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->never();

		// The denied branch short-circuits before schedulable_post_types_includes()
		// or the resolved-outcome/cascade reads ever run — see
		// enqueue_schedule_panel()'s own short-circuiting `&&` — so no
		// further stubbing is needed for the schedule panel's own script.
		\WP_Mock::userFunction( 'wp_enqueue_script' )->twice();

		$localized = array();
		\WP_Mock::userFunction( 'wp_localize_script' )
			->twice()
			->andReturnUsing(
				static function ( $handle, $object_name, $l10n ) use ( &$localized ) {
					$localized[ $object_name ] = $l10n;
				}
			);

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->assertFalse(
			$localized['archivedPostStatus']['archiveUrl'],
			'archiveUrl must not be a usable URL when the user cannot archive'
		);
		$this->assertFalse( $localized['archivedPostStatus']['canArchive'] );

		$this->assertFalse(
			$localized['archivedPostStatusSchedule']['canSchedule'],
			'The schedule panel must also be disabled for a user who cannot archive this post'
		);
		$this->assertSame( '', $localized['archivedPostStatusSchedule']['outcome'] );
		$this->assertSame( '', $localized['archivedPostStatusSchedule']['daysStatusText'] );
	}

	/**
	 * The schedule panel's own localized data, for a user who CAN schedule:
	 * `canSchedule` true, the resolved-outcome text from
	 * `ScheduleOutcome::describe()`, and the panel disabled entirely when
	 * `aps_scheduled_archive_post_types` excludes this post's type.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_localizes_schedule_panel_data_when_user_can_schedule() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 7 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->with( 7 )->andReturn( 'http://example.com/archive' );

		$this->stubScheduleOutcomeAsUnscheduled( 7 );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->twice();

		$localized = array();
		\WP_Mock::userFunction( 'wp_localize_script' )
			->twice()
			->andReturnUsing(
				static function ( $handle, $object_name, $l10n ) use ( &$localized ) {
					$localized[ $object_name ] = $l10n;
				}
			);

		$this->post_editor->enqueue_scripts( 'post.php' );

		$schedule = $localized['archivedPostStatusSchedule'];
		$this->assertTrue( $schedule['canSchedule'] );
		$this->assertSame( 'Not scheduled to archive.', $schedule['outcome'] );
		$this->assertSame( '', $schedule['exactDate'] );
		$this->assertSame( '', $schedule['days'] );
		$this->assertSame( '', $schedule['daysStatusText'] );
	}

	/**
	 * When an ancestor Locks the cascade, the panel's localized data carries
	 * the SAME "N days — locked by X" text {@see \ArchivedPostStatus\Settings\CascadeField}
	 * renders for the classic metabox — the block editor panel must not
	 * silently offer an editable input for a value that would sit inert
	 * until a later unlock activates it.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_localizes_the_locked_days_status_text_when_an_ancestor_is_locked() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 7 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->with( 7 )->andReturn( 'http://example.com/archive' );

		\WP_Mock::onFilter( 'aps_scheduled_archive_post_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ArchivedPostStatus\Schedule\ScheduleMeta::META_SOURCE, true )->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::META_DAYS, true )->andReturn( '' );

		// Locked site rule -> frozen ancestor chain (mirrors ScheduleMetaBoxTest's
		// identical fixture for PostInheritance).
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( 365 );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'locked' );
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( 7, 'category' )->andReturn( array() );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->twice();

		$localized = array();
		\WP_Mock::userFunction( 'wp_localize_script' )
			->twice()
			->andReturnUsing(
				static function ( $handle, $object_name, $l10n ) use ( &$localized ) {
					$localized[ $object_name ] = $l10n;
				}
			);

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->assertSame(
			'365 days — locked by Site default',
			$localized['archivedPostStatusSchedule']['daysStatusText']
		);
	}

	/**
	 * When an ancestor is Off, the panel's localized data carries the SAME
	 * "Hidden by X." notice text {@see \ArchivedPostStatus\Settings\CascadeField}
	 * renders for the classic metabox.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_localizes_the_off_days_status_text_when_an_ancestor_hides_it() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 7 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->with( 7 )->andReturn( 'http://example.com/archive' );

		\WP_Mock::onFilter( 'aps_scheduled_archive_post_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ArchivedPostStatus\Schedule\ScheduleMeta::META_SOURCE, true )->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::META_DAYS, true )->andReturn( '' );

		// Off site rule -> frozen (hidden) ancestor chain.
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( 180 );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'off' );
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( 7, 'category' )->andReturn( array() );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->twice();

		$localized = array();
		\WP_Mock::userFunction( 'wp_localize_script' )
			->twice()
			->andReturnUsing(
				static function ( $handle, $object_name, $l10n ) use ( &$localized ) {
					$localized[ $object_name ] = $l10n;
				}
			);

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->assertSame(
			'Hidden by Site default.',
			$localized['archivedPostStatusSchedule']['daysStatusText']
		);
	}

	/**
	 * `aps_scheduled_archive_post_types` excluding this post's type disables
	 * the panel even though the user can otherwise archive it — the same
	 * post-type opt-in {@see ArchivedPostStatus\Admin\ScheduleMetaBox} enforces.
	 *
	 * @covers ArchivedPostStatus\Admin\PostEditor::enqueue_scripts
	 */
	public function test_enqueue_scripts_disables_schedule_panel_for_a_non_schedulable_post_type() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );
		\WP_Mock::userFunction( 'is_plugin_active' )
			->with( 'classic-editor/classic-editor.php' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_is_classic_editor' )->with( false )->reply( false );

		\WP_Mock::userFunction( 'get_the_ID' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 7 )->andReturn( 'post' );
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 7 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_get_archive_post_link' )->with( 7 )->andReturn( 'http://example.com/archive' );

		// 'page' is schedulable, 'post' is not -- the opt-in list excludes it.
		\WP_Mock::onFilter( 'aps_scheduled_archive_post_types' )->with( array() )->reply( array( 'page' ) );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->twice();

		$localized = array();
		\WP_Mock::userFunction( 'wp_localize_script' )
			->twice()
			->andReturnUsing(
				static function ( $handle, $object_name, $l10n ) use ( &$localized ) {
					$localized[ $object_name ] = $l10n;
				}
			);

		$this->post_editor->enqueue_scripts( 'post.php' );

		$this->assertFalse( $localized['archivedPostStatusSchedule']['canSchedule'] );
	}
}
