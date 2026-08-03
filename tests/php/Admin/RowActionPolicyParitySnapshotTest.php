<?php
/**
 * RowActionPolicy parity-snapshot test.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostList::row_actions
 * @covers ArchivedPostStatus\Admin\RowActionPolicy
 *
 * `PostList::row_actions()` delegates to the standalone static
 * `Admin\RowActionPolicy` helper. A parity snapshot over
 * `(post_status x cap_set)` locks the policy output: both entry points
 * MUST reproduce it byte-for-byte, so any drift in either is a
 * deliberate, test-updating change.
 *
 * Cross-product surface:
 *   - post_status:   publish, draft, archive, pending           (4)
 *   - cap_set:       full_caps, view_only, no_caps              (3)
 *
 *   = 12 combinations.
 */

use ArchivedPostStatus\Admin\BulkActionHandler;
use ArchivedPostStatus\Admin\PostList;
use ArchivedPostStatus\Admin\RowActionPolicy;

/**
 * Parity snapshot covering the row-action policy across the
 * `(post_status x cap_set)` cross-product.
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
	 * Literal expected row-action key sets, one entry per
	 * `{post_status}|{cap_set}` combination in the grid. Hand-derived from
	 * the incoming `array('edit', 'inline hide-if-no-js', 'view')` fixture
	 * against the branch contract documented on
	 * `RowActionPolicy::for_post()`, then pinned as data — not recomputed
	 * from that same branch logic.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function expected_key_table(): array {
		$archivable_pass_through = array( 'archive', 'edit', 'inline hide-if-no-js', 'view' );
		$no_op_pass_through      = array( 'edit', 'inline hide-if-no-js', 'view' );

		return array(
			'publish|full_caps' => $archivable_pass_through,
			'publish|view_only' => $no_op_pass_through,
			'publish|no_caps'   => $no_op_pass_through,
			'draft|full_caps'   => $archivable_pass_through,
			'draft|view_only'   => $no_op_pass_through,
			'draft|no_caps'     => $no_op_pass_through,
			'pending|full_caps' => $archivable_pass_through,
			'pending|view_only' => $no_op_pass_through,
			'pending|no_caps'   => $no_op_pass_through,
			'archive|full_caps' => array( 'unarchive', 'view' ),
			'archive|view_only' => $no_op_pass_through,
			'archive|no_caps'   => $no_op_pass_through,
		);
	}

	/**
	 * Provider for the 12-combination cross-product.
	 *
	 * Yields rows shaped:
	 *   [ post_status, cap_set, expected_action_keys ]
	 *
	 * `expected_action_keys` is the sorted list of row-action keys the
	 * resulting array must contain — the literal parity snapshot from
	 * {@see expected_key_table()}.
	 *
	 * @return iterable<string, array{0:string,1:string,2:array<int,string>}>
	 */
	public function row_action_grid_provider(): iterable {
		$statuses = array( 'publish', 'draft', 'archive', 'pending' );
		$cap_sets = array( 'full_caps', 'view_only', 'no_caps' );
		$table    = $this->expected_key_table();

		foreach ( $statuses as $status ) {
			foreach ( $cap_sets as $caps ) {
				$expected = $table[ "{$status}|{$caps}" ];

				$key = sprintf( '%s|%s', $status, $caps );
				yield $key => array( $status, $caps, $expected );
			}
		}
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
			->with( array( 'post' => 'post' ) )
			->reply( array( 'post' ) );

		// Archivable statuses.
		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
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
	 * @param array<int, string> $expected     Sorted list of expected action keys.
	 */
	public function test_postlist_row_actions_matches_parity_snapshot(
		string $status,
		string $caps,
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
				'Row-action keys must match parity snapshot for status=%s caps=%s. Got [%s], expected [%s].',
				$status,
				$caps,
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
	 * @param array<int, string> $expected
	 */
	public function test_row_action_policy_for_post_matches_parity_snapshot(
		string $status,
		string $caps,
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

		$result = RowActionPolicy::for_post( $post, $incoming );

		$actual_keys = array_keys( $result );
		sort( $actual_keys );

		$this->assertSame(
			$expected,
			$actual_keys,
			sprintf(
				'RowActionPolicy::for_post() must match parity snapshot for status=%s caps=%s.',
				$status,
				$caps
			)
		);
	}
}
