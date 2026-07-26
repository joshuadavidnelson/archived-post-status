<?php
/**
 * E2E mu-plugin fixture: render post.php with the classic editor.
 *
 * TOGGLE: option `aps_test_classic_editor_enabled` (boolean, default OFF).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_classic_editor_enabled": true }
 *   WP-CLI: wp option update aps_test_classic_editor_enabled 1
 *           wp option update aps_test_classic_editor_enabled 0
 *
 * Two filters, both required, matching the condition the plugin actually
 * supports ({@see \ArchivedPostStatus\Admin\EditorContext::is_classic_editor()}):
 *
 *   1. `use_block_editor_for_post` -> false makes WordPress render
 *      `edit-form-advanced.php`, which is what fires `post_submitbox_start` and
 *      therefore renders the plugin's classic Archive link. Without this the
 *      classic submit box does not exist on the page at all.
 *   2. `aps_is_classic_editor` -> true is the documented extension point for
 *      "a third-party plugin disabled the block editor". `EditorContext`'s
 *      built-in detection only recognises the Classic Editor plugin by file
 *      path, so a site that disables the block editor by filter has to trip
 *      this flag. With it set, `PostEditor::enqueue_scripts()` skips the
 *      block-editor bundle — which the spec asserts, because that bundle
 *      dereferences `wp.element` / `wp.editPost` and would throw on a classic
 *      screen.
 *
 * Enabling only (1) is the unsupported combination and is deliberately not what
 * this fixture does.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Whether the classic-editor fixture is switched on.
 *
 * @return bool
 */
function aps_test_classic_editor_enabled() {
	return (bool) get_option( 'aps_test_classic_editor_enabled', false );
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_classic_editor_enabled',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: force the classic editor and trip aps_is_classic_editor.',
			)
		);
	}
);

add_filter(
	'use_block_editor_for_post',
	function ( $use_block_editor ) {
		return aps_test_classic_editor_enabled() ? false : $use_block_editor;
	}
);

add_filter(
	'aps_is_classic_editor',
	function ( $is_classic ) {
		return aps_test_classic_editor_enabled() ? true : $is_classic;
	}
);
