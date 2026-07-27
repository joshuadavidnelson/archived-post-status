<?php
/**
 * Admin\BulkActionResult Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\BulkActionResult
 *
 * Pure value-object tests for the bulk-action accumulator. The only WP
 * surface this class touches is add_query_arg(), which is mocked per test
 * so we can verify the query args that get applied to the redirect URL.
 */

use ArchivedPostStatus\Admin\BulkActionResult;
use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * BulkActionResult test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\BulkActionResult
 */
class BulkActionResultTest extends TestCase {

	/**
	 * A freshly-constructed result has zero counts and an empty id list.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::count
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::locked
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::ids
	 */
	public function test_fresh_result_has_zero_counts_and_no_ids() {
		$result = new BulkActionResult();

		$this->assertSame( 0, $result->count() );
		$this->assertSame( 0, $result->locked() );
		$this->assertSame( array(), $result->ids() );
	}

	/**
	 * record() increments the success count and appends the post id, in order.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::count
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::ids
	 */
	public function test_record_increments_count_and_collects_ids_in_order() {
		$result = new BulkActionResult();

		$result->record( 101 );
		$result->record( 202 );
		$result->record( 303 );

		$this->assertSame( 3, $result->count() );
		$this->assertSame( array( 101, 202, 303 ), $result->ids() );
	}

	/**
	 * record_locked() only increments the locked counter — not success count.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_locked
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::locked
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::count
	 */
	public function test_record_locked_increments_only_locked_counter() {
		$result = new BulkActionResult();

		$result->record_locked();
		$result->record_locked();

		$this->assertSame( 2, $result->locked() );
		$this->assertSame( 0, $result->count() );
	}

	/**
	 * apply_to_url() always adds the action's query arg with the success count.
	 * When locked/ids are all zero/empty, only the action key is added.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::apply_to_url
	 */
	public function test_apply_to_url_adds_only_action_query_arg_when_no_skips() {
		$result = new BulkActionResult();
		$result->record( 7 );
		$result->record( 8 );

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing( function ( $key, $value, $url ) use ( &$captured ) {
				$captured[] = array( $key, $value );
				return $url . '?' . $key . '=' . $value;
			} );

		$final_url = $result->apply_to_url( 'http://example.test/wp-admin/edit.php', ArchiveAction::Archive );

		// The action's query_arg is added with the success count …
		$this->assertContains( array( 'archived', 2 ), $captured );
		// … the ids list is added as a comma-joined string …
		$this->assertContains( array( 'ids', '7,8' ), $captured );
		// … and no locked arg is appended when that counter is 0.
		foreach ( $captured as $pair ) {
			$this->assertNotSame( 'locked', $pair[0] );
		}
		$this->assertStringContainsString( 'archived=2', $final_url );
	}

	/**
	 * apply_to_url() includes 'locked=N' when at least one locked post was
	 * recorded. The unarchive action contributes its own query_arg ('unarchived').
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::apply_to_url
	 */
	public function test_apply_to_url_adds_locked_arg_when_recorded() {
		$result = new BulkActionResult();
		$result->record( 5 );
		$result->record_locked();
		$result->record_locked();

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing( function ( $key, $value, $url ) use ( &$captured ) {
				$captured[] = array( $key, $value );
				return $url;
			} );

		$result->apply_to_url( 'http://example.test/wp-admin/edit.php', ArchiveAction::Unarchive );

		$this->assertContains( array( 'unarchived', 1 ), $captured );
		$this->assertContains( array( 'locked', 2 ), $captured );
		$this->assertContains( array( 'ids', '5' ), $captured );
	}

	/**
	 * apply_to_url() omits the 'ids' query arg entirely when no post was
	 * successfully recorded — the redirect can advertise only the skip counts.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::apply_to_url
	 */
	public function test_apply_to_url_omits_ids_when_no_successful_records() {
		$result = new BulkActionResult();
		$result->record_locked();

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing( function ( $key, $value, $url ) use ( &$captured ) {
				$captured[] = array( $key, $value );
				return $url;
			} );

		$result->apply_to_url( 'http://example.test/wp-admin/edit.php', ArchiveAction::Archive );

		// 'archived=0' is always added; 'locked=1' is added; 'ids' is NOT.
		$this->assertContains( array( 'archived', 0 ), $captured );
		$this->assertContains( array( 'locked', 1 ), $captured );
		foreach ( $captured as $pair ) {
			$this->assertNotSame( 'ids', $pair[0] );
		}
	}

	// -----------------------------------------------------------------------
	// reason-bucket API
	// -----------------------------------------------------------------------

	/**
	 * record_denied() bumps the denied bucket only. denied_count() reports
	 * the new value. The other buckets stay at zero.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_denied
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::denied_count
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::not_found_count
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::wrong_status_count
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::skipped_count
	 */
	public function test_record_denied_increments_only_denied_bucket() {
		$result = new BulkActionResult();
		$result->record_denied( 11 );
		$result->record_denied( 22 );

		$this->assertSame( 2, $result->denied_count() );
		$this->assertSame( 0, $result->not_found_count() );
		$this->assertSame( 0, $result->wrong_status_count() );
		$this->assertSame( 0, $result->count() );
		$this->assertSame( 2, $result->skipped_count() );
	}

	/**
	 * record_not_found() bumps the not_found bucket only.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_not_found
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::not_found_count
	 */
	public function test_record_not_found_increments_only_not_found_bucket() {
		$result = new BulkActionResult();
		$result->record_not_found( 99 );

		$this->assertSame( 1, $result->not_found_count() );
		$this->assertSame( 0, $result->denied_count() );
		$this->assertSame( 0, $result->wrong_status_count() );
		$this->assertSame( 1, $result->skipped_count() );
	}

	/**
	 * record_wrong_status() bumps the wrong_status bucket only.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::record_wrong_status
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::wrong_status_count
	 */
	public function test_record_wrong_status_increments_only_wrong_status_bucket() {
		$result = new BulkActionResult();
		$result->record_wrong_status( 7 );
		$result->record_wrong_status( 8 );
		$result->record_wrong_status( 9 );

		$this->assertSame( 3, $result->wrong_status_count() );
		$this->assertSame( 0, $result->denied_count() );
		$this->assertSame( 0, $result->not_found_count() );
		$this->assertSame( 3, $result->skipped_count() );
	}

	/**
	 * apply_to_url() emits the per-bucket query args AND an aggregate
	 * `skipped=N` arg when any reason bucket is non-zero. Documents the
	 * URL contract NoticeBuilder consumes for the "X skipped"
	 * banner.
	 *
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::apply_to_url
	 * @covers ArchivedPostStatus\Admin\BulkActionResult::skipped_count
	 */
	public function test_apply_to_url_emits_per_bucket_and_aggregate_skipped_args() {
		$result = new BulkActionResult();
		$result->record( 1 );
		$result->record_denied( 100 );
		$result->record_not_found( 200 );
		$result->record_wrong_status( 300 );
		$result->record_locked();

		$captured = array();
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing( function ( $key, $value, $url ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return $url;
			} );

		$result->apply_to_url( 'http://example.test/wp-admin/edit.php', ArchiveAction::Archive );

		$this->assertSame( 1, $captured['archived'] ?? null );
		$this->assertSame( 1, $captured['denied'] ?? null );
		$this->assertSame( 1, $captured['not_found'] ?? null );
		$this->assertSame( 1, $captured['wrong_status'] ?? null );
		$this->assertSame( 1, $captured['locked'] ?? null );
		// Aggregate: denied + not_found + wrong_status + locked = 4.
		$this->assertSame( 4, $captured['skipped'] ?? null );
	}
}
