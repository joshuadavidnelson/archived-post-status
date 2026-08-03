<?php
/**
 * RowActionPolicy unit tests.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\RowActionPolicy
 *
 * Direct unit coverage on the static `RowActionPolicy::for_post()` helper.
 * The cross-product parity is pinned in
 * {@see RowActionPolicyParitySnapshotTest}; this file targets the three
 * branches the 0.4.0 refactor plan called out as coverage holes on
 * `PostList::row_actions()`:
 *
 *   1. archivable status + cannot archive → no `archive` entry.
 *   2. archive status + cannot unarchive  → no `unarchive` entry; edit/view kept.
 *   3. archive status + can unarchive but cannot view → `view` stripped + `unarchive` appended.
 */

use ArchivedPostStatus\Admin\RowActionPolicy;

/**
 * RowActionPolicy direct unit tests.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\RowActionPolicy
 */
class RowActionPolicyTest extends TestCase {

	public function tear_down() {
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}

	/**
	 * Stub the boundary `RowActionPolicy::for_post()` traverses via the static
	 * helpers (SupportedPostTypes, ArchivableStatuses, capability functions,
	 * link builders).
	 *
	 * @param bool $can_archive   Result of current_user_can('edit_others_posts', $id).
	 * @param bool $can_view      Result of current_user_can('read_private_posts', $id).
	 */
	private function configure_boundary( int $post_id, bool $can_archive, bool $can_view ): void {
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

		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
			->reply( array( 'publish', 'future', 'draft', 'pending', 'private' ) );

		// Anonymous mock user (id 0): the ownership-aware capability
		// defaults resolve to the others-primitive, and ViewCapability's
		// author fallback never engages.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts', $post_id )
			->andReturn( $can_archive );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'read_private_posts', $post_id )
			->andReturn( $can_view );

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
			->andReturnUsing( static fn( $arg, $value, $url ) => $url . '&' . $arg . '=' . $value );
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=abc' );
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => $url );
	}

	/**
	 * Coverage hole #1 (from the 0.4.0 refactor plan): publish status + cannot archive.
	 * The archive branch is gated on capability, so the actions array comes
	 * back unchanged — no `archive` entry, no `unarchive` entry, original
	 * `edit`/`view`/`inline` keys all preserved.
	 *
	 * @covers ArchivedPostStatus\Admin\RowActionPolicy::for_post
	 */
	public function test_for_post_omits_archive_entry_when_user_lacks_archive_cap_on_publish_post() {
		$post_id = 11;
		$this->configure_boundary( $post_id, false, false );

		$post = $this->createMockPost(
			array(
				'ID'          => $post_id,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$incoming = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<a>Quick Edit</a>',
			'view'                 => '<a>View</a>',
		);

		$result = RowActionPolicy::for_post( $post, $incoming );

		$this->assertArrayNotHasKey( 'archive', $result );
		$this->assertArrayNotHasKey( 'unarchive', $result );
		// Original keys must survive verbatim.
		$this->assertSame( $incoming, $result );
	}

	/**
	 * Coverage hole #2: archive status + cannot unarchive. The unarchive
	 * branch is gated on capability, so the actions array comes back
	 * unchanged — no `unarchive` entry, edit and view both preserved
	 * (the strip-edit behavior is also gated on can-unarchive).
	 *
	 * @covers ArchivedPostStatus\Admin\RowActionPolicy::for_post
	 */
	public function test_for_post_omits_unarchive_entry_when_user_lacks_unarchive_cap_on_archived_post() {
		$post_id = 12;
		$this->configure_boundary( $post_id, false, false );

		$post = $this->createMockPost(
			array(
				'ID'          => $post_id,
				'post_type'   => 'post',
				'post_status' => 'archive',
			)
		);

		$incoming = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<a>Quick Edit</a>',
			'view'                 => '<a>View</a>',
		);

		$result = RowActionPolicy::for_post( $post, $incoming );

		$this->assertArrayNotHasKey( 'unarchive', $result );
		$this->assertArrayNotHasKey( 'archive', $result );
		// edit / view / inline are NOT stripped when the unarchive branch
		// short-circuits — the strip is part of the unarchive-branch logic.
		$this->assertSame( $incoming, $result );
	}

	/**
	 * Coverage hole #3: archive status + can unarchive + cannot view.
	 * The unarchive branch runs and strips `edit` + `inline`. The extra
	 * branch under test: when the user CANNOT view archived content,
	 * `view` is also stripped from the row.
	 *
	 * @covers ArchivedPostStatus\Admin\RowActionPolicy::for_post
	 */
	public function test_for_post_strips_view_entry_when_user_can_unarchive_but_cannot_view() {
		$post_id = 13;
		$this->configure_boundary( $post_id, true, false );

		$post = $this->createMockPost(
			array(
				'ID'          => $post_id,
				'post_type'   => 'post',
				'post_status' => 'archive',
			)
		);

		$incoming = array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<a>Quick Edit</a>',
			'view'                 => '<a>View</a>',
			'trash'                => '<a>Trash</a>',
		);

		$result = RowActionPolicy::for_post( $post, $incoming );

		$this->assertArrayHasKey( 'unarchive', $result );
		$this->assertArrayNotHasKey( 'view', $result );
		$this->assertArrayNotHasKey( 'edit', $result );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $result );
		// Non-archive-related entries (trash) are untouched.
		$this->assertArrayHasKey( 'trash', $result );
	}

	/**
	 * Pin: unsupported post type → return $actions unchanged regardless of
	 * status / caps. Mirrors the same check in the parity snapshot.
	 *
	 * @covers ArchivedPostStatus\Admin\RowActionPolicy::for_post
	 */
	public function test_for_post_returns_actions_unchanged_for_unsupported_post_type() {
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'post' => 'post', 'attachment' => 'attachment' ) );
		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( static fn( $v ) => $v );
		// The default-exclusions list is only kept if the excluded slug
		// actually exists — see SupportedPostTypes::all().
		\WP_Mock::userFunction( 'post_type_exists' )
			->andReturn( true );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'post' => 'post' ) )
			->reply( array( 'post' ) );

		// Cap functions must never even be consulted — the post-type gate
		// is the early return.
		\WP_Mock::userFunction( 'current_user_can' )->never();

		$post = $this->createMockPost(
			array(
				'ID'          => 15,
				'post_type'   => 'attachment',
				'post_status' => 'publish',
			)
		);

		$incoming = array( 'edit' => '<a>Edit</a>' );

		$result = RowActionPolicy::for_post( $post, $incoming );

		$this->assertSame( $incoming, $result );
	}

	/**
	 * Ownership default: the post's own author needs only the post type's
	 * edit_posts primitive to see the Archive row action. Every test above
	 * routes through {@see configure_boundary()}, which stubs
	 * get_current_user_id() to the anonymous id 0 — that never equals a
	 * post_author, so none of them ever reach the ownership comparison in
	 * ArchiveCapability::default_capability(). This test sets post_author
	 * equal to the current user id and asserts the *primitive*
	 * current_user_can() receives, not merely that the archive entry
	 * appears — asserting only the outcome would still pass if the
	 * ownership branch were deleted and every post resolved to
	 * edit_others_posts.
	 *
	 * Uses a 'book' post type (edit_books / edit_others_books, mirroring
	 * ArchiveCapabilityTest::stubOwnershipBoundary()) so the assertion
	 * cannot pass by coincidence with configure_boundary()'s generic
	 * edit_others_posts string. Stubs the boundary inline rather than via
	 * configure_boundary() — that helper hardcodes the anonymous user and
	 * the 'post' type, both of which this scenario needs to override.
	 *
	 * @covers ArchivedPostStatus\Admin\RowActionPolicy::for_post
	 */
	public function test_for_post_consults_edit_posts_primitive_for_authors_own_post() {
		$post_id = 16;

		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array( 'book' => 'book' ) );
		\WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( array( 'book' => 'book' ) )
			->reply( array( 'book' ) );

		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
			->reply( array( 'publish', 'future', 'draft', 'pending', 'private' ) );

		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$post = $this->createMockPost(
			array(
				'ID'          => $post_id,
				'post_type'   => 'book',
				'post_status' => 'publish',
				'post_author' => 7,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( $post_id )->andReturn( $post );

		// Serves both consumers that call get_post_type_object('book'):
		// ArchiveCapability::default_capability() (needs ->cap) and
		// ArchivePostLink::build() (needs ->_edit_link) — for_post() calls
		// the archive capability function once directly and once more
		// inside aps_get_archive_post_link() while composing the href.
		$type_object      = (object) array(
			'_edit_link' => 'post.php?post=%d&action=edit',
			'cap'        => (object) array(
				'edit_posts'        => 'edit_books',
				'edit_others_posts' => 'edit_others_books',
			),
		);
		\WP_Mock::userFunction( 'get_post_type_object' )
			->with( 'book' )
			->andReturn( $type_object );

		$received_capability = null;
		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::userFunction( 'admin_url' )
			->andReturnUsing( static fn( $path ) => 'http://example.com/wp-admin/' . $path );
		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturnUsing( static fn( $arg, $value, $url ) => $url . '&' . $arg . '=' . $value );
		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=abc' );
		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( static fn( $url ) => $url );

		$incoming = array(
			'edit' => '<a>Edit</a>',
			'view' => '<a>View</a>',
		);

		$result = RowActionPolicy::for_post( $post, $incoming );

		$this->assertSame(
			'edit_books',
			$received_capability,
			"the post type's edit_posts primitive must be consulted for the author's own post"
		);
		$this->assertArrayHasKey( 'archive', $result );
	}
}
