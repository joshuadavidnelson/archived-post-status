<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Detects whether the current admin context is the classic editor.
 *
 * Pure environment introspection. Shareable between `PostEditor` and any
 * future consumer, and extensible via the `aps_is_classic_editor` filter.
 *
 * Hybrid architecture pattern:
 *   - This class is STATIC; it has no state and no collaborators worth
 *     swapping. Pure functions of environment input only.
 *   - Consumers call {@see is_classic_editor()} at the use site — no
 *     constructor injection. `PostEditor::enqueue_scripts()` is the one
 *     production caller today; the static, no-DI shape is what would let a
 *     future consumer (e.g. `PostEditorGuard`) call it the same way.
 *
 * @since 0.4.0
 */
final class EditorContext {

	/**
	 * Whether the current admin context is the classic editor.
	 *
	 * Detection asks WordPress rather than guessing at what might have
	 * changed its mind:
	 *
	 *   1. `WP_Screen::is_block_editor()` when a screen is available. Core
	 *      sets that flag in `wp-admin/edit-form-blocks.php`, which it only
	 *      reaches after `use_block_editor_for_post()` returns true — and it
	 *      sets it before `admin-header.php` fires `admin_enqueue_scripts`,
	 *      so the flag is already correct by the time this plugin's enqueue
	 *      callback runs.
	 *   2. `use_block_editor_for_post()` directly when there is no screen,
	 *      which is the same question core asked in step 1.
	 *
	 * This replaces an earlier check that looked for the Classic Editor
	 * plugin by name. That produced a false negative for every other way the
	 * block editor gets turned off — a post type registered without
	 * `editor` support, a `use_block_editor_for_post_type` filter, a
	 * `replace_editor` handler — reporting those screens as non-classic and
	 * loading the block-editor bundle onto a page with no block editor on it.
	 * Both core entry points already run the `use_block_editor_for_post_type`
	 * and `use_block_editor_for_post` filters, which is how the Classic
	 * Editor plugin does its work, so naming that plugin bought nothing that
	 * asking core does not cover.
	 *
	 * The result is passed through the {@see aps_is_classic_editor} filter so
	 * a site can still force the answer.
	 *
	 * @since 0.4.0
	 *
	 * @return bool True when the current admin context is the classic editor.
	 */
	public static function is_classic_editor(): bool {
		/**
		 * Filters whether the current admin context is the classic editor.
		 *
		 * Lets third-party plugins that disable the block editor (Classic
		 * Editor plugin variants, custom rollbacks, mu-plugin shims) flip
		 * the flag without intercepting `WP_Screen::is_block_editor()`.
		 *
		 * @since 0.4.0
		 *
		 * @param bool $is_classic Whether the current request is in the classic editor.
		 */
		return (bool) apply_filters( 'aps_is_classic_editor', self::detect_classic_editor() );
	}

	/**
	 * The unfiltered detection, split out so the filter call above reads as
	 * one line and neither branch needs an else.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	private static function detect_classic_editor(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen ) {
			return ! $screen->is_block_editor();
		}

		$post = get_post();

		return $post instanceof \WP_Post && ! use_block_editor_for_post( $post );
	}
}
