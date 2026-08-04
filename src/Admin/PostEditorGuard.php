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
 * `load-post.php` fires for EVERY wp-admin/post.php request, before post.php's
 * own `switch ( $action )` runs, so this handler sees trash, untrash, delete,
 * unarchive and everything else — not just the actions that render the editor.
 * When read-only mode is active it handles two of them:
 *
 * 1. `action=edit&message=1`, core's post-save redirect — sent to the list
 *    table rather than back into the editor.
 * 2. An empty action or `action=edit` without `message=1`, which would render
 *    the editor for an archived post — stopped with wp_die().
 *
 * Every other action proceeds to core. That is safe on its own:
 * `deny_editing_archived()`'s map_meta_cap deny blocks a real save inside
 * core's `edit_post()`, and destructive actions gate on `delete_post`, which
 * this plugin never touches.
 *
 * @since 0.4.0
 */
final class PostEditorGuard implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
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
	 * Whenever a post type sets `map_meta_cap => false` (core's default for any
	 * capability_type other than post/page), core reassigns $cap to that type's
	 * own primitive — `edit_book`, not the literal `edit_post` — before firing
	 * this filter. Matching both is what keeps those types from staying
	 * editable via a direct edit URL.
	 *
	 * Archive, unarchive, and author view capabilities resolve through post
	 * type primitives rather than `edit_post`, so this deny cannot lock a post
	 * out of being unarchived or hide it from its own author.
	 *
	 * @since 0.4.0
	 * @param array<int, string> $caps    Primitive capabilities required.
	 * @param string             $cap     The meta capability being mapped.
	 * @param int                $user_id The user ID being checked.
	 * @param array<int, mixed>  $args    Context arguments; [0] is the post ID.
	 * @return array<int, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical slug and primitive lookups.
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- $user_id is fixed by the
	 * map_meta_cap filter signature; the deny applies to every user identically.
	 */
	public function deny_editing_archived( array $caps, string $cap, int $user_id, array $args ): array {
		// Cheapest bail-outs first — this is a hot filter, and matching $cap
		// needs the post's type, so the post must be resolved before that check.
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
	 * The message shown when a direct edit attempt on an archived post is blocked.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
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

		// Core's post-save redirect.
		if ( 'edit' === $action && 1 === $this->request_message() ) {
			$this->redirect_to_list( $post );
		}

		if ( ! $this->renders_editor( $action ) ) {
			return;
		}

		wp_die(
			esc_html( self::read_only_message() ),
			esc_html__( 'WordPress &rsaquo; Error', 'archived-post-status' )
		);
	}

	/**
	 * The archived post this request targets, or null when there is no post id,
	 * no such post, or the post is not archived.
	 *
	 * @since 0.4.0
	 * @return \WP_Post|null
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- pure-functional URL builder.
	 */
	private function redirect_to_list( \WP_Post $post ): never {
		$url = PostListUrlBuilder::for_post_type( $post->post_type, true );

		wp_safe_redirect( $url );
		exit;
	}
}
