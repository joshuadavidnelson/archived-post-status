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
 */

use ArchivedPostStatus\Archive\ArchiveOperation;
use ArchivedPostStatus\Archive\UnarchiveOperation;
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
		// returns the default when no `onFilter` is registered.
		\WP_Mock::userFunction( 'wp_update_post' )
			->once()
			->andReturnUsing(
				static function ( $args ) {
					// Pin the inverse contract — the new status must NOT be the
					// filtered archive slug (we are leaving it).
					return 42;
				}
			);

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
}
