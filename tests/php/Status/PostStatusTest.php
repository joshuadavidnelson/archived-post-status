<?php
/**
 * Status\PostStatus Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Status\PostStatus
 *
 * Migration note (0.4.0 §1.1):
 *   The ten `aps_status_arg_*` scenarios in this file were migrated from
 *   FunctionsTest::test_aps_status_arg_* and FunctionsTest::test_register_archive_post_status
 *   when `aps_register_archive_post_status()` was removed; the behavior now
 *   lives in `Status\PostStatus::register_status()` (which delegates to
 *   the private `status_args()`).
 *
 * Brittleness note :
 *   The original ten near-identical filter-pinning tests were collapsed into
 *   a single `@dataProvider` driven test. Each old test pinned all seven
 *   filters; only one differed per row. The new test asserts a single
 *   filter per data row — the matrix is now visible at the provider, and
 *   the assertion fails only when the SUT's default for the named arg
 *   changes.
 */

/**
 * PostStatus test case
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Status\PostStatus
 */
class PostStatusTest extends TestCase {

	/**
	 * PostStatus instance.
	 *
	 * @var ArchivedPostStatus\Status\PostStatus
	 */
	protected $post_status;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->post_status = new ArchivedPostStatus\Status\PostStatus();
	}

	/**
	 * Mocks the dependencies that `register_status()` always calls regardless
	 * of branch. Keeps the per-test setups short and behavior-focused.
	 *
	 * @param string $expected_slug The slug the SUT is expected to pass into
	 *                              register_post_status(). Defaults to
	 *                              `'archive'`; override when a test pins the
	 *                              `aps_post_status_slug` filter to verify
	 *                              the override threads through.
	 */
	private function mockRegisterStatusDependencies( string $expected_slug = 'archive' ): void {
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( [ 'post' ] );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( [] );
		\WP_Mock::userFunction( 'register_post_status' )
			->once()
			->with( $expected_slug, \Mockery::type( 'array' ) );
	}

	/**
	 * register_status() calls register_post_status('archive', ...).
	 *
	 * Migrated from FunctionsTest::test_register_archive_post_status.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_register_status_registers_archive_post_status() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		$this->post_status->register_status();

		// WP_Mock verifies the register_post_status('archive', ...) once()
		// expectation during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Data provider for the per-arg default matrix.
	 *
	 * Each row is `[is_admin, can_view, filter_name, expected_default]`. The
	 * filter under examination is the only one the test pins; the rest are
	 * left unmocked so an assertion failure points at the SUT's default for
	 * exactly the named filter rather than at a cascade of unrelated mocks.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: string, 3: mixed}>
	 */
	public function status_arg_default_provider(): array {
		return array(
			// Frontend + can view  → public defaults to true.
			'public is true on frontend when user can view archives' => array(
				false, true, 'aps_status_arg_public', true,
			),
			// Frontend + cannot view → public defaults to false.
			'public is false on frontend when user cannot view archives' => array(
				false, false, 'aps_status_arg_public', false,
			),
			// Default is fixed at false regardless of context.
			'protected always defaults to false' => array(
				false, true, 'aps_status_arg_protected', false,
			),
			// Admin + can view → exclude_from_search defaults to false.
			'exclude_from_search is false in admin when user can view' => array(
				true, true, 'aps_status_arg_exclude_from_search', false,
			),
			// Frontend + can view → the filter receives true. The SUT default
			// `! ( is_admin() && aps_current_user_can_view() )` is true off the
			// admin side, matching 0.3.12's effective `! is_admin()` — continuity,
			// not drift. Pins the filter value only; see the admin sibling row
			// for what actually consumes this arg.
			'exclude_from_search is true on frontend even when user can view' => array(
				false, true, 'aps_status_arg_exclude_from_search', true,
			),
			// Admin + cannot view → exclude_from_search defaults to true; the one
			// arm whose value drifted (0.3.12's parameterless `aps_is_frontend`
			// forced `! is_admin()` = false here). Only `post_status => 'any'`
			// queries read this arg — the find-posts AJAX picker and similar
			// internals, not the Posts screen, which never consults it.
			'exclude_from_search is true in admin when user cannot view' => array(
				true, false, 'aps_status_arg_exclude_from_search', true,
			),
			// Default is fixed at false regardless of context.
			'show_in_admin_all_list always defaults to false' => array(
				true, true, 'aps_status_arg_show_in_admin_all_list', false,
			),
			// Admin + can view → show_in_admin_status_list defaults to true.
			'show_in_admin_status_list is true when user can view archives' => array(
				true, true, 'aps_status_arg_show_in_admin_status_list', true,
			),
			// Default is fixed at 'dashicons-archive'.
			'dashicon default is dashicons-archive' => array(
				true, true, 'aps_status_arg_dashicon', 'dashicons-archive',
			),
		);
	}

	/**
	 * Each `aps_status_arg_*` filter receives the SUT-calculated default for
	 * the given (is_admin, can_view) context. This test pins exactly the
	 * default for the filter named in the row — see status_arg_default_provider
	 * for the matrix.
	 *
	 * Replaces 10 prior near-duplicate `test_status_arg_*` methods that each
	 * pinned all 7 filters; the matrix is now visible at the provider, and
	 * each row asserts precisely one fact.
	 *
	 * @dataProvider status_arg_default_provider
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 *
	 * @param bool   $is_admin         Mocked return for is_admin().
	 * @param bool   $can_view         Mocked return for aps_current_user_can_view().
	 * @param string $filter           Name of the filter being pinned.
	 * @param mixed  $expected_default Value the SUT must pass into apply_filters().
	 */
	public function test_status_arg_default_per_context_matrix( bool $is_admin, bool $can_view, string $filter, $expected_default ): void {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( $is_admin );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( $can_view );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::expectFilter( $filter, $expected_default );

		$this->post_status->register_status();

		// WP_Mock verifies the expectFilter expectation during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Site authors can override every status arg via the dedicated filters.
	 * The (bool)/(string) casts in status_args() must apply the overridden
	 * value back into the register_post_status() args — the only observable
	 * is that the call completes without type error and the filters fired.
	 *
	 * Migrated from FunctionsTest::test_status_argument_filters_can_be_modified.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_status_argument_filters_can_be_modified() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::onFilter( 'aps_status_arg_public' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_private' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_protected' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_exclude_from_search' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_all_list' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_status_list' )->with( true )->reply( false );
		\WP_Mock::onFilter( 'aps_status_arg_dashicon' )->with( 'dashicons-archive' )->reply( 'dashicons-lock' );

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Non-boolean filter returns are coerced via the (bool)/(string) casts in
	 * status_args(); the call must still succeed without type errors.
	 *
	 * Migrated from FunctionsTest::test_status_argument_filters_type_casting.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_status_argument_filters_type_casting() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::onFilter( 'aps_status_arg_public' )->with( false )->reply( 'yes' );        // truthy string → true
		\WP_Mock::onFilter( 'aps_status_arg_private' )->with( false )->reply( 0 );           // 0 → false (admin context default is already false)
		\WP_Mock::onFilter( 'aps_status_arg_protected' )->with( false )->reply( 1 );         // 1 → true
		\WP_Mock::onFilter( 'aps_status_arg_exclude_from_search' )->with( false )->reply( '' ); // '' → false
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_all_list' )->with( false )->reply( 'false' ); // any non-empty string is truthy
		\WP_Mock::onFilter( 'aps_status_arg_show_in_admin_status_list' )->with( true )->reply( null ); // null → false
		\WP_Mock::onFilter( 'aps_status_arg_dashicon' )->with( 'dashicons-archive' )->reply( 123 );    // cast to string

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The `aps_post_status_slug` filter (restored in the 0.4.0 refactor
	 * cleanup) lets sites that registered the status under a custom slug
	 * under 0.3.x continue to do so. When the filter returns `'archived'`,
	 * `register_post_status()` must receive `'archived'` (not the default
	 * `'archive'`).
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_register_post_status_uses_filtered_slug_when_aps_post_status_slug_is_overridden() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies( 'archived' );

		\WP_Mock::onFilter( 'aps_post_status_slug' )->with( 'archive' )->reply( 'archived' );

		$this->post_status->register_status();

		// WP_Mock verifies the register_post_status('archived', ...) once()
		// expectation during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Without any `aps_post_status_slug` filter callback in place, the SUT
	 * must register the status under the default `'archive'` slug.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_register_post_status_uses_default_slug_archive_when_no_filter() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies( 'archive' );

		$this->post_status->register_status();

		// WP_Mock verifies the register_post_status('archive', ...) once()
		// expectation during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * `aps_status_arg_private` default is `! is_admin()` — `false` in admin
	 * context, `true` on the front end.
	 *
	 * Admin-context `false` is the deliberate behavior: archived posts should
	 * not appear in the admin's default "All" filter listing, mirroring how
	 * the Trash status is handled. The `show_in_admin_all_list=false` arg
	 * (set separately in `status_args`) keeps the status out of the post-list
	 * table's "All" view; the `private=false` default in admin works in
	 * concert with that to keep archived posts from being treated as private
	 * by admin queries that don't already account for the status.
	 *
	 * On the front end the default flips to `true`, which routes archived
	 * post visibility through WP core's `read_private_posts` capability gate
	 * — the same capability `aps_current_user_can_view()` consults.
	 *
	 * `public` and `private` are independent `register_post_status()` flags
	 * per WP core — they are not opposites. See
	 * `src/Status/PostStatus.php::status_args()` for the docblock.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_aps_status_arg_private_defaults_to_false_in_admin_context() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::expectFilter( 'aps_status_arg_private', false );

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * On the front end the default flips to `true` — see the admin-context
	 * sibling test for the full rationale.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_aps_status_arg_private_defaults_to_true_on_frontend() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::expectFilter( 'aps_status_arg_private', true );

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Sanity check that `aps_status_arg_private` still functions as a hook —
	 * a callback returning `false` overrides the default. Frontend context
	 * here so the starting default is `true` and the override produces a
	 * different value.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_aps_status_arg_private_filter_can_be_overridden_to_false() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::onFilter( 'aps_status_arg_private' )->with( true )->reply( false );

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `aps_status_arg_public` defaults to `true` on the front end when the
	 * current user can view archived content. Pins the 0.4.0 refinement that
	 * the status only claims front-end-public registration off the admin
	 * side.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_aps_status_arg_public_defaults_to_true_on_frontend_when_user_can_view() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::expectFilter( 'aps_status_arg_public', true );

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `aps_status_arg_public` defaults to `false` in the admin context
	 * regardless of whether the user can view archived content. Pins the
	 * `! is_admin()` clause of the default — the status does not claim
	 * front-end-public registration on the admin side.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_aps_status_arg_public_defaults_to_false_in_admin_context() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( true );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::expectFilter( 'aps_status_arg_public', false );

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `aps_status_arg_public` defaults to `false` on the front end when the
	 * current user cannot view archived content. Pins the
	 * `aps_current_user_can_view()` clause of the default.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_aps_status_arg_public_defaults_to_false_on_frontend_when_user_cannot_view() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( false );
		$this->mockRegisterStatusDependencies();

		\WP_Mock::expectFilter( 'aps_status_arg_public', false );

		$this->post_status->register_status();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * On the all-posts view (no `post_status` query var filter), an archived
	 * post must surface the "Archived" label in the post states.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::display_post_states
	 */
	public function test_display_post_states_adds_label_on_all_posts_view() {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( '' );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );

		$result = $this->post_status->display_post_states( array(), $post );

		$this->assertArrayHasKey( 'archive', $result );
		$this->assertSame( 'Archived', $result['archive'] );
	}

	/**
	 * When the user has filtered the list to archived posts only (scalar
	 * `post_status=archive`), the label is suppressed to avoid redundancy.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::display_post_states
	 */
	public function test_display_post_states_omits_label_when_filtering_archive_scalar() {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( 'archive' );

		$result = $this->post_status->display_post_states( array( 'private' => 'Private' ), $post );

		$this->assertArrayNotHasKey( 'archive', $result );
		$this->assertSame( array( 'private' => 'Private' ), $result );
	}

	/**
	 * The §1.3 multi-status array fix: a `post_status[]=publish&post_status[]=archive`
	 * filter (an array containing 'archive') must still suppress the label.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::display_post_states
	 */
	public function test_display_post_states_omits_label_when_filtering_archive_array() {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( array( 'publish', 'archive' ) );

		$result = $this->post_status->display_post_states( array(), $post );

		$this->assertArrayNotHasKey( 'archive', $result );
	}

	/**
	 * §1.6 regression (self-discovered consumer, found by grepping for
	 * `aps_archived_label_string()` callers): display_post_states() feeds
	 * the label straight into WP core's `_post_states()`, which
	 * concatenates every post state directly into raw HTML with no
	 * escaping of its own (`"<span class='post-state'>{$state}...`"`).
	 * Before the fix this happened to look correct only because
	 * ArchiveLabel::value() escaped with esc_attr() internally; once that
	 * internal escaping is removed, display_post_states() must escape the
	 * label itself.
	 *
	 * Distinguishable esc_attr()/esc_html() markers prove which function
	 * actually produced the returned value.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::display_post_states
	 */
	public function test_display_post_states_escapes_label_for_html_output() {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( '' );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );

		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $s ) => "(({$s}))"
		);
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => "[[{$s}]]"
		);

		$result = $this->post_status->display_post_states( array(), $post );

		$this->assertSame( '[[Archived]]', $result['archive'] );
	}

	/**
	 * Coverage pin: a multi-status filter that does NOT include
	 * `'archive'` (e.g. `?post_status[]=publish&post_status[]=draft`) must
	 * still surface the "Archived" label for an archived post. The
	 * `in_array( $slug, …, true )` early-return only fires when the filter
	 * list actually contains 'archive' — this is the false-branch of the
	 * in_array() guard, previously implicit.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::display_post_states
	 */
	public function test_display_post_states_adds_label_when_filtering_a_multi_status_array_not_containing_archive() {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( array( 'publish', 'draft' ) );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );

		$result = $this->post_status->display_post_states( array(), $post );

		$this->assertArrayHasKey( 'archive', $result );
		$this->assertSame( 'Archived', $result['archive'] );
	}

	/**
	 * Coverage pin: when the post status is NOT 'archive' — even
	 * if `aps_is_supported_post_type` is true — `display_post_states()`
	 * short-circuits without touching `$post_states`. Pins the
	 * `$slug !== $post->post_status` early-return clause for a non-archive
	 * post status, which the existing tests didn't exercise (they used
	 * `post_status = 'archive'` throughout).
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::display_post_states
	 */
	public function test_display_post_states_returns_unchanged_when_post_status_is_not_archive() {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'post_status' )
			->andReturn( '' );

		$existing_states = array( 'sticky' => 'Sticky', 'private' => 'Private' );

		$result = $this->post_status->display_post_states( $existing_states, $post );

		$this->assertSame(
			$existing_states,
			$result,
			'A non-archive post must not get an archive label appended.'
		);
	}

	/**
	 * Unsupported post types never receive the label, regardless of status.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::display_post_states
	 */
	public function test_display_post_states_skips_unsupported_post_types() {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'attachment';
		$post->post_status = 'archive';

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'attachment' )
			->andReturn( false );

		$result = $this->post_status->display_post_states( array(), $post );

		$this->assertArrayNotHasKey( 'archive', $result );
	}
}
