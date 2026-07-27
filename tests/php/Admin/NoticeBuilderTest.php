<?php
/**
 * NoticeBuilder Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\NoticeBuilder
 *
 * the 0.4.0 refactor extracted `NoticeBuilder` from
 * `Admin\Notices`. The reflection-based `parse_ids()` data-provider from
 * `NoticesTest` moves here — `parse_ids()` is now a public method, no
 * reflection required. The two build_* methods and the `build_notices()`
 * orchestration also get direct unit tests here, separate from the
 * `Notices::display_notices()` HTML-render path tests that stay in
 * `NoticesTest`.
 */

/**
 * NoticeBuilder test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\NoticeBuilder
 */
class NoticeBuilderTest extends TestCase {

	/**
	 * @var ArchivedPostStatus\Admin\NoticeBuilder
	 */
	protected $builder;

	public function set_up() {
		parent::set_up();
		$this->builder = new ArchivedPostStatus\Admin\NoticeBuilder();
	}

	// -----------------------------------------------------------------------
	// parse_ids()
	// -----------------------------------------------------------------------
	//
	// Normalizes the `ids` query var into an int[] regardless of whether
	// WordPress handed us:
	//   - a CSV string ("1,2,3")
	//   - a real array ([1, 2, 3])
	//   - an array whose first element is itself a CSV string (["1,2,3"])
	//
	// The third shape shows up because some core list-table flows pass
	// `ids` through `wp_parse_id_list()` before the query var is read,
	// and others don't. Both shapes flow through here.
	//
	// Moved from `NoticesTest::test_parse_ids_normalizes_inputs_to_int_array`
	// in the 0.4.0 refactor `parse_ids()` is now public on
	// `NoticeBuilder`, so the test no longer needs ReflectionMethod.

	/**
	 * Data provider for parse_ids().
	 *
	 * @return array<string, array{0: mixed, 1: array<int, int>}>
	 */
	public function parse_ids_provider(): array {
		return array(
			'csv string'                            => array( '1,2,3', array( 1, 2, 3 ) ),
			'native int array'                      => array( array( 1, 2, 3 ), array( 1, 2, 3 ) ),
			'array containing csv string in [0]'    => array( array( '1,2,3' ), array( 1, 2, 3 ) ),
			'empty string'                          => array( '', array() ),
			'non-numeric string'                    => array( 'abc', array() ),
			'mixed numeric and non-numeric string'  => array( '1,abc,3', array( 1, 3 ) ),
			// `false` is what WordPress hands back for unset query vars; the
			// `! $ids` guard short-circuits to an empty array.
			'falsy bool from unset query var'       => array( false, array() ),
		);
	}

	/**
	 * parse_ids() returns int[] for each documented input shape, dropping
	 * non-numeric tokens and never raising on absent values.
	 *
	 * @dataProvider parse_ids_provider
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::parse_ids
	 *
	 * @param mixed       $input    Raw value as it would come from get_query_var().
	 * @param array<int>  $expected The normalized int[] we expect back.
	 */
	public function test_parse_ids_normalizes_inputs_to_int_array( $input, array $expected ) {
		$result = $this->builder->parse_ids( $input );

		// array_filter inside parse_ids() preserves source keys; for the
		// "mixed" case the expected indices aren't a 0..N sequence. Sort
		// by value so the assertion checks "right set of ids" rather than
		// "right keys" — keys aren't part of the contract.
		$result_values = array_values( $result );
		sort( $result_values );
		$expected_sorted = $expected;
		sort( $expected_sorted );

		$this->assertSame( $expected_sorted, $result_values );
	}

	// -----------------------------------------------------------------------
	// build_archive_notice()
	// -----------------------------------------------------------------------
	//
	// `build_archive_notice( int $count, array<int> $ids, string $post_type )`
	// returns a translation-ready string. With at least one id, the message
	// is suffixed with an Undo anchor pointing at the `unarchive` bulk
	// action; without ids (e.g. a count-only locked-post recovery flow),
	// only the count message comes back.

