<?php
/**
 * Network + Site cascade — end-to-end integration Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RuleChain
 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider
 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider
 *
 * The §4.1 rows involving the network level, exercised end to end through a
 * REAL RuleChain over REAL NetworkRuleProvider + SiteRuleProvider instances
 * (general to specific, matching Plugin::schedule_hookables()'s own
 * ordering) — not fakes, and not RuleResolver called directly. No filter is
 * explicitly registered for RuleChain's own internal hooks
 * (`aps_auto_archive_levels`, `aps_auto_archive_{level}_rule`,
 * `aps_auto_archive_rule_chain`, `aps_auto_archive_resolved_rule`) — an
 * unregistered WP_Mock filter passes its value through unchanged, exactly
 * like a real, unhooked apply_filters() (see RuleChainTest's own class
 * docblock for the same technique).
 */

use ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider;
use ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider;
use ArchivedPostStatus\AutoArchive\RuleChain;
use ArchivedPostStatus\Settings\NetworkStore;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleChain
 */
class NetworkSiteCascadeTest extends TestCase {

	public function set_up() {
		parent::set_up();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
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
	 * Stub the site-level `aps_*` filter chain SiteRuleProvider reads,
	 * mirroring SiteRuleProviderTest's own stubSiteSettings()-style helper.
	 *
	 * @param int    $days       Site auto_archive_days.
	 * @param string $child_mode Site auto_archive_child_mode.
	 */
	private function stubSiteRuleFor( int $post_id, int $days, string $child_mode = 'open' ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( $post_id )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( $days );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( $child_mode );
	}

	private function chain(): RuleChain {
		return new RuleChain( array( new NetworkRuleProvider(), new SiteRuleProvider() ) );
	}

	/**
	 * §4.1 row: "Network 365d (Locked), site 12d" -> 365, frozen by network.
	 * A Locked network value freezes the site level below it -- the site's
	 * own 12-day rule is never consulted for the resolved outcome.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleChain::resolve_for
	 */
	public function test_locked_network_value_freezes_the_site_below_it() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 365,
				'auto_archive_child_mode' => 'locked',
			)
		);
		$this->stubSiteRuleFor( 42, 12, 'open' );

		$resolved = $this->chain()->resolve_for( 42 );

		$this->assertSame( 365, $resolved->days );
		$this->assertSame( 'network', $resolved->origin_level );
		$this->assertSame( 'network', $resolved->frozen_by );
	}

	/**
	 * §4.1 row: an Off network value likewise freezes the site level below
	 * it -- same resolved value, same frozen_by, differing only in
	 * ChildMode's UI-facing case, not in RuleResolver's outcome.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleChain::resolve_for
	 */
	public function test_off_network_value_freezes_the_site_below_it() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 180,
				'auto_archive_child_mode' => 'off',
			)
		);
		$this->stubSiteRuleFor( 42, 12, 'open' );

		$resolved = $this->chain()->resolve_for( 42 );

		$this->assertSame( 180, $resolved->days );
		$this->assertSame( 'network', $resolved->origin_level );
		$this->assertSame( 'network', $resolved->frozen_by );
	}

	/**
	 * §4.1 row: an Open network value is overridden by a site value -- the
	 * site's own 12-day rule wins because the network level never freezes
	 * the walk.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleChain::resolve_for
	 */
	public function test_open_network_value_is_overridden_by_a_site_value() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 180,
				'auto_archive_child_mode' => 'open',
			)
		);
		$this->stubSiteRuleFor( 42, 12, 'open' );

		$resolved = $this->chain()->resolve_for( 42 );

		$this->assertSame( 12, $resolved->days );
		$this->assertSame( 'site', $resolved->origin_level );
		$this->assertNull( $resolved->frozen_by );
	}

	/**
	 * Not network-activated: the network level contributes nothing, so the
	 * resolved outcome is exactly the site's own rule, same as a
	 * single-site install (site level unaffected by the network provider's
	 * mere presence in the chain).
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleChain::resolve_for
	 */
	public function test_not_network_activated_resolves_to_the_site_rule_alone() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		$this->stubSiteRuleFor( 42, 12, 'open' );

		$resolved = $this->chain()->resolve_for( 42 );

		$this->assertSame( 12, $resolved->days );
		$this->assertSame( 'site', $resolved->origin_level );
		$this->assertNull( $resolved->frozen_by );
	}
}
