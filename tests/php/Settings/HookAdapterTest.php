<?php
/**
 * Settings\HookAdapter Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\HookAdapter
 * @covers ArchivedPostStatus\Settings\Store
 *
 * Verifies the bridge between persisted settings (Store) and the plugin's
 * filter system. HookAdapter is the only place that reads from Store on
 * behalf of public filters; its priority and hook surface are part of the
 * public API.
 *
 * These tests intentionally cross into Store because HookAdapter::is_read_only
 * delegates through Store::get()/all()/defaults(), and set_up()/tear_down()
 * exercise Store::flush_cache. Declaring @covers on Store at class level
 * keeps the coverage credit honest rather than discarded as incidental.
 */

use ArchivedPostStatus\AutoArchive\RulesVersion;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Settings\HookAdapter;
use ArchivedPostStatus\Settings\NetworkStore;
use ArchivedPostStatus\Settings\Store;

/**
 * HookAdapter test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Settings\HookAdapter
 * @covers ArchivedPostStatus\Settings\Store
 */
class HookAdapterTest extends TestCase {

	private HookAdapter $adapter;

	public function set_up() {
		parent::set_up();
		Store::flush_cache();
		$this->adapter = new HookAdapter();
	}

	public function tear_down() {
		Store::flush_cache();
		parent::tear_down();
	}

	/**
	 * Priority contract — locked at 20 so developer overrides (≤10) win
	 * and future network-level enforcement (50+) can override settings.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter
	 */
	public function test_priority_constant_is_twenty() {
		$this->assertSame( 20, HookAdapter::PRIORITY );
	}

	/**
	 * hooks() returns twenty descriptors: ten filters for the value bridge
	 * (is_read_only plus the nine 0.5.0 settings keys), four actions for
	 * cache invalidation (three option-write hooks, plus `switch_blog` for
	 * multisite), three actions bumping {@see RulesVersion} on the site
	 * option's write hooks, and three more bumping it on the network
	 * option's write hooks (phase 7 — a network settings write is a rule
	 * write too, per §4.7).
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::hooks
	 */
	public function test_hooks_returns_twenty_descriptors() {
		$hooks = $this->adapter->hooks();

		$this->assertCount( 20, $hooks );
		foreach ( $hooks as $hook ) {
			$this->assertInstanceOf( HookDescriptor::class, $hook );
		}
	}

	/**
	 * The first descriptor is the aps_is_read_only filter bridge at the
	 * documented priority, with the default single accepted arg
	 * (the incoming `$default`). This is the public API contract — moving
	 * priority away from 20 silently breaks override semantics.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::hooks
	 */
	public function test_hooks_registers_is_read_only_filter_at_priority_twenty() {
		$hooks = $this->adapter->hooks();

		$filter = $hooks[0];

		$this->assertFalse( $filter->is_action() );
		$this->assertSame( 'aps_is_read_only', $filter->hook );
		$this->assertSame( HookAdapter::PRIORITY, $filter->priority );
		$this->assertSame( array( $this->adapter, 'is_read_only' ), $filter->callback );
		$this->assertSame( 1, $filter->accepted_args );
	}

