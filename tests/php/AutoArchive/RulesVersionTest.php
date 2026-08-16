<?php
/**
 * AutoArchive\RulesVersion Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RulesVersion
 */

use ArchivedPostStatus\AutoArchive\RulesVersion;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RulesVersion
 */
class RulesVersionTest extends TestCase {

	/**
	 * Single-site: the network half of the counter is always 0 and is never
	 * read. Every test below that does not call stubMultisite() is describing
	 * single-site behaviour.
	 */
	private function stubSingleSite(): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
	}

	/**
	 * Multisite with a given network-wide counter value.
	 *
	 * @param int $network_version The stored network counter.
	 */
	private function stubMultisite( int $network_version ): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, RulesVersion::NETWORK_OPTION_KEY, 0 )
			->andReturn( $network_version );
	}

	/**
	 * current() reads the option, defaulting to 0 when unset.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::current
	 */
	public function test_current_defaults_to_zero_when_option_unset() {
		$this->stubSingleSite();

		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 0 );

		$this->assertSame( 0, RulesVersion::current() );
	}

	/**
	 * current() returns the stored value, absint()-coerced.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::current
	 */
	public function test_current_returns_stored_value() {
		$this->stubSingleSite();

		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 5 );

		$this->assertSame( 5, RulesVersion::current() );
	}

	/**
	 * bump() increments the stored counter by one and persists it with
	 * autoload disabled — this option is read only by the stamper's stale
	 * query, never on every page load.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::bump
	 */
	public function test_bump_increments_and_persists_with_autoload_off() {
		$this->stubSingleSite();

		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 5 );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( RulesVersion::OPTION_KEY, 6, false )
			->andReturn( true );

		\WP_Mock::expectAction( 'aps_rules_version_bumped', 6 );

		$this->assertSame( 6, RulesVersion::bump() );
	}

	/**
	 * bump() starts a never-before-written counter at 1, not 0 — the first
	 * rule write must be distinguishable from "no rule has ever been
	 * written".
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::bump
	 */
	public function test_bump_starts_at_one_when_option_unset() {
		$this->stubSingleSite();

		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 0 );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( RulesVersion::OPTION_KEY, 1, false )
			->andReturn( true );

		\WP_Mock::expectAction( 'aps_rules_version_bumped', 1 );

		$this->assertSame( 1, RulesVersion::bump() );
	}

	// -----------------------------------------------------------------------
	// the network half of the counter
	// -----------------------------------------------------------------------

	/**
	 * On multisite, current() is the sum of the per-site and network-wide
	 * counters, so a network rule change is visible from every site.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::current
	 */
	public function test_current_sums_the_site_and_network_counters_on_multisite() {
		$this->stubMultisite( 4 );
		\WP_Mock::userFunction( 'get_option' )
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 3 );

		$this->assertSame( 7, RulesVersion::current() );
	}

	/**
	 * THE REASON THE NETWORK COUNTER EXISTS.
	 *
	 * A network admin changes the network rule once, on one request, which
	 * runs in the context of a single site. Every OTHER site on the network
	 * has an untouched per-site counter — yet each of them must still see a
	 * changed version, or the schedules they already stamped stay pointing at
	 * the old due date forever and the stale-refresh pass never picks them up.
	 *
	 * This test is that other site: its own counter never moves, and the
	 * version it reads changes anyway.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::current
	 */
	public function test_a_network_bump_changes_the_version_a_site_reads_without_touching_that_site() {
		$this->stubMultisite( 9 );
		\WP_Mock::userFunction( 'get_option' )
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 2 );

		$after_network_bump = RulesVersion::current();

		\WP_Mock::tearDown();
		\WP_Mock::setUp();

		// Same site, same untouched per-site counter, network counter one higher.
		$this->stubMultisite( 10 );
		\WP_Mock::userFunction( 'get_option' )
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 2 );

		$this->assertNotSame(
			$after_network_bump,
			RulesVersion::current(),
			'A network-level rule change must be visible from a site whose own counter never moved.'
		);
	}

	/**
	 * bump_network() increments the network-wide counter, not the per-site one.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::bump_network
	 */
	public function test_bump_network_increments_the_network_counter() {
		$this->stubMultisite( 4 );
		\WP_Mock::userFunction( 'get_option' )
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 3 );

		\WP_Mock::userFunction( 'update_network_option' )
			->once()
			->with( null, RulesVersion::NETWORK_OPTION_KEY, 5 )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->never();

		\WP_Mock::expectAction( 'aps_rules_version_bumped', 8 );

		$this->assertSame( 8, RulesVersion::bump_network() );
	}

	/**
	 * bump_network() is a no-op on single-site: there is no network level to
	 * change, and writing a network option there would be meaningless.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::bump_network
	 */
	public function test_bump_network_is_a_no_op_on_single_site() {
		$this->stubSingleSite();
		\WP_Mock::userFunction( 'get_option' )
			->with( RulesVersion::OPTION_KEY, 0 )
			->andReturn( 3 );

		\WP_Mock::userFunction( 'update_network_option' )->never();
		\WP_Mock::userFunction( 'update_option' )->never();

		$this->assertSame( 3, RulesVersion::bump_network() );
	}
}
