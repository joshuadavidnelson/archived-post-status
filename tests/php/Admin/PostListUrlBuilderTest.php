<?php
/**
 * Admin\PostListUrlBuilder tests.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\PostListUrlBuilder
 *
 * the 0.4.0 refactor centralized the edit.php + optional `post_type`
 * query-arg construction (previously duplicated in `PostEditorGuard` and
 * `BulkActionHandler`) into a static helper. These tests pin the four
 * observable cases: default post type, non-default post type, the
 * self_admin / admin URL fork, and the empty-string boundary.
 */

use ArchivedPostStatus\Admin\PostListUrlBuilder;

/**
 * PostListUrlBuilder test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\PostListUrlBuilder
 */
class PostListUrlBuilderTest extends TestCase {

	/**
	 * Default `post` post type returns admin_url('edit.php') with no
	 * `post_type` query arg — because edit.php already lists posts.
	 *
	 * @covers ArchivedPostStatus\Admin\PostListUrlBuilder::for_post_type
	 */
	public function test_for_post_type_returns_admin_url_without_post_type_arg_for_default_post_type() {
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php' )
			->andReturn( 'https://example.com/wp-admin/edit.php' );

		$url = PostListUrlBuilder::for_post_type( 'post' );

		$this->assertSame( 'https://example.com/wp-admin/edit.php', $url );
	}

	/**
	 * Non-default post type returns admin_url('edit.php') with a
	 * `post_type={type}` query arg appended.
	 *
	 * @covers ArchivedPostStatus\Admin\PostListUrlBuilder::for_post_type
	 */
	public function test_for_post_type_appends_post_type_query_arg_for_non_default_type() {
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php' )
			->andReturn( 'https://example.com/wp-admin/edit.php' );
		\WP_Mock::userFunction( 'add_query_arg' )
			->with( 'post_type', 'page', 'https://example.com/wp-admin/edit.php' )
			->andReturn( 'https://example.com/wp-admin/edit.php?post_type=page' );

		$url = PostListUrlBuilder::for_post_type( 'page' );

		$this->assertSame( 'https://example.com/wp-admin/edit.php?post_type=page', $url );
	}

	/**
	 * When $self_admin is true, the builder routes through self_admin_url
	 * instead of admin_url. PostEditorGuard uses this form so the
	 * post-save redirect stays on the current blog (multisite).
	 *
	 * @covers ArchivedPostStatus\Admin\PostListUrlBuilder::for_post_type
	 */
	public function test_for_post_type_uses_self_admin_url_when_self_admin_true() {
		\WP_Mock::userFunction( 'self_admin_url' )
			->with( 'edit.php' )
			->andReturn( 'https://blog42.example.com/wp-admin/edit.php' );

		$url = PostListUrlBuilder::for_post_type( 'post', true );

		$this->assertSame( 'https://blog42.example.com/wp-admin/edit.php', $url );
	}

	/**
	 * Empty string post type is treated the same as 'post' (no
	 * `post_type` query arg). Pinned as a boundary so a caller that
	 * passes through a falsy post_type doesn't accidentally produce
	 * `?post_type=` (which WP would parse as an empty filter).
	 *
	 * @covers ArchivedPostStatus\Admin\PostListUrlBuilder::for_post_type
	 */
	public function test_for_post_type_omits_query_arg_for_empty_post_type_string() {
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'edit.php' )
			->andReturn( 'https://example.com/wp-admin/edit.php' );

		$url = PostListUrlBuilder::for_post_type( '' );

		$this->assertSame( 'https://example.com/wp-admin/edit.php', $url );
	}
}
