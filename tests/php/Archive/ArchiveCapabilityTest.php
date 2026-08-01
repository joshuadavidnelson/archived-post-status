<?php
/**
 * Archive\ArchiveCapability Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchiveCapability
 *
 * Mirrors the facade tests in FunctionsTest
 * (`test_archive_capability_filter_replaces_capability_passed_to_current_user_can`,
 * `test_unarchive_capability_filter_replaces_capability_passed_to_current_user_can`,
 * `test_archive_capability_defaults_to_edit_others_posts_when_no_filter_registered`)
 * but exercises the lifted SUT directly. The two filters are decoupled — a
 * site may grant archive rights to one role and reserve unarchive for
 * another — so the test surface mirrors that split.
 */

use ArchivedPostStatus\Archive\ArchiveCapability;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ArchiveCapability
 */
class ArchiveCapabilityTest extends TestCase {

	/**
	 * Default-path archive: without any filter override, the SUT sends the
	 * default `'edit_others_posts'` through to `current_user_can()`.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_archive
	 */
	public function test_can_archive_defaults_to_edit_others_posts() {
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

		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_others_posts', 0 );

		$result = ArchiveCapability::can_archive();

		$this->assertTrue( $result );
		$this->assertSame( 'edit_others_posts', $received_capability );
	}

	/**
	 * Filter-override archive: `aps_default_archive_capability` replaces the
	 * cap that `current_user_can()` sees.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_archive
	 */
	public function test_can_archive_filter_replaces_capability() {
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

		\WP_Mock::onFilter( 'aps_default_archive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'manage_archives' );

		ArchiveCapability::can_archive();

		$this->assertSame( 'manage_archives', $received_capability );
	}

	/**
	 * Default-path unarchive: separate filter, same default capability. Two
	 * separate filter names is a deliberate design choice — sites may grant
	 * a role one direction but not the other.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_unarchive
	 */
	public function test_can_unarchive_defaults_to_edit_others_posts() {
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

		\WP_Mock::expectFilter( 'aps_default_unarchive_capability', 'edit_others_posts', 0 );

		$result = ArchiveCapability::can_unarchive();

		$this->assertTrue( $result );
		$this->assertSame( 'edit_others_posts', $received_capability );
	}

	/**
	 * Filter-override unarchive: `aps_default_unarchive_capability` replaces
	 * the cap that `current_user_can()` sees.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_unarchive
	 */
	public function test_can_unarchive_filter_replaces_capability() {
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

		\WP_Mock::onFilter( 'aps_default_unarchive_capability' )
			->with( 'edit_others_posts', 0 )
			->reply( 'manage_archives' );

		ArchiveCapability::can_unarchive();

		$this->assertSame( 'manage_archives', $received_capability );
	}

	/**
	 * Edge case: a non-zero post id passes through both filter and
	 * `current_user_can()` for archive checks. Covers the
	 * post-id-as-second-arg contract callers rely on.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_archive
	 */
	public function test_can_archive_passes_post_id_to_filter_and_current_user_can() {
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

		\WP_Mock::userFunction( 'get_post' )->with( 17 )->andReturn( null );

		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_others_posts', 17 );

		ArchiveCapability::can_archive( 17 );

		$this->assertSame( 17, $received_post_id );
	}

	/**
	 * Build a post + post-type-object boundary for the ownership-default
	 * tests, using CPT-flavored primitives to prove type awareness.
	 *
	 * @param int $author_id The post_author to record on the post.
	 */
	private function stubOwnershipBoundary( int $author_id ): void {
		$post = $this->createMockPost(
			array(
				'ID'          => 5,
				'post_type'   => 'book',
				'post_author' => $author_id,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );

		$type_object      = new \stdClass();
		$type_object->cap = (object) array(
			'edit_posts'        => 'edit_books',
			'edit_others_posts' => 'edit_others_books',
		);
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );
	}

	/**
	 * Ownership default: the post's own author only needs the post type's
	 * edit_posts primitive to archive it.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_archive
	 */
	public function test_can_archive_defaults_to_type_edit_posts_primitive_for_own_post() {
		$this->stubOwnershipBoundary( 7 );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$received_capability = null;
		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_books', 5 );

		$this->assertTrue( ArchiveCapability::can_archive( 5 ) );
		$this->assertSame( 'edit_books', $received_capability );
	}

	/**
	 * Ownership default: anyone who is not the post's author needs the post
	 * type's edit_others_posts primitive.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_archive
	 */
	public function test_can_archive_defaults_to_type_edit_others_posts_primitive_for_another_authors_post() {
		$this->stubOwnershipBoundary( 9 );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$received_capability = null;
		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return false;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_others_books', 5 );

		$this->assertFalse( ArchiveCapability::can_archive( 5 ) );
		$this->assertSame( 'edit_others_books', $received_capability );
	}

	/**
	 * Ownership default: an anonymous visitor (user id 0) never owns a post,
	 * so the stricter others-primitive is the default.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_archive
	 */
	public function test_can_archive_treats_anonymous_visitors_as_non_owners() {
		$this->stubOwnershipBoundary( 0 );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => false,
			)
		);

		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_others_books', 5 );

		$this->assertFalse( ArchiveCapability::can_archive( 5 ) );
	}

	/**
	 * Deactivated-CPT fallback: when a post's type has since been
	 * unregistered, get_post_type_object() returns null and the default
	 * capability resolves to the literal 'edit_posts' primitive for the
	 * post's own author.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_archive
	 */
	public function test_can_archive_falls_back_to_edit_posts_when_post_type_is_unregistered() {
		$post = $this->createMockPost(
			array(
				'ID'          => 5,
				'post_type'   => 'book',
				'post_author' => 7,
			)
		);
		\WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( null );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$received_capability = null;
		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_archive_capability', 'edit_posts', 5 );

		$this->assertTrue( ArchiveCapability::can_archive( 5 ) );
		$this->assertSame( 'edit_posts', $received_capability );
	}

	/**
	 * Ownership default applies to unarchive through its own filter.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchiveCapability::can_unarchive
	 */
	public function test_can_unarchive_defaults_to_type_edit_posts_primitive_for_own_post() {
		$this->stubOwnershipBoundary( 7 );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$received_capability = null;
		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_unarchive_capability', 'edit_books', 5 );

		$this->assertTrue( ArchiveCapability::can_unarchive( 5 ) );
		$this->assertSame( 'edit_books', $received_capability );
	}
}
