<?php
/**
 * Schedule\Queue\QueueLock Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\Queue\QueueLock
 */

use ArchivedPostStatus\Schedule\Queue\QueueLock;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\Queue\QueueLock
 */
class QueueLockTest extends TestCase {

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueLock::acquire
	 */
	public function test_acquire_succeeds_when_the_lock_is_free() {
		\WP_Mock::userFunction( 'get_transient' )
			->with( 'aps_queue_lock_sweep' )
			->andReturn( false );
		\WP_Mock::onFilter( 'aps_queue_lock_ttl' )
			->with( 30, 'sweep' )
			->reply( 30 );
		\WP_Mock::userFunction( 'set_transient' )
			->with( 'aps_queue_lock_sweep', \Mockery::type( 'int' ), 30 )
			->andReturn( true );

		$lock = new QueueLock( 'sweep' );

		$this->assertTrue( $lock->acquire( 30 ) );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueLock::acquire
	 */
	public function test_acquire_fails_when_another_run_holds_the_lock() {
		\WP_Mock::userFunction( 'get_transient' )
			->with( 'aps_queue_lock_sweep' )
			->andReturn( time() );
		\WP_Mock::userFunction( 'set_transient' )->never();

		$lock = new QueueLock( 'sweep' );

		$this->assertFalse( $lock->acquire( 30 ) );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueLock::release
	 */
	public function test_release_deletes_the_transient() {
		\WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'aps_queue_lock_sweep' );

		( new QueueLock( 'sweep' ) )->release();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The queue name distinguishes locks: two different queues never
	 * collide on the same transient key.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueLock::acquire
	 */
	public function test_lock_key_is_scoped_per_queue_name() {
		\WP_Mock::userFunction( 'get_transient' )
			->with( 'aps_queue_lock_stamp' )
			->andReturn( false );
		\WP_Mock::onFilter( 'aps_queue_lock_ttl' )
			->with( 30, 'stamp' )
			->reply( 30 );
		\WP_Mock::userFunction( 'set_transient' )
			->with( 'aps_queue_lock_stamp', \Mockery::type( 'int' ), 30 )
			->andReturn( true );

		$lock = new QueueLock( 'stamp' );

		$this->assertTrue( $lock->acquire( 30 ) );
	}

	/**
	 * The aps_queue_lock_ttl filter can change the TTL actually passed to
	 * set_transient(), e.g. shortening it for a host with tight cron
	 * resolution.
	 *
	 * @covers ArchivedPostStatus\Schedule\Queue\QueueLock::acquire
	 */
	public function test_lock_ttl_filter_is_applied() {
		\WP_Mock::userFunction( 'get_transient' )
			->with( 'aps_queue_lock_sweep' )
			->andReturn( false );
		\WP_Mock::onFilter( 'aps_queue_lock_ttl' )
			->with( 30, 'sweep' )
			->reply( 90 );
		\WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'aps_queue_lock_sweep', \Mockery::type( 'int' ), 90 )
			->andReturn( true );

		$lock = new QueueLock( 'sweep' );

		$this->assertTrue( $lock->acquire( 30 ) );
	}
}
