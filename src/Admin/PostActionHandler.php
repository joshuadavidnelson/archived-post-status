<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Handles the single-post archive/unarchive admin actions
 * (`?action=archive|unarchive&post={id}`).
 *
 * The row-action links `RowActionPolicy` builds point here. Failure is
 * user-facing: every guard that rejects the request does so via `wp_die()`
 * with an explanatory message, unlike the bulk path on `BulkActionHandler`,
 * which accumulates per-item outcomes into a `BulkActionResult` instead of
 * dying. The two are deliberately not unified — see this class's
 * counterpart in `BulkActionHandler::process_archive_post()` for why.
 *
 * Shares its injected `BulkActionHandler` instance with `PostList` (both are
 * constructed from the same instance in `Plugin::hookables()`) purely to
 * reuse {@see BulkActionHandler::get_redirect_url()} for the post-action
 * redirect target — no bulk-action state is read or written here.
 *
 * @since 0.4.0
 */
final class PostActionHandler implements HookableInterface {

	/**
	 * @since 0.4.0
	 */
	public function __construct(
		private readonly BulkActionHandler $bulk_handler,
	) {}

	/**
	 * Return an array of HookDescriptor objects.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'post_action_archive', array( $this, 'post_action_archive' ) ),
			HookDescriptor::action( 'post_action_unarchive', array( $this, 'post_action_unarchive' ) ),
		);
	}

	/**
	 * Do the 'archive' post action.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function post_action_archive( int $post_id ): void {
		$this->handle_post_action( $post_id, ArchiveAction::Archive );
	}

	/**
	 * Do the 'unarchive' post action.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function post_action_unarchive( int $post_id ): void {
		$this->handle_post_action( $post_id, ArchiveAction::Unarchive );
	}

	/**
	 * Handle individual post archive/unarchive actions.
	 *
	 * Post-id validation deliberately runs before the nonce check:
	 * `check_admin_referer()` fatal-errors on a bad nonce, leaking a WordPress
	 * dialog to the requester, so obviously-bogus payloads are rejected
	 * silently first. The nonce check still guards every valid request.
	 *
	 * @since 0.4.0
	 * @param int           $post_id The post ID.
	 * @param ArchiveAction $action The action to perform.
	 * @return void
	 */
	private function handle_post_action( int $post_id, ArchiveAction $action ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( ! aps_is_supported_post_type( $post->post_type ) ) {
			return;
		}

		check_admin_referer( $action->nonce_key( $post_id ) );

		if ( ! ( $action->capability_function() )( $post_id ) ) {
			wp_die( esc_html( $action->denied_message() ) );
		}

		if ( ! get_post_type_object( $post->post_type ) ) {
			wp_die( esc_html( __( 'Invalid post type.', 'archived-post-status' ) ) );
		}

		$user_id = wp_check_post_lock( $post_id );
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			/* translators: fallback name shown when the post-lock holder's account no longer exists. */
			$user_name = $user ? $user->display_name : __( 'Another user', 'archived-post-status' );
			wp_die(
				sprintf(
					esc_html( $action->locked_message() ),
					esc_html( $user_name )
				)
			);
		}

		if ( ! $action->perform( $post_id ) ) {
			wp_die( esc_html( $action->failure_message() ) );
		}

		$sendback = add_query_arg(
			array(
				$action->query_arg() => 1,
				'ids'                => $post_id,
			),
			$this->bulk_handler->get_redirect_url( $post->post_type )
		);

		wp_safe_redirect( $sendback );
		exit;
	}
}
