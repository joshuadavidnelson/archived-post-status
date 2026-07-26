<?php

namespace ArchivedPostStatus\Admin;

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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action() is a
	 * named-constructor factory for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
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
	 * @since 0.4.0
	 */
	public function post_submitbox_archive_button(): void {
		$post_id = get_the_ID();
		$cap     = ArchiveAction::Archive->capability_function();
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
	 * Skipped on the classic editor — the archive button is rendered via
	 * post_submitbox_start instead.
	 *
	 * @since 0.4.0
	 * @param string $hook The current admin page hook.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see EditorContext::is_classic_editor()}
	 * is a pure environment-introspection helper (hybrid pattern: static helper for
	 * stateless value lookups, DI for Hookables).
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( EditorContext::is_classic_editor() ) {
			return;
		}

		wp_enqueue_script(
			'aps-block-editor',
			ARCHIVED_POST_STATUS_URL . 'assets/js/block-editor.js',
			array( 'wp-blocks', 'wp-dom-ready', 'wp-hooks', 'wp-i18n' ),
			ARCHIVED_POST_STATUS_VERSION,
			true
		);

		wp_set_script_translations(
			'aps-block-editor',
			'archived-post-status',
			plugin_dir_path( dirname( __DIR__ ) ) . '/languages/'
		);

		$post_id = get_the_ID();
		$cap     = ArchiveAction::Archive->capability_function();
		wp_localize_script(
			'aps-block-editor',
			'archivedPostStatus',
			array(
				'archiveUrl' => aps_get_archive_post_link( $post_id ),
				'canArchive' => $cap( $post_id ),
			)
		);
	}

}
