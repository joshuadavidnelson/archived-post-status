<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Enqueues post editor assets and renders the classic editor archive button.
 *
 * Owns only asset loading and the submit-box button — nothing else. Access
 * enforcement for archived posts (redirect after save, block edit access) is
 * handled entirely by PostEditorGuard, which owns load-post.php.
 *
 * @since 0.4.0
 */
final class PostEditor implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) ),
			HookDescriptor::action( 'post_submitbox_start', array( $this, 'post_submitbox_archive_button' ) ),
		);
	}

	/**
	 * Add the archive button to the classic editor submit box.
	 *
	 * Gates on post type before capability, so the button never renders for an
	 * unsupported post type regardless of the capability result.
	 *
	 * @since 0.4.0
	 */
	public function post_submitbox_archive_button(): void {
		$post_id = get_the_ID();
		if ( ! aps_is_supported_post_type( get_post_type( $post_id ) ) ) {
			return;
		}

		$cap = ArchiveAction::Archive->capability_function();
		if ( ! $cap( $post_id ) ) {
			return;
		}

		printf(
			'<div id="archive-action" style="margin-right: 10px; float: left; line-height: calc(30/13);"><a class="submitdelete deletion" href="%s">%s</a></div>',
			esc_url( aps_get_archive_post_link( $post_id ) ),
			esc_html__( 'Archive', 'archived-post-status' )
		);
	}

	/**
	 * Enqueue block editor script on post editor screens.
	 *
	 * Skipped on the classic editor, where post_submitbox_start renders the
	 * button instead, and on unsupported post types.
	 *
	 * @since 0.4.0
	 * @param string $hook The current admin page hook.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- pure environment-introspection helper.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( EditorContext::is_classic_editor() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! aps_is_supported_post_type( get_post_type( $post_id ) ) ) {
			return;
		}

		// Dependencies match what assets/js/block-editor.js actually calls.
		wp_enqueue_script(
			'aps-block-editor',
			ARCHIVED_POST_STATUS_URL . 'assets/js/block-editor.js',
			array( 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-i18n' ),
			ARCHIVED_POST_STATUS_VERSION,
			true
		);

		wp_set_script_translations(
			'aps-block-editor',
			'archived-post-status',
			plugin_dir_path( dirname( __DIR__ ) ) . '/languages/'
		);

		$cap         = ArchiveAction::Archive->capability_function();
		$can_archive = $cap( $post_id );

		// ArchivePostLink::build() does not check capability, so gate here: a
		// user who cannot archive must not receive a working, nonce-signed
		// archiveUrl, even though the JS also checks canArchive before rendering.
		wp_localize_script(
			'aps-block-editor',
			'archivedPostStatus',
			array(
				'archiveUrl' => $can_archive ? aps_get_archive_post_link( $post_id ) : false,
				'canArchive' => $can_archive,
			)
		);
	}

}
