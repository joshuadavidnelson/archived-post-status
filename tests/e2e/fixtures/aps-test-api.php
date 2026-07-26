<?php
/**
 * E2E mu-plugin fixture: seeding + inspection endpoints for the Playwright suite.
 *
 * NAMESPACE: `aps-test/v1`. Every route declares
 * `permission_callback => current_user_can( 'manage_options' )`, so the surface is
 * unreachable to anonymous or low-privileged requests.
 *
 * NOT TOGGLE-GATED, unlike the other fixtures in this directory, and deliberately
 * so: the toggles exist because those fixtures hook plugin filters and would
 * therefore change behaviour on every request in the environment. These routes
 * register nothing on the plugin's own hooks — they are inert until an
 * authenticated administrator calls them — so a toggle would add per-spec
 * bookkeeping without removing any risk.
 *
 * Why routes instead of `wp-env run tests-cli` for every seed: a WP-CLI round trip
 * costs ~1.2s of Docker/Node startup, which a twelve-spec suite pays hundreds of
 * times over. These endpoints call the same public API functions the CLI command
 * calls (`aps_archive_post()` / `aps_unarchive_post()`), so the seeded state is
 * produced by the real code path, not by a raw status write. Specs that need
 * genuine CLI semantics (anonymous archiving with no current user, so
 * `ArchiveMeta::from_post()` records user 0) still shell out to WP-CLI.
 *
 * Routes:
 *   POST aps-test/v1/archive/<id>          -> aps_archive_post()
 *   POST aps-test/v1/unarchive/<id>        -> aps_unarchive_post()
 *   GET  aps-test/v1/post/<id>             -> status + comment/ping status + archive meta
 *   POST aps-test/v1/post/<id>/raw-status  -> bare wp_update_post(), bypassing the plugin API
 *   POST aps-test/v1/post/<id>/lock        -> write `_edit_lock` for another user
 *   POST aps-test/v1/post/<id>/archive-meta-> overwrite archive date/user meta
 *   POST aps-test/v1/settings              -> external update_option( 'aps_settings' ) write
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Shared permission callback: administrators only.
 *
 * @return bool
 */
function aps_test_api_can_manage() {
	return current_user_can( 'manage_options' );
}

/**
 * Describe a post's archive-relevant state.
 *
 * Returned by every mutating route as well as the read route so a spec can
 * assert on the outcome without a second request.
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed>|WP_Error
 */
function aps_test_api_describe_post( $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'aps_test_no_post', "No post {$post_id}.", array( 'status' => 404 ) );
	}

	return array(
		'id'             => $post->ID,
		'post_status'    => $post->post_status,
		'comment_status' => $post->comment_status,
		'ping_status'    => $post->ping_status,
		'post_title'     => $post->post_title,
		'link'           => get_permalink( $post ),
		'meta'           => array(
			'previous_status' => get_post_meta( $post_id, '_aps_archive_meta_status', true ),
			'archive_date'    => (int) get_post_meta( $post_id, '_aps_archive_meta_time', true ),
			'archive_user'    => (int) get_post_meta( $post_id, '_aps_archive_meta_user', true ),
			'comment_status'  => get_post_meta( $post_id, '_aps_archive_meta_comment_status', true ),
			'ping_status'     => get_post_meta( $post_id, '_aps_archive_meta_ping_status', true ),
		),
	);
}