	/**
	 * Descriptors 1-9 are the nine 0.5.0 settings filters, each at the
	 * documented priority with the default single accepted arg — the same
	 * shape as descriptor 0 (`aps_is_read_only`), pinned above.
	 *
	 * @dataProvider provider_new_settings_filter_descriptors
	 * @covers ArchivedPostStatus\Settings\HookAdapter::hooks
	 */
	public function test_hooks_registers_new_settings_filter_at_priority_twenty( int $index, string $hook, string $method ) {
		$filter = $this->adapter->hooks()[ $index ];

		$this->assertFalse( $filter->is_action() );
		$this->assertSame( $hook, $filter->hook );
		$this->assertSame( HookAdapter::PRIORITY, $filter->priority );
		$this->assertSame( array( $this->adapter, $method ), $filter->callback );
		$this->assertSame( 1, $filter->accepted_args );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public function provider_new_settings_filter_descriptors(): array {
		return array(
			'scheduled_archive_enabled'    => array( 1, 'aps_scheduled_archive_enabled', 'scheduled_archive_enabled' ),
			'scheduled_archive_post_types' => array( 2, 'aps_scheduled_archive_post_types', 'scheduled_archive_post_types' ),
			'auto_archive_enabled'         => array( 3, 'aps_auto_archive_enabled', 'auto_archive_enabled' ),
			'auto_archive_days'            => array( 4, 'aps_auto_archive_days', 'auto_archive_days' ),
			'auto_archive_child_mode'      => array( 5, 'aps_auto_archive_child_mode', 'auto_archive_child_mode' ),
			'auto_archive_types'           => array( 6, 'aps_auto_archive_types', 'auto_archive_types' ),
			'auto_archive_taxonomies'      => array( 7, 'aps_auto_archive_taxonomies', 'auto_archive_taxonomies' ),
			'auto_archive_age_basis'       => array( 8, 'aps_auto_archive_age_basis', 'auto_archive_age_basis' ),
			'auto_archive_grace_days'      => array( 9, 'aps_auto_archive_grace_days', 'auto_archive_grace_days' ),
		);
	}

	/**
	 * Cache invalidation: HookAdapter registers four cache-invalidation
	 * actions on Store::flush_cache, each at the default priority with
	 * zero accepted args (flush_cache() takes no parameters) — three for
	 * direct option writes that bypass Store::update()/save()/delete(),
	 * plus `switch_blog` so a multisite switch_to_blog() doesn't
	 * leave Store's static cache serving the previous site's settings.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::hooks
	 * @covers ArchivedPostStatus\Settings\Store::flush_cache
	 */
	public function test_hooks_registers_four_cache_invalidation_actions() {
		$hooks = $this->adapter->hooks();

		$cache_actions  = array_slice( $hooks, 10, 4 );
		$expected_hooks = array(
			'update_option_' . Store::OPTION_KEY,
			'add_option_' . Store::OPTION_KEY,
			'delete_option_' . Store::OPTION_KEY,
			'switch_blog',
		);
		$actual_hooks   = array();

		foreach ( $cache_actions as $descriptor ) {
			$this->assertTrue( $descriptor->is_action() );
			$this->assertSame( array( Store::class, 'flush_cache' ), $descriptor->callback );
			$this->assertSame( 10, $descriptor->priority );
			$this->assertSame( 0, $descriptor->accepted_args );
			$actual_hooks[] = $descriptor->hook;
		}

		$this->assertSame( $expected_hooks, $actual_hooks );
	}

	/**
	 * Every write of the settings option also bumps {@see RulesVersion} —
	 * a sibling action on the same three option-write hooks the
	 * cache-invalidation actions above already cover (not `switch_blog`,
	 * which switches sites rather than writing a rule).
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::hooks
	 */
	public function test_hooks_registers_rules_version_bump_on_each_option_write_action() {
		$hooks = $this->adapter->hooks();

		$bump_actions   = array_slice( $hooks, 14, 3 );
		$expected_hooks = array(
			'update_option_' . Store::OPTION_KEY,
			'add_option_' . Store::OPTION_KEY,
			'delete_option_' . Store::OPTION_KEY,
		);
		$actual_hooks   = array();

		foreach ( $bump_actions as $descriptor ) {
			$this->assertTrue( $descriptor->is_action() );
			$this->assertSame( array( RulesVersion::class, 'bump' ), $descriptor->callback );
			$this->assertSame( 10, $descriptor->priority );
			$this->assertSame( 0, $descriptor->accepted_args );
			$actual_hooks[] = $descriptor->hook;
		}

		$this->assertSame( $expected_hooks, $actual_hooks );
	}

	/**
	 * A network settings write is also a rule write (§4.7's "any level"),
	 * but it lands through update_network_option(), which fires the
	 * *_site_option_ hook family, not *_option_ — a genuinely different
	 * hook name for the same event, so it needs its own trio distinct from
	 * the site-option one above.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::hooks
	 */
	public function test_hooks_registers_rules_version_bump_on_each_network_option_write_action() {
		$hooks = $this->adapter->hooks();

		$bump_actions   = array_slice( $hooks, 17, 3 );
		$expected_hooks = array(
			'update_site_option_' . NetworkStore::OPTION_KEY,
			'add_site_option_' . NetworkStore::OPTION_KEY,
			'delete_site_option_' . NetworkStore::OPTION_KEY,
		);
		$actual_hooks   = array();

		foreach ( $bump_actions as $descriptor ) {
			$this->assertTrue( $descriptor->is_action() );

			// bump_network(), not bump(): this hook fires once, on whichever
			// single site the network admin request ran on, so a per-site
			// counter could never carry a network rule change to the rest of
			// the network.
			$this->assertSame( array( RulesVersion::class, 'bump_network' ), $descriptor->callback );
			$this->assertSame( 10, $descriptor->priority );
			$this->assertSame( 0, $descriptor->accepted_args );
			$actual_hooks[] = $descriptor->hook;
		}

		$this->assertSame( $expected_hooks, $actual_hooks );
	}

	/**
	 * is_read_only() reads from Store. When the option is unset, Store
	 * falls through to its default (true), which is what the adapter
	 * returns regardless of the filter's incoming $default.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::is_read_only
	 * @covers ArchivedPostStatus\Settings\Store::get
	 * @covers ArchivedPostStatus\Settings\Store::all
	 * @covers ArchivedPostStatus\Settings\Store::defaults
	 */
	public function test_is_read_only_returns_store_default_when_option_unset() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );

