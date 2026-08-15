<?php
/**
 * Schedule\ScheduleMetaListener Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\ScheduleMetaListener
 *
 * The listener composes ScheduleOperation::clear() directly rather than a
 * mocked collaborator, so these tests assert against the real postmeta
 * reads/writes clear() dispatches -- the listener's actual observable
 * behavior.
 */

use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleMetaListener;
use ArchivedPostStatus\Schedule\ScheduleSource;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\ScheduleMetaListener
 */
class ScheduleMetaListenerTest extends TestCase {

	/**
	 * @var ScheduleMetaListener
	 */
	protected $listener;

	public function set_up() {
		parent::set_up();
		$this->listener = new ScheduleMetaListener();
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\ScheduleMetaListener::hooks
	 */
	public function test_hooks_returns_one_action_descriptor_for_aps_archived_post() {
		$descriptors = $this->listener->hooks();

		$this->assertCount( 1, $descriptors );

		$descriptor = $descriptors[0];
		$this->assertInstanceOf( HookDescriptor::class, $descriptor );
		$this->assertTrue( $descriptor->is_action() );
		$this->assertSame( 'aps_archived_post', $descriptor->hook );
		$this->assertSame( 3, $descriptor->accepted_args );
	}

	/**
	 * A post with a pending manual schedule has it deleted outright once
	 * archived through some other route -- ScheduleOperation::clear()'s
	 * non-tombstone path for a non-rule source.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMetaListener::clear_schedule
	 */
	public function test_clear_schedule_deletes_a_pending_manual_schedule() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'manual' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )->andReturn( '2000000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );

		\WP_Mock::onFilter( 'aps_schedule_tombstone_on_clear' )
			->with( false, 42, ScheduleSource::Manual )
			->reply( false );

		\WP_Mock::userFunction( 'update_post_meta' )->never();
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

		\WP_Mock::expectAction( 'aps_unscheduled_archive', 42 );

		$this->listener->clear_schedule( 42, 'draft', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A post with no pending schedule is a no-op -- clear() returns false
	 * without writing anything, and the listener does not care about that
	 * return value.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMetaListener::clear_schedule
	 */
	public function test_clear_schedule_is_noop_when_post_has_no_pending_schedule() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( '' );

		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->listener->clear_schedule( 42, 'draft', $post );

		$this->addToAssertionCount( 1 );
	}
}
