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
 * Hooks template_redirect and 404s visitors who lack the capability, matching
 * pre-0.4.0 behaviour. The capability comes from `aps_default_read_capability`.
 *
 * No REST equivalent is registered because core already enforces the same
 * policy: the archived status registers `private => ! is_admin()`, which is
 * true for REST, so `check_read_permission()` falls through to `read_post` —
 * and core's `map_meta_cap()` requires `read_private_posts` for a private
 * status. The public-status escape hatch below is covered too, since
 * `check_read_permission()` reads `$post_status_obj->public` directly.
 * `tests/php/Frontend/RestReadParityTest.php` pins the `status_args()` output
 * this depends on.
 *
 * @since 0.4.0
 */
final class AccessGuard implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	public function enforce_access(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || PostStatusValue::resolved_slug() !== $post->post_status ) {
			return;
		}

		// A site that registers the status as public has explicitly decided
		// archived posts are world-readable, so stand down: the check below
		// would 404 visitors holding no capabilities, which no filter can
		// satisfy. The registered status object is the source of truth because
		// it is what the filters actually produced.
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
