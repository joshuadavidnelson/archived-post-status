<?php
/**
 * End-to-end regression: the `aps_post_status_slug` filter threads through
 * every internal consumer.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchiveOperation
 * @covers ArchivedPostStatus\Archive\UnarchiveOperation
 * @covers ArchivedPostStatus\Status\PostStatusValue
 * @covers ArchivedPostStatus\Status\PostStatusGuard
 *
 * The archived post status slug is centralised in the
 * `PostStatusValue::resolved_slug()` accessor. The motivating bug:
 * stable 0.3.x applied the `aps_post_status_slug` filter at registration
 * only, leaving every other internal comparison ('archive' === $post->post_status,
 * wp_update_post(['post_status' => 'archive']), etc.) hardcoded against the
 * default. A site that registered the status under a custom slug ('archived')
 * would see the registration succeed but every subsequent internal comparison
 * fail to match.
 *
 * This test pins the fix end-to-end at the Operation layer: register a custom
 * slug via the filter, then archive a publish-status post; assert that
 * `wp_update_post` was called with the *filtered* slug. Mirrors the symmetric
 * unarchive flow: an archived post under the custom slug round-trips back to
 * its previous status without the comparison leaking the default 'archive'.
 *
 * Also pins PostStatusGuard's two out-of-band comparisons — the entry path
 * (correcting a direct `post_status` write on `save_post`) and the exit path
 * (restoring state on `transition_post_status`) — against the filtered slug.
 */

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Archive\ArchiveOperation;
use ArchivedPostStatus\Archive\UnarchiveOperation;
use ArchivedPostStatus\Status\PostStatusGuard;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * @since 0.4.0
 */
class SlugFilterIntegrationTest extends TestCase {

