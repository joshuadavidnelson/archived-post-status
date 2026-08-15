<?php
/**
 * Settings\Store Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\Store
 *
 * Store is a static facade over wp_options with an in-memory cache. The
 * cache is the load-bearing piece — every test flushes it in setUp and
 * tearDown so a leak from one test cannot poison another.
 */

use ArchivedPostStatus\Settings\Store;

/**
 * Store test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Settings\Store
 */
class StoreTest extends TestCase {

	/**
	 * The full defaults table Store::defaults() must produce now that it
	 * derives from Schema — the plan's §5.8 table, transcribed exactly, the
	 * same literal values SchemaTest pins independently. Tests that need the
	 * merged option array with one or two keys overridden build on this via
	 * {@see self::defaultsWith()} rather than restating all ten keys.
	 */
	private const FULL_DEFAULTS = array(
		'is_read_only'                 => true,
		'scheduled_archive_enabled'    => true,
		'scheduled_archive_post_types' => array(),
		'auto_archive_enabled'         => false,
		'auto_archive_days'            => null,
		'auto_archive_child_mode'      => 'open',
		'auto_archive_types'           => array(),
		'auto_archive_taxonomies'      => array( 'category' ),
		'auto_archive_age_basis'       => 'modified',
		'auto_archive_grace_days'      => 7,
	);

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function defaultsWith( array $overrides ): array {
		return array_merge( self::FULL_DEFAULTS, $overrides );
	}

	/**
	 * Defensive: clear the static cache before every test so test order
	 * cannot affect outcomes.
	 */
	public function set_up() {
		parent::set_up();
		Store::flush_cache();
	}

	/**
	 * Defensive: clear the static cache after every test so leaks from
	 * mocked get_option() values cannot poison the next test.
	 */
	public function tear_down() {
		Store::flush_cache();
		parent::tear_down();
	}

	/**
	 * defaults() derives its result from Schema — this pins the full
	 * ten-key table Store hands back, independently of SchemaTest's own
	 * pin, so a Store/Schema wiring mistake (e.g. a key dropped in
	 * translation) fails here even if Schema itself is correct.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::defaults
	 */
	public function test_defaults_returns_full_schema_derived_table() {
		$this->assertSame( self::FULL_DEFAULTS, Store::defaults() );
	}

	/**
	 * The pre-0.5.0 contract this class must keep unchanged: is_read_only
	 * defaults to true, from the source-of-truth {@see self::defaultsWith()}
	 * consumed by every get() call that lands on a missing key.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::defaults
	 */
	public function test_defaults_still_contains_is_read_only_true() {
		$this->assertArrayHasKey( 'is_read_only', Store::defaults() );
		$this->assertTrue( Store::defaults()['is_read_only'] );
	}

