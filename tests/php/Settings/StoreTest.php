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
	 * defaults() returns the hardcoded default array — currently just
	 * is_read_only=true. This is the source-of-truth fallback consumed
	 * by every get() call that lands on a missing key.
	 *
	 * @covers ArchivedPostStatus\Settings\Store::defaults
	 */
	public function test_defaults_returns_is_read_only_true() {
		$defaults = Store::defaults();

		$this->assertSame( array( 'is_read_only' => true ), $defaults );
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
			->with( Store::OPTION_KEY, array( 'is_read_only' => false ) )
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
			->with( Store::OPTION_KEY, array( 'is_read_only' => false ) )
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

		$this->assertSame( array( 'is_read_only' => false ), Store::all() );
	}

	/**
	 * Cache invariant — load-bearing for §1.4 #14.
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
			->with( Store::OPTION_KEY, array( 'is_read_only' => false ) )
			->andReturn( true );

		Store::update( 'is_read_only', false );

		// get_option() must not be called again — the cache should hold
		// the value update() just wrote.
		$this->assertFalse( Store::get( 'is_read_only' ) );
	}

	/**
	 * Cache-flush regression: `update_option` fires
	 * BEFORE the cache is primed. The HookAdapter wires the option's
	 * update-action to {@see Store::flush_cache}, which clears the cache.
	 * If the cache were primed before update_option ran, that flush would
	 * blow away the just-written value and the next get() would re-read
	 * from get_option().
	 *
	 * The ordering this test pins:
	 *   1. update_option fires (the cache-flush hook runs synchronously
	 *      and clears self::$cache — modeled here by an in-test
	 *      flush_cache() call inside the update_option stub).
	 *   2. update() primes the cache AFTER step 1.
	 *   3. A subsequent get() returns the new value WITHOUT a second
	 *      get_option() call — ->once() pins that.
	 *
	 * If ordering ever flips (cache prime → update_option), the in-stub
	 * flush_cache() would clear the just-primed cache and the get_option
	 * expectation of `->twice()` would be needed — but we pin ->once()
	 * here, so a regression trips this expectation count.
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
		// If Store::update() primes the cache BEFORE update_option,
		// this stub-side flush nukes the just-primed value — and the
		// subsequent get() would either re-read get_option (tripping
		// the once() count) or return the default. Either way fails.
		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Store::OPTION_KEY, array( 'is_read_only' => false ) )
			->andReturnUsing(
				static function () {
					Store::flush_cache(); // simulate the hook-bound callback
					return true;
				}
			);

		Store::update( 'is_read_only', false );

		// The H8 contract: a get() immediately after update() returns the
		// new value without a second get_option() call. ->once() above
		// pins the no-second-read clause; the assertSame pins the value.
		$this->assertFalse(
			Store::get( 'is_read_only' ),
			'A get() after update() must see the newly-written value — without re-reading get_option().'
		);
	}
}