	/**
	 * With `aps_post_status_slug` overridden to `'archived'`,
	 * `PostStatusValue::resolved_slug()` must return the override at every
	 * call — including the implicit ones inside `ArchiveOperation::perform()`.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue::resolved_slug
	 */
	public function test_resolved_slug_returns_filtered_value_under_archived_override() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		$this->assertSame( 'archived', PostStatusValue::resolved_slug() );
	}

	/**
	 * THE slug-leak regression pin: when the slug filter overrides the
	 * registered status to `'archived'`, the `wp_update_post` call inside
	 * `ArchiveOperation::perform()` must use the filtered slug — not the
	 * hardcoded default. Without `PostStatusValue::resolved_slug()` routing,
	 * this assertion fails (the SUT would write `'post_status' => 'archive'`
	 * to a status that was never registered).
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_archive_operation_writes_filtered_slug_via_wp_update_post() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );

		// THE assertion: wp_update_post receives the *filtered* slug, not 'archive'.
		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'post_status'    => 'archived',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			)
			->andReturn( 42 );

		\WP_Mock::onFilter( 'aps_pre_archive_post' )
			->with( null, $post, 'publish' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_archive_post', 42, 'publish' );
		\WP_Mock::expectAction( 'aps_archived_post', 42, 'publish', $post );

		$result = ArchiveOperation::perform( 42 );

		$this->assertSame( $post, $result );
	}

	/**
	 * The already-archived short-circuit must compare against the *filtered*
	 * slug, not the hardcoded `'archive'`. A post whose status is `'archived'`
	 * (matching the override) must be detected as already archived and
	 * short-circuit without dispatching to `wp_update_post`. Pre-3B this
	 * comparison was `'archive' === $post->post_status` — the leak that
	 * `resolved_slug()` closes.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveOperation::perform
	 */
	public function test_archive_operation_short_circuits_when_post_status_matches_filtered_slug() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archived',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->assertFalse( ArchiveOperation::perform( 42 ) );
	}

	/**
	 * Symmetric on the unarchive path: a post whose status matches the
	 * *filtered* slug (`'archived'`) is treated as archived for the purposes
	 * of `UnarchiveOperation::perform()`'s eligibility check. Pre-3B the
	 * comparison was `'archive' !== $post->post_status`, so a custom-slug
	 * site could never unarchive anything.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_unarchive_operation_treats_filtered_slug_as_archived_status() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archived',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )->andReturn( '' );

		// Filters that the unarchive operation consults to derive the
		// restored status. The defaults reach apply_filters; WP_Mock
		// returns the default when no `onFilter` is registered. No archive
		// meta is recorded here (get_post_meta stubbed to '' above), so
		// resolve_restore_values() takes the legacy branch — new_status
		// 'draft', comment/ping 'closed' — and all three pass through
		// apply_filters unchanged, landing verbatim in the payload below.
		//
		// THE assertion: post_status is 'draft' — the inverse contract pin.
		// The new status must NOT be the filtered archive slug ('archived');
		// unarchiving leaves the archived state rather than re-writing it.
		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'post_status'    => 'draft',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			)
			->andReturn( 42 );

		\WP_Mock::onFilter( 'aps_pre_unarchive_post' )
			->with( null, $post, 'draft' )
			->reply( null );

		\WP_Mock::expectAction( 'aps_unarchive_post', 42, 'draft' );
		\WP_Mock::expectAction( 'aps_unarchived_post', 42, 'draft', $post );

		$result = UnarchiveOperation::perform( 42 );

		$this->assertSame( $post, $result );
	}

	/**
	 * Negative test: a post whose status is the *default* `'archive'` slug
	 * is NOT treated as archived under an override that registered the
	 * status as `'archived'`. This is the strict-equality direction of the
	 * leak fix — comparisons must match the filtered slug, not the
	 * canonical default.
	 *
	 * @covers ArchivedPostStatus\Archive\UnarchiveOperation::perform
	 */
	public function test_unarchive_operation_rejects_default_slug_under_filtered_override() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive', // canonical default — does NOT match the override
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->assertFalse( UnarchiveOperation::perform( 42 ) );
	}

	/**
	 * Entry-path pin: PostStatusGuard::enforce_archive_state() runs on
	 * save_post and corrects a direct `post_status` write that bypasses
	 * aps_archive_post() — but only if it recognises the post as archived.
	 * That recognition must compare against the *filtered* slug, not the
	 * hardcoded 'archive' literal. A post whose status is 'archived'
	 * (matching the override) with open comments/pings must still be
	 * corrected. Pre-fix this comparison was
	 * `'archive' !== $post->post_status`, so a custom-slug site's archived
	 * posts would never be corrected by this guard.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state
	 */
	public function test_post_status_guard_corrects_comment_ping_when_status_matches_filtered_slug() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		\WP_Mock::userFunction( 'wp_doing_ajax' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_doing_cron' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->with( 'post' )
			->andReturn( true );

		// THE assertion: the corrective write fires for a post_status of
		// 'archived' — the filtered slug — not just the literal default
		// 'archive'.
		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);

		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'archived',
				'post_type'      => 'post',
				'comment_status' => 'open',
				'ping_status'    => 'open',
			)
		);

		( new PostStatusGuard() )->enforce_archive_state( 42, $post );

		// WP_Mock verifies the never()/once() expectations above during tearDown;
		// register the assertion explicitly so PHPUnit doesn't flag it as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Exit-path pin (leaving branch): PostStatusGuard::restore_state_on_exit()
	 * treats a transition away from the *filtered* slug as leaving the
	 * archived status — not just the literal 'archive'. A post whose
	 * $old_status is 'archived' (matching the override) has its comment/ping
	 * restored from archive meta and the meta rows deleted, exactly as it
	 * would under the default slug. Pre-fix this comparison was
	 * `'archive' !== $old_status`, so a custom-slug site's posts would never
	 * trigger the exit restore.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_post_status_guard_restores_state_when_leaving_filtered_slug() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( 'publish' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( 1700000000 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( 'open' );

		// THE assertion: the corrective restore fires for an old_status of
		// 'archived' — the filtered slug — not just the literal default
		// 'archive'.
		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'             => 42,
					'comment_status' => 'open',
					'ping_status'    => 'open',
				)
			)
			->andReturn( 42 );

		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );

		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		( new PostStatusGuard() )->restore_state_on_exit( 'publish', 'archived', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Exit-path pin (returning branch): PostStatusGuard::restore_state_on_exit()
	 * must recognise a transition *back into* the filtered slug as still
	 * inside the archive lifecycle — not an exit. A trash→'archived'
	 * transition (untrashing back to the custom-slug archived status) must
	 * short-circuit without touching meta, mirroring
	 * PostStatusGuardTest::test_restore_state_on_exit_ignores_untrash_back_to_archive
	 * under the literal slug. Pre-fix this comparison was
	 * `'archive' === $new_status`, so under a custom-slug override this
	 * branch would never recognise the return and would incorrectly run the
	 * restore/delete logic on a post that is, in fact, still archived.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusGuard::restore_state_on_exit
	 */
	public function test_post_status_guard_ignores_return_to_filtered_slug() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		\WP_Mock::userFunction( 'get_post_meta' )->never();
		\WP_Mock::userFunction( 'wp_update_post' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$post = $this->createMockPost(
			array(
				'ID'             => 42,
				'post_status'    => 'archived',
				'post_type'      => 'post',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		( new PostStatusGuard() )->restore_state_on_exit( 'archived', 'trash', $post );

		$this->addToAssertionCount( 1 );
	}
}
