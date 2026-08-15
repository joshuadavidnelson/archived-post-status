<?php
/**
 * Schedule\Queue\Budget Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\Queue\Budget
 */

use ArchivedPostStatus\Schedule\Queue\Budget;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\Queue\Budget
 */
class BudgetTest extends TestCase {

	/**
	 * A fresh budget, well inside both ceilings, is not exceeded.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::exceeded
	 */
	public function test_exceeded_returns_false_well_inside_both_ceilings() {
		$budget = new Budget( 1000, 20, 1000000 );

		\WP_Mock::onFilter( 'aps_queue_budget_exceeded' )
			->with( false, $budget, 1005, 500000 )
			->reply( false );

		$this->assertFalse( $budget->exceeded( 1005, 500000 ) );
	}

	/**
	 * Exceeding the time ceiling alone is enough to report exceeded, even
	 * with memory nowhere near its limit.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::exceeded
	 */
	public function test_exceeded_returns_true_when_time_ceiling_alone_is_reached() {
		$budget = new Budget( 1000, 20, 1000000 );

		\WP_Mock::onFilter( 'aps_queue_budget_exceeded' )
			->with( true, $budget, 1025, 1 )
			->reply( true );

		$this->assertTrue( $budget->exceeded( 1025, 1 ) );
	}

	/**
	 * Exceeding the memory ceiling alone is enough to report exceeded, even
	 * with time nowhere near its limit.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::exceeded
	 */
	public function test_exceeded_returns_true_when_memory_ceiling_alone_is_reached() {
		$budget = new Budget( 1000, 20, 1000000 );

		\WP_Mock::onFilter( 'aps_queue_budget_exceeded' )
			->with( true, $budget, 1001, 2000000 )
			->reply( true );

		$this->assertTrue( $budget->exceeded( 1001, 2000000 ) );
	}

	/**
	 * Elapsed time exactly equal to the time limit counts as exceeded —
	 * documented `>=` boundary in Budget::time_exceeded().
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::time_exceeded
	 */
	public function test_time_exceeded_is_true_exactly_at_the_boundary() {
		$budget = new Budget( 1000, 20, 1000000 );

		$this->assertTrue( $budget->time_exceeded( 1020 ) );
	}

	/**
	 * One second under the boundary is not exceeded.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::time_exceeded
	 */
	public function test_time_exceeded_is_false_one_second_under_the_boundary() {
		$budget = new Budget( 1000, 20, 1000000 );

		$this->assertFalse( $budget->time_exceeded( 1019 ) );
	}

	/**
	 * Memory usage exactly equal to the memory limit counts as exceeded —
	 * the same documented `>=` boundary as time.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::memory_exceeded
	 */
	public function test_memory_exceeded_is_true_exactly_at_the_boundary() {
		$budget = new Budget( 1000, 20, 1000000 );

		$this->assertTrue( $budget->memory_exceeded( 1000000 ) );
	}

	/**
	 * One byte under the memory boundary is not exceeded.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::memory_exceeded
	 */
	public function test_memory_exceeded_is_false_one_byte_under_the_boundary() {
		$budget = new Budget( 1000, 20, 1000000 );

		$this->assertFalse( $budget->memory_exceeded( 999999 ) );
	}

	/**
	 * The aps_queue_budget_exceeded filter can force a false unfiltered
	 * result to true.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::exceeded
	 */
	public function test_exceeded_filter_can_force_true() {
		$budget = new Budget( 1000, 20, 1000000 );

		\WP_Mock::onFilter( 'aps_queue_budget_exceeded' )
			->with( false, $budget, 1005, 1 )
			->reply( true );

		$this->assertTrue( $budget->exceeded( 1005, 1 ) );
	}

	/**
	 * The aps_queue_budget_exceeded filter can force a true unfiltered
	 * result to false — e.g. a site that wants to ignore the guard entirely.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\Budget::exceeded
	 */
	public function test_exceeded_filter_can_force_false() {
		$budget = new Budget( 1000, 20, 1000000 );

		\WP_Mock::onFilter( 'aps_queue_budget_exceeded' )
			->with( true, $budget, 1025, 2000000 )
			->reply( false );

		$this->assertFalse( $budget->exceeded( 1025, 2000000 ) );
	}
}
