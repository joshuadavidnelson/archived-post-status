<?php
/**
 * Manual-test mu-plugin: capture all three args fired by aps_archived_post
 * and aps_unarchived_post into options for the E2E scenarios A9 / A10.
 *
 * Pins the 3-arg action signatures (post id, previous status, WP_Post)
 * established in the 0.4.0 contract pin. The captured shapes are read back
 * by the manual-test scenario for inspection.
 *
 * Drop into `/wp-content/mu-plugins/` for the duration of the test, then
 * remove. Preserved here so the test can be re-run. Clean up the option
 * keys with `wp option delete aps_test_archived_args aps_test_unarchived_args`
 * when done.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

add_action(
	'aps_archived_post',
	function ( $post_id, $previous_status, $post ) {
		update_option(
			'aps_test_archived_args',
			array(
				'post_id'          => $post_id,
				'post_id_type'     => gettype( $post_id ),
				'previous_status'  => $previous_status,
				'previous_type'    => gettype( $previous_status ),
				'post_class'       => is_object( $post ) ? get_class( $post ) : gettype( $post ),
				'post_status_attr' => is_object( $post ) ? $post->post_status : null,
				'post_id_attr'     => is_object( $post ) ? $post->ID : null,
			)
		);
	},
	10,
	3
);

add_action(
	'aps_unarchived_post',
	function ( $post_id, $previous_status, $post ) {
		update_option(
			'aps_test_unarchived_args',
			array(
				'post_id'          => $post_id,
				'post_id_type'     => gettype( $post_id ),
				'previous_status'  => $previous_status,
				'previous_type'    => gettype( $previous_status ),
				'post_class'       => is_object( $post ) ? get_class( $post ) : gettype( $post ),
				'post_status_attr' => is_object( $post ) ? $post->post_status : null,
				'post_id_attr'     => is_object( $post ) ? $post->ID : null,
			)
		);
	},
	10,
	3
);
