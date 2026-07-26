<?php
/**
 * Archive\ArchiveAction Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchiveAction
 *
 * Pure-enum tests: every method is a single match expression, so coverage
 * is one assertion per case per method.
 */

use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * ArchiveAction test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ArchiveAction
 */
class ArchiveActionTest extends TestCase {

	/**
	 * past_tense() — used as the success query arg in redirect URLs.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::past_tense
	 */
	public function test_past_tense_archive_returns_archived() {
		$this->assertSame( 'archived', ArchiveAction::Archive->past_tense() );
	}

	/**
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::past_tense
	 */
	public function test_past_tense_unarchive_returns_unarchived() {
		$this->assertSame( 'unarchived', ArchiveAction::Unarchive->past_tense() );
	}

	/**
	 * query_arg() is an alias for past_tense() — keep the alias contract
	 * explicit so a future refactor cannot silently break readability at
	 * call sites.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::query_arg
	 */
	public function test_query_arg_matches_past_tense() {
		$this->assertSame( ArchiveAction::Archive->past_tense(), ArchiveAction::Archive->query_arg() );
		$this->assertSame( ArchiveAction::Unarchive->past_tense(), ArchiveAction::Unarchive->query_arg() );
	}

	/**
	 * nonce_key() formats as 'archive-{id}' / 'unarchive-{id}'.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::nonce_key
	 */
	public function test_nonce_key_archive_concatenates_post_id() {
		$this->assertSame( 'archive-42', ArchiveAction::Archive->nonce_key( 42 ) );
	}

	/**
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::nonce_key
	 */
	public function test_nonce_key_unarchive_concatenates_post_id() {
		$this->assertSame( 'unarchive-42', ArchiveAction::Unarchive->nonce_key( 42 ) );
	}

	/**
	 * capability_function() returns the aps_current_user_can_* function name
	 * used by PostList::handle_post_action() for variable-function dispatch.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::capability_function
	 */
	public function test_capability_function_archive_returns_can_archive() {
		$this->assertSame( 'aps_current_user_can_archive', ArchiveAction::Archive->capability_function() );
	}

	/**
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::capability_function
	 */
	public function test_capability_function_unarchive_returns_can_unarchive() {
		$this->assertSame( 'aps_current_user_can_unarchive', ArchiveAction::Unarchive->capability_function() );
	}

	/**
	 * perform() dispatches to aps_archive_post() for the Archive case.
	 * This is the single seam preventing call sites from branching on
	 * action type; verifying the dispatch is the central contract.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::perform
	 */
	public function test_perform_archive_dispatches_to_aps_archive_post() {
		$post = new WP_Post();
		$post->ID = 42;

		\WP_Mock::userFunction( 'aps_archive_post' )
			->once()
			->with( 42 )
			->andReturn( $post );

		\WP_Mock::userFunction( 'aps_unarchive_post' )->never();

		$result = ArchiveAction::Archive->perform( 42 );

		$this->assertSame( $post, $result );
	}

	/**
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::perform
	 */
	public function test_perform_unarchive_dispatches_to_aps_unarchive_post() {
		$post = new WP_Post();
		$post->ID = 42;

		\WP_Mock::userFunction( 'aps_unarchive_post' )
			->once()
			->with( 42 )
			->andReturn( $post );

		\WP_Mock::userFunction( 'aps_archive_post' )->never();

		$result = ArchiveAction::Unarchive->perform( 42 );

		$this->assertSame( $post, $result );
	}
}
