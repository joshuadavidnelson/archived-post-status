<?php

namespace ArchivedPostStatus\Admin;

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Warns admins before they deactivate the plugin while archived content exists.
 *
 * Deactivating unregisters the archived post status, so any content in it
 * would fall back to WordPress's default handling for an unregistered
 * status. The JS reads the localized `archivedPostStatus.hasArchivedPosts`
 * flag to decide whether to prompt on the Plugins screen's Deactivate link.
 *
 * @since 0.4.0
 */
final class PluginScreen implements HookableInterface {

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
		);
	}

	/**
	 * Enqueue the deactivation-warning script on the Plugins screen only.
	 *
	 * @since 0.4.0
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'aps-plugin-screen',
			ARCHIVED_POST_STATUS_URL . 'assets/js/plugin-screen.js',
			array( 'wp-i18n' ),
			ARCHIVED_POST_STATUS_VERSION,
			true
		);

		wp_set_script_translations(
			'aps-plugin-screen',
			'archived-post-status',
			plugin_dir_path( dirname( __DIR__ ) ) . '/languages/'
		);

		wp_localize_script(
			'aps-plugin-screen',
			'archivedPostStatus',
			array(
				'hasArchivedPosts' => $this->has_archived_posts(),
			)
		);
	}

	/**
	 * Cheap existence check for archived content across supported post types.
	 *
	 * @since 0.4.0
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
	 */
	private function has_archived_posts(): bool {
		$archived = get_posts(
			array(
				'post_status'    => PostStatusValue::resolved_slug(),
				'post_type'      => aps_get_supported_post_types(),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $archived );
	}
}
