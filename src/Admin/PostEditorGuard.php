<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Controls access to the post editor for archived posts.
 *
 * `load-post.php` fires from wp-admin/admin.php for EVERY wp-admin/post.php
 * request, before post.php's own `switch ( $action )` runs — so this handler
 * sees trash, untrash, delete, unarchive, and every other post action, not
 * just the ones that render the editor. Two cases are handled when read-only
 * mode is active:
 *
 * 1. Redirect after save — WordPress redirects back to post.php with
 *    action=edit&message=1 after a successful save. For archived posts we
 *    send the editor to the list table instead of rendering the editor again.
 *
 * 2. Block direct edit access — an empty action (the bare edit screen) or
 *    action=edit without message=1 actually renders the editor for an
 *    archived post; that attempt is stopped with a wp_die().
 *
 * Every other action (unarchive, trash, untrash, delete, and anything else)
 * is left to proceed to core: `deny_editing_archived()`'s `map_meta_cap` deny
 * independently blocks a real save inside core's `edit_post()`, and
 * destructive actions gate on `delete_post`, which this plugin never touches.
 *
 * Separated from PostEditor so asset enqueuing and the classic editor button
 * are independent of access enforcement — they change for different reasons.
 *
 * @since 0.4.0
 */
final class PostEditorGuard implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action() is a
	 * named-constructor factory for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'load-post.php', array( $this, 'enforce_read_only' ) ),
			HookDescriptor::filter( 'map_meta_cap', array( $this, 'deny_editing_archived' ), 10, 4 ),
		);
	}

	/**
	 * Deny the edit_post meta cap for archived posts while read-only mode
	 * is active, so core drops its own edit affordances (row title link,
	 * Edit and Quick Edit actions, editor screens) server-side.
	 *
	 * Core's map_meta_cap() reassigns $cap to the post type's own edit_post
	 * primitive (e.g. edit_book) — not the literal 'edit_post' — before
	 * firing this filter, whenever the post type's map_meta_cap is false
	 * (WordPress's default for any capability_type other than post/page).
	 * Matching against both keeps the deny effective for those types too,
	 * instead of leaving them editable via a direct edit URL.
	 *
	 * Archive, unarchive, and author view capabilities resolve through
	 * post type primitives — never `edit_post` — so this deny cannot lock
	 * a post out of being unarchived or hidden from its own author.
	 *
	 * @since 0.4.0
	 * @param array<int, string> $caps    Primitive capabilities required.
	 * @param string             $cap     The meta capability being mapped.
	 * @param int                $user_id The user ID being checked.
	 * @param array<int, mixed>  $args    Context arguments; [0] is the post ID.
	 * @return array<int, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor; {@see PostTypeCapabilityPrimitive::resolve()}
	 * is the shared post-type-primitive lookup used identically in
	 * ArchiveCapability / ViewCapability, resolving 'edit_post' here (e.g.
	 * edit_book for a `capability_type => 'book'` type with
	 * `map_meta_cap => false`) instead of a private copy of the same lookup.
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $user_id is fixed by the
	 * map_meta_cap filter signature; the deny applies to every user identically.
	 */
	public function deny_editing_archived( array $caps, string $cap, int $user_id, array $args ): array {
		// Cheapest possible bail-outs first — this runs on a very hot filter,
		// and matching the cap requires knowing the post's type, so the post
		// has to be resolved before $cap can be checked at all.
		if ( empty( $args[0] ) || ! aps_is_read_only() ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		if ( ! $post || PostStatusValue::resolved_slug() !== $post->post_status ) {
			return $caps;
		}

		if ( 'edit_post' !== $cap && PostTypeCapabilityPrimitive::resolve( $post->post_type, 'edit_post' ) !== $cap ) {
			return $caps;
		}

		$caps[] = 'do_not_allow';

		return $caps;
	}

	/**
	 * The message shown when a direct edit attempt on an archived post is
	 * blocked. Exposed so callers (and tests) have a single source of truth
	 * instead of duplicating the copy.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function read_only_message(): string {
		/* translators: wp_die() message shown when a direct edit attempt on an archived post is blocked. */
		return __( "You can't edit this item because it has been Archived. Please change the post status and try again.", 'archived-post-status' );
	}

	/**
	 * Redirect or block access when an archived post is opened in the editor.
	 *
	 * @since 0.4.0
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
	 */
	public function enforce_read_only(): void {
		if ( ! aps_is_read_only() ) {
			return;
		}

		$post = $this->archived_post_in_request();
		if ( ! $post ) {
			return;
		}

		$action = $this->request_action();

		// Redirect to list table after saving as Archived (action=edit&message=1).
		if ( 'edit' === $action && 1 === $this->request_message() ) {
			$this->redirect_to_list( $post );
		}

		// `load-post.php` fires for EVERY post.php request — before post.php's
		// own switch( $action ) runs — so this handler sees trash, untrash,
		// delete, unarchive, and every other post action too, not just the
		// ones that render the editor. Only an empty action (the bare
		// edit screen) or action=edit without message=1 actually render the
		// editor; every other action is left to proceed to core. This is safe
		// standalone: deny_editing_archived()'s map_meta_cap deny independently
		// blocks a real save inside core's edit_post(), and destructive
		// actions gate on delete_post, which this plugin never touches.
		if ( ! $this->renders_editor( $action ) ) {
			return;
		}

		// Block any attempt to render the editor for an archived post.
		wp_die(
			esc_html( self::read_only_message() ),
			esc_html__( 'WordPress &rsaquo; Error', 'archived-post-status' )
		);
	}

	/**
	 * The archived post this request targets, or null.
	 *
	 * Null covers all three uninteresting cases together — no post id on the
	 * request, no such post, or a post that is not archived — so the caller
	 * reads as a single guard rather than three.
	 *
	 * @since 0.4.0
	 * @return \WP_Post|null
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
	 */
	private function archived_post_in_request(): ?\WP_Post {
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || PostStatusValue::resolved_slug() !== $post->post_status ) {
			return null;
		}

		return $post;
	}

	/**
	 * The sanitized `action` query arg, or an empty string.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	private function request_action(): string {
		if ( ! isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		return sanitize_text_field( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The `message` query arg core appends after a save, or 0.
	 *
	 * @since 0.4.0
	 * @return int
	 */
	private function request_message(): int {
		return absint( $_GET['message'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Whether an action would actually render the post editor.
	 *
	 * Only the bare edit screen and `action=edit` do. Every other post.php
	 * action — trash, untrash, delete, unarchive — passes through to core.
	 *
	 * @since 0.4.0
	 * @param string $action The request's action.
	 * @return bool
	 */
	private function renders_editor( string $action ): bool {
		return '' === $action || 'edit' === $action;
	}

	/**
	 * Redirect to the post list table after saving an archived post.
	 *
	 * @since 0.4.0
	 * @param \WP_Post $post The archived post.
	 * @return never
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostListUrlBuilder::for_post_type()}
	 * is a pure-functional URL builder (hybrid pattern — static helper for
	 * stateless URL construction, DI for Hookables). The static call is the
	 * documented public surface.
	 */
	private function redirect_to_list( \WP_Post $post ): never {
		$url = PostListUrlBuilder::for_post_type( $post->post_type, true );

		wp_safe_redirect( $url );
		exit;
	}
}