	/**
	 * get() returns the value from defaults() when the option array
	 * holds nothing for that key.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::get
	 * @covers ArchivedPostStatus\Settings\Store::all
	 */
	public function test_get_returns_default_when_key_not_in_option() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );

		$this->assertTrue( Store::get( 'is_read_only' ) );
	}

	/**
	 * get() returns the stored value when the option array contains
	 * an explicit setting for that key.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::get
	 * @covers ArchivedPostStatus\Settings\Store::all
	 */
	public function test_get_returns_stored_value_when_present() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'is_read_only' => false ) );

		$this->assertFalse( Store::get( 'is_read_only' ) );
	}

	/**
	 * get() honors a caller-supplied default for keys that are not in
	 * defaults() and not in the stored option.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::get
	 */
	public function test_get_returns_caller_default_for_unknown_key() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );

		$this->assertSame( 'fallback', Store::get( 'unknown_setting', 'fallback' ) );
	}

	/**
	 * update() writes the merged settings back through update_option()
	 * under the OPTION_KEY constant.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::update
	 */
	public function test_update_writes_merged_settings_to_update_option() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Store::OPTION_KEY, $this->defaultsWith( array( 'is_read_only' => false ) ) )
			->andReturn( true );

		Store::update( 'is_read_only', false );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * save() merges the supplied array on top of defaults() and writes
	 * the result via update_option(). The merge guarantees a setting
	 * dropped from the caller's array still has its default value
	 * persisted, preventing partial-write corruption.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::save
	 */
	public function test_save_merges_with_defaults_and_writes_option() {
		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Store::OPTION_KEY, $this->defaultsWith( array( 'is_read_only' => false ) ) )
			->andReturn( true );

		Store::save( array( 'is_read_only' => false ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * delete() calls delete_option() under the OPTION_KEY constant.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::delete
	 */
	public function test_delete_calls_delete_option_with_option_key() {
		\WP_Mock::userFunction( 'delete_option' )
			->once()
			->with( Store::OPTION_KEY )
			->andReturn( true );

		Store::delete();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * all() merges defaults() on top of the stored option, so callers
	 * always see every defined setting key.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::all
	 */
	public function test_all_returns_defaults_merged_with_option() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'is_read_only' => false ) );

		$this->assertSame( $this->defaultsWith( array( 'is_read_only' => false ) ), Store::all() );
	}

	/**
	 * Cache invariant.
	 *
	 * Sequence:
	 *   1. get() populates the static cache from get_option().
	 *   2. A second get() must serve from cache (no second get_option call).
	 *   3. flush_cache() clears the static cache.
	 *   4. The next get() must re-read from get_option().
	 *
	 * Verified by an expectation of get_option() being called twice across
	 * the whole sequence: once before flush, once after.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::get
	 * @covers ArchivedPostStatus\Settings\Store::all
	 * @covers ArchivedPostStatus\Settings\Store::flush_cache
	 */
	public function test_get_caches_until_flush_cache_clears_the_cache() {
		\WP_Mock::userFunction( 'get_option' )
			->twice()
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'is_read_only' => true ) );

		// First call — populates the cache.
		$this->assertTrue( Store::get( 'is_read_only' ) );
		// Second call — must be served from cache.
		$this->assertTrue( Store::get( 'is_read_only' ) );

		Store::flush_cache();

		// Third call — cache miss, hits get_option() again.
		$this->assertTrue( Store::get( 'is_read_only' ) );

		// twice() above is the implicit assertion that flush_cache works.
	}

	/**
	 * update() refreshes the cache with the written value, so a get()
	 * after update() reflects the change without an extra get_option()
	 * call.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::update
	 * @covers ArchivedPostStatus\Settings\Store::get
	 */
	public function test_update_refreshes_cache_with_written_value() {
		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );

		\WP_Mock::userFunction( 'update_option' )
			->with( Store::OPTION_KEY, $this->defaultsWith( array( 'is_read_only' => false ) ) )
			->andReturn( true );

		Store::update( 'is_read_only', false );

		// get_option() must not be called again — the cache should hold
		// the value update() just wrote.
		$this->assertFalse( Store::get( 'is_read_only' ) );
	}

	/**
	 * Cache-flush ordering: `update_option` fires BEFORE the cache is
	 * primed. The HookAdapter wires the option's update-action to
	 * {@see Store::flush_cache}, which clears the cache. If the cache were
	 * primed before update_option ran, that flush would blow away the
	 * just-written value and the next get() would re-read from
	 * get_option().
	 *
	 * The ordering this test pins:
	 *   1. update_option fires (the cache-flush hook runs synchronously
	 *      and clears self::$cache — modeled here by an in-test
	 *      flush_cache() call inside the update_option stub).
	 *   2. update() primes the cache AFTER step 1.
	 *   3. A subsequent get() returns the new value WITHOUT a second
	 *      get_option() call — ->once() pins that.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::update
	 * @covers ArchivedPostStatus\Settings\Store::flush_cache
	 */
	public function test_update_fires_update_option_before_priming_cache_so_get_serves_from_cache() {
		// get_option must be called EXACTLY ONCE — during the initial
		// all() resolution inside update(). After that, every read
		// must serve from the cache update() primed.
		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );

		// Model the cache-flush hook: when update_option fires (which
		// happens because update_option_aps_settings is wired to
		// Store::flush_cache via HookAdapter), the cache is cleared.
		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Store::OPTION_KEY, $this->defaultsWith( array( 'is_read_only' => false ) ) )
			->andReturnUsing(
				static function () {
					Store::flush_cache(); // simulate the hook-bound callback
					return true;
				}
			);

		Store::update( 'is_read_only', false );

		// The cache-flush contract: a get() immediately after update() returns the
		// new value without a second get_option() call. ->once() above
		// pins the no-second-read clause; the assertSame pins the value.
		$this->assertFalse(
			Store::get( 'is_read_only' ),
			'A get() after update() must see the newly-written value — without re-reading get_option().'
		);
	}

	/**
	 * Cache-flush ordering for save(), mirroring
	 * test_update_fires_update_option_before_priming_cache_so_get_serves_from_cache
	 * — see that test for the hook/cache-flush mechanism. save() never
	 * reads the option first, so ->never() replaces the sibling's ->once()
	 * as a secondary no-read guard.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::save
	 * @covers ArchivedPostStatus\Settings\Store::flush_cache
	 */
	public function test_save_fires_update_option_before_priming_cache_so_get_serves_from_cache() {
		// get_option must NEVER be called — save() doesn't read the
		// option before writing, and the post-save get() must be served
		// entirely from the cache save() primed.
		\WP_Mock::userFunction( 'get_option' )->never();

		// Model the cache-flush hook: when update_option fires (which
		// happens because update_option_aps_settings is wired to
		// Store::flush_cache via HookAdapter), the cache is cleared.
		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Store::OPTION_KEY, $this->defaultsWith( array( 'is_read_only' => false ) ) )
			->andReturnUsing(
				static function () {
					Store::flush_cache(); // simulate the hook-bound callback
					return true;
				}
			);

		Store::save( array( 'is_read_only' => false ) );

		// The cache-flush contract: a get() immediately after save()
		// returns the saved value — merged over defaults() — without
		// ever touching get_option(); never() above is only a secondary
		// no-read guard.
		$this->assertFalse(
			Store::get( 'is_read_only' ),
			'A get() after save() must see the newly-saved value — without ever calling get_option().'
		);
	}
}
