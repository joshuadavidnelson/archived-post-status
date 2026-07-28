<?php
/**
 * Shared WP-boundary stubs for tests that exercise the archive / unarchive
 * pipeline end-to-end. Centralizes three helpers previously duplicated
 * across `PostListTest`, `BulkActionHandlerTest`, and
 * `Archive\UnarchiveStatusFilterTest`:
 *
 *   - {@see stubSupportedPostTypesBoundary()}  — the `get_post_types`,
 *     `aps_excluded_post_types`, and `aps_supported_post_types` filter
 *     chain that drives `aps_get_supported_post_types()`.
 *   - {@see stubArchivableStatusesBoundary()} — the `aps_archivable_statuses`
 *     filter that drives `_aps_get_archivable_statuses()`.
 *   - {@see stubUnarchivePersistBoundary()}   — the five ArchiveMeta
 *     meta-key reads + `wp_update_post` + `delete_post_meta` that
 *     `aps_unarchive_post()` traverses.
 *
 * Tests opt-in with `use BoundaryStubs;` inside the test class. The two
 * previously duplicated copies of
 * `stubUnarchivePersistBoundary` had drifted on parameter ordering — the
 * consolidated signature uses BulkActionHandlerTest's ordering
 * (`$post_id, $wp_update_post_result, $previous_status_meta`) so the
 * majority of existing call sites need no edits. UnarchiveStatusFilterTest's
 * two call sites are updated to the new ordering.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\Tests\Support;

use ArchivedPostStatus\Archive\ArchiveMeta;

trait BoundaryStubs {

	/**
	 * Stub the WP-boundary chain `aps_get_supported_post_types()` walks:
	 *   - `get_post_types()` returns $public_types
	 *   - `esc_attr` is stubbed as identity (the production function applies
	 *     it per element)
	 *   - `post_type_exists()` returns true (the default-exclusions list is
	 *     only kept if the excluded slug actually exists — see
	 *     {@see \ArchivedPostStatus\Status\SupportedPostTypes::all()})
	 *   - `aps_excluded_post_types` filter replies with `array('attachment')`
	 *   - `aps_supported_post_types` filter replies with $supported
	 *
	 * @param string[] $public_types Public post types `get_post_types()` would return.
	 * @param string[] $supported    The list the supported-types filter replies with.
	 */
	private function stubSupportedPostTypesBoundary(
		array $public_types = array( 'post', 'page' ),
		array $supported    = array( 'post', 'page' )
	): void {
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( $public_types );
		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( \Mockery::type( 'array' ) )
			->reply( $supported );
	}

	/**
	 * Stub the `aps_archivable_statuses` filter (and the per-element
	 * `esc_attr` call inside the legacy code path) so the real
	 * `_aps_get_archivable_statuses()` returns the configured list.
	 *
	 * Call separately from `stubSupportedPostTypesBoundary()` if a test only
	 * needs the archivable-status path (the post-types helper also stubs
	 * esc_attr; calling both is safe — esc_attr is stubbed as identity in
	 * each).
	 *
	 * @param string[] $statuses The list the filter replies with.
	 */
	private function stubArchivableStatusesBoundary(
		array $statuses = array( 'publish', 'future', 'draft', 'pending', 'private' )
	): void {
		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( \Mockery::type( 'array' ) )
			->reply( $statuses );
	}

	/**
	 * Stub the full WP-boundary chain that `aps_unarchive_post()` traverses
	 * for post id $post_id: the five `ArchiveMeta` meta-key reads +
	 * `wp_update_post` + `delete_post_meta`.
	 *
	 * @param int    $post_id               The post id under test.
	 * @param mixed  $wp_update_post_result Value `wp_update_post` returns.
	 *                                       Pass `null` (default) for the
	 *                                       post id (success); `0` to simulate
	 *                                       a persist failure; `false` to skip
	 *                                       the wp_update_post stub entirely
	 *                                       (the test must declare its own).
	 * @param string $previous_status_meta  Value to return for
	 *                                       META_PREVIOUS_STATUS. Pass `''`
	 *                                       to make `ArchiveMeta::for_post()`
	 *                                       return `null`.
	 */
	private function stubUnarchivePersistBoundary(
		int $post_id,
		$wp_update_post_result = null,
		string $previous_status_meta = 'publish'
	): void {
		if ( null === $wp_update_post_result ) {
			$wp_update_post_result = $post_id;
		}

		// ArchiveMeta::for_post() reads the five meta keys.
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_PREVIOUS_STATUS, true )
			->andReturn( $previous_status_meta );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_ARCHIVE_DATE, true )
			->andReturn( time() );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_ARCHIVE_USER, true )
			->andReturn( 1 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_COMMENT_STATUS, true )
			->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ArchiveMeta::META_PING_STATUS, true )
			->andReturn( 'open' );

		if ( false !== $wp_update_post_result ) {
			\WP_Mock::userFunction( 'wp_update_post' )->andReturn( $wp_update_post_result );
		}
		\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
	}
}
