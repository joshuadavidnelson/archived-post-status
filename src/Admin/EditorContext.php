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
 *   - Consumers (`PostEditor`, `PostEditorGuard`, etc.) call
 *     {@see is_classic_editor()} at the use site — no constructor injection.
 *
 * @since 0.4.0
 */
final class EditorContext {

	/**
	 * Whether the current admin context is the classic editor.
	 *
	 * Default detection:
	 *
	 *   1. If `get_current_screen()` returns a screen with `is_block_editor() === true`,
	 *      the request is rendering the block editor — return false.
	 *   2. Otherwise, return true iff the Classic Editor plugin
	 *      (`classic-editor/classic-editor.php`) is currently active.
	 *
	 * The detection result is passed through the {@see aps_is_classic_editor}
	 * filter, letting third-party plugins that disable the block editor (a
	 * custom rollback, a non-standard classic-editor port, an mu-plugin
	 * shim) trip the flag explicitly without monkey-patching WordPress's
	 * `WP_Screen::is_block_editor()`.
	 *
	 * @since 0.4.0
	 *
	 * @return bool True when the current admin context is the classic editor.
	 */
	public static function is_classic_editor(): bool {
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_classic = false;

		// Block-editor screen: definitively NOT classic. Early return preserves
		// the `if` shape without an `else` branch.
		if ( $screen && $screen->is_block_editor() ) {
			$is_classic = false;
		} elseif ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'classic-editor/classic-editor.php' ) ) {
			// Otherwise: classic editor is active iff the Classic Editor plugin
			// is loaded. Default detection (no plugin loaded, no block-editor
			// screen) returns false.
			$is_classic = true;
		}

		/**
		 * Filters whether the current admin context is the classic editor.
		 *
		 * Lets third-party plugins that disable the block editor (Classic
		 * Editor plugin variants, custom rollbacks, mu-plugin shims) flip
		 * the flag without intercepting `WP_Screen::is_block_editor()` or
		 * monkey-patching `is_plugin_active()`.
		 *
		 * @since 0.4.0
		 *
		 * @param bool $is_classic Whether the current request is in the classic editor.
		 */
		return (bool) apply_filters( 'aps_is_classic_editor', $is_classic );
	}
}