	/**
	 * Common WP-boundary stubs shared across the build_archive_notice cases.
	 *
	 * `wp_nonce_url` returns the URL with a sentinel `_wpnonce` query arg
	 * appended so callers can verify the nonce hop actually fired.
	 */
	private function stubArchiveNoticeBoundary(): void {
		\WP_Mock::userFunction( '_n' )->andReturnUsing(
			static function ( $singular, $plural, $count ) {
				return 1 === (int) $count ? $singular : $plural;
			}
		);
		\WP_Mock::userFunction( 'number_format_i18n' )
			->andReturnUsing( static fn( $n ) => (string) $n );
		\WP_Mock::userFunction( 'admin_url' )
			->andReturnUsing( static fn( $path ) => 'http://example.com/wp-admin/' . ltrim( (string) $path, '/' ) );
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=test' );
		\WP_Mock::userFunction( '__' )
			->with( 'Undo', 'archived-post-status' )->andReturn( 'Undo' );
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => (string) $url );
	}

	/**
	 * Single-post archive: the message uses the singular form and appends
	 * an Undo anchor carrying the post id.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_archive_notice
	 */
	public function test_build_archive_notice_single_post_includes_undo_anchor_with_id() {
		$this->stubArchiveNoticeBoundary();

		$message = $this->builder->build_archive_notice( 1, array( 123 ), 'post' );

		$this->assertStringContainsString( 'post moved to the Archive', $message );
		$this->assertStringContainsString( '<a href=', $message );
		$this->assertStringContainsString( 'ids=123', $message );
		$this->assertStringContainsString( '_wpnonce=test', $message );
		$this->assertStringContainsString( 'action=unarchive', $message );
		$this->assertStringContainsString( 'doaction=undo', $message );
	}

	/**
	 * Bulk archive: the plural form fires and the Undo anchor carries the
	 * full CSV id list, not just the first id.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_archive_notice
	 */
	public function test_build_archive_notice_bulk_includes_full_id_list_in_undo_anchor() {
		$this->stubArchiveNoticeBoundary();

		$message = $this->builder->build_archive_notice( 3, array( 123, 456, 789 ), 'post' );

		$this->assertStringContainsString( 'posts moved to the Archive', $message );
		$this->assertStringContainsString( 'ids=123,456,789', $message );
	}

	/**
	 * When the ids array is empty (e.g. a counter-only message flow), the
	 * Undo anchor must NOT be appended — there's nothing to undo.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_archive_notice
	 */
	public function test_build_archive_notice_omits_undo_anchor_when_ids_empty() {
		// No need for the full fixture — the empty-ids branch never reaches
		// wp_nonce_url / admin_url / esc_url, so we only stub the _n + format.
		\WP_Mock::userFunction( '_n' )->andReturn( '2 posts moved to the Archive.' );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturn( '2' );

		$message = $this->builder->build_archive_notice( 2, array(), 'post' );

		$this->assertStringNotContainsString( '<a href=', $message );
		$this->assertStringContainsString( 'moved to the Archive', $message );
	}

	// -----------------------------------------------------------------------
	// build_unarchive_notice()
	// -----------------------------------------------------------------------
	//
	// `build_unarchive_notice( int $count, array<int> $ids )` returns a
	// translation-ready string. The single-id branch appends an Edit anchor
	// pointing back at the now-restored post (only when the current user
	// has the edit_post capability AND the post type still resolves to a
	// real post-type object). Bulk unarchive returns the count message
	// alone — no edit link, because there's no single post to edit.

	/**
	 * Single-post unarchive: appends an Edit link routed through
	 * `get_edit_post_link()` with the post type object's `edit_item` label.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_unarchive_notice
	 */
	public function test_build_unarchive_notice_single_post_appends_edit_link() {
		\WP_Mock::userFunction( '_n' )->andReturn( '1 post restored from the Archive.' );
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
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => (string) $url );
		\WP_Mock::userFunction( 'esc_html' )
			->andReturnUsing( static fn( $text ) => (string) $text );

		$message = $this->builder->build_unarchive_notice( 1, array( 123 ) );

		$this->assertStringContainsString( 'restored from the Archive', $message );
		$this->assertStringContainsString( '<a href=', $message );
		$this->assertStringContainsString( 'post=123', $message );
		$this->assertStringContainsString( 'Edit Post', $message );
	}

	/**
	 * Single-post unarchive without the edit capability: the message comes
	 * back without an Edit anchor (no point linking to a screen the user
	 * can't open).
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_unarchive_notice
	 */
	public function test_build_unarchive_notice_omits_edit_link_when_user_cannot_edit() {
		\WP_Mock::userFunction( '_n' )->andReturn( '1 post restored from the Archive.' );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturn( '1' );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_post', 123 )->andReturn( false );

		$message = $this->builder->build_unarchive_notice( 1, array( 123 ) );

		$this->assertStringContainsString( 'restored from the Archive', $message );
		$this->assertStringNotContainsString( '<a href=', $message );
	}

	/**
	 * Edit-link-gate regression: the routing
	 * through the centralized `aps_current_user_can_edit()` helper, which
	 * consults the `aps_default_edit_capability` filter to resolve the
	 * capability. Filtering the cap to one the user lacks must suppress
	 * the edit anchor without touching NoticeBuilder.
	 *
	 * Proves the cap question now goes through a single filterable
	 * surface — sites can grant or deny the unarchive-notice edit link
	 * without intercepting the raw `current_user_can` call site.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_unarchive_notice
	 */
	public function test_build_unarchive_notice_respects_edit_capability_filter_override() {
		\WP_Mock::userFunction( '_n' )->andReturn( '1 post restored from the Archive.' );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturn( '1' );

		// Filter resolves to a capability the user does not hold. The
		// helper must call current_user_can() with the filtered cap (not
		// the default 'edit_post') and treat the false return as a denial.
		\WP_Mock::onFilter( 'aps_default_edit_capability' )
			->with( 'edit_post', 123 )
			->reply( 'aps_nonexistent_edit_cap' );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'aps_nonexistent_edit_cap', 123 )
			->andReturn( false );

		$message = $this->builder->build_unarchive_notice( 1, array( 123 ) );

		$this->assertStringContainsString( 'restored from the Archive', $message );
		$this->assertStringNotContainsString( '<a href=', $message );
	}

	/**
	 * Bulk unarchive: the plural form fires and no edit anchor is appended
	 * (only the single-id branch builds one).
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_unarchive_notice
	 */
	public function test_build_unarchive_notice_bulk_omits_edit_link() {
		\WP_Mock::userFunction( '_n' )->andReturn( '5 posts restored from the Archive.' );
		\WP_Mock::userFunction( 'number_format_i18n' )->andReturn( '5' );

		$message = $this->builder->build_unarchive_notice( 5, array( 1, 2, 3, 4, 5 ) );

		$this->assertStringContainsString( 'posts restored from the Archive', $message );
		$this->assertStringNotContainsString( '<a href=', $message );
	}

	// -----------------------------------------------------------------------
	// build_notices() orchestration
	// -----------------------------------------------------------------------
	//
	// `build_notices( string $post_type )` reads the `archived`,
	// `unarchived`, `locked`, `ids` query vars and assembles
	// the corresponding notice strings in order. Returns an empty array
	// when no counters are set. The render layer in `Notices::display_notices`
	// then concatenates and emits the array.

	/**
	 * No query vars set → empty notices array. Documents the no-op path.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_notices
	 */
	public function test_build_notices_returns_empty_array_when_no_counters_present() {
		\WP_Mock::userFunction( 'get_query_var' )->andReturn( false );

		$result = $this->builder->build_notices( 'post' );

		$this->assertSame( array(), $result );
	}

	/**
	 * `archived=1`, `ids=123` → exactly one notice in the array (the
	 * archive notice). The locked branch must NOT contribute because its
	 * counter is absent.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_notices
	 */
	public function test_build_notices_returns_single_archive_notice_for_archived_query_var() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'archived', false )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'unarchived', false )->andReturn( false );
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

		$this->stubArchiveNoticeBoundary();

		$result = $this->builder->build_notices( 'post' );

		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'moved to the Archive', $result[0] );
		$this->assertStringContainsString( 'ids=123', $result[0] );
	}

	/**
	 * the denied / not_found / wrong_status query vars
	 * each contribute their own translation-ready notice line. The
	 * builder NEVER performs capability checks — the denied count
	 * arrives pre-computed from BulkActionHandler.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_notices
	 */
	public function test_build_notices_emits_per_bucket_notices_for_phase_1_reason_buckets() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'archived', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'unarchived', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'locked', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'denied', false )->andReturn( '2' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'not_found', false )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'wrong_status', false )->andReturn( '3' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'ids', false )->andReturn( false );

		\WP_Mock::userFunction( '_n' )->andReturnUsing(
			static function ( $singular, $plural, $count ) {
				return 1 === (int) $count ? $singular : $plural;
			}
		);
		\WP_Mock::userFunction( 'number_format_i18n' )
			->andReturnUsing( static fn( $n ) => (string) $n );

		$result = $this->builder->build_notices( 'post' );

		$this->assertCount( 3, $result, 'Expect one notice per non-zero reason bucket' );
		// Order: denied → not_found → wrong_status (matches the order
		// the SUT writes them in build_notices()).
		$joined = implode( ' | ', $result );
		$this->assertStringContainsString( 'not allowed', $joined );
		$this->assertStringContainsString( 'no longer exists', $joined );
		$this->assertStringContainsString( 'status is not eligible', $joined );
	}

	/**
	 * Three counters set (archived, unarchived, locked) → three notice
	 * strings in the returned array, in the documented order. The order
	 * matters because `Notices::render_notices` joins them with a single
	 * space and the user reads them top-to-bottom.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeBuilder::build_notices
	 */
	public function test_build_notices_returns_three_notices_when_archived_unarchived_and_locked_set() {
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'archived', false )->andReturn( '2' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'unarchived', false )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'locked', false )->andReturn( '3' );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'denied', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'not_found', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'wrong_status', false )->andReturn( false );
		\WP_Mock::userFunction( 'get_query_var' )
			->with( 'ids', false )->andReturn( '10,20' );

		// Cover both _n branches: build_archive sees count=2 (plural),
		// build_unarchive sees count=1 (singular), locked sees its own
		// count. Return the singular/plural based on count.
		\WP_Mock::userFunction( '_n' )->andReturnUsing(
			static function ( $singular, $plural, $count ) {
				return 1 === (int) $count ? $singular : $plural;
			}
		);
		\WP_Mock::userFunction( 'number_format_i18n' )
			->andReturnUsing( static fn( $n ) => (string) $n );

		// Archive-notice undo-anchor boundary stubs.
		\WP_Mock::userFunction( 'admin_url' )
			->andReturnUsing( static fn( $path ) => 'http://example.com/wp-admin/' . ltrim( (string) $path, '/' ) );
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=test' );
		\WP_Mock::userFunction( '__' )
			->with( 'Undo', 'archived-post-status' )->andReturn( 'Undo' );
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => (string) $url );

		// Unarchive-notice edit-link boundary stubs (single-id case: ids=10,20
		// has count(parse_ids)=2, so edit-link branch is skipped — but
		// unarchive count param is 1, which triggers `_n` singular form).
		// No current_user_can / get_post_type stubs needed: count(ids)=2 ≠ 1.

		$result = $this->builder->build_notices( 'post' );

		$this->assertCount( 3, $result );
		$this->assertStringContainsString( 'moved to the Archive', $result[0] );
		$this->assertStringContainsString( 'restored from the Archive', $result[1] );
		$this->assertStringContainsString( 'somebody is editing', $result[2] );
	}
}
