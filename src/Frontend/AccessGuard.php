<?php

namespace ArchivedPostStatus\Frontend;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Enforces view-capability access for archived content on the frontend.
 *
 * Hooks template_redirect to intercept requests for archived posts.
 * Visitors without the required capability receive a 404. This mirrors
 * the pre-0.4.0 behaviour where archived content was private on the frontend
 * unless the current user could view it.
 *
 * The required capability is controlled by the aps_default_read_capability
 * filter (default: 'read_private_posts'), making it overridable without
 * touching this class.
 *
 * No REST API equivalent is registered here, and none is needed. WordPress
 * core's own REST permission check already enforces the same policy for
 * free: {@see Status\PostStatus::status_args()} registers the archived
 * status with `private => ! is_admin()` (true for a REST request), which
 * makes `WP_REST_Posts_Controller::check_read_permission()` fall through to
 * `current_user_can( 'read_post', $post->ID )` for anyone but the post's own
 * author — and core's `map_meta_cap()` `read_post` case requires
 * `read_private_posts` whenever the resolved status object has
 * `private = true`, the same capability `aps_current_user_can_view()`
 * consults by default. The public-status escape hatch below is likewise
 * covered without any REST-side hook: `check_read_permission()` reads
 * `$post_status_obj->public` directly, the exact flag this class stands
 * down on. See `tests/php/Frontend/RestReadParityTest.php`, which pins the
 * `status_args()` output this parity depends on, and cites the exact core
 * functions and file paths this claim was verified against.
 *
 * @since 0.4.0
 */
final class AccessGuard implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action() is a
	 * named-constructor factory for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'template_redirect', array( $this, 'enforce_access' ) ),
		);
	}

	/**
	 * Redirect or 404 if the current visitor cannot view the archived post.
	 *
	 * Only acts on singular archived posts. Archive list pages and all other
	 * query types are left unchanged.
	 *
	 * @since 0.4.0
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
	 */
	public function enforce_access(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || PostStatusValue::resolved_slug() !== $post->post_status ) {
			return;
		}

		// A site can publish archived content by registering the status as
		// public (`aps_status_arg_public` true, `aps_status_arg_private`
		// false). That is an explicit decision that archived posts are
		// world-readable, so this guard stands down rather than overriding
		// it — the capability check below would 404 visitors who hold no
		// capabilities at all, which no filter could ever satisfy. The
		// registered status object is the source of truth because it is
		// what the filters actually produced.
		$status = get_post_status_object( PostStatusValue::resolved_slug() );
		if ( $status && ! empty( $status->public ) ) {
			return;
		}

		if ( ! aps_current_user_can_view( $post->ID ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}
}
