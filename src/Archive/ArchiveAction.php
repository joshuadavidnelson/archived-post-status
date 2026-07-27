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
	 * Perform this action on a post by calling the appropriate public API function.
	 *
	 * Centralises the dispatch so callers (PostList, CLI) never branch on
	 * action type to decide which function to call.
	 */
	public function perform( int $post_id ): \WP_Post|false {
		return match ( $this ) {
			self::Archive   => aps_archive_post( $post_id ),
			self::Unarchive => aps_unarchive_post( $post_id ),
		};
	}

}
