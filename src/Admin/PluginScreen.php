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
 * Deactivating unregisters the archived post status, so any content in it
 * would fall back to WordPress's default handling for an unregistered
 * status. The JS reads the localized `archivedPostStatus.hasArchivedPosts`
 * flag to decide whether to prompt — on the Plugins screen's Deactivate
 * link, and on a Bulk Actions -> Deactivate submit that includes this
 * plugin.
 *
 * Network Admin's Plugins screen shares the same `admin_enqueue_scripts`
 * hook suffix, but a single site's `has_archived_posts()` query cannot
 * answer a network-wide question. There, `enqueue_scripts()` skips the
 * query entirely and localizes `archivedPostStatus.isNetworkAdmin` instead
 * — the JS always warns on that screen, with a generalized message,
 * independent of `hasArchivedPosts`.
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

		$is_network_admin = is_network_admin();

		wp_localize_script(
			'aps-plugin-screen',
			'archivedPostStatus',
			array(
				'isNetworkAdmin'   => $is_network_admin,
				// On Network Admin, one site can't speak for the network —
				// skip the query and let the JS's unconditional, generalized
				// warning take over instead (see has_archived_posts()).
				'hasArchivedPosts' => $is_network_admin ? false : $this->has_archived_posts(),
			)
		);
	}

	/**
	 * Cheap existence check for archived content across supported post types.
	 *
	 * Answers only for the current site — {@see enqueue_scripts()} never
	 * calls this on Network Admin, where no single site's answer is
	 * meaningful for the network as a whole.
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
