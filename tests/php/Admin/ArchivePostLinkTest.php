<?php
/**
 * Admin\ArchivePostLink Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ArchivePostLink
 *
 * Mirrors the previously implicit coverage of
 * `aps_get_archive_post_link()` / `aps_get_unarchive_post_link()`. The
 * procedural facades stay in place this step; Step 3B will rewire them as
 * one-line delegates that wrap the bare string `'archive'` / `'unarchive'`
 * into the `ArchiveAction` enum.
 */

use ArchivedPostStatus\Admin\ArchivePostLink;
use ArchivedPostStatus\Archive\ArchiveAction;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\ArchivePostLink
 */
class ArchivePostLinkTest extends TestCase {

	/**
	 * Default-path: a supported post type + can-archive user produces a
	 * nonced admin URL. The `aps_get_archive_post_link` filter is applied
	 * to the nonced URL before `esc_url()`.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchivePostLink::build
	 */
	public function test_build_returns_nonced_admin_url_for_supported_post_and_capable_user() {
		$post = $this->createMockPost(
			array(
				'ID'        => 42,
				'post_type' => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'post' )->andReturnUsing(
			static function () {
				$object             = new stdClass();
				$object->_edit_link = 'post.php?post=%d&action=edit';
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'admin_url' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=edit' );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=archive' );
		\WP_Mock::userFunction( 'wp_nonce_url' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=archive&_wpnonce=abc' );

		\WP_Mock::expectFilter(
			'aps_get_archive_post_link',
			'http://example.com/wp-admin/post.php?post=42&action=archive&_wpnonce=abc',
			42,
			'display'
		);

		$result = ArchivePostLink::build( $post, ArchiveAction::Archive );

		$this->assertSame(
			'http://example.com/wp-admin/post.php?post=42&action=archive&_wpnonce=abc',
			$result
		);
	}

	/**
	 * Edge case: `get_post()` returns null (e.g. invalid id), the SUT
	 * returns false.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchivePostLink::build
	 */
	public function test_build_returns_false_when_post_does_not_exist() {
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );

		$this->assertFalse( ArchivePostLink::build( 0, ArchiveAction::Archive ) );
	}

	/**
	 * Edge case: an unsupported post type returns false.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchivePostLink::build
	 */
	public function test_build_returns_false_for_unsupported_post_type() {
		$post = $this->createMockPost(
			array(
				'ID'        => 42,
				'post_type' => 'attachment',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'attachment' )->andReturnUsing(
			static function () {
				$object             = new stdClass();
				$object->_edit_link = 'post.php?post=%d&action=edit';
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'attachment' )->andReturn( false );

		$this->assertFalse( ArchivePostLink::build( $post, ArchiveAction::Archive ) );
	}

	/**
	 * Action-dispatch: passing `ArchiveAction::Unarchive` builds the
	 * unarchive link (and applies the `aps_get_unarchive_post_link` filter,
	 * not the archive one). Pin the enum-driven dispatch.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchivePostLink::build
	 */
	public function test_build_dispatches_to_unarchive_filter_when_action_is_unarchive() {
		$post = $this->createMockPost(
			array(
				'ID'        => 42,
				'post_type' => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'post' )->andReturnUsing(
			static function () {
				$object             = new stdClass();
				$object->_edit_link = 'post.php?post=%d&action=edit';
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'admin_url' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=edit' );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=unarchive' );
		\WP_Mock::userFunction( 'wp_nonce_url' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=unarchive&_wpnonce=def' );

		\WP_Mock::expectFilter(
			'aps_get_unarchive_post_link',
			'http://example.com/wp-admin/post.php?post=42&action=unarchive&_wpnonce=def',
			42,
			'display'
		);

		$result = ArchivePostLink::build( $post, ArchiveAction::Unarchive );

		$this->assertSame(
			'http://example.com/wp-admin/post.php?post=42&action=unarchive&_wpnonce=def',
			$result
		);
	}

	/**
	 * Nonce-key contract: the SUT routes through `ArchiveAction::nonce_key()`,
	 * which produces `'archive-42'` / `'unarchive-42'`. Capture the value
	 * `wp_nonce_url` receives to pin the contract — this is what
	 * `check_admin_referer()` on the receive side will validate against.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchivePostLink::build
	 */
	public function test_build_passes_action_nonce_key_to_wp_nonce_url() {
		$post = $this->createMockPost(
			array(
				'ID'        => 42,
				'post_type' => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'post' )->andReturnUsing(
			static function () {
				$object             = new stdClass();
				$object->_edit_link = 'post.php?post=%d&action=edit';
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'admin_url' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=edit' );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/wp-admin/post.php?post=42&action=archive' );

		$captured_key = null;
		\WP_Mock::userFunction( 'wp_nonce_url' )->andReturnUsing(
			static function ( $url, $key ) use ( &$captured_key ) {
				$captured_key = $key;
				return $url . '&_wpnonce=abc';
			}
		);

		ArchivePostLink::build( $post, ArchiveAction::Archive );

		$this->assertSame( 'archive-42', $captured_key );
	}
}
