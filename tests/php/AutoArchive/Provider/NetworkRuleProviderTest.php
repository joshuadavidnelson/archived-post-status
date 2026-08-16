<?php
/**
 * AutoArchive\Provider\NetworkRuleProvider Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\Settings\NetworkStore;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider
 */
class NetworkRuleProviderTest extends TestCase {

	private NetworkRuleProvider $provider;

	public function set_up() {
		parent::set_up();
		$this->provider = new NetworkRuleProvider();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
		NetworkStore::flush_cache();
		parent::tear_down();
	}

	/**
	 * Stub is_multisite() + get_site_option() so NetworkActivation::active()
	 * resolves true for `archived-post-status/archived-post-status.php`.
	 */
	private function stubNetworkActivated(): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => true ) );
	}

	/**
	 * @param array<string, mixed> $stored The stored network option array.
	 */
	private function stubNetworkOption( array $stored ): void {
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( $stored );
	}

	/**
	 * level() is literally 'network' — the dynamic
	 * aps_auto_archive_{$level}_rule hook in RuleChain depends on this exact
	 * string (the phase-4 ledger constraint).
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::level
	 */
	public function test_level_is_literally_network() {
		$this->assertSame( 'network', $this->provider->level() );
	}

	/**
	 * Non-multisite: rules_for() returns [] — is_multisite() must
	 * short-circuit before anything else, so get_site_option()/
	 * get_network_option() are never called.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_returns_empty_array_when_not_multisite() {
		\WP_Mock::userFunction( 'is_multisite' )->once()->andReturn( false );
		\WP_Mock::userFunction( 'get_site_option' )->never();
		\WP_Mock::userFunction( 'get_network_option' )->never();

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * Multisite, but this plugin is not network-activated: rules_for()
	 * returns [] without ever reading the network option.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_returns_empty_array_when_multisite_but_not_network_activated() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( 'some-other-plugin/some-other-plugin.php' => true ) );
		\WP_Mock::userFunction( 'get_network_option' )->never();

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * Network-activated but auto-archive disabled at the network level:
	 * rules_for() returns [].
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_returns_empty_array_when_network_activated_but_auto_archive_disabled() {
		$this->stubNetworkActivated();
		$this->stubNetworkOption( array( 'auto_archive_enabled' => false ) );

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * Network-activated, enabled: returns exactly one correctly-populated
	 * Rule.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_returns_one_rule_when_network_activated_and_enabled() {
		$this->stubNetworkActivated();
		$this->stubNetworkOption(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_days'       => 365,
				'auto_archive_child_mode' => 'locked',
			)
		);

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertInstanceOf( Rule::class, $rules[0] );
		$this->assertSame( 'network', $rules[0]->level );
		$this->assertSame( 365, $rules[0]->days );
		$this->assertSame( ChildMode::Locked, $rules[0]->child_mode );
		$this->assertSame( 'Network default', $rules[0]->label );
	}

	/**
	 * Network-activated, enabled, but no days value stored yet — still
	 * returns a Rule with null days: the network level may only be freezing
	 * child_mode, with the days value coming from a lower level.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_returns_rule_with_null_days_when_only_child_mode_is_set() {
		$this->stubNetworkActivated();
		$this->stubNetworkOption(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_child_mode' => 'off',
			)
		);

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertNull( $rules[0]->days );
		$this->assertSame( ChildMode::Off, $rules[0]->child_mode );
	}

	/**
	 * A misbehaving/corrupt stored auto_archive_child_mode value falls back
	 * to ChildMode::Open rather than fataling on a null enum.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_falls_back_to_open_child_mode_on_unrecognized_stored_value() {
		$this->stubNetworkActivated();
		$this->stubNetworkOption(
			array(
				'auto_archive_enabled'    => true,
				'auto_archive_child_mode' => 'not-a-real-mode',
			)
		);

		$rules = $this->provider->rules_for( 42 );

		$this->assertSame( ChildMode::Open, $rules[0]->child_mode );
	}

	/**
	 * Independent of $post_id -- get_post_type() is never called. The
	 * network level has no post-type opt-in of its own.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_never_reads_post_type() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_type' )->never();

		$this->provider->rules_for( 99 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * No fatal when wp-admin/includes/plugin.php has not been loaded --
	 * NetworkRuleProvider never calls is_plugin_active_for_network() at all.
	 * Every scenario in this file already proves this implicitly (the
	 * function is genuinely undefined in this test runtime); this test
	 * makes the claim explicit.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::rules_for
	 */
	public function test_rules_for_never_calls_is_plugin_active_for_network() {
		$this->assertFalse(
			function_exists( 'is_plugin_active_for_network' ),
			'This test only proves something if the function genuinely is not defined in the test runtime.'
		);

		$this->stubNetworkActivated();
		$this->stubNetworkOption( array( 'auto_archive_enabled' => false ) );

		$this->assertSame( array(), $this->provider->rules_for( 1 ), 'Resolves without fataling on the missing wp-admin function.' );
	}
}
