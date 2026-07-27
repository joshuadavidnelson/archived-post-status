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
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
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
	 * Edge case: a user without archive capability gets false back. Mirrors
	 * the `aps_current_user_can_archive()` early-return in the procedural
	 * facade.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchivePostLink::build
	 */
	public function test_build_returns_false_when_user_cannot_archive() {
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
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );

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
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
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
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
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

	/**
	 * Capability dispatch: build() must consult the capability that matches
	 * the $action argument passed in, not a hardcoded archive check. Lets
	 * the real `aps_current_user_can_archive()` / `aps_current_user_can_unarchive()`
	 * facades and `ArchiveCapability` run for real, filters the two
	 * `aps_default_*_capability` hooks to two DIFFERENT capability strings,
	 * and reads back which capability `current_user_can()` actually
	 * received. Fails against a build() that always calls
	 * `aps_current_user_can_archive()` regardless of $action, because the
	 * Unarchive call would then observe the archive capability instead of
	 * the unarchive one.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchivePostLink::build
	 */
	public function test_build_consults_the_capability_matching_the_action() {
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
		// Ownership default resolution: anonymous → others-primitive; the
		// stubbed post type object has no cap map, so the string default
		// 'edit_others_posts' flows to the filters below.
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		\WP_Mock::onFilter( 'aps_default_archive_capability' )
			->with( 'edit_others_posts', 42 )
			->reply( 'aps_archive_only_cap' );
		\WP_Mock::onFilter( 'aps_default_unarchive_capability' )
			->with( 'edit_others_posts', 42 )
			->reply( 'aps_unarchive_only_cap' );

		$received_capability = null;
		\WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			static function ( $capability ) use ( &$received_capability ) {
				$received_capability = $capability;
				return true;
			}
		);

		ArchivePostLink::build( $post, ArchiveAction::Archive );
		$this->assertSame(
			'aps_archive_only_cap',
			$received_capability,
			'Archive action must consult the archive capability.'
		);

		$received_capability = null;
		ArchivePostLink::build( $post, ArchiveAction::Unarchive );
		$this->assertSame(
			'aps_unarchive_only_cap',
			$received_capability,
			'Unarchive action must consult the unarchive capability, not the archive one.'
		);
	}
}
