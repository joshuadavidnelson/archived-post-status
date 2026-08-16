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
	public function test_hooks_registers_init_and_both_meta_write_actions() {
		$descriptors = $this->registrar->hooks();

		$this->assertCount( 3, $descriptors );

		$hook_names = array_map( fn( $descriptor ) => $descriptor->hook, $descriptors );
		$this->assertContains( 'init', $hook_names );
		$this->assertContains( 'added_post_meta', $hook_names );
		$this->assertContains( 'updated_post_meta', $hook_names );

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
	 * META_TIME and the post-level auto-archive override (0.5.0 phase 9)
	 * are both registered, with REST enabled, for every supported post
	 * type -- and only those two; the other four ScheduleMeta keys are
	 * internal bookkeeping that must stay out of REST entirely.
	 *
	 * @covers ArchivedPostStatus\Schedule\MetaRegistrar::register_meta
	 */
	public function test_register_meta_registers_both_keys_for_every_supported_post_type() {
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

		$this->assertCount( 4, $registered, 'two keys x two post types' );

		$by_post_type = array();
		foreach ( $registered as $call ) {
			$this->assertContains( $call[0], array( 'post', 'page' ) );
			$this->assertContains( $call[1], array( ScheduleMeta::META_TIME, PostRuleProvider::META_DAYS ) );
			$this->assertSame( 'integer', $call[2]['type'] );
			$this->assertTrue( $call[2]['single'] );
			$this->assertTrue( $call[2]['show_in_rest'] );
			$this->assertIsCallable( $call[2]['auth_callback'] );

			$by_post_type[ $call[0] ][] = $call[1];
		}

		$this->assertSame(
			array( ScheduleMeta::META_TIME, PostRuleProvider::META_DAYS ),
			$by_post_type['post'],
			'Both keys must be registered for the "post" post type, in order.'
		);
		$this->assertSame(
			array( ScheduleMeta::META_TIME, PostRuleProvider::META_DAYS ),
			$by_post_type['page'],
			'Both keys must be registered for the "page" post type, in order.'
		);
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
}
