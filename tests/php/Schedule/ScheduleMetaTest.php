<?php
/**
 * Schedule\ScheduleMeta Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\ScheduleMeta
 *
 * Tests the readonly value object that centralizes schedule meta-key reads
 * and writes. Verifies null-on-missing semantics for for_post(), the
 * exempt-tombstone valid state (source present, no time), and that save() /
 * delete() write / remove the five expected meta keys.
 */

use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\ScheduleMeta
 */
class ScheduleMetaTest extends TestCase {

	/**
	 * for_post() returns null when the source meta row is missing — the
	 * canonical signal that a post has no schedule record at all.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMeta::for_post
	 */
	public function test_for_post_returns_null_when_source_meta_missing() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 99, ScheduleMeta::META_SOURCE, true )
			->andReturn( '' );

		$this->assertNull( ScheduleMeta::for_post( 99 ) );
	}

	/**
	 * for_post() with a full set of meta rows returns a fully populated
	 * value object — verifies each meta key maps to the right property.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMeta::for_post
	 */
	public function test_for_post_returns_populated_object_when_all_meta_present() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )
			->andReturn( 'rule' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )
			->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )
			->andReturn( '3' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )
			->andReturn( '0' );

		$meta = ScheduleMeta::for_post( 42 );

		$this->assertNotNull( $meta );
		$this->assertSame( 1700000000, $meta->time );
		$this->assertSame( ScheduleSource::Rule, $meta->source );
		$this->assertSame( 0, $meta->user );
		$this->assertSame( 3, $meta->rule_version );
		$this->assertSame( 0, $meta->attempts );
	}

	/**
	 * The exempt tombstone is a valid, non-null state: source is present
	 * ('exempt') but the time meta row is absent (dropped by
	 * ScheduleOperation::clear()). for_post() must not treat the missing
	 * time as "no record" — only the missing source means that.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMeta::for_post
	 */
	public function test_for_post_returns_object_for_exempt_tombstone_with_no_time() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ScheduleMeta::META_SOURCE, true )
			->andReturn( 'exempt' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ScheduleMeta::META_TIME, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ScheduleMeta::META_USER, true )
			->andReturn( '5' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ScheduleMeta::META_RULE_VERSION, true )
			->andReturn( '2' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ScheduleMeta::META_ATTEMPTS, true )
			->andReturn( '' );

		$meta = ScheduleMeta::for_post( 7 );

		$this->assertNotNull( $meta );
		$this->assertSame( ScheduleSource::Exempt, $meta->source );
		$this->assertSame( 0, $meta->time );
	}

	/**
	 * An unrecognized stored source value falls back to ScheduleSource::Manual
	 * rather than making the record disappear — the presence of *some* source
	 * value is still what decided this record exists.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMeta::for_post
	 */
	public function test_for_post_falls_back_to_manual_for_unrecognized_source_value() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 12, ScheduleMeta::META_SOURCE, true )
			->andReturn( 'not-a-real-source' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 12, ScheduleMeta::META_TIME, true )
			->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 12, ScheduleMeta::META_USER, true )
			->andReturn( '1' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 12, ScheduleMeta::META_RULE_VERSION, true )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 12, ScheduleMeta::META_ATTEMPTS, true )
			->andReturn( '0' );

		$meta = ScheduleMeta::for_post( 12 );

		$this->assertNotNull( $meta );
		$this->assertSame( ScheduleSource::Manual, $meta->source );
	}

	/**
	 * save() writes all five schedule meta keys idempotently via
	 * update_post_meta(), mirroring ArchiveMeta::save()'s rationale.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMeta::save
	 */
	public function test_save_writes_all_five_schedule_meta_keys_idempotently() {
		$meta = new ScheduleMeta( 1700000000, ScheduleSource::Manual, 7, 0, 0 );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_TIME, 1700000000 )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_SOURCE, 'manual' )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_USER, 7 )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_RULE_VERSION, 0 )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_ATTEMPTS, 0 )
			->andReturn( true );
		\WP_Mock::userFunction( 'add_post_meta' )->never();

		$meta->save( 42 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * delete() removes every one of the five schedule meta keys for the post.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleMeta::delete
	 */
	public function test_delete_removes_all_five_schedule_meta_keys() {
		$meta = new ScheduleMeta( 1700000000, ScheduleSource::Manual, 7, 0, 0 );

		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_TIME )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_SOURCE )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_USER )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_RULE_VERSION )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ScheduleMeta::META_ATTEMPTS )
			->andReturn( true );

		$meta->delete( 42 );

		$this->addToAssertionCount( 1 );
	}
}
