<?php
/**
 * Notices Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\Notices
 *
 * Merge note (0.4.0 §1.1):
 *   This file consumes the meaningful scenarios from the old
 *   AdminNoticesTest.php (which has been deleted). Specifically:
 *     - `test_register_hooks` was folded into the stronger
 *       `test_hooks_registers_admin_notices_action` below.
 *     - `test_notices_without_query_vars` and `test_notices_wrong_screen_base`
 *       were brought over because they assert observable behavior
 *       (wp_admin_notice must NOT fire).
 *   The remaining tests in AdminNoticesTest were `assertTrue(true)`
 *   smoke checks whose archive/unarchive scenarios are already covered
 *   here by the `test_archive_notice_includes_undo_link_*` tests, which
 *   assert on rendered output. Dropped without loss.
 */

/**
 * Notices test case
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\Notices
 */
class NoticesTest extends TestCase {

	/**
	 * Notices instance
	 *
	 * @var ArchivedPostStatus\Admin\Notices
	 */
	protected $notices;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->notices = new ArchivedPostStatus\Admin\Notices( new ArchivedPostStatus\Admin\NoticeBuilder() );
	}

	/**
	 * hooks() returns a single descriptor binding `admin_notices` as an
	 * action, pinned in full: hook name, callback, priority, and accepted
	 * args.
	 *
	 * Merged from AdminNoticesTest::test_register_hooks — that version checked
	 * hook name and type, whereas the previous test_hooks_returns_hookable_descriptors
	 * only checked that descriptors existed. Keeping the stronger assertions.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::hooks
	 */
	public function test_hooks_registers_admin_notices_action() {
		$hooks = $this->notices->hooks();

		$this->assertIsArray( $hooks );
		$this->assertCount( 1, $hooks );
		$this->assertInstanceOf( ArchivedPostStatus\Hooks\HookDescriptor::class, $hooks[0] );
		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'admin_notices', $hooks[0]->hook );
		$this->assertSame( array( $this->notices, 'display_notices' ), $hooks[0]->callback );
		$this->assertSame( 10, $hooks[0]->priority );
		$this->assertSame( 1, $hooks[0]->accepted_args );
	}

	/**
	 * display_notices() short-circuits silently on screens that are not the
	 * `edit` base (e.g. dashboard, plugins) — wp_admin_notice must NEVER fire.
	 *
	 * Migrated from AdminNoticesTest::test_notices_wrong_screen_base; the
	 * `never()` expectation makes this a real behavior test rather than
	 * an assertTrue(true) smoke check.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_display_notices_returns_silently_on_non_edit_screen() {
		$screen       = new \stdClass();
		$screen->base = 'dashboard';

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		$this->notices->display_notices();

		// WP_Mock verifies the never() expectation during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * display_notices() does not emit a notice when no query vars are set
	 * (no archived/unarchived/locked counters).
	 *
	 * Migrated from AdminNoticesTest::test_notices_without_query_vars.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_display_notices_emits_nothing_without_query_vars() {
		$screen            = new \stdClass();
		$screen->base      = 'edit';
		$screen->post_type = 'post';

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'get_query_var' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		global $post_type;
		$post_type = 'post';

		$this->notices->display_notices();

		// WP_Mock verifies the never() expectation during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Common WP mocks for the archive-notice path. Pinning these once per
	 * test cuts ~15 nearly-identical \WP_Mock::userFunction() lines per
	 * scenario and gives the assertion section room to focus on the
	 * structural-output contract.
	 *
	 * `wp_nonce_url` returns the URL with a sentinel `_wpnonce` query arg
	 * appended so structural assertions can verify the production code
	 * actually routed through wp_nonce_url() rather than the raw admin URL.
	 *
	 * @param string $archived_count  Value the `archived` query var returns.
	 * @param string $ids             Value the `ids` query var returns (CSV form).
	 */
	private function mockUndoNoticeFixture( string $archived_count, string $ids ): void {
		\WP_Mock::userFunction( 'get_current_screen' )
			->andReturn(
				(object) array(
					'id'        => 'edit-post',
					'base'      => 'edit',
					'post_type' => 'post',
				)
			);

		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'archived', false )->andReturn( $archived_count );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'ids', false )->andReturn( $ids );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'unarchived', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'locked', false )->andReturn( false );
		// Skip-reason buckets — NoticeBuilder reads them too.
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'denied', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'not_found', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'wrong_status', false )->andReturn( false );

		\WP_Mock::userFunction( '_n' )->andReturnUsing(
			static function ( $singular, $plural, $count ) {
				return 1 === (int) $count ? $singular : $plural;
			}
		);
		\WP_Mock::userFunction( 'number_format_i18n' )
			->andReturnUsing( static fn( $n ) => (string) $n );

		// Return the admin URL untouched so the production code's
		// edit.php?... query string survives all the way to the rendered
		// markup. wp_nonce_url appends &_wpnonce=test so structural
		// assertions can verify the nonce hop fired.
		\WP_Mock::userFunction( 'admin_url' )
			->andReturnUsing( static fn( $path ) => 'http://example.com/wp-admin/' . ltrim( (string) $path, '/' ) );
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=test' );

		\WP_Mock::userFunction( '__' )
			->with( 'Undo', 'archived-post-status' )->andReturn( 'Undo' );

		global $post_type;
		$post_type = 'post';
	}

	/**
	 * Extract the anchor element from the captured notice HTML.
	 *
	 * Uses DOMDocument because the production code emits both the wp_admin_notice
	 * fallback wrapper (`<div ...><p>...</p></div>`) AND nests our Undo link
	 * inside that wrapper. We need the `<a>` node specifically — string
	 * assertions on the rendered output couldn't tell "an anchor was
	 * rendered with this href" from "the substring happens to appear in
	 * the wrapping markup".
	 *
	 * @return \DOMElement|null Anchor element or null if none found.
	 */
	private function extractAnchor( string $html ): ?\DOMElement {
		$doc = new \DOMDocument();
		// `wp_admin_notice` (mocked in includes/common.php) escapes the
		// outer message body via esc_html, but the production code's
		// fallback branch in render_notices() uses wp_kses_post which keeps
		// the anchor. The fallback wp_admin_notice in common.php is the
		// one we hit (production calls it via function_exists), and the
		// captured HTML for our purposes is the inner notice div.
		// Suppress libxml errors — partial HTML fragments are normal.
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$anchors = $doc->getElementsByTagName( 'a' );
		return $anchors->length > 0 ? $anchors->item( 0 ) : null;
	}

	/**
	 * Extract the `ids` query arg value from the undo URL's href.
	 *
	 * Parses with parse_url + parse_str so the assertion survives
	 * insignificant URL shape changes (different param ordering, optional
	 * encoding) — only the semantically-meaningful query data matters.
	 *
	 * @return string Comma-separated ids, or '' if missing.
	 */
	private function extractIdsArg( string $url ): string {
		$query = parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return '';
		}
		parse_str( $query, $parts );
		return isset( $parts['ids'] ) ? (string) $parts['ids'] : '';
	}

	/**
	 * Single-post archive flow: the success notice includes an Undo anchor
	 * routed through wp_nonce_url and carrying the archived post id in the
	 * `ids` query arg.
	 *
	 * Structural assertion : rather than substring-matching the
	 * production code's emitted markup, parse the captured HTML and assert
	 * on (a) presence of an `<a>` element, (b) its href passing through
	 * wp_nonce_url (`_wpnonce` query arg present from the fixture's stub),
	 * (c) `ids=123` carried through. Survives markup tweaks — the prior
	 * `assertStringContainsString( 'undo-url' )` style broke on every
	 * cosmetic change.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_archive_notice_renders_undo_anchor_with_single_post_id() {
		$this->mockUndoNoticeFixture( '1', '123' );

		ob_start();
		$this->notices->display_notices();
		$output = ob_get_clean();

		$anchor = $this->extractAnchor( $output );
		$this->assertNotNull( $anchor, 'Undo notice must render an <a> element' );

		$this->assertSame( 'Undo', trim( $anchor->textContent ) );

		$href = $anchor->getAttribute( 'href' );
		$this->assertStringContainsString( '_wpnonce=test', $href, 'Undo URL must be routed through wp_nonce_url' );
		$this->assertStringContainsString( 'doaction=undo', $href, 'Undo URL must carry the bulk doaction=undo signal' );
		$this->assertStringContainsString( 'action=unarchive', $href, 'Undo URL must point at the unarchive bulk action' );

		$this->assertSame( '123', $this->extractIdsArg( $href ), 'Undo URL must carry the archived post id in ?ids' );
	}

	/**
	 * Bulk-archive flow: the success notice's Undo anchor carries the full
	 * CSV id list, not just the first id.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_archive_notice_renders_undo_anchor_with_bulk_id_list() {
		$this->mockUndoNoticeFixture( '3', '123,456,789' );

		ob_start();
		$this->notices->display_notices();
		$output = ob_get_clean();

		$anchor = $this->extractAnchor( $output );
		$this->assertNotNull( $anchor, 'Undo notice must render an <a> element' );

		$href = $anchor->getAttribute( 'href' );
		$this->assertStringContainsString( '_wpnonce=test', $href );

		$this->assertSame(
			'123,456,789',
			$this->extractIdsArg( $href ),
			'Bulk-archive Undo URL must carry the full CSV id list, not just the first id'
		);
	}

	// -----------------------------------------------------------------------
	// locked / unarchive notice branches
	// -----------------------------------------------------------------------
	//
	// build_notices() produces several kinds of notice: archived, unarchived,
	// and locked (plus the reason buckets covered in NoticeBuilderTest).
	// The existing test_archive_notice_* tests cover the first one. The
	// tests below pin the other branches so a regression in the counter
	// pluralization or the message string surfaces in CI.

	/**
	 * When the `locked` query var is set (one or more posts were skipped
	 * during a bulk archive because someone else was editing them), the
	 * notice mentions "not archived" + the count via the `_n` plural form.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_notice_for_locked_posts_includes_count_and_locked_message() {
		\WP_Mock::userFunction( 'get_current_screen' )
			->andReturn( (object) array( 'base' => 'edit', 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'archived', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'unarchived', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'locked', false )->andReturn( '2' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'denied', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'not_found', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'wrong_status', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'ids', false )->andReturn( false );

		\WP_Mock::userFunction( '_n' )->andReturn( '2 posts not archived, somebody is editing them.' );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturn( '2' );

		global $post_type;
		$post_type = 'post';

		ob_start();
		$this->notices->display_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'not archived', $output );
		$this->assertStringContainsString( 'somebody is editing', $output );
	}

	/**
	 * Single-post unarchive flow: the success notice includes an edit
	 * link back to the now-restored post, since the user is likely to
	 * want to keep editing it. The link only appears for single-id
	 * unarchives (count of 1).
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_unarchive_notice_includes_edit_link_for_single_post() {
		\WP_Mock::userFunction( 'get_current_screen' )
			->andReturn( (object) array( 'base' => 'edit', 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'archived', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'unarchived', false )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'locked', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'denied', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'not_found', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'wrong_status', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'ids', false )->andReturn( '123' );

		\WP_Mock::userFunction( '_n' )
			->andReturn( '1 post restored from the Archive.' );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturn( '1' );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_post', 123 )->andReturn( true );
		\WP_Mock::userFunction( 'get_post_type' )
			->with( 123 )->andReturn( 'post' );

		$post_type_object                  = new \stdClass();
		$post_type_object->labels          = new \stdClass();
		$post_type_object->labels->edit_item = 'Edit Post';
		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'post' )->andReturn( $post_type_object );

		\WP_Mock::userFunction( 'get_edit_post_link' )
			->with( 123 )->andReturn( 'http://example.com/wp-admin/post.php?post=123&action=edit' );

		global $post_type;
		$post_type = 'post';

		ob_start();
		$this->notices->display_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'restored from the Archive', $output );
		$this->assertStringContainsString( 'Edit Post', $output );
		$this->assertStringContainsString( 'post=123', $output );
	}

	// -----------------------------------------------------------------------
	// parse_ids() — moved to NoticeBuilderTest
	// -----------------------------------------------------------------------
	//
	// the 0.4.0 refactor extracted parse_ids() onto
	// the new NoticeBuilder class as a public method. The reflection-based
	// data-provider that used to live here is now in NoticeBuilderTest, no
	// longer reflection-based.

	// -----------------------------------------------------------------------
	// allowed-base list (should_show_notices())
	// -----------------------------------------------------------------------
	//
	// The allowed-screen-base list is now an explicit class constant
	// (Notices::ALLOWED_SCREEN_BASES). The three tests below pin the
	// behavior:
	//   1. should_show_notices() returns true for every base in the list.
	//   2. should_show_notices() returns false for bases that are NOT in
	//      the list (regression guard).
	//   3. The redirect contract: BulkActionHandler / PostEditorGuard both
	//      land on a screen whose base is in the allowed list. That guard
	//      is what makes the entire bulk + post-action notice path visible.
	//
	// should_show_notices() is private, so the tests reach it via
	// reflection (the same pattern PluginTest uses for hookables()).

	/**
	 * Invoke the private Notices::should_show_notices() method via reflection.
	 *
	 * @return bool
	 */
	private function invoke_should_show_notices(): bool {
		$method = new ReflectionMethod( ArchivedPostStatus\Admin\Notices::class, 'should_show_notices' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $this->notices );
	}

	/**
	 * Provider — every entry in Notices::ALLOWED_SCREEN_BASES must return
	 * true from should_show_notices(). Sourced from the constant via
	 * reflection so the test stays honest when the constant grows.
	 *
	 * @return iterable<string, array{0: string}>
	 */
	public function allowed_screen_bases_provider(): iterable {
		$reflection = new ReflectionClass( ArchivedPostStatus\Admin\Notices::class );
		$bases      = $reflection->getReflectionConstant( 'ALLOWED_SCREEN_BASES' )->getValue();
		foreach ( $bases as $base ) {
			yield $base => array( $base );
		}
	}

	/**
	 * Every screen base that the class declares allowed must return true
	 * from should_show_notices(). If the constant grows, this data-driven
	 * test grows with it automatically.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::should_show_notices
	 * @dataProvider allowed_screen_bases_provider
	 */
	public function test_should_show_notices_returns_true_for_each_allowed_base( string $base ) {
		$screen       = new \stdClass();
		$screen->base = $base;

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		$this->assertTrue(
			$this->invoke_should_show_notices(),
			sprintf( 'Screen base %s is in ALLOWED_SCREEN_BASES; should_show_notices() must return true', $base )
		);
	}

	/**
	 * Regression guard: any screen base that is NOT in the allowed list
	 * must return false. Two non-trivial bases sampled here — `dashboard`
	 * (admin home) and `options-general` (settings). If either of these
	 * silently flips to "allowed," the notice surface starts leaking onto
	 * unrelated admin pages.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::should_show_notices
	 * @testWith ["dashboard"]
	 *           ["options-general"]
	 */
	public function test_should_show_notices_returns_false_for_disallowed_bases( string $base ) {
		$screen       = new \stdClass();
		$screen->base = $base;

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		$this->assertFalse(
			$this->invoke_should_show_notices(),
			sprintf( 'Screen base %s is NOT in ALLOWED_SCREEN_BASES; should_show_notices() must return false', $base )
		);
	}

	/**
	 * Redirect-contract pin: both
	 * {@see ArchivedPostStatus\Admin\BulkActionHandler::get_redirect_url} and
	 * {@see ArchivedPostStatus\Admin\PostEditorGuard::redirect_to_list} send
	 * the user to a URL whose admin screen has base `edit`, so `edit` must
	 * stay in ALLOWED_SCREEN_BASES.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::should_show_notices
	 */
	public function test_edit_base_remains_allowed_to_preserve_bulk_and_editor_redirect_notice_contract() {
		$reflection = new ReflectionClass( ArchivedPostStatus\Admin\Notices::class );
		$bases      = $reflection->getReflectionConstant( 'ALLOWED_SCREEN_BASES' )->getValue();

		$this->assertContains(
			'edit',
			$bases,
			'Notices::ALLOWED_SCREEN_BASES must include `edit` — BulkActionHandler::get_redirect_url() '
				. 'and PostEditorGuard::redirect_to_list() both send users to edit.php (screen base `edit`). '
				. 'Removing `edit` from the allowed list silently breaks the post-action and bulk-action '
				. 'success notices.'
		);
	}

	/**
	 * Companion to the constant pin above: an `edit` screen actually does
	 * return true from should_show_notices(). The constant pin alone
	 * doesn't prove the runtime path uses it; this exercises the runtime
	 * surface with a stub `edit` screen.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::should_show_notices
	 */
	public function test_should_show_notices_returns_true_on_edit_screen_satisfying_redirect_contract() {
		$screen       = new \stdClass();
		$screen->base = 'edit';

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );

		$this->assertTrue( $this->invoke_should_show_notices() );
	}

	/**
	 * Defensive: when get_current_screen() returns null (e.g. an
	 * extremely-early admin_notices fire before WP has set the screen),
	 * should_show_notices() must short-circuit false instead of throwing
	 * on null->base property access.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::should_show_notices
	 */
	public function test_should_show_notices_returns_false_when_screen_is_null() {
		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( null );

		$this->assertFalse( $this->invoke_should_show_notices() );
	}

	/**
	 * Coverage pin: when `display_notices()` reaches the
	 * `should_show_notices()` allowed-screen branch on a non-edit screen,
	 * the early-return path must short-circuit before the
	 * `get_current_post_type()` call. This exercises the integrated path
	 * (not the reflection-direct invocation above) so the runtime call
	 * graph that hits `should_show_notices()` is also pinned.
	 *
	 * Pairs with the constant pin
	 * {@see test_edit_base_remains_allowed_to_preserve_bulk_and_editor_redirect_notice_contract}
	 * — that test asserts the constant content; this one asserts the
	 * runtime branch.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 * @covers ArchivedPostStatus\Admin\Notices::should_show_notices
	 */
	public function test_display_notices_short_circuits_when_should_show_notices_returns_false() {
		$screen       = new \stdClass();
		$screen->base = 'options-general'; // explicitly NOT in ALLOWED_SCREEN_BASES

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		// get_current_post_type() must not be reached when should_show_notices()
		// returns false — assert via never() on the WP function it would call.
		\WP_Mock::userFunction( 'get_post_type' )->never();
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		ob_start();
		$this->notices->display_notices();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'No output should be emitted when should_show_notices() returns false' );
	}

	/**
	 * Coverage pin : `get_current_post_type()` reads the
	 * `$_GET['post_type']` request variable when the global `$post_type`
	 * is not set. the 0.4.0 refactor plan flags this branch as a
	 * coverage hole; pinning it here also keeps the working coverage
	 * above baseline.
	 *
	 * The post_type from $_GET is `sanitize_key`-d before being trusted
	 * by the builder; this test exercises the sanitize_key fallback.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_get_current_post_type_falls_back_to_get_request_var_when_global_unset() {
		$screen       = new \stdClass();
		$screen->base = 'edit';

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'get_query_var' )->andReturn( false );
		\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => strtolower( (string) $v ) );

		// No notice should fire because there's nothing to show (no counters set).
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		// Clear the global so the $_GET branch is reached.
		global $post_type;
		$post_type = '';

		$_GET['post_type'] = 'page';

		try {
			ob_start();
			$this->notices->display_notices();
			$output = ob_get_clean();

			$this->assertSame( '', $output, 'No output expected — counters are all false' );
		} finally {
			unset( $_GET['post_type'] );
		}
	}

	/**
	 * Coverage pin: `get_current_post_type()` final fallback —
	 * when neither the `$post_type` global nor `$_GET['post_type']` are
	 * set, the SUT consults `get_post_type()` (the WP-core helper) for
	 * the loop's current post.
	 *
	 * The fallback fires on edit-screen loads where the global is
	 * initialised late (or not at all in mocked contexts).
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_get_current_post_type_falls_back_to_get_post_type_when_global_and_get_var_unset() {
		$screen       = new \stdClass();
		$screen->base = 'edit';

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'get_query_var' )->andReturn( false );

		// No notice fires — counters are all false. The point is to exercise
		// the get_post_type() fallback path so coverage records it.
		\WP_Mock::userFunction( 'get_post_type' )->andReturn( 'post' );
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		// Neither global nor $_GET set.
		global $post_type;
		$post_type = '';
		unset( $_GET['post_type'] );

		ob_start();
		$this->notices->display_notices();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'No output expected — counters are all false but the get_post_type fallback should have executed' );
	}

	/**
	 * Companion coverage pin: when `get_post_type()` returns `false` (no
	 * current post in the loop), `get_current_post_type()` returns null
	 * and `display_notices()` short-circuits without calling the builder.
	 *
	 * Pins the `get_post_type() ?: null` coalesce branch.
	 *
	 * @covers ArchivedPostStatus\Admin\Notices::display_notices
	 */
	public function test_display_notices_returns_silently_when_post_type_cannot_be_resolved() {
		$screen       = new \stdClass();
		$screen->base = 'edit';

		\WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
		\WP_Mock::userFunction( 'get_post_type' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_admin_notice' )->never();

		global $post_type;
		$post_type = '';
		unset( $_GET['post_type'] );

		ob_start();
		$this->notices->display_notices();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
