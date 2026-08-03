<?php
/**
 * E2E mu-plugin fixture: a custom post type with `map_meta_cap => false`.
 *
 * Companion to `aps-cpt.php`, which pins `map_meta_cap => true` deliberately —
 * see that file's docblock. This fixture is the shape that trap is guarding
 * against: WordPress defaults `map_meta_cap` to `false` for any
 * `capability_type` other than `post`/`page`, and when it's false core's
 * `map_meta_cap()` reassigns the `edit_post` meta cap to the type's own
 * singular primitive (`$post_type->cap->edit_post`, e.g. `edit_pamphlet`)
 * BEFORE firing the `map_meta_cap` filter, with no ownership mapping at all.
 * `PostEditorGuard::deny_editing_archived()` has to match that primitive, not
 * just the literal 'edit_post', or this shape of post type stays editable via
 * a direct edit URL while its row actions correctly hide Edit.
 *
 * NOT TOGGLE-GATED, for the same reason as `aps-cpt.php`: registering an
 * additional PUBLIC post type is additive-only. `SupportedPostTypes::all()`
 * can only ever add this type to the supported list, never reclassify `post`,
 * `page`, or `aps_book` — and no spec asserts an exact/exhaustive post-type
 * list, so there's nothing here to protect.
 *
 * `capability_type => 'pamphlet'`, distinct from `aps-cpt.php`'s `book`: a
 * different slug so the two fixtures can coexist without their primitives
 * colliding.
 *
 * Because `map_meta_cap` is false, core checks the SINGULAR primitives
 * (`edit_pamphlet` / `read_pamphlet` / `delete_pamphlet`) directly, with no
 * ownership distinction between an author's own post and anyone else's — the
 * legacy, pre-3.1 behaviour this fixture exists to exercise. The PLURAL
 * primitives (`edit_pamphlets` / `edit_others_pamphlets` / etc.) are also
 * granted so `ArchiveCapability` / `ViewCapability`'s `$post_type_object->cap->*`
 * resolution — which this plugin uses for archive/unarchive/view, deliberately
 * routed around `edit_post` — still has something to check against.
 *
 * None of these primitives exist on any stock role, so this fixture grants
 * each role what core's `populate_roles()` grants it for `post` (see
 * `$full_grant` / `$own_content_grant` below). Grants are additive, idempotent,
 * and under a namespace nothing else checks — they cannot change how a `post`,
 * `page`, or `aps_book` spec resolves its own primitives.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Slug of the fixture's custom post type.
 */
define( 'APS_TEST_LEGACY_CPT_SLUG', 'aps_pamphlet' );

add_action(
	'init',
	function () {
		register_post_type(
			APS_TEST_LEGACY_CPT_SLUG,
			array(
				// Minimal labels: nothing in the suite reads this type's admin
				// UI copy, only its query args and REST responses.
				'label'           => 'Pamphlets',
				'public'          => true,
				'show_in_rest'    => true,
				'rest_base'       => APS_TEST_LEGACY_CPT_SLUG,
				'supports'        => array( 'title', 'editor', 'author', 'comments', 'trackbacks', 'custom-fields' ),
				'capability_type' => 'pamphlet',
				'map_meta_cap'    => false,
			)
		);
	}
);

add_action(
	'init',
	function () {
		$full_grant = array(
			// Plural primitives: ArchiveCapability / ViewCapability's
			// ownership-aware $post_type_object->cap->* resolution.
			'edit_pamphlets',
			'edit_others_pamphlets',
			'edit_private_pamphlets',
			'edit_published_pamphlets',
			'publish_pamphlets',
			'read_private_pamphlets',
			'delete_pamphlets',
			'delete_private_pamphlets',
			'delete_published_pamphlets',
			'delete_others_pamphlets',
			// Singular primitives: what map_meta_cap() checks directly for
			// edit_post / read_post / delete_post when map_meta_cap is false,
			// with no ownership mapping.
			'edit_pamphlet',
			'read_pamphlet',
			'delete_pamphlet',
		);

		$own_content_grant = array(
			'edit_pamphlets',
			'edit_published_pamphlets',
			'publish_pamphlets',
			'delete_pamphlets',
			'delete_published_pamphlets',
			'edit_pamphlet',
			'read_pamphlet',
			'delete_pamphlet',
		);

		$grants = array(
			'administrator' => $full_grant,
			'editor'        => $full_grant,
			'author'        => $own_content_grant,
			// `subscriber` deliberately receives no grant: it holds none of the
			// `post`-equivalent primitives on a stock install either, so a
			// future spec iterating a post-type list over the deny path gets
			// the same answer for every type without extra fixture work.
		);

		foreach ( $grants as $role_slug => $caps ) {
			aps_test_legacy_cpt_grant_role( $role_slug, $caps );
		}
	}
);

/**
 * Grant a role a set of capabilities, skipping any it already holds.
 *
 * `WP_Role::add_cap()` calls `update_option( 'wp_user_roles', ... )`
 * unconditionally on every invocation; guarding with `has_cap()` first means a
 * write happens only for a capability a role doesn't already have. Once every
 * role holds its full grant, later requests write nothing at all, despite this
 * mu-plugin loading on every request.
 *
 * @param string   $role_slug Role to grant.
 * @param string[] $caps      Capabilities to add.
 */
function aps_test_legacy_cpt_grant_role( $role_slug, $caps ) {
	$role = get_role( $role_slug );

	if ( ! $role ) {
		return;
	}

	foreach ( $caps as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}
