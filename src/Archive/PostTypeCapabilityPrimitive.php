<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves a post type's own capability primitive, falling back to the
 * literal capability name when the type is unregistered or its cap map is
 * incomplete.
 *
 * Shared by {@see ArchiveCapability::default_capability()},
 * {@see ViewCapability::author_owns_and_can_edit()}, and
 * {@see \ArchivedPostStatus\Admin\PostEditorGuard::deny_editing_archived()} —
 * all three need this exact lookup (get_post_type_object()->cap->{name},
 * falling back to the literal {name}) for a different capability name
 * ('edit_posts', 'edit_others_posts', and 'edit_post' respectively). The
 * mechanics — and the reason for the fallback — are identical regardless of
 * which capability is being resolved or what the caller does with the
 * result, so one method serves all three instead of three near-identical
 * private copies.
 *
 * @since 0.4.0
 */
final class PostTypeCapabilityPrimitive {

	/**
	 * Resolve a post type's own primitive for the given capability name.
	 *
	 * A post row can outlive its post type's registration (e.g. a
	 * deactivated CPT plugin) — get_post_type_object() then returns null,
	 * and this falls back to the literal $primitive rather than fataling on
	 * a null-property access. The `??` also tolerates a type object whose
	 * cap map doesn't define $primitive.
	 *
	 * @since 0.4.0
	 * @param string $post_type Post type slug.
	 * @param string $primitive The generic capability name to resolve
	 *                          (e.g. 'edit_posts', 'edit_others_posts', 'edit_post').
	 * @return string
	 */
	public static function resolve( string $post_type, string $primitive ): string {
		$type_object = get_post_type_object( $post_type );

		return $type_object ? ( $type_object->cap->$primitive ?? $primitive ) : $primitive;
	}
}
