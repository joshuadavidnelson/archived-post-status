<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Warns admins before they deactivate the plugin while archived content exists.
 *
 * Deactivating unregisters the archived post status, leaving that content to
 * WordPress's default handling for an unregistered status. The JS reads the
 * localized `hasArchivedPosts` flag to decide whether to prompt.
 *
 * Network Admin shares the same hook suffix, but no single site's
 * `has_archived_posts()` answer is meaningful network-wide. There the query is
 * skipped and `isNetworkAdmin` localized instead, so the JS always warns with
 * a generalized message.
 *
 * @since 0.4.0
 */
final class PluginScreen implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
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

		$is_network_admin = is_network_admin();

		wp_localize_script(
			'aps-plugin-screen',
			'archivedPostStatus',
			array(
				'isNetworkAdmin'   => $is_network_admin,
				// One site can't speak for the network; the JS warns
				// unconditionally there instead.
				'hasArchivedPosts' => $is_network_admin ? false : $this->has_archived_posts(),
			)
		);
	}

	/**
	 * Cheap existence check for archived content across supported post types.
	 * Answers for the current site only.
	 *
	 * @since 0.4.0
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
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
