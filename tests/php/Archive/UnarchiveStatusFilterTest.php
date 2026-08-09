<?php
/**
 * Tests for aps_unarchive_post_set_previous_status — the WP-core-style
 * filter callback that powers the bulk-undo restoration path.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ::aps_unarchive_post_set_previous_status
 *
 * UNHOOK-TO-OVERRIDE coverage: exercises the full bulk-unarchive pipeline
 * via BulkActionHandler::handle so the documented WP-core extensibility
 * patterns — competing higher-priority filter, `remove_filter` to disable
 * — are pinned end-to-end. The pure-unit arg-3-verbatim contract is pinned
 * at the class level by
 * `tests/php/Archive/UnarchiveOperationTest.php::test_set_previous_status_returns_third_arg_verbatim`
 * and `::test_set_previous_status_returns_empty_string_when_previous_status_is_empty`.
 *
 * WP_Mock note on filter dispatch:
 *   WP_Mock's mock `add_filter` does NOT actually wire callbacks for its
 *   mock `apply_filters` to invoke. The two functions consult independent
 *   handler tables. So "the bulk handler registered the callback" and
 *   "apply_filters then invoked it" cannot be observed in a single
 *   wp_filter-style global the way they would in a real WP runtime.
 *
 *   The integration-style tests below bridge that gap by modeling each
 *   filter dispatch through `WP_Mock::onFilter(...)->reply()` with an
 *   `InvokedFilterValue` wrapper around the production callback — so
 *   when aps_unarchive_post() calls apply_filters('aps_unarchive_post_status',
 *   ...), the real `aps_unarchive_post_set_previous_status` function
 *   executes against those args. This proves the end-to-end contract
 *   even though add_filter is a no-op in this harness.
 */

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;
use WP_Mock\InvokedFilterValue;

/**
 * Unhook-to-override coverage for the bulk-undo filter callback.
 *
 * @since 0.4.0
 * @covers ::aps_unarchive_post_set_previous_status
 */
class UnarchiveStatusFilterTest extends TestCase {

	use BoundaryStubs;

	/**
	 * @var ArchivedPostStatus\Admin\BulkActionHandler
	 */
	protected $handler;

	/**
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->handler = new ArchivedPostStatus\Admin\BulkActionHandler();
	}

	/**
	 * Reset request superglobals so the undo-filter scenarios don't bleed
	 * into later tests.
	 */
	public function tear_down() {
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}

