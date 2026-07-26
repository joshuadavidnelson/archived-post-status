<?php
/**
 * Archive\ReadOnlyPolicy Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ReadOnlyPolicy
 *
 * Phase 3A extraction (0.4.0): mirrors the facade tests in FunctionsTest
 * (`test_is_read_only_defaults_to_true_and_applies_filter`,
 * `test_read_only_mode_can_be_disabled_via_filter`) but exercises the
 * lifted SUT directly.
 */

use ArchivedPostStatus\Archive\ReadOnlyPolicy;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ReadOnlyPolicy
 */
class ReadOnlyPolicyTest extends TestCase {

	/**
	 * Default-path: with no filter override, archived content is read-only.
	 * The default `true` is applied through `aps_is_read_only`.
	 *
	 * @covers ArchivedPostStatus\Archive\ReadOnlyPolicy::enabled
	 */
	public function test_enabled_defaults_to_true_and_applies_filter() {
		\WP_Mock::expectFilter( 'aps_is_read_only', true );

		$this->assertTrue( ReadOnlyPolicy::enabled() );
	}

	/**
	 * Filter-override path: sites that want to allow direct edits to
	 * archived posts can flip the policy off via `aps_is_read_only`.
	 *
	 * @covers ArchivedPostStatus\Archive\ReadOnlyPolicy::enabled
	 */
	public function test_enabled_can_be_disabled_via_filter() {
		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( false );

		$this->assertFalse( ReadOnlyPolicy::enabled() );
	}

	/**
	 * Edge case: non-boolean filter returns are coerced via the `(bool)`
	 * cast that the SUT applies. Pin the cast so a future change can't
	 * silently regress the contract.
	 *
	 * @covers ArchivedPostStatus\Archive\ReadOnlyPolicy::enabled
	 */
	public function test_enabled_casts_non_boolean_filter_return_to_bool() {
		\WP_Mock::onFilter( 'aps_is_read_only' )
			->with( true )
			->reply( 0 );

		$this->assertFalse( ReadOnlyPolicy::enabled() );
	}
}
