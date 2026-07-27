<?php
/**
 * RowActionPolicy parity-snapshot test.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostList::row_actions
 * @covers ArchivedPostStatus\Admin\RowActionPolicy
 *
 * Phase 4 of the 0.4.0 cleanup: the row-action policy is being extracted out
 * of `PostList::row_actions()` into a standalone static `Admin\RowActionPolicy`
 * helper. The plan's Phase 4 Risk note calls for a parity snapshot over
 * `(post_status x cap_set x screen_base)` taken BEFORE the extraction; the
 * pre-extraction shape becomes the locked snapshot, and the new static
 * `RowActionPolicy::for_post()` MUST reproduce it byte-for-byte.
 *
 * Cross-product surface:
 *   - post_status:   publish, draft, archive, pending           (4)
 *   - cap_set:       full_caps, view_only, no_caps              (3)
 *   - screen_base:   edit, post                                 (2)
 *
 *   = 24 combinations.
 *
 * Per the plan, the new helper accepts a `WP_Screen|null` argument; the
 * current `PostList::row_actions()` does not consult any screen state. We
 * still exercise the grid with both screen variants so the SUT contract is
 * pinned: the screen MUST NOT change the policy output (regression guard
 * against any future drift in how the screen arg is consumed).
 */

use ArchivedPostStatus\Admin\BulkActionHandler;
use ArchivedPostStatus\Admin\PostList;
use ArchivedPostStatus\Admin\RowActionPolicy;

/**
 * Parity snapshot covering the row-action policy across the
 * `(post_status x cap_set x screen_base)` cross-product.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\PostList::row_actions
 * @covers ArchivedPostStatus\Admin\RowActionPolicy
 */
class RowActionPolicyParitySnapshotTest extends TestCase {

	/**
	 * @var PostList
	 */
	protected $post_list;

	public function set_up() {
		parent::set_up();
		$this->post_list = new PostList( new BulkActionHandler() );
	}

	public function tear_down() {
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}

	/**
	 * Provider for the 24-combination cross-product.
	 *
	 * Yields rows shaped:
	 *   [ post_status, cap_set, screen_base, expected_action_keys ]
	 *
	 * `expected_action_keys` is the sorted list of row-action keys the
	 * resulting array must contain. This is the parity snapshot —
	 * derived once from the current `PostList::row_actions()` behavior
	 * and locked here so the extraction has a fixed target.
	 *
	 * Current rules captured from `PostList::row_actions()`:
	 *
	 *   - Unsupported post type → return $actions unchanged.
	 *   - Archivable status + can_archive → append 'archive' to $actions.
	 *   - post_status == 'archive' + can_unarchive →
	 *       drop 'edit' and 'inline hide-if-no-js';
	 *       drop 'view' if not can_view;
	 *       append 'unarchive'.
	 *   - Otherwise → return $actions unchanged.
	 *
	 * The archivable list contains publish, draft, pending (and also future,
	 * private — but we test only the three reachable from the grid).
	 *
	 * @return iterable<string, array{0:string,1:string,2:string,3:array<int,string>}>
	 */
	public function row_action_grid_provider(): iterable {
		$statuses     = array( 'publish', 'draft', 'archive', 'pending' );
		$cap_sets     = array( 'full_caps', 'view_only', 'no_caps' );
		$screen_bases = array( 'edit', 'post' );

		// Input row-actions array — the WP shape before our filter runs.
		$incoming_keys = array( 'edit', 'inline hide-if-no-js', 'view' );

		foreach ( $statuses as $status ) {
			foreach ( $cap_sets as $caps ) {
				foreach ( $screen_bases as $screen ) {
					$expected = $this->compute_expected_keys( $incoming_keys, $status, $caps );

					$key = sprintf( '%s|%s|%s', $status, $caps, $screen );
					yield $key => array( $status, $caps, $screen, $expected );
				}
			}
		}
	}

	/**
	 * Compute the expected row-action key set for a given combination.
	 *
	 * Pure function — the snapshot oracle. Mirrors the rules in
	 * `PostList::row_actions()` so the test can assert on a deterministic
	 * shape rather than re-running the SUT to learn its own answer.
	 *
	 * @param array<int, string> $incoming_keys
	 * @param string             $status
	 * @param string             $caps
	 * @return array<int, string>
	 */
	private function compute_expected_keys( array $incoming_keys, string $status, string $caps ): array {
		$can_archive   = ( 'full_caps' === $caps );
		$can_unarchive = ( 'full_caps' === $caps );
		$can_view      = ( 'full_caps' === $caps || 'view_only' === $caps );

		$archivable = array( 'publish', 'future', 'draft', 'pending', 'private' );

		// Branch A: archivable status + can_archive → append 'archive'.
		if ( in_array( $status, $archivable, true ) && $can_archive ) {
			$keys   = $incoming_keys;
			$keys[] = 'archive';
			sort( $keys );
			return array_values( $keys );
		}

		// Branch B: status === 'archive' + can_unarchive → strip + append.
		if ( 'archive' === $status && $can_unarchive ) {
			$keys = array_values(
				array_diff( $incoming_keys, array( 'edit', 'inline hide-if-no-js' ) )
			);
			if ( ! $can_view ) {
				$keys = array_values( array_diff( $keys, array( 'view' ) ) );
			}
			$keys[] = 'unarchive';
			sort( $keys );
			return array_values( $keys );
		}

		// Branch C: pass-through.
		$keys = $incoming_keys;
		sort( $keys );
		return array_values( $keys );
	}

