<?php
/**
 * Schedule\MetaRegistrar Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\MetaRegistrar
 */

use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\MetaRegistrar;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\MetaRegistrar
 */
class MetaRegistrarTest extends TestCase {

	/**
	 * @var MetaRegistrar
	 */
	protected $registrar;

	public function set_up() {
		parent::set_up();
		$this->registrar = new MetaRegistrar();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::hooks
	 */
	public function test_hooks_registers_init_and_all_meta_write_actions() {
		$descriptors = $this->registrar->hooks();

		// init, plus four write-listener callbacks (backfill_source,
		// sync_from_local_input, sync_from_clear_flag, guard_frozen_days_override)
		// each registered on both added_post_meta and updated_post_meta --
		// 1 + (4 x 2).
		$this->assertCount( 9, $descriptors );

		$hook_names = array_map( fn( $descriptor ) => $descriptor->hook, $descriptors );
		$this->assertSame( array( 'init' ), array_unique( array_diff( $hook_names, array( 'added_post_meta', 'updated_post_meta' ) ) ) );
		$this->assertCount( 4, array_keys( $hook_names, 'added_post_meta' ) );
		$this->assertCount( 4, array_keys( $hook_names, 'updated_post_meta' ) );

		foreach ( $descriptors as $descriptor ) {
			$this->assertTrue( $descriptor->is_action() );
			if ( 'init' !== $descriptor->hook ) {
				$this->assertSame( 4, $descriptor->accepted_args );
			}
		}
	}

	// -----------------------------------------------------------------------
	// register_meta()
	// -----------------------------------------------------------------------

	/**
	 * META_TIME, the post-level auto-archive override (0.5.0 phase 9), and
	 * the block editor panel's two write-only input channels (0.5.0 phase
	 * 11) are all registered, with REST enabled, for every supported post
	 * type -- and only those four; the other three ScheduleMeta keys are
	 * internal bookkeeping that must stay out of REST entirely.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::register_meta
	 */
	public function test_register_meta_registers_all_four_keys_for_every_supported_post_type() {
		\WP_Mock::userFunction( 'get_post_types' )
			->with( array( 'public' => true ) )
			->andReturn( array( 'post' => 'post', 'page' => 'page' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )->with( array( 'attachment' ) )->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post', 'page' => 'page' ) )
			->reply( array( 'post', 'page' ) );

		$registered = array();
		\WP_Mock::userFunction( 'register_post_meta' )
			->andReturnUsing(
				function ( $post_type, $meta_key, $args ) use ( &$registered ) {
					$registered[] = array( $post_type, $meta_key, $args );
					return true;
				}
			);

		$this->registrar->register_meta();

		$expected_keys = array(
			ScheduleMeta::META_TIME,
			PostRuleProvider::META_DAYS,
			MetaRegistrar::META_LOCAL_INPUT,
			MetaRegistrar::META_CLEAR_FLAG,
		);

		$this->assertCount( 8, $registered, 'four keys x two post types' );

		$by_post_type = array();
		foreach ( $registered as $call ) {
			$this->assertContains( $call[0], array( 'post', 'page' ) );
			$this->assertContains( $call[1], $expected_keys );
			$this->assertTrue( $call[2]['single'] );
			$this->assertTrue( $call[2]['show_in_rest'] );
			$this->assertIsCallable( $call[2]['auth_callback'] );

			$by_post_type[ $call[0] ][] = $call[1];
		}

		$this->assertSame(
			$expected_keys,
			$by_post_type['post'],
			'All four keys must be registered for the "post" post type, in order.'
		);
		$this->assertSame(
			$expected_keys,
			$by_post_type['page'],
			'All four keys must be registered for the "page" post type, in order.'
		);
	}

	/**
	 * The two REST types this class registers that are NOT integers:
	 * META_LOCAL_INPUT is a string defaulting to '', META_CLEAR_FLAG a
	 * boolean defaulting to false -- pinned separately from the shared
	 * assertions above since `meta_args()` branches on `$type`.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::register_meta
	 */
	public function test_register_meta_registers_the_local_input_and_clear_flag_channels_with_their_own_types_and_defaults() {
		\WP_Mock::userFunction( 'get_post_types' )
			->with( array( 'public' => true ) )
			->andReturn( array( 'post' => 'post' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )->with( array( 'attachment' ) )->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post' ) )
			->reply( array( 'post' ) );

		$registered = array();
		\WP_Mock::userFunction( 'register_post_meta' )
			->andReturnUsing(
				function ( $post_type, $meta_key, $args ) use ( &$registered ) {
					$registered[ $meta_key ] = $args;
					return true;
				}
			);

		$this->registrar->register_meta();

		$this->assertSame( 'string', $registered[ MetaRegistrar::META_LOCAL_INPUT ]['type'] );
		$this->assertSame( '', $registered[ MetaRegistrar::META_LOCAL_INPUT ]['default'] );

		$this->assertSame( 'boolean', $registered[ MetaRegistrar::META_CLEAR_FLAG ]['type'] );
		$this->assertFalse( $registered[ MetaRegistrar::META_CLEAR_FLAG ]['default'] );

		$this->assertSame( 'integer', $registered[ ScheduleMeta::META_TIME ]['type'] );
		$this->assertArrayNotHasKey( 'default', $registered[ ScheduleMeta::META_TIME ], 'META_TIME keeps its prior no-explicit-default shape.' );
	}

	// -----------------------------------------------------------------------
	// auth_callback()
	// -----------------------------------------------------------------------

	/**
	 * The auth_callback is per-post: a user who cannot archive THIS post is
	 * denied, even if they hold the type-level edit_posts primitive the PoC
	 * this feature replaces would have accepted.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::auth_callback
	 */
	public function test_auth_callback_denies_a_user_who_cannot_archive_that_post() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_author' => 7 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		\WP_Mock::onFilter( 'aps_default_archive_capability' )
			->with( 'edit_others_posts', 42 )
			->reply( 'edit_others_posts' );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', 42 )
			->andReturn( false );

		$this->assertFalse( MetaRegistrar::auth_callback( true, ScheduleMeta::META_TIME, 42 ) );
	}

	/**
	 * The auth_callback grants a user who CAN archive the post -- the
	 * positive counterpart to the denial test above.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::auth_callback
	 */
	public function test_auth_callback_allows_a_user_who_can_archive_that_post() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_author' => 3 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		\WP_Mock::onFilter( 'aps_default_archive_capability' )
			->with( 'edit_posts', 42 )
			->reply( 'edit_posts' );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts', 42 )
			->andReturn( true );

		$this->assertTrue( MetaRegistrar::auth_callback( true, ScheduleMeta::META_TIME, 42 ) );
	}

	// -----------------------------------------------------------------------
	// backfill_source()
	// -----------------------------------------------------------------------

	/**
	 * The critical invariant: writing META_TIME directly -- the REST path
	 * this class's own registration opens up -- with no META_SOURCE on
	 * record backfills it to `manual`, so ScheduleMeta::for_post() reports
	 * this post as scheduled rather than silently missing it.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::backfill_source
	 */
	public function test_backfill_source_sets_manual_source_when_none_is_on_record() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( '' );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_SOURCE, ScheduleSource::Manual->value )
			->andReturn( true );

