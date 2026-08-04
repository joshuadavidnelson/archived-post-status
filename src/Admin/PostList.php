<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Hooks\HookLoader;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * All behavior related to the WordPress post list table screen (edit.php).
 *
 * PostList owns the WordPress integration — which screen-specific hook names to
 * register — while the injected `BulkActionHandler` owns the per-post
 * capability gates, persist calls, and redirect URL composition.
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
	 * `post_row_actions` and `page_row_actions` are the only two row-actions
	 * hooks core fires, and it fires them for every post type. A per-type
	 * `"{$post_type}_row_actions"` registration would only ever match the
	 * literal slugs `post`/`page`. Since neither depends on the
	 * supported-post-types list, they need no deferral — the bulk-action hooks
	 * below do.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::filter( 'query_vars', array( $this, 'query_vars' ) ),
			HookDescriptor::filter( 'wp_list_table_show_post_checkbox', array( $this, 'show_archived_row_checkbox' ), 10, 2 ),
			HookDescriptor::action( 'post_action_archive', array( $this, 'post_action_archive' ) ),
			HookDescriptor::action( 'post_action_unarchive', array( $this, 'post_action_unarchive' ) ),
			HookDescriptor::filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 ),
			HookDescriptor::filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 ),
			HookDescriptor::action( 'wp_loaded', array( $this, 'register_post_type_hooks' ) ),
			HookDescriptor::filter( 'removable_query_args', array( $this, 'removable_query_args' ) ),
		);
	}

	/**
	 * Register the per-post-type bulk-action hooks once custom post types exist.
	 *
	 * `hooks()` runs on `plugins_loaded`. Core's `post`/`page` exist by then but
	 * plugin- and theme-registered types do not, so enumerating supported post
	 * types there would permanently miss them.
	 *
	 * `wp_loaded` rather than a late `init` priority: `init` fires at every
	 * registered priority before `wp_loaded` fires at all, so it is the first
	 * point guaranteed to follow every `init` callback rather than only those
	 * below a priority number we'd have to guess. The hooks built here don't
	 * fire until the list screen renders, so nothing is lost by waiting.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register_post_type_hooks(): void {
		( new HookLoader() )->register( $this->post_type_hooks() );
	}

	/**
	 * Build the bulk-action hook descriptors for each supported post type.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	private function post_type_hooks(): array {
		$hooks = array();

		foreach ( aps_get_supported_post_types() as $post_type ) {
			$screen  = 'edit-' . $post_type;
			$hooks[] = HookDescriptor::filter( "bulk_actions-{$screen}", array( $this, 'bulk_actions' ) );
			$hooks[] = HookDescriptor::filter( "handle_bulk_actions-{$screen}", array( $this->bulk_handler, 'handle' ), 10, 3 );
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
	 * Strips the bulk-action notice args from the visible URL once the notice
	 * has rendered.
	 *
	 * Must list the same eight names as {@see query_vars()} and
	 * {@see BulkActionHandler::STRIPPED_QUERY_ARGS}, or a stale skip-reason
	 * notice or undo link re-renders on every refresh of that URL.
	 *
	 * @since 0.4.0
	 * @param array<int, string> $args Existing removable query arg names.
	 * @return array<int, string>
	 */
	public function removable_query_args( array $args ): array {
		return array_merge(
			$args,
			array( 'archived', 'unarchived', 'ids', 'locked', 'denied', 'not_found', 'wrong_status', 'skipped' )
		);
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical vocabulary lookups.
	 */
	public function bulk_actions( array $actions ): array {
		// Multi-status filters (?post_status[]=publish&post_status[]=archive)
		// arrive as an array; normalize so one check covers both shapes.
		$post_status  = get_query_var( 'post_status', false );
		$statuses     = $post_status ? (array) $post_status : array();
		$archivable   = ArchivableStatuses::all();

		// The "All" view, or any archivable status selected.
		if ( empty( $statuses ) || array_intersect( $statuses, $archivable ) ) {
			$actions[ ArchiveAction::Archive->value ] = __( 'Archive', 'archived-post-status' );
		}

		if ( in_array( PostStatusValue::resolved_slug(), $statuses, true ) ) {
			$actions[ ArchiveAction::Unarchive->value ] = __( 'Unarchive', 'archived-post-status' );
		}

		return $actions;
	}

	/**
	 * Add an Unarchive & Archive link to the post row actions.
	 *
	 * Registered unconditionally rather than per post type, so per-post-type
	 * filtering happens at call time via {@see RowActionPolicy::for_post()}'s
	 * `aps_is_supported_post_type()` gate, which always sees the live list.
	 *
	 * @since 0.4.0
	 * @param array<string, string> $actions Current post row actions (action key => HTML link).
	 * @param \WP_Post              $post    Post object.
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- pure-function policy helper.
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		return RowActionPolicy::for_post( $post, $actions );
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
