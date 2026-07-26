<?php

namespace ArchivedPostStatus\Frontend;

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

		if ( ! aps_current_user_can_view( $post->ID ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}
}