		$this->registrar->backfill_source( 1, 42, ScheduleMeta::META_TIME, 2000000000 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A post that already has a source on record -- a rule stamp, a prior
	 * manual write, an exempt tombstone -- is left alone; backfilling would
	 * silently reclassify a rule-stamped schedule as manual.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::backfill_source
	 */
	public function test_backfill_source_is_noop_when_a_source_already_exists() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'rule' );

		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->registrar->backfill_source( 1, 42, ScheduleMeta::META_TIME, 2000000000 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A write to any other meta key is ignored outright -- the callback
	 * does not even read META_SOURCE for an unrelated key.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::backfill_source
	 */
	public function test_backfill_source_ignores_writes_to_other_meta_keys() {
		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->registrar->backfill_source( 1, 42, '_some_other_key', 'value' );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// sync_from_local_input() — the block editor panel's write-only date channel
	// -----------------------------------------------------------------------

	/**
	 * Stub what {@see ScheduleOperation::set()} needs to succeed, mirroring
	 * ScheduleOperationTest's own boundary helper.
	 *
	 * @param int $post_id
	 */
	private function stubScheduleOperationSetSucceeds( int $post_id ): void {
		$post = $this->createMockPost( array( 'ID' => $post_id, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )->with( $post_id )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_types' )->andReturn( array( 'post' => 'post' ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )->with( array( 'attachment' ) )->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )->with( array( 'post' => 'post' ) )->reply( array( 'post' ) );
	}

	/**
	 * A well-formed local wall-clock string converts to the correct UTC
	 * epoch and writes a Manual schedule -- the ONLY place this class turns
	 * the input channel into the authoritative epoch. Non-UTC timezone, per
	 * the release-wide timezone-boundary discipline every ScheduleTime
	 * caller's tests follow.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::sync_from_local_input
	 */
	public function test_sync_from_local_input_converts_a_well_formed_string_and_sets_a_manual_schedule() {
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'America/New_York' ) );
		$this->stubScheduleOperationSetSucceeds( 42 );

		\WP_Mock::onFilter( 'aps_pre_schedule_archive' )
			->with( null, 42, 1718461800, ScheduleSource::Manual )
			->reply( null );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_TIME, 1718461800 )->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_SOURCE, ScheduleSource::Manual->value )->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_USER, 1 )->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_RULE_VERSION, 0 )->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_ATTEMPTS, 0 )->andReturn( true );

		\WP_Mock::expectAction( 'aps_scheduled_archive', 42, 1718461800, 'manual' );

		$this->registrar->sync_from_local_input( 1, 42, MetaRegistrar::META_LOCAL_INPUT, '2024-06-15T10:30' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * An empty submission is a no-op -- input-only channel, never itself a
	 * clear signal; see the class docblock. Clearing is META_CLEAR_FLAG's
	 * job.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::sync_from_local_input
	 */
	public function test_sync_from_local_input_ignores_an_empty_string() {
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->registrar->sync_from_local_input( 1, 42, MetaRegistrar::META_LOCAL_INPUT, '' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Unparseable input is left untouched rather than guessed at -- no
	 * schedule write is ever attempted for a string ScheduleTime cannot
	 * parse.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::sync_from_local_input
	 */
	public function test_sync_from_local_input_ignores_unparseable_input() {
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'America/New_York' ) );
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->registrar->sync_from_local_input( 1, 42, MetaRegistrar::META_LOCAL_INPUT, 'not-a-date' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A write to any other meta key is ignored outright.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::sync_from_local_input
	 */
	public function test_sync_from_local_input_ignores_writes_to_other_meta_keys() {
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->registrar->sync_from_local_input( 1, 42, ScheduleMeta::META_TIME, '2024-06-15T10:30' );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// sync_from_clear_flag() — the block editor panel's Clear button
	// -----------------------------------------------------------------------

	/**
	 * A truthy clear-flag write unschedules the post and drops its own
	 * cascade override -- mirroring ScheduleMetaBox's Clear button exactly.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::sync_from_clear_flag
	 */
	public function test_sync_from_clear_flag_unschedules_and_drops_the_post_override_when_true() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'manual' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_TIME )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_SOURCE )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_USER )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_RULE_VERSION )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_ATTEMPTS )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, PostRuleProvider::META_DAYS )->andReturn( true );

		\WP_Mock::expectAction( 'aps_unscheduled_archive', 42 );

		$this->registrar->sync_from_clear_flag( 1, 42, MetaRegistrar::META_CLEAR_FLAG, true );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A falsy clear-flag write is a no-op -- the REST default (false) must
	 * never itself trigger a clear on an unrelated save.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::sync_from_clear_flag
	 */
	public function test_sync_from_clear_flag_is_noop_when_false() {
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->registrar->sync_from_clear_flag( 1, 42, MetaRegistrar::META_CLEAR_FLAG, false );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A write to any other meta key is ignored outright.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::sync_from_clear_flag
	 */
	public function test_sync_from_clear_flag_ignores_writes_to_other_meta_keys() {
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->registrar->sync_from_clear_flag( 1, 42, ScheduleMeta::META_TIME, true );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// guard_frozen_days_override() — the REST write path's counterpart to
	// ScheduleMetaBox::save_days_override()'s own pre-write frozen guard
	// -----------------------------------------------------------------------

	/**
	 * A META_DAYS write is reverted when an ancestor freezes the cascade for
	 * this post -- proving the block editor panel cannot persist a value
	 * that would otherwise sit inert until a later unlock silently activates
	 * it.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::guard_frozen_days_override
	 */
	public function test_guard_frozen_days_override_reverts_the_write_when_an_ancestor_is_frozen() {
		// Locked site rule -> frozen ancestor chain (mirrors
		// ScheduleMetaBoxTest's identical fixture for PostInheritance).
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( 365 );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'locked' );
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( 42, 'category' )->andReturn( array() );

		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, PostRuleProvider::META_DAYS )->andReturn( true );

		$this->registrar->guard_frozen_days_override( 1, 42, PostRuleProvider::META_DAYS, 6 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A META_DAYS write is left alone when nothing freezes the cascade.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::guard_frozen_days_override
	 */
	public function test_guard_frozen_days_override_is_noop_when_unfrozen() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( false );
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( 42, 'category' )->andReturn( array() );

		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->registrar->guard_frozen_days_override( 1, 42, PostRuleProvider::META_DAYS, 6 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A write to any other meta key is ignored outright -- the frozen check
	 * never even runs.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::guard_frozen_days_override
	 */
	public function test_guard_frozen_days_override_ignores_writes_to_other_meta_keys() {
		\WP_Mock::userFunction( 'is_multisite' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->registrar->guard_frozen_days_override( 1, 42, ScheduleMeta::META_TIME, 6 );

		$this->addToAssertionCount( 1 );
	}
}
