<?php
/**
 * Archive\ArchiveMeta Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchiveMeta
 *
 * Tests the readonly value object that centralizes archive meta-key writes
 * and reads. Verifies snapshot capture from a WP_Post, null-on-missing
 * semantics for `for_post`, the legacy zero-field fallback, and that
 * `save` / `delete` write / remove the five expected meta keys.
 */

use ArchivedPostStatus\Archive\ArchiveMeta;

/**
 * ArchiveMeta test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ArchiveMeta
 */
class ArchiveMetaTest extends TestCase {

	/**
	 * from_post() captures the post's three status fields and stamps
	 * the current user id / timestamp into the resulting value object.
	 *
	 * The default common.php get_current_user_id() mock returns 1; time()
	 * is bracketed before/after the call to tolerate sub-second variance.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta::from_post
	 */
	public function test_from_post_snapshots_status_user_and_time() {
		$post = new WP_Post();
		$post->post_status    = 'publish';
		$post->comment_status = 'open';
		$post->ping_status    = 'open';

		$before = time();
		$meta   = ArchiveMeta::from_post( $post );
		$after  = time();

		$this->assertSame( 'publish', $meta->previous_status );
		$this->assertSame( 'open', $meta->comment_status );
		$this->assertSame( 'open', $meta->ping_status );
		$this->assertSame( 1, $meta->archive_user ); // common.php mock returns 1
		$this->assertGreaterThanOrEqual( $before, $meta->archive_date );
		$this->assertLessThanOrEqual( $after, $meta->archive_date );
	}

	/**
	 * from_post() preserves whatever comment_status and ping_status are
	 * on the post — including 'closed' — without rewriting them.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta::from_post
	 */
	public function test_from_post_preserves_closed_comment_and_ping_status() {
		$post = new WP_Post();
		$post->post_status    = 'draft';
		$post->comment_status = 'closed';
		$post->ping_status    = 'closed';

		$meta = ArchiveMeta::from_post( $post );

		$this->assertSame( 'draft', $meta->previous_status );
		$this->assertSame( 'closed', $meta->comment_status );
		$this->assertSame( 'closed', $meta->ping_status );
	}

	/**
	 * for_post() returns null when the previous-status meta row is missing —
	 * the canonical signal that a post has never been archived.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta::for_post
	 */
	public function test_for_post_returns_null_when_previous_status_meta_missing() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 99, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( '' );

		$this->assertNull( ArchiveMeta::for_post( 99 ) );
	}

	/**
	 * for_post() with the full set of meta rows returns a fully populated
	 * value object — verifies each meta key maps to the right property.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta::for_post
	 */
	public function test_for_post_returns_populated_object_when_all_meta_present() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( '7' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( 'closed' );

		$meta = ArchiveMeta::for_post( 42 );

		$this->assertNotNull( $meta );
		$this->assertSame( 'publish', $meta->previous_status );
		$this->assertSame( 1700000000, $meta->archive_date );
		$this->assertSame( 7, $meta->archive_user );
		$this->assertSame( 'open', $meta->comment_status );
		$this->assertSame( 'closed', $meta->ping_status );
	}

	/**
	 * Legacy archives (pre-0.4.0) only stored the previous-status meta key.
	 * for_post() must return an object with 0 for the date/user fields and
	 * 'closed' as the fallback for missing comment/ping status.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta::for_post
	 */
	public function test_for_post_returns_zero_fields_for_legacy_meta() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 5, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 5, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 5, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 5, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 5, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( '' );

		$meta = ArchiveMeta::for_post( 5 );

		$this->assertNotNull( $meta );
		$this->assertSame( 'publish', $meta->previous_status );
		$this->assertSame( 0, $meta->archive_date );
		$this->assertSame( 0, $meta->archive_user );
		// Fallback to 'closed' for missing comment/ping status.
		$this->assertSame( 'closed', $meta->comment_status );
		$this->assertSame( 'closed', $meta->ping_status );
	}

	/**
	 * save() writes all five archive meta keys for the given post id with
	 * the value object's properties as the meta values. The write must be
	 * idempotent — update_post_meta() overwrites any stale rows left by an
	 * interrupted archive/unarchive cycle, where add_post_meta() would
	 * append duplicates and get_post_meta( ..., true ) would keep
	 * returning the oldest (stale) row.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta::save
	 */
	public function test_save_writes_all_five_archive_meta_keys_idempotently() {
		$meta = new ArchiveMeta( 'publish', 1700000000, 7, 'open', 'closed' );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, 'publish' )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, 1700000000 )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, 7 )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, 'open' )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_PING_STATUS, 'closed' )
			->andReturn( true );
		\WP_Mock::userFunction( 'add_post_meta' )->never();

		$meta->save( 42 );

		// WP_Mock's ->once() expectations are checked at tearDown; register
		// the assertion explicitly so PHPUnit doesn't flag the test as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * delete() removes every one of the five archive meta keys for the post.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMeta::delete
	 */
	public function test_delete_removes_all_five_archive_meta_keys() {
		$meta = new ArchiveMeta( 'publish', 1700000000, 7, 'open', 'closed' );

		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_ARCHIVE_USER )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_COMMENT_STATUS )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, ArchiveMeta::META_PING_STATUS )
			->andReturn( true );

		$meta->delete( 42 );

		$this->addToAssertionCount( 1 );
	}
}