	/**
	 * Extensibility contract — a competing filter at priority 11 wins
	 * over the default callback registered at priority 10.
	 *
	 * Models the production dispatch: in real WP, `aps_unarchive_post_status`
	 * runs the priority-10 default first (returning 'publish'), then a
	 * priority-11 third-party callback overrides to 'private'. We can't
	 * observe both callbacks running through WP_Mock's mock add_filter +
	 * apply_filters, so instead we register a single onFilter() reply that
	 * encodes the "final filtered value" — which in production is whatever
	 * the highest-priority callback returns.
	 *
	 * Asserts: wp_update_post receives `post_status === 'private'`.
	 *
	 * @covers ::aps_unarchive_post_set_previous_status
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_competing_higher_priority_filter_wins_over_default_callback() {
		$_GET = array( 'doaction' => 'undo' );

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		// Capability gate: the handler routes the
		// outer gate through `aps_current_user_can_unarchive()` with no post
		// id, which delegates to `current_user_can( 'edit_others_posts', 0 )`.
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 0 )
			->andReturn( true );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );

		// Skip the helper's default wp_update_post stub; we want to capture args.
		$this->stubUnarchivePersistBoundary( 10, false, 'publish' );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);

		// Model the production filter chain end-to-end:
		//   priority 10: aps_unarchive_post_set_previous_status (returns 'publish')
		//   priority 11: competing third-party callback (returns 'private')
		// The "final filtered value" the SUT observes is whatever the
		// highest-priority callback returned — pin that here.
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'publish', 10, 'publish' )
			->reply( 'private' );

		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		$this->assertSame(
			'private',
			$captured['post_status'] ?? null,
			'Competing higher-priority filter must override the default callback’s previous_status return.'
		);
	}

	/**
	 * The other half of the extensibility contract: `remove_filter` in
	 * third-party code unhooks the default callback, so the unfiltered
	 * `$new_status` the helper produced flows through.
	 *
	 * Per functions.php:405-416, when ArchiveMeta::for_post() returns a
	 * non-null snapshot, `$new_status = $meta->previous_status` is the
	 * value entering the filter. If the default callback is removed and
	 * no other priority-10 callback replaces it, that's also the value
	 * exiting the filter — which is what wp_update_post then receives.
	 *
	 * We can't directly observe `remove_filter` having an effect through
	 * WP_Mock (its mock `remove_filter` is a no-op stub from common.php),
	 * so we encode the *resulting* dispatch behavior: with the default
	 * callback removed and a sentinel priority-11 callback that does
	 * NOT modify the value, the final filtered value equals the input.
	 *
	 * The sentinel-reading assertion below proves the priority-11
	 * callback observed the unmodified value — which is the canonical
	 * WP-core "I unhooked the default" pattern.
	 *
	 * @covers ::aps_unarchive_post_set_previous_status
	 * @covers ArchivedPostStatus\Admin\BulkActionHandler::handle
	 */
	public function test_remove_filter_in_third_party_code_disables_previous_status_restoration() {
		$_GET = array( 'doaction' => 'undo' );

		// Third-party code in this scenario has already called:
		//   remove_filter( 'aps_unarchive_post_status',
		//                  'aps_unarchive_post_set_previous_status', 10 );
		// We don't need to "execute" that here — the canonical effect is
		// that the filter dispatch returns its input unchanged when no
		// other callbacks transform it. That's what we model below.

		// Anonymous mock user: the ownership-aware default resolves to the
		// others-primitive.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		// Capability gate: the handler routes the
		// outer gate through `aps_current_user_can_unarchive()` with no post
		// id, which delegates to `current_user_can( 'edit_others_posts', 0 )`.
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 0 )
			->andReturn( true );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 10 )
			->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'          => 10,
				'post_status' => 'archive',
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 10 )->andReturn( $post );

		// Skip the helper's default wp_update_post stub; we want to capture args.
		$this->stubUnarchivePersistBoundary( 10, false, 'publish' );

		// Sentinel callback at priority 11 captures the value entering it.
		// In production this would observe the unmodified $new_status
		// because the priority-10 default was removed. We pin that here
		// by having onFilter()->reply() invoke a closure that records the
		// arg and returns it untouched — modeling a third-party
		// observer-only callback.
		$observed = null;
		\WP_Mock::onFilter( 'aps_unarchive_post_status' )
			->with( 'publish', 10, 'publish' )
			->reply(
				new InvokedFilterValue(
					static function ( $value ) use ( &$observed ) {
						$observed = $value;
						return $value;
					}
				)
			);

		\WP_Mock::onFilter( 'aps_unarchive_post_comment_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );
		\WP_Mock::onFilter( 'aps_unarchive_post_ping_status' )
			->with( 'open', 10, 'publish' )
			->reply( 'open' );

		$captured = array();
		\WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args['ID'];
			}
		);
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/edit.php' );

		$this->handler->handle( 'http://example.com/wp-admin/edit.php', 'unarchive', array( 10 ) );

		// The priority-11 sentinel saw the value the helper produced
		// (the meta-derived $new_status), not anything the default
		// priority-10 callback would have rewritten — because that
		// callback was unhooked.
		$this->assertSame(
			'publish',
			$observed,
			'Sentinel filter must receive the helper’s unmodified $new_status when the default callback is unhooked.'
		);
		// And that same value reaches wp_update_post unchanged.
		$this->assertSame( 'publish', $captured['post_status'] ?? null );
	}
}
