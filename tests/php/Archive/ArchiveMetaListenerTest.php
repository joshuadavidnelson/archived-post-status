<?php
/**
 * Archive\ArchiveMetaListener Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener
 * @covers ArchivedPostStatus\Archive\ArchiveMeta
 *
 * Covers the listener that persists archive meta via aps_archived_post and
 * removes it via aps_unarchived_post — verifies the hook descriptor shape
 * (both at accepted_args=3 after §1.2 #7) plus the save/delete branches.
 *
 * The listener composes ArchiveMeta directly. Rather than mocking that
 * dependency, the tests assert against the meta writes/reads it dispatches
 * (add_post_meta / delete_post_meta / get_post_meta) — the real observable
 * behaviour of the listener. Because the call graph intentionally exercises
 * ArchiveMeta::from_post()/save() and ArchiveMeta::for_post()/delete(),
 * ArchiveMeta is declared at class level so its coverage is credited here.
 */

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Archive\ArchiveMetaListener;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * ArchiveMetaListener test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener
 * @covers ArchivedPostStatus\Archive\ArchiveMeta
 */
class ArchiveMetaListenerTest extends TestCase {

	/**
	 * @var ArchiveMetaListener
	 */
	protected $listener;

	/**
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->listener = new ArchiveMetaListener();
	}

	/**
	 * hooks() returns two action descriptors — both at accepted_args=3
	 * after §1.2 #7. The 3rd arg is the WP_Post object now part of the
	 * locked public API for 0.4.0.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener::hooks
	 */
	public function test_hooks_returns_two_action_descriptors_with_accepted_args_3() {
		$descriptors = $this->listener->hooks();

		$this->assertCount( 2, $descriptors );

		foreach ( $descriptors as $descriptor ) {
			$this->assertInstanceOf( HookDescriptor::class, $descriptor );
			$this->assertTrue( $descriptor->is_action() );
			$this->assertSame( 3, $descriptor->accepted_args );
		}
	}

	/**
	 * The two hook names are `aps_archived_post` and `aps_unarchived_post` —
	 * verify by name so a rename in the listener cannot silently drift from
	 * the public API surface in §2 of the plan.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener::hooks
	 */
	public function test_hooks_registers_archived_and_unarchived_post_actions() {
		$descriptors = $this->listener->hooks();

		$hook_names = array_map( fn( $descriptor ) => $descriptor->hook, $descriptors );

		$this->assertContains( 'aps_archived_post', $hook_names );
		$this->assertContains( 'aps_unarchived_post', $hook_names );
	}

	/**
	 * save_meta() captures the post's status fields plus the current user
	 * and timestamp into the five archive meta keys via update_post_meta —
	 * exercises the ArchiveMeta::from_post(...)->save() dispatch end to end.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener::save_meta
	 */
	public function test_save_meta_writes_five_archive_meta_keys_from_post_snapshot() {
		$post                 = new WP_Post();
		$post->ID             = 42;
		$post->post_status    = 'publish';
		$post->comment_status = 'open';
		$post->ping_status    = 'closed';

		$writes = array();
		\WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing( function ( $post_id, $key, $value ) use ( &$writes ) {
				$writes[] = array( $post_id, $key, $value );
				return true;
			} );

		$this->listener->save_meta( 42, 'publish', $post );

		// Build a key-indexed map for clearer assertions.
		$by_key = array();
		foreach ( $writes as $write ) {
			$this->assertSame( 42, $write[0] );
			$by_key[ $write[1] ] = $write[2];
		}

		$this->assertSame( 'publish', $by_key[ ArchiveMeta::META_PREVIOUS_STATUS ] );
		$this->assertSame( 'open', $by_key[ ArchiveMeta::META_COMMENT_STATUS ] );
		$this->assertSame( 'closed', $by_key[ ArchiveMeta::META_PING_STATUS ] );
		// get_current_user_id() and time() are mocked in common.php / available natively;
		// the recorded user id should be the integer the helper returns (1).
		$this->assertSame( 1, $by_key[ ArchiveMeta::META_ARCHIVE_USER ] );
		$this->assertIsInt( $by_key[ ArchiveMeta::META_ARCHIVE_DATE ] );
		$this->assertGreaterThan( 0, $by_key[ ArchiveMeta::META_ARCHIVE_DATE ] );
	}

	/**
	 * delete_meta() is a no-op when the post has no archive meta at all —
	 * ArchiveMeta::for_post() returns null and the listener short-circuits
	 * without ever calling delete_post_meta.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener::delete_meta
	 */
	public function test_delete_meta_is_noop_when_post_has_no_archive_meta() {
		$post     = new WP_Post();
		$post->ID = 42;

		// for_post() reads the previous-status key first; empty => bail.
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( '' );

		// If for_post() returned null the listener must NOT call delete_post_meta.
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->listener->delete_meta( 42, 'publish', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * When archive meta exists, delete_meta() removes all five keys via
	 * ArchiveMeta::for_post(...)->delete().
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveMetaListener::delete_meta
	 */
	public function test_delete_meta_removes_all_five_keys_when_meta_exists() {
		$post     = new WP_Post();
		$post->ID = 42;

		// for_post() reads all 5 meta keys; meta is present.
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

		$deletes = array();
		\WP_Mock::userFunction( 'delete_post_meta' )
			->andReturnUsing( function ( $post_id, $key ) use ( &$deletes ) {
				$deletes[] = array( $post_id, $key );
				return true;
			} );

		$this->listener->delete_meta( 42, 'publish', $post );

		$deleted_keys = array_map( fn( $delete ) => $delete[1], $deletes );
		$this->assertContains( ArchiveMeta::META_PREVIOUS_STATUS, $deleted_keys );
		$this->assertContains( ArchiveMeta::META_ARCHIVE_DATE, $deleted_keys );
		$this->assertContains( ArchiveMeta::META_ARCHIVE_USER, $deleted_keys );
		$this->assertContains( ArchiveMeta::META_COMMENT_STATUS, $deleted_keys );
		$this->assertContains( ArchiveMeta::META_PING_STATUS, $deleted_keys );
		// Every delete targets post 42.
		foreach ( $deletes as $delete ) {
			$this->assertSame( 42, $delete[0] );
		}
	}
}
