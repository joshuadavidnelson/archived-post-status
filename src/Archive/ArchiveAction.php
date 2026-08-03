<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The two actions that can be performed on a post's archive status.
 *
 * A backed enum that eliminates magic strings 'archive' and 'unarchive'
 * that appear throughout the codebase in nonce keys, URL params, switch
 * statements, and query args.
 *
 * @since 0.4.0
 */
enum ArchiveAction: string {

	case Archive   = 'archive';
	case Unarchive = 'unarchive';

	/**
	 * The past-tense form: 'archived' or 'unarchived'.
	 * Used as the query arg name in redirect URLs and admin notices.
	 */
	public function past_tense(): string {
		return $this->value . 'd';
	}

	/** Alias for past_tense() — more readable at call sites. */
	public function query_arg(): string {
		return $this->past_tense();
	}

	/**
	 * The nonce key for this action on a specific post.
	 */
	public function nonce_key( int $post_id ): string {
		return $this->value . '-' . $post_id;
	}

	/**
	 * The capability check function name for this action.
	 * e.g. 'aps_current_user_can_archive'
	 */
	public function capability_function(): string {
		return 'aps_current_user_can_' . $this->value;
	}

	/**
	 * The message shown when this action is denied because another user
	 * currently holds the post's edit lock. Carries one %s placeholder,
	 * filled in by the caller (via sprintf()) with the locking user's
	 * display name.
	 *
	 * Archive and Unarchive each get their own complete, independently
	 * translatable string rather than a shared template assembled by
	 * concatenating a direction word into it — concatenation breaks
	 * translations that need to reorder or inflect around the verb.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public function locked_message(): string {
		return match ( $this ) {
			self::Archive =>
				/* translators: %s: display name of the user currently editing the post. */
				__( 'You cannot archive this item. %s is currently editing.', 'archived-post-status' ),
			self::Unarchive =>
				/* translators: %s: display name of the user currently editing the post. */
				__( 'You cannot unarchive this item. %s is currently editing.', 'archived-post-status' ),
		};
	}

	/**
	 * The message shown when perform() fails to persist the status change.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public function failure_message(): string {
		return match ( $this ) {
			self::Archive   => __( 'Error in archiving this item.', 'archived-post-status' ),
			self::Unarchive => __( 'Error in unarchiving this item.', 'archived-post-status' ),
		};
	}

	/**
	 * The message shown when the current user fails the capability check
	 * for this action.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public function denied_message(): string {
		return match ( $this ) {
			self::Archive   => __( 'You do not have permission to archive this item.', 'archived-post-status' ),
			self::Unarchive => __( 'You do not have permission to unarchive this item.', 'archived-post-status' ),
		};
	}

	/**
	 * Perform this action on a post by calling the appropriate public API function.
	 *
	 * Centralises the dispatch so callers (PostList, CLI) never branch on
	 * action type to decide which function to call.
	 *
	 * Return type mirrors `aps_archive_post()` / `aps_unarchive_post()`
	 * (and, in turn, `ArchiveOperation::perform()` / `UnarchiveOperation::perform()`):
	 * `\WP_Post|bool`, not `\WP_Post|false`. Both functions pass the
	 * `aps_pre_archive_post` / `aps_pre_unarchive_post` filter's return value
	 * through when it is non-null, and that filter is documented as
	 * `bool|null` — so a site-registered `true` must be a legal return here.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to act on.
	 * @return \WP_Post|bool
	 */
	public function perform( int $post_id ): \WP_Post|bool {
		return match ( $this ) {
			self::Archive   => aps_archive_post( $post_id ),
			self::Unarchive => aps_unarchive_post( $post_id ),
		};
	}

}
