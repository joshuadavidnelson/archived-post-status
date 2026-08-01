<?php
/**
 * Archive\ViewCapability Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ViewCapability
 *
 * Mirrors the facade test
 * `FunctionsTest::test_aps_current_user_can_view_filter` but exercises the
 * lifted SUT directly. The two complementary tests below cover the default
 * capability (`read_private_posts` flows to current_user_can()) and the
 * filter override path.
 */

use ArchivedPostStatus\Archive\ViewCapability;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ViewCapability
 */
class ViewCapabilityTest extends TestCase {

	/**
	 * Default-path: without any filter, the SUT forwards the default
	 * `'read_private_posts'` capability to `current_user_can()`.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_default_capability_is_read_private_posts() {
		$received_capability = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability, $post_id ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_read_capability', 'read_private_posts', 0 );

		$result = ViewCapability::granted();

		$this->assertTrue( $result );
		$this->assertSame( 'read_private_posts', $received_capability );
	}

	/**
	 * Filter-override path: the `aps_default_read_capability` filter mutates
	 * the capability passed to `current_user_can()` — the public extension
	 * point sites use to widen / narrow the read gate.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_filter_replaces_capability_passed_to_current_user_can() {
		$received_capability = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability, $post_id ) use ( &$received_capability ) {
					$received_capability = $capability;
					return $capability === 'read_private_posts';
				},
			)
		);

		\WP_Mock::onFilter( 'aps_default_read_capability' )
			->with( 'read_private_posts', 0 )
			->reply( 'read' );

		$result = ViewCapability::granted();

		$this->assertFalse( $result );
		$this->assertSame( 'read', $received_capability );
	}

	/**
	 * Edge case: a non-zero post id passes through to both the filter and
	 * `current_user_can()`, matching the procedural facade's contract.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_passes_post_id_to_filter_and_current_user_can() {
		$received_post_id = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability, $post_id ) use ( &$received_post_id ) {
					$received_post_id = $post_id;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_read_capability', 'read_private_posts', 42 );

		ViewCapability::granted( 42 );

		$this->assertSame( 42, $received_post_id );
	}

	/**
	 * Ownership fallback: when the filtered read capability is denied, the
	 * post's own author is still granted view access via the post type's
	 * edit_posts primitive.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_falls_back_to_ownership_when_filtered_capability_denied() {
		$post = $this->createMockPost(
			array(
				'ID'          => 5,
				'post_type'   => 'book',
				'post_author' => 7,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$type_object      = new \stdClass();
		$type_object->cap = (object) array( 'edit_posts' => 'edit_books' );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );

		\WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			static function ( $capability ) {
				return 'edit_books' === $capability; // read_private_posts denied, primitive granted.
			}
		);

		$this->assertTrue( ViewCapability::granted( 5 ) );
	}

	/**
	 * Ownership fallback denies non-owners: without the filtered read
	 * capability, another author's archived post stays hidden.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_denies_non_owner_without_filtered_capability() {
		$post = $this->createMockPost(
			array(
				'ID'          => 5,
				'post_type'   => 'book',
				'post_author' => 9,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type_object' )->never();

		\WP_Mock::userFunction( 'current_user_can' )->andReturn( false );

		$this->assertFalse( ViewCapability::granted( 5 ) );
	}

	/**
	 * No post context, no ownership fallback: the filtered capability is
	 * the only path.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_has_no_ownership_fallback_without_post_context() {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( false );
		\WP_Mock::userFunction( 'get_post' )->never();

		$this->assertFalse( ViewCapability::granted() );
	}

	/**
	 * Ownership fallback edge case: an anonymous visitor (user id 0) never
	 * owns an authorless post (post_author 0) — the `! $user_id` guard
	 * short-circuits before the author comparison would otherwise treat
	 * "no current user" and "no post author" as a match.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_treats_anonymous_visitor_as_non_owner_of_authorless_post() {
		$post = $this->createMockPost(
			array(
				'ID'          => 5,
				'post_type'   => 'book',
				'post_author' => 0,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_type_object' )->never();

		\WP_Mock::userFunction( 'current_user_can' )->andReturn( false );

		$this->assertFalse( ViewCapability::granted( 5 ) );
	}

	/**
	 * Deactivated-CPT fallback: when a post's type has since been
	 * unregistered, get_post_type_object() returns null and the ownership
	 * fallback grants access via the literal 'edit_posts' primitive.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_falls_back_to_edit_posts_when_post_type_is_unregistered() {
		$post = $this->createMockPost(
			array(
				'ID'          => 5,
				'post_type'   => 'book',
				'post_author' => 7,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( null );

		\WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			static function ( $capability ) {
				return 'edit_posts' === $capability; // read_private_posts denied, literal fallback granted.
			}
		);

		$this->assertTrue( ViewCapability::granted( 5 ) );
	}
}
