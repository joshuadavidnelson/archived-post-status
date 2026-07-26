<?php
/**
 * E2E mu-plugin fixture: capture the args fired by the archive/unarchive actions.
 *
 * TOGGLE: option `aps_test_hooks_enabled` (boolean, default OFF).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_hooks_enabled": true }
 *   WP-CLI: wp option update aps_test_hooks_enabled 1
 *           wp option update aps_test_hooks_enabled 0
 *
 * This file is mapped into `wp-content/mu-plugins/` by `.wp-env.json`, so it
 * loads on EVERY request in the test environment. The listeners are therefore
 * registered unconditionally but write nothing until the option is switched on,
 * so specs that do not opt in never see the capture options appear.
 *
 * Behaviour when enabled: the three args of `aps_archived_post` and
 * `aps_unarchived_post` (post id, previous status, WP_Post) are recorded into
 * the `aps_test_archived_args` / `aps_test_unarchived_args` options, pinning the
 * 3-arg action signatures established by the 0.4.0 contract. Specs read the
 * captured shapes back over REST or WP-CLI and are responsible for clearing
 * them:
 *
 *   wp option delete aps_test_archived_args aps_test_unarchived_args
 *
 * Ported from `tests/manual/fixtures/aps-test-hooks.php` (E2E scenarios
 * A9/A10); the original was retired once this port landed.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Whether the hook-capture fixture is switched on.
 *
 * @return bool
 */
function aps_test_hooks_enabled() {
	return (bool) get_option( 'aps_test_hooks_enabled', false );
}

/**
 * Describe the args of an archive/unarchive action for later inspection.
 *
 * @param int|mixed      $post_id         Post ID passed to the action.
 * @param string|mixed   $previous_status Status the post held before the change.
 * @param \WP_Post|mixed $post            Post object passed to the action.
 * @return array<string, mixed>
 */
function aps_test_hooks_describe_args( $post_id, $previous_status, $post ) {
	return array(
		'post_id'          => $post_id,
		'post_id_type'     => gettype( $post_id ),
		'previous_status'  => $previous_status,
		'previous_type'    => gettype( $previous_status ),
		'post_class'       => is_object( $post ) ? get_class( $post ) : gettype( $post ),
		'post_status_attr' => is_object( $post ) ? $post->post_status : null,
		'post_id_attr'     => is_object( $post ) ? $post->ID : null,
	);
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_hooks_enabled',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: capture aps_archived_post / aps_unarchived_post args into options.',
			)
		);
	}
);

add_action(
	'aps_archived_post',
	function ( $post_id, $previous_status, $post ) {
		if ( ! aps_test_hooks_enabled() ) {
			return;
		}

		update_option( 'aps_test_archived_args', aps_test_hooks_describe_args( $post_id, $previous_status, $post ) );
	},
	10,
	3
);

add_action(
	'aps_unarchived_post',
	function ( $post_id, $previous_status, $post ) {
		if ( ! aps_test_hooks_enabled() ) {
			return;
		}

		update_option( 'aps_test_unarchived_args', aps_test_hooks_describe_args( $post_id, $previous_status, $post ) );
	},
	10,
	3
);
