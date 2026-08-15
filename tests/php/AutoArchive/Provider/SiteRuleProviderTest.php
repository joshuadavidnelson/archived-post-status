<?php
/**
 * AutoArchive\Provider\SiteRuleProvider Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider;
use ArchivedPostStatus\AutoArchive\Rule;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider
 */
class SiteRuleProviderTest extends TestCase {

	private SiteRuleProvider $provider;

	public function set_up() {
		parent::set_up();
		$this->provider = new SiteRuleProvider();
	}

	/**
	 * level() is literally 'site' — the dynamic
	 * aps_auto_archive_{$level}_rule hook in RuleChain depends on this exact
	 * string (the phase-4 ledger constraint).
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::level
	 */
	public function test_level_is_literally_site() {
		$this->assertSame( 'site', $this->provider->level() );
	}

	/**
	 * Disabled site-wide: rules_for() returns [] regardless of anything
	 * else, and never even reads the post's type.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::rules_for
	 */
	public function test_rules_for_returns_empty_array_when_auto_archive_disabled() {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( false );
		\WP_Mock::userFunction( 'get_post_type' )->never();

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * Enabled, but the post's type is not in the opted-in list: rules_for()
	 * returns [] — an empty auto_archive_types list opts NOTHING in, so this
	 * also covers the "nothing configured yet" default state.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::rules_for
	 */
	public function test_rules_for_returns_empty_array_for_unlisted_post_type() {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'page' );

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * Enabled, matching post type: returns exactly one correctly-populated
	 * Rule, reading days and child_mode through their own filters.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::rules_for
	 */
	public function test_rules_for_returns_one_rule_when_enabled_and_post_type_matches() {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( 30 );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'locked' );
		\WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( $text ) => $text );

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertInstanceOf( Rule::class, $rules[0] );
		$this->assertSame( 'site', $rules[0]->level );
		$this->assertSame( 30, $rules[0]->days );
		$this->assertSame( ChildMode::Locked, $rules[0]->child_mode );
		$this->assertSame( 'Site default', $rules[0]->label );
	}

	/**
	 * A site with auto-archive enabled and a matching post type, but no
	 * days value set at this level, still returns a Rule — the site level
	 * may only be freezing child_mode, with the days value coming from a
	 * different level in the chain.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::rules_for
	 */
	public function test_rules_for_returns_rule_with_null_days_when_only_child_mode_is_set() {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( null );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'off' );
		\WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( $text ) => $text );

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertNull( $rules[0]->days );
		$this->assertSame( ChildMode::Off, $rules[0]->child_mode );
	}

	/**
	 * A misbehaving `aps_auto_archive_child_mode` filter returning a value
	 * that is not one of ChildMode's cases falls back to ChildMode::Open
	 * rather than fataling — the same defensive posture RuleChain takes
	 * with its own filter returns.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::rules_for
	 */
	public function test_rules_for_falls_back_to_open_child_mode_on_unrecognized_filter_return() {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( null );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'not-a-real-mode' );
		\WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( $text ) => $text );

		$rules = $this->provider->rules_for( 42 );

		$this->assertSame( ChildMode::Open, $rules[0]->child_mode );
	}

	/**
	 * Every setting is read through its own `aps_*` filter, not through
	 * Store::get() directly — this is what lets a developer override at
	 * priority ≤ 10 win over a stored value. The onFilter()->with()
	 * expectations throughout this class are the proof: WP_Mock only
	 * satisfies them if the SUT actually calls apply_filters() with that
	 * exact hook name and incoming default.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::rules_for
	 */
	public function test_rules_for_reads_every_value_through_its_aps_filter() {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( 90 );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'open' );
		\WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( $text ) => $text );

		$rules = $this->provider->rules_for( 42 );

		$this->assertSame( 90, $rules[0]->days );
	}
}
