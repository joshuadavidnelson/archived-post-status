<?php
/**
 * E2E mu-plugin fixture: deny archive/unarchive capability for ONE post id.
 *
 * TOGGLE: option `aps_test_denied_post_id` (integer, default 0 = off).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_denied_post_id": 123 }
 *   WP-CLI: wp option update aps_test_denied_post_id 123
 *           wp option update aps_test_denied_post_id 0
 *
 * Why a per-post denial rather than reusing `aps-cap-filter.php`: the bulk
 * handler runs a screen-level capability gate first
 * ({@see \ArchivedPostStatus\Admin\BulkActionHandler::handle()} calls the cap
 * function with no post id) and returns the sendback untouched when it fails.
 * A blanket cap filter therefore aborts the whole batch and the per-item
 * `denied` bucket is never reached. Both `aps_default_archive_capability` and
 * `aps_default_unarchive_capability` receive the post id as their second
 * argument, so keying on it lets the outer gate pass and exactly one item be
 * skipped — which is the state the bulk-action notices need to be pinned
 * against.
 *
 * Behaviour when enabled: for the flagged post id only, the required capability
 * becomes `aps_test_impossible_capability`, a capability no role holds (this is
 * a single-site install, so there is no super-admin bypass — administrators are
 * denied too, which keeps the spec on one identity).
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Post id whose archive/unarchive capability is denied, or 0 when off.
 *
 * @return int
 */
function aps_test_denied_post_id() {
	return (int) get_option( 'aps_test_denied_post_id', 0 );
}

/**
 * Swap in an unattainable capability for the flagged post.
 *
 * @param string|mixed $capability Capability resolved so far.
 * @param int|mixed    $post_id    Post the check is being made against.
 * @return string|mixed
 */
function aps_test_deny_capability_for_post( $capability, $post_id = 0 ) {
	$denied = aps_test_denied_post_id();

	if ( $denied && (int) $post_id === $denied ) {
		return 'aps_test_impossible_capability';
	}

	return $capability;
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_denied_post_id',
			array(
				'type'         => 'integer',
				'default'      => 0,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: deny archive/unarchive capability for this post id only.',
			)
		);
	}
);

add_filter( 'aps_default_archive_capability', 'aps_test_deny_capability_for_post', 10, 2 );
add_filter( 'aps_default_unarchive_capability', 'aps_test_deny_capability_for_post', 10, 2 );
