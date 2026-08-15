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
	 * current() reads the option, defaulting to 0 when unset.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RulesVersion::current
	 */
	public function test_current_defaults_to_zero_when_option_unset() {
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
}
