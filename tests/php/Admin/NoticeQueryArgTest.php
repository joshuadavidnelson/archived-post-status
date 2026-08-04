<?php
/**
 * Admin\NoticeQueryArg Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\NoticeQueryArg
 *
 * Pure-enum tests: pins the exact case values and the order values() returns
 * them in, since PostList and BulkActionResult characterization tests assert
 * exact resulting arrays derived from this enum.
 */

use ArchivedPostStatus\Admin\NoticeQueryArg;

/**
 * NoticeQueryArg test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\NoticeQueryArg
 */
class NoticeQueryArgTest extends TestCase {

	/**
	 * Each case's backing value is the exact string previously hand-copied
	 * across PostList, BulkActionHandler, and BulkActionResult.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeQueryArg
	 */
	public function test_case_values_are_the_expected_strings() {
		$this->assertSame( 'archived', NoticeQueryArg::Archived->value );
		$this->assertSame( 'unarchived', NoticeQueryArg::Unarchived->value );
		$this->assertSame( 'ids', NoticeQueryArg::Ids->value );
		$this->assertSame( 'locked', NoticeQueryArg::Locked->value );
		$this->assertSame( 'denied', NoticeQueryArg::Denied->value );
		$this->assertSame( 'not_found', NoticeQueryArg::NotFound->value );
		$this->assertSame( 'wrong_status', NoticeQueryArg::WrongStatus->value );
		$this->assertSame( 'skipped', NoticeQueryArg::Skipped->value );
	}

	/**
	 * values() must return all eight strings in exactly this order — the
	 * order PostList::query_vars() (also the removable_query_args filter
	 * callback), and BulkActionHandler previously kept in hand-sync — so a
	 * future case addition can't silently half-land.
	 *
	 * @covers ArchivedPostStatus\Admin\NoticeQueryArg::values
	 */
	public function test_values_returns_all_eight_strings_in_declaration_order() {
		$this->assertSame(
			array(
				'archived',
				'unarchived',
				'ids',
				'locked',
				'denied',
				'not_found',
				'wrong_status',
				'skipped',
			),
			NoticeQueryArg::values()
		);
	}
}
