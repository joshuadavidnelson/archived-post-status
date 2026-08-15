<?php
/**
 * Schedule\Queue\BudgetFactory Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory
 */

use ArchivedPostStatus\Schedule\Queue\BudgetFactory;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory
 */
class BudgetFactoryTest extends TestCase {

	/** @var string|false Real ini value, saved to restore after each test. */
	private $original_time_limit;

	/** @var string|false Real ini value, saved to restore after each test. */
	private $original_memory_limit;

	/**
	 * WP_Mock refuses to stub internal PHP functions (ini_get() among
	 * them), so these tests drive BudgetFactory through the real ini
	 * directives with ini_set() instead of mocking the reader — saving
	 * and restoring the process's actual values around each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_time_limit      = ini_get( 'max_execution_time' );
		$this->original_memory_limit    = ini_get( 'memory_limit' );
	}

	public function tear_down() {
		ini_set( 'max_execution_time', $this->original_time_limit );
		ini_set( 'memory_limit', $this->original_memory_limit );

		parent::tear_down();
	}

	/**
	 * Set the real max_execution_time ini directive for this test.
	 *
	 * @param string $value The raw ini string ini_get() should then return.
	 */
	private function mockTimeLimitIni( string $value ): void {
		ini_set( 'max_execution_time', $value );
	}

	/**
	 * Set the real memory_limit ini directive for this test, plus, when
	 * not "-1", stub the wp_convert_hr_to_bytes() parse of it — that is a
	 * WP core function and must be stubbed, per these being isolated unit
	 * tests.
	 *
	 * @param string $raw   The raw ini string ini_get() should then return.
	 * @param int    $bytes The bytes wp_convert_hr_to_bytes( $raw ) resolves to.
	 */
	private function mockMemoryLimitIni( string $raw, int $bytes = 0 ): void {
		ini_set( 'memory_limit', $raw );

		if ( '-1' !== $raw ) {
			\WP_Mock::userFunction( 'wp_convert_hr_to_bytes' )
				->with( $raw )
				->andReturn( $bytes );
		}
	}

	/**
	 * Neutral memory stub ("128M" parsing to 128MB) for tests that only
	 * care about the time-limit half of build().
	 */
	private function mockNeutralMemory(): void {
		$this->mockMemoryLimitIni( '128M', 134217728 );
		\WP_Mock::onFilter( 'aps_queue_memory_percent' )
			->with( 90, 134217728 )
			->reply( 90 );
	}

	/**
	 * Neutral time stub (a mid-range value, under the cap) for tests that
	 * only care about the memory half of build().
	 */
	private function mockNeutralTime(): void {
		$this->mockTimeLimitIni( '15' );
		\WP_Mock::onFilter( 'aps_queue_time_limit' )
			->with( 15, 15 )
			->reply( 15 );
	}

	// -----------------------------------------------------------------------
	// time_limit() — via build()->time_limit
	// -----------------------------------------------------------------------

	/**
	 * The single most important test in this phase: PHP reporting
	 * max_execution_time = 0 (the normal case under WP-CLI and some FPM
	 * pools) must NOT be treated as "unlimited" — it engages the 30s
	 * fallback, which the 20s cap then reduces to 20.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_max_execution_time_zero_engages_fallback_not_unlimited() {
		$this->mockTimeLimitIni( '0' );
		$this->mockNeutralMemory();
		\WP_Mock::onFilter( 'aps_queue_time_limit' )
			->with( 20, 30 )
			->reply( 20 );

		$budget = BudgetFactory::build( 1000 );

		$this->assertSame( 20, $budget->time_limit );
	}

	/**
	 * An ini value above the cap is reduced to the 20s cap.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_time_limit_above_cap_is_reduced_to_the_cap() {
		$this->mockTimeLimitIni( '60' );
		$this->mockNeutralMemory();
		\WP_Mock::onFilter( 'aps_queue_time_limit' )
			->with( 20, 60 )
			->reply( 20 );

		$budget = BudgetFactory::build( 1000 );

		$this->assertSame( 20, $budget->time_limit );
	}

	/**
	 * An ini value already under the cap passes through unreduced — the
	 * cap only ever lowers, never raises.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_time_limit_under_cap_passes_through_unchanged() {
		$this->mockTimeLimitIni( '10' );
		$this->mockNeutralMemory();
		\WP_Mock::onFilter( 'aps_queue_time_limit' )
			->with( 10, 10 )
			->reply( 10 );

		$budget = BudgetFactory::build( 1000 );

		$this->assertSame( 10, $budget->time_limit );
	}

	/**
	 * aps_queue_time_limit can override the capped value entirely, e.g. a
	 * site deliberately restoring a higher ceiling.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_time_limit_filter_is_honoured() {
		$this->mockTimeLimitIni( '60' );
		$this->mockNeutralMemory();
		\WP_Mock::onFilter( 'aps_queue_time_limit' )
			->with( 20, 60 )
			->reply( 45 );

		$budget = BudgetFactory::build( 1000 );

		$this->assertSame( 45, $budget->time_limit );
	}

	// -----------------------------------------------------------------------
	// memory_limit() — via build()->memory_limit
	// -----------------------------------------------------------------------

	/**
	 * A plain shorthand value ("256M") parses via wp_convert_hr_to_bytes()
	 * and is reduced by the default 90% margin.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_memory_shorthand_parses_and_applies_default_percent() {
		$this->mockNeutralTime();
		$this->mockMemoryLimitIni( '256M', 268435456 );
		\WP_Mock::onFilter( 'aps_queue_memory_percent' )
			->with( 90, 268435456 )
			->reply( 90 );

		$budget = BudgetFactory::build( 1000 );

		$this->assertSame( intdiv( 268435456 * 90, 100 ), $budget->memory_limit );
	}

	/**
	 * "-1" (unlimited) hits the documented 256M concrete fallback, then
	 * has the same margin factor applied as any other value.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_memory_unlimited_hits_documented_fallback() {
		$this->mockNeutralTime();
		$this->mockMemoryLimitIni( '-1' );
		\WP_Mock::onFilter( 'aps_queue_memory_percent' )
			->with( 90, 268435456 )
			->reply( 90 );

		$budget = BudgetFactory::build( 1000 );

		$this->assertSame( intdiv( 268435456 * 90, 100 ), $budget->memory_limit );
	}

	/**
	 * aps_queue_memory_percent overrides the default 90% margin.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_memory_percent_filter_is_honoured() {
		$this->mockNeutralTime();
		$this->mockMemoryLimitIni( '256M', 268435456 );
		\WP_Mock::onFilter( 'aps_queue_memory_percent' )
			->with( 90, 268435456 )
			->reply( 50 );

		$budget = BudgetFactory::build( 1000 );

		$this->assertSame( intdiv( 268435456 * 50, 100 ), $budget->memory_limit );
	}

	// -----------------------------------------------------------------------
	// build() — started_at passthrough
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\BudgetFactory::build
	 */
	public function test_build_passes_started_at_through_unchanged() {
		$this->mockNeutralTime();
		$this->mockNeutralMemory();

		$budget = BudgetFactory::build( 424242 );

		$this->assertSame( 424242, $budget->started_at );
	}
}
