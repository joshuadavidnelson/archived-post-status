<?php
/**
 * Frontend\ArchivedPostLink Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink
 *
 * exercises the lifted body of
 * `aps_get_archived_post_link()` directly. The procedural facade stays in
 * place this step; Step 3B will rewire it as a one-line delegate.
 */

use ArchivedPostStatus\Frontend\ArchivedPostLink;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink
 */
class ArchivedPostLinkTest extends TestCase {

	/**
	 * Edge case: a non-existent post returns false (no get_permalink call).
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink::build
	 */
	public function test_build_returns_false_when_post_does_not_exist() {
		\WP_Mock::userFunction( 'get_post' )->andReturn( null );

		$this->assertFalse( ArchivedPostLink::build() );
	}

	/**
	 * Default-path: a publicly-viewable status (e.g. publish) takes the
	 * `get_permalink()` early return — there's no need for a preview-style
	 * URL when the post is already public.
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink::build
	 */
	public function test_build_returns_permalink_when_post_status_is_viewable() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_post_status_viewable' )->with( 'publish' )->andReturn( true );
		\WP_Mock::userFunction( 'get_permalink' )->with( $post )->andReturn( 'http://example.com/?p=42' );

		$result = ArchivedPostLink::build( $post );

		$this->assertSame( 'http://example.com/?p=42', $result );
	}

	/**
	 * Edge case: a non-viewable status on an unsupported post type returns
	 * false.
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink::build
	 */
	public function test_build_returns_false_when_post_type_is_unsupported() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'attachment',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_post_status_viewable' )->with( 'archive' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'attachment' )->andReturnUsing(
			static function () {
				$object = new stdClass();
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'attachment' )->andReturn( false );

		$this->assertFalse( ArchivedPostLink::build( $post ) );
	}

	/**
	 * Edge case: when the post type itself is publicly viewable (eg public
	 * post-type registration), the SUT returns false — the archive preview
	 * URL is only needed for *private* post types.
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink::build
	 */
	public function test_build_returns_false_when_post_type_is_publicly_viewable() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_post_status_viewable' )->with( 'archive' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'post' )->andReturnUsing(
			static function () {
				$object = new stdClass();
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'is_post_type_viewable' )->andReturn( true );

		$this->assertFalse( ArchivedPostLink::build( $post ) );
	}

	/**
	 * Default-path: a non-viewable status on a supported, non-publicly-
	 * viewable post type produces a preview URL with `preview=true` added
	 * to the query string. The `aps_archived_post_link` filter is applied
	 * before `esc_url()`.
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink::build
	 */
	public function test_build_produces_preview_url_for_archived_post_on_non_viewable_type() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_post_status_viewable' )->with( 'archive' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'post' )->andReturnUsing(
			static function () {
				$object = new stdClass();
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'is_post_type_viewable' )->andReturn( false );
		\WP_Mock::userFunction( 'get_permalink' )->andReturn( 'http://example.com/?p=42' );
		\WP_Mock::userFunction( 'set_url_scheme' )->andReturn( 'http://example.com/?p=42' );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/?p=42&preview=true' );

		\WP_Mock::expectFilter( 'aps_archived_post_link', 'http://example.com/?p=42&preview=true', $post );

		$result = ArchivedPostLink::build( $post );

		$this->assertSame( 'http://example.com/?p=42&preview=true', $result );
	}

	/**
	 * Filter-override path: the `aps_archived_post_link` filter can replace
	 * the URL entirely. Mirrors the contract sites rely on to route archive
	 * previews through a custom rewrite endpoint.
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink::build
	 */
	public function test_build_applies_aps_archived_post_link_filter_override() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_post_status_viewable' )->with( 'archive' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'post' )->andReturnUsing(
			static function () {
				$object = new stdClass();
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'is_post_type_viewable' )->andReturn( false );
		\WP_Mock::userFunction( 'get_permalink' )->andReturn( 'http://example.com/?p=42' );
		\WP_Mock::userFunction( 'set_url_scheme' )->andReturn( 'http://example.com/?p=42' );
		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/?p=42&preview=true' );

		\WP_Mock::onFilter( 'aps_archived_post_link' )
			->with( 'http://example.com/?p=42&preview=true', $post )
			->reply( 'http://example.com/custom-archive-route/42' );

		$result = ArchivedPostLink::build( $post );

		$this->assertSame( 'http://example.com/custom-archive-route/42', $result );
	}

	/**
	 * Edge case: a caller-supplied `$archived_link` short-circuits the
	 * `set_url_scheme( get_permalink( $post ) )` derivation — useful for
	 * sites that build the preview URL themselves.
	 *
	 * @covers ArchivedPostStatus\Frontend\ArchivedPostLink::build
	 */
	public function test_build_uses_caller_supplied_archived_link_when_provided() {
		$post = $this->createMockPost(
			array(
				'ID'          => 42,
				'post_status' => 'archive',
				'post_type'   => 'post',
			)
		);

		\WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		\WP_Mock::userFunction( 'is_post_status_viewable' )->with( 'archive' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'post' )->andReturnUsing(
			static function () {
				$object = new stdClass();
				return $object;
			}
		);
		\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
		\WP_Mock::userFunction( 'is_post_type_viewable' )->andReturn( false );

		// The SUT must NOT call set_url_scheme/get_permalink when a custom
		// base link is provided — pinning that the derivation branch is
		// skipped.
		\WP_Mock::userFunction( 'set_url_scheme' )->never();
		\WP_Mock::userFunction( 'get_permalink' )->never();

		\WP_Mock::userFunction( 'add_query_arg' )->andReturn( 'http://example.com/custom-base?preview=true' );

		\WP_Mock::expectFilter(
			'aps_archived_post_link',
			'http://example.com/custom-base?preview=true',
			$post
		);

		$result = ArchivedPostLink::build( $post, array(), 'http://example.com/custom-base' );

		$this->assertSame( 'http://example.com/custom-base?preview=true', $result );
	}
}