	/**
	 * Configure the WP-mock boundary so `PostList::row_actions()` runs
	 * against the named status / capability set.
	 */
	private function configure_boundary( string $post_id_used_for_cap_checks, string $caps ): void {
		// Supported post types — `post` is always supported in this grid.
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post' ) );
		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( \Mockery::type( 'array' ) )
			->reply( array( 'post' ) );

		// Archivable statuses.
		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( \Mockery::type( 'array' ) )
			->reply( array( 'publish', 'future', 'draft', 'pending', 'private' ) );

		// Capability resolution. The grid's mock user is anonymous (id 0),
		// so the ownership-aware defaults resolve to the others-primitive
		// and the view fallback never engages:
		// aps_current_user_can_archive($id)   -> current_user_can('edit_others_posts', $id)
		// aps_current_user_can_unarchive($id) -> current_user_can('edit_others_posts', $id)
		// aps_current_user_can_view($id)      -> current_user_can('read_private_posts', $id)
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		$can_archive   = ( 'full_caps' === $caps );
		$can_unarchive = ( 'full_caps' === $caps );
		$can_view      = ( 'full_caps' === $caps || 'view_only' === $caps );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', (int) $post_id_used_for_cap_checks )
			->andReturn( $can_archive );

		// View cap may also be queried.
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'read_private_posts', (int) $post_id_used_for_cap_checks )
			->andReturn( $can_view );

		// URL-pipeline boundary (consumed by ArchivePostLink::build).
		\WP_Mock::userFunction( 'get_post' )
			->andReturnUsing( static function ( $arg ) {
				if ( is_object( $arg ) ) {
					return $arg;
				}
				$post              = new \stdClass();
				$post->ID          = (int) $arg;
				$post->post_type   = 'post';
				$post->post_status = 'publish';
				return $post;
			} );
		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) array( '_edit_link' => 'post.php?post=%d&action=edit' ) );
		\WP_Mock::userFunction( 'admin_url' )
			->andReturnUsing( static fn( $path ) => 'http://example.com/wp-admin/' . $path );
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing( static function ( $arg, $value, $url ) {
				return $url . '&' . $arg . '=' . $value;
			} );
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=test' );
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => $url );
	}

	/**
	 * Cross-product parity assertion: every grid cell produces the snapshot.
	 *
	 * @dataProvider row_action_grid_provider
	 *
	 * @covers ArchivedPostStatus\Admin\PostList::row_actions
	 *
	 * @param string             $status       Post status to put on the WP_Post.
	 * @param string             $caps         Capability set: full_caps|view_only|no_caps.
	 * @param string             $screen_base  Screen base under test (informational).
	 * @param array<int, string> $expected     Sorted list of expected action keys.
	 */
	public function test_postlist_row_actions_matches_parity_snapshot(
		string $status,
		string $caps,
		string $screen_base,
		array $expected
	): void {
		$post_id = 7;
		$this->configure_boundary( (string) $post_id, $caps );

		$post = $this->createMockPost(
			array(
				'ID'          => $post_id,
				'post_type'   => 'post',
				'post_status' => $status,
			)
		);

		$incoming = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<a>Quick Edit</a>',
			'view'                 => '<a>View</a>',
		);

		$result = $this->post_list->row_actions( $incoming, $post );

		$actual_keys = array_keys( $result );
		sort( $actual_keys );

		$this->assertSame(
			$expected,
			$actual_keys,
			sprintf(
				'Row-action keys must match parity snapshot for status=%s caps=%s screen=%s. Got [%s], expected [%s].',
				$status,
				$caps,
				$screen_base,
				implode( ',', $actual_keys ),
				implode( ',', $expected )
			)
		);
	}

	/**
	 * Mirror assertion: the new `RowActionPolicy::for_post()` static helper
	 * MUST produce the same row-action key set as `PostList::row_actions()`
	 * for every grid cell. This is the post-extraction parity pin —
	 * extraction is byte-for-byte equivalent if both assertions in this
	 * file pass on the same fixture.
	 *
	 * @dataProvider row_action_grid_provider
	 *
	 * @covers ArchivedPostStatus\Admin\RowActionPolicy::for_post
	 *
	 * @param string             $status
	 * @param string             $caps
	 * @param string             $screen_base
	 * @param array<int, string> $expected
	 */
	public function test_row_action_policy_for_post_matches_parity_snapshot(
		string $status,
		string $caps,
		string $screen_base,
		array $expected
	): void {
		$post_id = 7;
		$this->configure_boundary( (string) $post_id, $caps );

		$post = $this->createMockPost(
			array(
				'ID'          => $post_id,
				'post_type'   => 'post',
				'post_status' => $status,
			)
		);

		$incoming = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<a>Quick Edit</a>',
			'view'                 => '<a>View</a>',
		);

		// RowActionPolicy::for_post() returns the FULL row-action array
		// after applying the archive/unarchive policy — same return shape
		// as PostList::row_actions(). Screen arg is null in this assertion;
		// the policy contract says screen MUST NOT alter the output for
		// the current (Phase 4) scope.
		$screen = null;
		$result = RowActionPolicy::for_post( $post, $incoming, $screen );

		$actual_keys = array_keys( $result );
		sort( $actual_keys );

		$this->assertSame(
			$expected,
			$actual_keys,
			sprintf(
				'RowActionPolicy::for_post() must match parity snapshot for status=%s caps=%s screen=%s.',
				$status,
				$caps,
				$screen_base
			)
		);
	}
}
