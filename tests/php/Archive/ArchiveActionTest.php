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

	/**
	 * Regression: `aps_archive_post()` returns `\WP_Post|bool` and,
	 * per its own docblock, propagates the `aps_pre_archive_post` filter's
	 * return value verbatim — including a bare `true`. `perform()`'s return
	 * type must widen to accept that shape; a narrower `\WP_Post|false`
	 * declaration throws a TypeError the moment a site adds
	 * `add_filter( 'aps_pre_archive_post', '__return_true' )`.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::perform
	 */
	public function test_perform_archive_returns_true_when_aps_archive_post_short_circuits_true() {
		\WP_Mock::userFunction( 'aps_archive_post' )
			->once()
			->with( 42 )
			->andReturn( true );

		$result = ArchiveAction::Archive->perform( 42 );

		$this->assertTrue( $result );
	}

	/**
	 * Regression: the Unarchive twin of the above. `aps_unarchive_post()`
	 * short-circuits on `aps_pre_unarchive_post` the same way.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::perform
	 */
	public function test_perform_unarchive_returns_true_when_aps_unarchive_post_short_circuits_true() {
		\WP_Mock::userFunction( 'aps_unarchive_post' )
			->once()
			->with( 42 )
			->andReturn( true );

		$result = ArchiveAction::Unarchive->perform( 42 );

		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// locked_message() / failure_message() / denied_message()
	// -----------------------------------------------------------------------
	//
	// PostList::handle_post_action() is shared by both directions but its
	// wp_die() copy used to be archive-only regardless of which action was
	// running. These three accessors give each direction its own complete,
	// independently translatable string — not a shared template assembled by
	// concatenating a direction word into it — so PostList can select the
	// right copy without ever building a sentence out of fragments.

	/**
	 * locked_message() names the archive action and carries the %s
	 * placeholder PostList fills in with the locking user's display name.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::locked_message
	 */
	public function test_locked_message_archive_names_the_archive_action() {
		$message = ArchiveAction::Archive->locked_message();

		$this->assertStringContainsString( 'cannot archive this item', $message );
		$this->assertStringNotContainsString( 'unarchive', $message );
		$this->assertStringContainsString( '%s', $message );
	}

	/**
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::locked_message
	 */
	public function test_locked_message_unarchive_names_the_unarchive_action() {
		$message = ArchiveAction::Unarchive->locked_message();

		$this->assertStringContainsString( 'cannot unarchive this item', $message );
		$this->assertStringContainsString( '%s', $message );
	}

	/**
	 * failure_message() names the archive action — the wp_die() shown when
	 * ArchiveAction::perform() fails to persist the status change.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::failure_message
	 */
	public function test_failure_message_archive_names_the_archive_action() {
		$this->assertSame( 'Error in archiving this item.', ArchiveAction::Archive->failure_message() );
	}

	/**
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::failure_message
	 */
	public function test_failure_message_unarchive_names_the_unarchive_action() {
		$this->assertSame( 'Error in unarchiving this item.', ArchiveAction::Unarchive->failure_message() );
	}

	/**
	 * denied_message() names the archive action — the wp_die() shown when
	 * the current user fails the capability check. This branch used to return
	 * silently with no explanation at all.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::denied_message
	 */
	public function test_denied_message_archive_names_the_archive_action() {
		$this->assertSame(
			'You do not have permission to archive this item.',
			ArchiveAction::Archive->denied_message()
		);
	}

	/**
	 * @covers ArchivedPostStatus\Archive\ArchiveAction::denied_message
	 */
	public function test_denied_message_unarchive_names_the_unarchive_action() {
		$this->assertSame(
			'You do not have permission to unarchive this item.',
			ArchiveAction::Unarchive->denied_message()
		);
	}
}
