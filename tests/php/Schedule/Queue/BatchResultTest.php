<?php
/**
 * Schedule\Queue\BatchResult Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult
 */

use ArchivedPostStatus\Schedule\Queue\BatchResult;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult
 */
class BatchResultTest extends TestCase {

	// -----------------------------------------------------------------------
	// is_dry() / has_more_work() — the dry/more-to-do boundary
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::is_dry
	 */
	public function test_is_dry_is_true_when_remaining_is_zero() {
		$result = new BatchResult( 5, 0, 0, false );

		$this->assertTrue( $result->is_dry() );
	}

	/**
	 * One item still remaining is the boundary case: not dry.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::is_dry
	 */
	public function test_is_dry_is_false_when_one_item_remains() {
		$result = new BatchResult( 5, 0, 1, false );

		$this->assertFalse( $result->is_dry() );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::has_more_work
	 */
	public function test_has_more_work_is_false_when_dry() {
		$result = new BatchResult( 5, 0, 0, false );

		$this->assertFalse( $result->has_more_work() );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::has_more_work
	 */
	public function test_has_more_work_is_true_when_remaining_is_positive() {
		$result = new BatchResult( 5, 0, 3, false );

		$this->assertTrue( $result->has_more_work() );
	}

	// -----------------------------------------------------------------------
	// should_continue_now()
	// -----------------------------------------------------------------------

	/**
	 * Work remains and the batch did not stop on budget: a runner should
	 * call process_batch() again immediately.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::should_continue_now
	 */
	public function test_should_continue_now_is_true_with_work_remaining_and_budget_not_exhausted() {
		$result = new BatchResult( 5, 0, 10, false );

		$this->assertTrue( $result->should_continue_now() );
	}

	/**
	 * Work remains, but the budget is exhausted: this is exactly the case
	 * a runner must NOT loop immediately — a continuation is scheduled
	 * for later instead.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::should_continue_now
	 */
	public function test_should_continue_now_is_false_when_budget_exhausted_even_with_work_remaining() {
		$result = new BatchResult( 5, 0, 10, true );

		$this->assertFalse( $result->should_continue_now() );
	}

	/**
	 * The queue is dry and the budget was not exhausted: nothing to do,
	 * nothing forces a stop either — should_continue_now() is still false.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::should_continue_now
	 */
	public function test_should_continue_now_is_false_when_dry_and_budget_not_exhausted() {
		$result = new BatchResult( 5, 0, 0, false );

		$this->assertFalse( $result->should_continue_now() );
	}

	/**
	 * The queue is dry and the budget was also exhausted (the last item
	 * used up the budget on its way out): still false.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BatchResult::should_continue_now
	 */
	public function test_should_continue_now_is_false_when_dry_and_budget_exhausted() {
		$result = new BatchResult( 5, 0, 0, true );

		$this->assertFalse( $result->should_continue_now() );
	}
}