add_action(
	'rest_api_init',
	function () {
		$id_args = array(
			'id' => array(
				'required'          => true,
				'validate_callback' => static function ( $value ) {
					return is_numeric( $value );
				},
			),
		);

		// Archive through the plugin's public API.
		register_rest_route(
			'aps-test/v1',
			'/archive/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'aps_test_api_can_manage',
				'args'                => $id_args,
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = (int) $request['id'];
					$result  = aps_archive_post( $post_id );

					if ( ! $result ) {
						return new WP_Error(
							'aps_test_archive_failed',
							"aps_archive_post() returned falsey for {$post_id}.",
							array( 'status' => 409 )
						);
					}

					return rest_ensure_response( aps_test_api_describe_post( $post_id ) );
				},
			)
		);

		// Unarchive through the plugin's public API.
		register_rest_route(
			'aps-test/v1',
			'/unarchive/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'aps_test_api_can_manage',
				'args'                => $id_args,
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = (int) $request['id'];
					$result  = aps_unarchive_post( $post_id );

					if ( ! $result ) {
						return new WP_Error(
							'aps_test_unarchive_failed',
							"aps_unarchive_post() returned falsey for {$post_id}.",
							array( 'status' => 409 )
						);
					}

					return rest_ensure_response( aps_test_api_describe_post( $post_id ) );
				},
			)
		);

		// Read archive-relevant state.
		register_rest_route(
			'aps-test/v1',
			'/post/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'aps_test_api_can_manage',
				'args'                => $id_args,
				'callback'            => static function ( WP_REST_Request $request ) {
					return rest_ensure_response( aps_test_api_describe_post( (int) $request['id'] ) );
				},
			)
		);

		/*
		 * Bare wp_update_post() — the "somebody set post_status directly"
		 * path that PostStatusGuard exists to correct. Deliberately does NOT
		 * call aps_archive_post().
		 */
		register_rest_route(
			'aps-test/v1',
			'/post/(?P<id>\d+)/raw-status',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'aps_test_api_can_manage',
				'args'                => $id_args,
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = (int) $request['id'];

					wp_update_post(
						array(
							'ID'             => $post_id,
							'post_status'    => (string) $request->get_param( 'post_status' ),
							'comment_status' => (string) $request->get_param( 'comment_status' ),
							'ping_status'    => (string) $request->get_param( 'ping_status' ),
						)
					);

					clean_post_cache( $post_id );

					return rest_ensure_response( aps_test_api_describe_post( $post_id ) );
				},
			)
		);

		/*
		 * Write `_edit_lock` on behalf of another user so a spec can exercise
		 * the bulk-action "locked" skip bucket. The meta key is protected, so
		 * the core posts controller cannot set it.
		 */
		register_rest_route(
			'aps-test/v1',
			'/post/(?P<id>\d+)/lock',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'aps_test_api_can_manage',
				'args'                => $id_args,
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = (int) $request['id'];
					$user_id = (int) $request->get_param( 'user_id' );

					if ( $user_id ) {
						update_post_meta( $post_id, '_edit_lock', time() . ':' . $user_id );
					} else {
						delete_post_meta( $post_id, '_edit_lock' );
					}

					return rest_ensure_response(
						array(
							'id'         => $post_id,
							'_edit_lock' => get_post_meta( $post_id, '_edit_lock', true ),
						)
					);
				},
			)
		);

		/*
		 * Overwrite the archive date/user meta. ArchiveMeta::from_post() stamps
		 * time(), so two posts archived inside the same second tie — which would
		 * make a sort assertion non-deterministic. Specs set explicit
		 * timestamps instead of sleeping.
		 */
		register_rest_route(
			'aps-test/v1',
			'/post/(?P<id>\d+)/archive-meta',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'aps_test_api_can_manage',
				'args'                => $id_args,
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = (int) $request['id'];

					if ( null !== $request->get_param( 'archive_date' ) ) {
						update_post_meta( $post_id, '_aps_archive_meta_time', (int) $request->get_param( 'archive_date' ) );
					}

					if ( null !== $request->get_param( 'archive_user' ) ) {
						update_post_meta( $post_id, '_aps_archive_meta_user', (int) $request->get_param( 'archive_user' ) );
					}

					return rest_ensure_response( aps_test_api_describe_post( $post_id ) );
				},
			)
		);

		/*
		 * External write of the plugin's settings option. Uses update_option()
		 * / delete_option() directly rather than Settings\Store so the spec
		 * exercises the cache-invalidation hooks wired in Settings\HookAdapter
		 * (the path an unaware third party would take), not Store's own
		 * cache priming.
		 */
		register_rest_route(
			'aps-test/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'aps_test_api_can_manage',
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( $request->get_param( 'reset' ) ) {
						delete_option( 'aps_settings' );
					} else {
						update_option(
							'aps_settings',
							array( 'is_read_only' => (bool) $request->get_param( 'is_read_only' ) )
						);
					}

					return rest_ensure_response(
						array(
							'aps_settings'  => get_option( 'aps_settings', array() ),
							'is_read_only' => aps_is_read_only(),
						)
					);
				},
			)
		);
	}
);
