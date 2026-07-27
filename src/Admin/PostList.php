<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Status\PostStatusValue;
// RowActionPolicy is in the same namespace — no `use` needed, but referenced
// explicitly at the use site for IDE / search ergonomics.

/**
 * All behavior related to the WordPress post list table screen (edit.php).
 *
 * Merges functionality from BulkEdit, RowActions, and the edit-screen.js enqueue
 * that was previously in Plugin.php. These all relate to the post list table
 * and change for the same reasons.
 *
 * Bulk-action dispatch lives in the injected `BulkActionHandler` service —
 * PostList owns the WordPress integration (knows which screen-specific hook
 * names to register) while the handler owns the per-post capability gates,
 * persist calls, and redirect URL composition. The handler also exposes
 * `get_redirect_url()` for the single-post `post_action_*` handlers.
 *
 * @since 0.4.0
 */
final class PostList implements HookableInterface {

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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action()/::filter()
	 * are named-constructor factories for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		$hooks = array(
			HookDescriptor::filter( 'query_vars', array( $this, 'query_vars' ) ),
			HookDescriptor::filter( 'wp_list_table_show_post_checkbox', array( $this, 'show_archived_row_checkbox' ), 10, 2 ),
			HookDescriptor::action( 'post_action_archive', array( $this, 'post_action_archive' ) ),
			HookDescriptor::action( 'post_action_unarchive', array( $this, 'post_action_unarchive' ) ),
		);

		// Register bulk actions and row actions for each supported post type
		$post_types = aps_get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			// Bulk actions
			$screen  = 'edit-' . $post_type;
			$hooks[] = HookDescriptor::filter( "bulk_actions-{$screen}", array( $this, 'bulk_actions' ) );
			$hooks[] = HookDescriptor::filter( "handle_bulk_actions-{$screen}", array( $this->bulk_handler, 'handle' ), 10, 3 );

			// Row actions
			$hooks[] = HookDescriptor::filter( "{$post_type}_row_actions", array( $this, 'row_actions' ), 10, 2 );
		}

		return $hooks;
	}

	/**
	 * Add custom query vars for archive feedback.
	 *
	 * @since 0.4.0
	 * @param array<int, string> $vars Current query var names.
	 * @return array<int, string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'archived';
		$vars[] = 'unarchived';
		$vars[] = 'ids';
		// Skip-reason buckets emitted by BulkActionResult on the redirect URL.
		// NoticeBuilder reads these to render per-reason "X skipped" lines.
		$vars[] = 'locked';
		$vars[] = 'denied';
		$vars[] = 'not_found';
		$vars[] = 'wrong_status';
		$vars[] = 'skipped';
		return $vars;
	}

	/**
	 * Keep archived rows selectable for the plugin's own bulk actions.
	 *
	 * Core only renders the bulk checkbox when the user can edit_post,
	 * which PostEditorGuard denies for archived posts in read-only mode.
	 * Users who can unarchive a row still need to select it for bulk
	 * Unarchive, so the checkbox is restored on that capability instead.
	 *
	 * @since 0.4.0
	 * @param bool     $show Whether core would show the checkbox.
	 * @param \WP_Post $post The row's post.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
	 */
	public function show_archived_row_checkbox( bool $show, \WP_Post $post ): bool {
		if ( $show || PostStatusValue::resolved_slug() !== $post->post_status ) {
			return $show;
		}

		return aps_current_user_can_unarchive( $post->ID );
	}

	/**
	 * Add the custom bulk actions.
	 *
	 * @since 0.4.0
	 * @param array<string, string> $actions Bulk action map (action key => label).
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchivableStatuses::all()}
	 * and {@see PostStatusValue::resolved_slug()} are the canonical
	 * vocabulary lookups.
	 */
	public function bulk_actions( array $actions ): array {
		// Multi-status filters (e.g. ?post_status[]=publish&post_status[]=archive)
		// surface as an array; normalize to an array of strings for a uniform check.
		$post_status  = get_query_var( 'post_status', false );
		$statuses     = $post_status ? (array) $post_status : array();
		$archivable   = ArchivableStatuses::all();

		// If it's the "All" view, or any selected status is archivable,
		// then show the "Archive" bulk action.
		if ( empty( $statuses ) || array_intersect( $statuses, $archivable ) ) {
			$actions[ ArchiveAction::Archive->value ] = __( 'Archive', 'archived-post-status' );
		}

		// If the "Archived" status is among the selected filters,
		// then show the "Unarchive" bulk action.
		if ( in_array( PostStatusValue::resolved_slug(), $statuses, true ) ) {
			$actions[ ArchiveAction::Unarchive->value ] = __( 'Unarchive', 'archived-post-status' );
		}

		return $actions;
	}

	/**
	 * Add an Unarchive & Archive link to the post row actions.
	 *
	 * The policy branches live in the pure-static
	 * {@see RowActionPolicy::for_post()} helper; this filter callback is a
	 * thin WP-adapter that forwards the current screen for forward
	 * compatibility.
	 *
	 * @since 0.4.0
	 * @param array<string, string> $actions Current post row actions (action key => HTML link).
	 * @param \WP_Post              $post    Post object.
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see RowActionPolicy::for_post()}
	 * is a pure function of input (the hybrid pattern); the static call
	 * is the documented public surface, not a service-locator pull.
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return RowActionPolicy::for_post( $post, $actions, $screen );
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
	 * Ordering matters: post-id validation runs BEFORE
	 * the nonce check. Rationale: `check_admin_referer()` fatal-errors on
	 * a missing/invalid nonce, which leaks a real WordPress dialog to
	 * the requester. By validating the post id first we can reject
	 * obviously-bogus payloads (non-existent post, unsupported post type)
	 * silently — and a subsequent nonce check on a valid request still
	 * provides the CSRF guarantee.
	 *
	 * @since 0.4.0
	 * @param int           $post_id The post ID.
	 * @param ArchiveAction $action The action to perform.
	 * @return void
	 */
	private function handle_post_action( int $post_id, ArchiveAction $action ): void {
		// Pre-nonce validation. A non-numeric, missing, or
		// unsupported-post-type id is rejected without ever reaching
		// check_admin_referer().
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
			return;
		}

		if ( ! get_post_type_object( $post->post_type ) ) {
			wp_die( __( 'Invalid post type.', 'archived-post-status' ) );
		}

		$user_id = wp_check_post_lock( $post_id );
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			wp_die(
				sprintf(
					/* translators: %s: User's display name. */
					__( 'You cannot archive this item. %s is currently editing.', 'archived-post-status' ),
					esc_html( $user->display_name )
				)
			);
		}

		if ( ! $action->perform( $post_id ) ) {
			wp_die( __( 'Error in archiving this item.', 'archived-post-status' ) );
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
