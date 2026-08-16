<?php
/**
 * Settings\NetworkStore Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\NetworkStore
 *
 * Mirrors StoreTest.php's shape exactly -- NetworkStore is Store's
 * get_network_option() twin. The one genuinely new thing this file pins:
 * defaults() is scoped to Schema::LEVEL_NETWORK, so a site-only key
 * (`scheduled_archive_post_types`, `auto_archive_types`,
 * `auto_archive_taxonomies`, `auto_archive_age_basis`,
 * `auto_archive_grace_days`, `is_read_only`) must never appear in what this
 * class persists.
 */

use ArchivedPostStatus\Settings\NetworkStore;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\NetworkStore
 */
class NetworkStoreTest extends TestCase {

	/**
	 * The network-applicable defaults table -- exactly the four keys whose
	 * Schema `levels` include LEVEL_NETWORK, per the plan's §5.8 table:
	 * `scheduled_archive_enabled`, `auto_archive_enabled`, `auto_archive_days`,
	 * `auto_archive_child_mode`. No site-only key appears here.
	 */
	private const NETWORK_DEFAULTS = array(
		'scheduled_archive_enabled' => true,
		'auto_archive_enabled'      => false,
		'auto_archive_days'         => null,
		'auto_archive_child_mode'   => 'open',
	);

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function defaultsWith( array $overrides ): array {
		return array_merge( self::NETWORK_DEFAULTS, $overrides );
	}

	public function set_up() {
		parent::set_up();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
		NetworkStore::flush_cache();
		parent::tear_down();
	}

	/**
	 * defaults() is scoped to LEVEL_NETWORK -- exactly the four
	 * network-applicable keys, none of the six site-only keys.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::defaults
	 */
	public function test_defaults_returns_only_network_applicable_keys() {
		$this->assertSame( self::NETWORK_DEFAULTS, NetworkStore::defaults() );
	}

	/**
	 * A site-only key must never appear in defaults() -- the exact hazard
	 * the phase-7 brief calls out: a network admin must not be able to see
	 * (or, downstream, write) a site-only key through the network option.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::defaults
	 */
	public function test_defaults_excludes_every_site_only_key() {
		$defaults = NetworkStore::defaults();

		foreach ( array( 'is_read_only', 'scheduled_archive_post_types', 'auto_archive_types', 'auto_archive_taxonomies', 'auto_archive_age_basis', 'auto_archive_grace_days' ) as $site_only_key ) {
			$this->assertArrayNotHasKey( $site_only_key, $defaults, "'{$site_only_key}' is site-only and must not appear in NetworkStore::defaults()." );
		}
	}

	/**
	 * get() returns the value from defaults() when the network option holds
	 * nothing for that key.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::get
	 * @covers ArchivedPostStatus\Settings\NetworkStore::all
	 */
	public function test_get_returns_default_when_key_not_in_option() {
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array() );

		$this->assertFalse( NetworkStore::get( 'auto_archive_enabled' ) );
	}

	/**
	 * get() returns the stored value when the network option contains an
	 * explicit setting for that key.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::get
	 * @covers ArchivedPostStatus\Settings\NetworkStore::all
	 */
	public function test_get_returns_stored_value_when_present() {
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array( 'auto_archive_days' => 365 ) );

		$this->assertSame( 365, NetworkStore::get( 'auto_archive_days' ) );
	}

	/**
	 * get() honors a caller-supplied default for keys that are not in
	 * defaults() and not in the stored option.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::get
	 */
	public function test_get_returns_caller_default_for_unknown_key() {
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array() );

		$this->assertSame( 'fallback', NetworkStore::get( 'unknown_setting', 'fallback' ) );
	}

	/**
	 * update() writes the merged settings back through
	 * update_network_option() under the OPTION_KEY constant, over the
	 * current network (null network id).
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::update
	 */
	public function test_update_writes_merged_settings_to_update_network_option() {
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array() );

		\WP_Mock::userFunction( 'update_network_option' )
			->once()
			->with( null, NetworkStore::OPTION_KEY, $this->defaultsWith( array( 'auto_archive_days' => 30 ) ) )
			->andReturn( true );

		NetworkStore::update( 'auto_archive_days', 30 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * save() merges the supplied array on top of defaults() and writes the
	 * result via update_network_option().
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::save
	 */
	public function test_save_merges_with_defaults_and_writes_option() {
		\WP_Mock::userFunction( 'update_network_option' )
			->once()
			->with( null, NetworkStore::OPTION_KEY, $this->defaultsWith( array( 'auto_archive_enabled' => true ) ) )
			->andReturn( true );

		NetworkStore::save( array( 'auto_archive_enabled' => true ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * delete() calls delete_network_option() under the OPTION_KEY constant,
	 * over the current network (null network id).
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::delete
	 */
	public function test_delete_calls_delete_network_option_with_option_key() {
		\WP_Mock::userFunction( 'delete_network_option' )
			->once()
			->with( null, NetworkStore::OPTION_KEY )
			->andReturn( true );

		NetworkStore::delete();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * all() merges defaults() on top of the stored network option, so
	 * callers always see every network-applicable setting key.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::all
	 */
	public function test_all_returns_defaults_merged_with_option() {
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array( 'auto_archive_enabled' => true ) );

		$this->assertSame( $this->defaultsWith( array( 'auto_archive_enabled' => true ) ), NetworkStore::all() );
	}

	/**
	 * Cache invariant, mirroring StoreTest's own: a second get() must serve
	 * from the in-memory cache, and flush_cache() must clear it.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::get
	 * @covers ArchivedPostStatus\Settings\NetworkStore::all
	 * @covers ArchivedPostStatus\Settings\NetworkStore::flush_cache
	 */
	public function test_get_caches_until_flush_cache_clears_the_cache() {
		\WP_Mock::userFunction( 'get_network_option' )
			->twice()
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array( 'auto_archive_enabled' => true ) );

		$this->assertTrue( NetworkStore::get( 'auto_archive_enabled' ) );
		$this->assertTrue( NetworkStore::get( 'auto_archive_enabled' ) );

		NetworkStore::flush_cache();

		$this->assertTrue( NetworkStore::get( 'auto_archive_enabled' ) );
	}

	/**
	 * update() refreshes the cache with the written value, so a get() after
	 * update() reflects the change without an extra get_network_option()
	 * call.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkStore::update
	 * @covers ArchivedPostStatus\Settings\NetworkStore::get
	 */
	public function test_update_refreshes_cache_with_written_value() {
		\WP_Mock::userFunction( 'get_network_option' )
			->once()
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array() );

		\WP_Mock::userFunction( 'update_network_option' )
			->with( null, NetworkStore::OPTION_KEY, $this->defaultsWith( array( 'auto_archive_enabled' => true ) ) )
			->andReturn( true );

		NetworkStore::update( 'auto_archive_enabled', true );

		$this->assertTrue( NetworkStore::get( 'auto_archive_enabled' ) );
	}
}
