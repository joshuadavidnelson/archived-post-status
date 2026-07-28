<?php
/**
 * E2E mu-plugin fixture: a custom post type with its own capability primitives.
 *
 * NOT TOGGLE-GATED, unlike most fixtures here: an additional PUBLIC post type
 * is additive-only. `SupportedPostTypes::all()` can only ever add this type to
 * the supported list, never reclassify `post` or `page` — and no spec asserts
 * an exact/exhaustive post-type list, so there's nothing here to protect.
 *
 * `capability_type => 'book'`, not the default `post`: the default would
 * exercise the same `edit_posts` / `edit_others_posts` primitives `post` and
 * `page` already cover, proving nothing new about `ArchiveCapability` /
 * `ViewCapability`'s `$post_type_object->cap->*` resolution.
 *
 * `map_meta_cap` is explicitly `true`: WordPress defaults this to `true` only
 * for `capability_type` `post`/`page` and silently `false` otherwise. Drop it
 * and `edit_post` / `delete_post` degrade to a legacy passthrough instead of
 * real ownership-aware mapping — a trap for whoever "simplifies" this later.
 *
 * None of the ten `book` primitives WordPress derives exist on any stock
 * role, so this fixture grants each role what core's `populate_roles()`
 * grants it for `post` (see `$full_grant` / `$own_content_grant` below).
 * Grants are additive, idempotent, and under a namespace nothing else checks
 * — they cannot change how a `post` or `page` spec resolves `edit_posts`.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Slug of the fixture's custom post type.
 *
 * @see tests/e2e/config/seed.ts — the `book` entry of `POST_TYPES` duplicates
 * this literal; TypeScript cannot import a PHP constant, so keep the two in
 * sync by hand if this ever changes.
 */
define( 'APS_TEST_CPT_SLUG', 'aps_book' );

add_action(
	'init',
	function () {
		register_post_type(
			APS_TEST_CPT_SLUG,
			array(
				// Minimal labels: nothing in the suite reads this type's admin
				// UI copy, only its query args and REST responses.
				'label'           => 'Books',
				'public'          => true,
				'show_in_rest'    => true,
				'rest_base'       => APS_TEST_CPT_SLUG,
				'supports'        => array( 'title', 'editor', 'author', 'comments', 'trackbacks', 'custom-fields' ),
				'capability_type' => 'book',
				'map_meta_cap'    => true,
			)
		);
	}
);

add_action(
	'init',
	function () {
		$full_grant = array(
			'edit_books',
			'edit_others_books',
			'edit_private_books',
			'edit_published_books',
			'publish_books',
			'read_private_books',
			'delete_books',
			'delete_private_books',
			'delete_published_books',
			'delete_others_books',
		);

		$own_content_grant = array(
			'edit_books',
			'edit_published_books',
			'publish_books',
			'delete_books',
			'delete_published_books',
		);

		$grants = array(
			'administrator' => $full_grant,
			'editor'        => $full_grant,
			'author'        => $own_content_grant,
			// `subscriber` deliberately receives no grant: it holds none of the
			// `post`-equivalent primitives on a stock install either, so a
			// future spec iterating `POST_TYPES` over the deny path gets the
			// same answer for every type without extra fixture work.
		);

		foreach ( $grants as $role_slug => $caps ) {
			aps_test_cpt_grant_role( $role_slug, $caps );
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
function aps_test_cpt_grant_role( $role_slug, $caps ) {
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
