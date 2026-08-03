<?php
/**
 * Archive\PostTypeCapabilityPrimitive Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive
 *
 * Direct unit coverage on the shared lookup extracted out of
 * ArchiveCapability::default_capability() and
 * ViewCapability::author_owns_and_can_edit() (byte-for-byte identical logic
 * in both), also now used by PostEditorGuard::deny_editing_archived(). The
 * three call sites' own tests already exercise this indirectly; this file
 * pins the extracted method's own contract directly.
 */

use ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive
 */
class PostTypeCapabilityPrimitiveTest extends TestCase {

	/**
	 * A registered post type with a full cap map resolves to its own
	 * primitive rather than the literal capability name.
	 *
	 * @covers ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive::resolve
	 */
	public function test_resolve_returns_the_post_types_own_primitive() {
		$type_object      = new \stdClass();
		$type_object->cap = (object) array(
			'edit_posts'        => 'edit_books',
			'edit_others_posts' => 'edit_others_books',
		);
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );

		$this->assertSame( 'edit_books', PostTypeCapabilityPrimitive::resolve( 'book', 'edit_posts' ) );
		$this->assertSame( 'edit_others_books', PostTypeCapabilityPrimitive::resolve( 'book', 'edit_others_posts' ) );
	}

	/**
	 * Deactivated-CPT fallback: get_post_type_object() returns null when the
	 * type is no longer registered — resolve() falls back to the literal
	 * capability name rather than fataling on a null-property access.
	 *
	 * @covers ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive::resolve
	 */
	public function test_resolve_falls_back_to_the_literal_when_type_is_unregistered() {
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( null );

		$this->assertSame( 'edit_posts', PostTypeCapabilityPrimitive::resolve( 'book', 'edit_posts' ) );
	}

	/**
	 * A type object may exist without a full cap map — the `??` fallback to
	 * the literal capability name must not fatal on the missing property.
	 *
	 * @covers ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive::resolve
	 */
	public function test_resolve_falls_back_to_the_literal_when_cap_map_is_incomplete() {
		$type_object      = new \stdClass();
		$type_object->cap = new \stdClass(); // No `edit_post` property.
		\WP_Mock::userFunction( 'get_post_type_object' )->with( 'book' )->andReturn( $type_object );

		$this->assertSame( 'edit_post', PostTypeCapabilityPrimitive::resolve( 'book', 'edit_post' ) );
	}
}
