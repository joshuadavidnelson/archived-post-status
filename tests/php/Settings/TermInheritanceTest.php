<?php
/**
 * Settings\TermInheritance Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\TermInheritance
 *
 * Exercised through REAL NetworkRuleProvider + Store reads, over a REAL
 * RuleResolver -- not a hand-built CascadeInheritance. No filter is
 * explicitly registered for `aps_auto_archive_taxonomies` (irrelevant here)
 * or any RuleResolver-internal concern -- this class calls RuleResolver
 * directly, not through RuleChain, so no `aps_auto_archive_*` filters are in
 * play at all.
 */

use ArchivedPostStatus\Settings\NetworkStore;
use ArchivedPostStatus\Settings\Store;
use ArchivedPostStatus\Settings\TermInheritance;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\TermInheritance
 */
class TermInheritanceTest extends TestCase {

	public function set_up() {
		parent::set_up();
		Store::flush_cache();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
		Store::flush_cache();
		NetworkStore::flush_cache();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $stored The stored network option array.
	 */
	private function stubNetworkActivatedWith( array $stored ): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => true ) );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( $stored );
	}

	/**
	 * @param array<string, mixed> $stored The stored site option array.
	 */
	private function stubSiteStore( array $stored ): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( $stored );
	}

	/**
	 * A Locked network value freezes everything below it: the term level
	 * inherits the network's own days value, locked, badged with the
	 * network's own label.
	 *
	 * @covers ArchivedPostStatus\Settings\TermInheritance::resolve
	 */
	public function test_locked_network_value_freezes_the_term_level() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 365,
				'auto_archive_child_mode' => 'locked',
			)
		);
		$this->stubSiteStore( array( 'auto_archive_enabled' => true, 'auto_archive_days' => 12 ) );

		$inheritance = TermInheritance::resolve();

		$this->assertSame( 365, $inheritance->days );
		$this->assertSame( 'Network default', $inheritance->origin_label );
		$this->assertTrue( $inheritance->locked );
		$this->assertFalse( $inheritance->off );
		$this->assertSame( 'Network default', $inheritance->frozen_by_label );
	}

	/**
	 * An Off network value hides the term control entirely.
	 *
	 * @covers ArchivedPostStatus\Settings\TermInheritance::resolve
	 */
	public function test_off_network_value_hides_the_term_control() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 180,
				'auto_archive_child_mode' => 'off',
			)
		);
		$this->stubSiteStore( array() );

		$inheritance = TermInheritance::resolve();

		$this->assertTrue( $inheritance->off );
		$this->assertFalse( $inheritance->locked );
		$this->assertSame( 'Network default', $inheritance->frozen_by_label );
	}

	/**
	 * An Open network value is overridden by the site's own value -- the
	 * term level inherits the site's contribution, unfrozen.
	 *
	 * @covers ArchivedPostStatus\Settings\TermInheritance::resolve
	 */
	public function test_open_network_value_is_overridden_by_the_site_value() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 180,
				'auto_archive_child_mode' => 'open',
			)
		);
		$this->stubSiteStore( array( 'auto_archive_enabled' => true, 'auto_archive_days' => 12 ) );

		$inheritance = TermInheritance::resolve();

		$this->assertSame( 12, $inheritance->days );
		$this->assertSame( 'Site default', $inheritance->origin_label );
		$this->assertFalse( $inheritance->locked );
		$this->assertFalse( $inheritance->off );
		$this->assertNull( $inheritance->frozen_by_label );
	}

	/**
	 * Not network-activated: the term level inherits exactly the site's own
	 * rule, same as a single-site install.
	 *
	 * @covers ArchivedPostStatus\Settings\TermInheritance::resolve
	 */
	public function test_not_network_activated_inherits_the_site_rule_alone() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		$this->stubSiteStore(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 12,
				'auto_archive_child_mode' => 'locked',
			)
		);

		$inheritance = TermInheritance::resolve();

		$this->assertSame( 12, $inheritance->days );
		$this->assertSame( 'Site default', $inheritance->origin_label );
		$this->assertTrue( $inheritance->locked );
		$this->assertSame( 'Site default', $inheritance->frozen_by_label );
	}

	/**
	 * A corrupt/unrecognized stored site child_mode falls back to Open
	 * rather than fataling on a null enum — same defensive shape as every
	 * other ChildMode-hydrating reader in this release.
	 *
	 * @covers ArchivedPostStatus\Settings\TermInheritance::resolve
	 */
	public function test_falls_back_to_open_child_mode_for_an_unrecognized_stored_site_value() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		$this->stubSiteStore(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 6,
				'auto_archive_child_mode' => 'not-a-real-mode',
			)
		);

		$inheritance = TermInheritance::resolve();

		$this->assertFalse( $inheritance->locked );
		$this->assertFalse( $inheritance->off );
	}

	/**
	 * Neither level sets anything: an unfrozen, empty inheritance.
	 *
	 * @covers ArchivedPostStatus\Settings\TermInheritance::resolve
	 */
	public function test_nothing_inherited_when_neither_level_sets_anything() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		$this->stubSiteStore( array( 'auto_archive_enabled' => false ) );

		$inheritance = TermInheritance::resolve();

		$this->assertNull( $inheritance->days );
		$this->assertNull( $inheritance->origin_label );
		$this->assertFalse( $inheritance->frozen() );
	}
}
