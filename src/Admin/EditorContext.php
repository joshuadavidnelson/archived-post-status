<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Detects whether the current admin context is the classic editor.
 *
 * @since 0.4.0
 */
final class EditorContext {

	/**
	 * Whether the current admin context is the classic editor.
	 *
	 * Detection asks core rather than sniffing for the Classic Editor plugin
	 * by name. Naming that plugin missed every other way the block editor gets
	 * turned off — a post type without `editor` support, a
	 * `use_block_editor_for_post_type` filter, a `replace_editor` handler —
	 * and loaded the block-editor bundle onto pages with no block editor.
	 *
	 * `WP_Screen::is_block_editor()` is authoritative when a screen exists:
	 * core sets it in `edit-form-blocks.php`, before `admin-header.php` fires
	 * `admin_enqueue_scripts`. Without a screen, `use_block_editor_for_post()`
	 * answers the same question directly.
	 *
	 * @since 0.4.0
	 *
	 * @return bool True when the current admin context is the classic editor.
	 */
	public static function is_classic_editor(): bool {
		/**
		 * Filters whether the current admin context is the classic editor.
		 *
		 * Lets a site force the answer without intercepting
		 * `WP_Screen::is_block_editor()`.
		 *
		 * @since 0.4.0
		 *
		 * @param bool $is_classic Whether the current request is in the classic editor.
		 */
		return (bool) apply_filters( 'aps_is_classic_editor', self::detect_classic_editor() );
	}

	/**
	 * The unfiltered detection.
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
