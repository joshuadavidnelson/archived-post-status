<?php
/**
 * REST API read-permission parity for archived content.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Status\PostStatus
 * @covers ArchivedPostStatus\Frontend\AccessGuard
 *
 * `Frontend\AccessGuard` enforces the view capability for archived content
 * on `template_redirect`, but registers no equivalent for the WordPress
 * REST API. That is deliberate, not an oversight: WordPress core's own REST
 * permission check already enforces the same policy with zero plugin-side
 * wiring, PROVIDED the archived status is registered the way
 * `Status\PostStatus::status_args()` produces it on a non-admin (REST)
 * request. Verified by reading WordPress 6.9.4 core source directly:
 *
 *   - `status_args()` sets `private => ! is_admin()`, which is `true` for a
 *     REST request. That makes
 *     `WP_REST_Posts_Controller::check_read_permission()`
 *     (wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php,
 *     ~line 1749) fall through to `current_user_can( 'read_post', $post->ID )`
 *     for anything other than the post's own author. Core's `map_meta_cap()`
 *     (wp-includes/capabilities.php, the `read_post`/`read_page` case,
 *     ~line 369-380) requires the `read_private_posts` primitive whenever
 *     the resolved post-status object has `private = true` — the same
 *     capability `aps_current_user_can_view()` consults by default (see
 *     `Archive\ViewCapability::granted()`). A logged-out visitor holds
 *     neither, so a single-item REST read (`GET /wp/v2/posts/<id>`) and a
 *     per-item collection read both deny exactly as `AccessGuard` does on
 *     the front end.
 *   - `status_args()` sets `public => ! is_admin() && aps_current_user_can_view()`.
 *     When a site adopts the documented escape-hatch recipe
 *     (`aps_status_arg_public` filtered true, `aps_status_arg_private`
 *     filtered false) — the same recipe `AccessGuard::enforce_access()`
 *     stands down on — `check_read_permission()` grants access immediately
 *     by reading `$post_status_obj->public` directly. No REST-side hook is
 *     needed to honor that escape hatch; core already reads the same flag.
 *   - Collection queries cannot leak archived content either way: the
 *     default `status=publish` listing never matches the archived status at
 *     the `WP_Query` level, and a non-default `status=archive` request must
 *     first pass `WP_REST_Posts_Controller::sanitize_post_statuses()`
 *     (requires `edit_posts`), after which every item is still individually
 *     re-gated by `check_read_permission()` above.
 *
 * This suite runs under WP_Mock with no real WordPress core loaded, so it
 * cannot execute `check_read_permission()` or `map_meta_cap()` themselves.
 * It pins the one thing this plugin actually controls: the args handed to
 * `register_post_status()`. If a future change to `PostStatus::status_args()`
 * ever stops setting `private => true` on a non-admin request, or decouples
 * `public` from `aps_current_user_can_view()`, this test fails — flagging
 * that the REST parity documented on `AccessGuard` may no longer hold.
 */

/**
 * RestReadParity test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Status\PostStatus
 */
class RestReadParityTest extends TestCase {

	/**
	 * Register the status and capture the args actually handed to
	 * `register_post_status()`.
	 *
	 * @param bool $can_view Mocked return for aps_current_user_can_view().
	 * @return array<string, mixed>
	 */
	private function capturedStatusArgs( bool $can_view ): array {
		// A REST request is never wp-admin — is_admin() is false, exactly
		// like a front-end request.
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_view' )->andReturn( $can_view );
		\WP_Mock::userFunction( 'aps_archived_label_string' )->andReturn( 'Archived' );
		\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( array( 'post' ) );
		\WP_Mock::userFunction( '_n_noop' )->andReturn( array() );

		$captured_args = null;
		\WP_Mock::userFunction( 'register_post_status' )
			->once()
			->andReturnUsing(
				static function ( $slug, $args ) use ( &$captured_args ) {
					$captured_args = $args;
				}
			);

		( new ArchivedPostStatus\Status\PostStatus() )->register_status();

		return $captured_args;
	}

	/**
	 * A REST request from a visitor who cannot view archived content
	 * registers the status `private => true`. That is the exact input
	 * WP core's `map_meta_cap()` requires `read_private_posts` for, which
	 * such a visitor lacks — denying the REST read the same way
	 * `AccessGuard::enforce_access()` denies the front end.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_archived_status_registers_private_true_on_rest_request_when_user_cannot_view() {
		$args = $this->capturedStatusArgs( false );

		$this->assertTrue( $args['private'], 'private must be true off the admin side so core requires read_private_posts.' );
		$this->assertFalse( $args['public'], 'public must be false for a visitor who cannot view archived content.' );
	}

	/**
	 * A REST request from a user who CAN view archived content (the
	 * documented public-status escape hatch, or simply a privileged viewer)
	 * registers `public => true`. `WP_REST_Posts_Controller::check_read_permission()`
	 * reads that flag directly and grants access without any plugin-side
	 * REST hook — the escape hatch `AccessGuard` honors on the front end
	 * carries over to REST automatically.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatus::register_status
	 */
	public function test_archived_status_registers_public_true_on_rest_request_when_user_can_view() {
		$args = $this->capturedStatusArgs( true );

		$this->assertTrue( $args['public'], 'public must be true so check_read_permission() grants REST access without plugin-side wiring.' );
	}
}