		// Caller's $default is false, but Store has its own default of true.
		$this->assertTrue( $this->adapter->is_read_only( false ) );
	}

	/**
	 * is_read_only() returns the stored value verbatim when the option
	 * is set, overriding the filter's incoming default.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::is_read_only
	 * @covers ArchivedPostStatus\Settings\Store::get
	 * @covers ArchivedPostStatus\Settings\Store::all
	 * @covers ArchivedPostStatus\Settings\Store::defaults
	 */
	public function test_is_read_only_returns_stored_value_when_option_set() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'is_read_only' => false ) );

		$this->assertFalse( $this->adapter->is_read_only( true ) );
	}

	/**
	 * Coverage pin: a stored options array containing extra/unknown
	 * keys (e.g. left over from a different plugin, manually-added junk)
	 * must still resolve `is_read_only` from the value-typed entry. The
	 * `array_merge(defaults, stored)` in {@see Store::all()} preserves the
	 * extra keys without leaking them into the read.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::is_read_only
	 * @covers ArchivedPostStatus\Settings\Store::get
	 * @covers ArchivedPostStatus\Settings\Store::all
	 */
	public function test_is_read_only_ignores_extraneous_stored_keys() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn(
				array(
					'is_read_only'        => false,
					'archivable_statuses' => array( 'publish' ),
					'leftover_legacy_key' => 'whatever',
					'__internal_junk'     => 12345,
				)
			);

		$this->assertFalse(
			$this->adapter->is_read_only( true ),
			'Extra keys in the stored option must not affect the is_read_only read.'
		);
	}

	/**
	 * Coverage pin: `Store::get('is_read_only', $default)` resolves
	 * a falsy stored value (literal `false`) verbatim — the
	 * `null === $value` guard inside {@see Store::get()} treats `null` as
	 * "use the default" but `false` as a real stored answer. This pins
	 * the value-coercion edge case.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::is_read_only
	 * @covers ArchivedPostStatus\Settings\Store::get
	 */
	public function test_is_read_only_resolves_literal_false_stored_value_without_coercing_to_default() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'is_read_only' => false ) );

		// Filter's $default is `true`; stored value is literal `false`. The
		// SUT must return false (the stored value) — not the default.
		$result = $this->adapter->is_read_only( true );

		$this->assertSame(
			false,
			$result,
			'Stored literal false must beat the filter default; coverage pin for the null !== $value branch in Store::get.'
		);
	}

	/**
	 * Coverage pin: when `is_read_only` is explicitly `null` in
	 * the stored option (rare; possible via manual db editing), the SUT
	 * falls through to the caller-supplied default via the `??` coalesce
	 * inside {@see Store::get()}.
	 *
	 * `Store::get( $key, $default )` returns `$default ?? defaults()[$key]`
	 * when `$value === null`. The `??` operator only fires on null — so a
	 * literal `false` $default beats the defaults() entry, and a `null`
	 * $default would fall through to defaults().
	 *
	 * Two assertions cover both halves of the coalesce.
	 *
	 * @covers ArchivedPostStatus\Settings\HookAdapter::is_read_only
	 * @covers ArchivedPostStatus\Settings\Store::get
	 */
	public function test_is_read_only_falls_through_to_caller_default_when_stored_value_is_null() {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'is_read_only' => null ) );

		// With a stored null, the SUT returns the caller's $default ?? defaults().
		// $default is `false` here, which is non-null — so the `??` returns it.
		$this->assertFalse(
			$this->adapter->is_read_only( false ),
			'Stored null + caller false default: caller default wins (false is non-null, so `??` returns it).'
		);
	}

	// -----------------------------------------------------------------------
	// The nine 0.5.0 settings filters — each returns the stored value when
	// set, and the filter's own incoming default when unset. Every provider
	// row below picks a stored value deliberately different from the
	// caller-supplied default so the two tests below can only pass if the
	// SUT reads the right one.
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider provider_new_settings_key_values
	 * @covers ArchivedPostStatus\Settings\HookAdapter::scheduled_archive_enabled
	 * @covers ArchivedPostStatus\Settings\HookAdapter::scheduled_archive_post_types
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_enabled
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_days
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_child_mode
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_types
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_taxonomies
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_age_basis
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_grace_days
	 */
	public function test_new_filter_method_returns_stored_value_when_option_set( string $method, string $key, mixed $stored, mixed $caller_default ) {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( $key => $stored ) );

		$this->assertSame( $stored, $this->adapter->$method( $caller_default ) );
	}

	/**
	 * @dataProvider provider_new_settings_key_values
	 * @covers ArchivedPostStatus\Settings\HookAdapter::scheduled_archive_enabled
	 * @covers ArchivedPostStatus\Settings\HookAdapter::scheduled_archive_post_types
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_enabled
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_days
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_child_mode
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_types
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_taxonomies
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_age_basis
	 * @covers ArchivedPostStatus\Settings\HookAdapter::auto_archive_grace_days
	 */
	public function test_new_filter_method_returns_incoming_default_when_option_unset( string $method, string $key, mixed $stored, mixed $caller_default ) {
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );

		$this->assertSame( $caller_default, $this->adapter->$method( $caller_default ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: mixed, 3: mixed}>
	 */
	public function provider_new_settings_key_values(): array {
		return array(
			'scheduled_archive_enabled'    => array( 'scheduled_archive_enabled', 'scheduled_archive_enabled', false, true ),
			'scheduled_archive_post_types' => array( 'scheduled_archive_post_types', 'scheduled_archive_post_types', array( 'post' ), array() ),
			'auto_archive_enabled'         => array( 'auto_archive_enabled', 'auto_archive_enabled', true, false ),
			'auto_archive_days'            => array( 'auto_archive_days', 'auto_archive_days', 45, null ),
			'auto_archive_child_mode'      => array( 'auto_archive_child_mode', 'auto_archive_child_mode', 'locked', 'open' ),
			'auto_archive_types'           => array( 'auto_archive_types', 'auto_archive_types', array( 'page' ), array() ),
			'auto_archive_taxonomies'      => array( 'auto_archive_taxonomies', 'auto_archive_taxonomies', array( 'post_tag' ), array( 'category' ) ),
			'auto_archive_age_basis'       => array( 'auto_archive_age_basis', 'auto_archive_age_basis', 'published', 'modified' ),
			'auto_archive_grace_days'      => array( 'auto_archive_grace_days', 'auto_archive_grace_days', 14, 7 ),
		);
	}
}
