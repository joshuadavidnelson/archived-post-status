<?php
/**
 * The file that defines the core plugin functions
 *
 * @link    https://github.com/joshuadavidnelson/archived-post-status
 * @since   0.4.0
 * @package ArchivedPostStatus
 * @author  Joshua David Nelson <josh@joshuadnelson.com>, fjarrett
 * @license GPL-2.0+
 */

namespace ArchivedPostStatus;

use ArchivedPostStatus\Admin;
use ArchivedPostStatus\Archive\ArchiveMetaListener;
use ArchivedPostStatus\CLI\ArchiveCommand;
use ArchivedPostStatus\CLI\CommandRunner;
use ArchivedPostStatus\CLI\Registrar;
use ArchivedPostStatus\CLI\UnarchiveCommand;
use ArchivedPostStatus\Frontend;
use ArchivedPostStatus\Hooks\HookLoader;
use ArchivedPostStatus\Settings;
use ArchivedPostStatus\Status\PostStatus;
use ArchivedPostStatus\Status\PostStatusGuard;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Wiring only. Builds the list of hookables and runs the loader.
 * No business logic, no database queries, no script enqueues.
 *
 * @since 0.4.0
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") -- composition root; the
 * coupling to every hookable class is inherent to a Plugin::hookables()
 * wiring list.
 */
final class Plugin {

	public function __construct(
		private readonly string $version,
	) {}

	public function run(): void {

		/**
		 * Fires before any plugin hooks are registered.
		 *
		 * @since 0.4.0
		 */
		do_action( 'aps_init' );

		$this->upgrade_check();

		// No load_plugin_textdomain() call: WP loads translations just in time
		// since 4.6, and calling it here — on plugins_loaded, before init —
		// trips WP 6.7+'s "translation loading was triggered too early" notice.

		( new HookLoader() )->add_all( $this->hookables() )->run();

		/**
		 * Fires after all plugin hooks have been registered.
		 *
		 * @since 0.4.0
		 */
		do_action( 'aps_loaded' );
	}

	/**
	 * Build the list of hookable classes.
	 *
	 * The authoritative list of everything the plugin registers with WordPress.
	 *
	 * Everything in the `is_admin()` branch registers hooks that only fire on a
	 * wp-admin page load. `PostEditorGuard` stays unconditional because its
	 * `map_meta_cap` filter runs on every capability check anywhere — front
	 * end, REST, CLI.
	 *
	 * @return Contracts\HookableInterface[]
	 */
	private function hookables(): array {
		$hookables = array(
			new PostStatus(),
			new PostStatusGuard(),
			new Frontend\ArchiveTitle(),
			new Frontend\AccessGuard(),
			new Admin\PostEditorGuard(),
			new Settings\HookAdapter(),
		);

		if ( apply_filters( 'aps_enable_archive_meta', true ) ) {
			$hookables[] = new ArchiveMetaListener();
		}

		if ( is_admin() ) {
			$hookables[] = new Admin\PostEditor();
			$hookables[] = new Admin\Notices( new Admin\NoticeBuilder() );
			$hookables[] = new Admin\PostList( new Admin\BulkActionHandler() );
			$hookables[] = new Admin\ArchiveColumn();
			$hookables[] = new Admin\PluginScreen();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$hookables[] = new Registrar(
				new CommandRunner(),
				new ArchiveCommand(),
				new UnarchiveCommand(),
			);
		}

		return $hookables;
	}

	/**
	 * Run upgrade routines when the stored version differs from current.
	 * Only runs on admin page loads.
	 *
	 * Each state — fresh install, upgrade, no-op — takes exactly one branch
	 * with a self-contained write sequence, so there is no observable
	 * half-upgraded state: anyone who sees the current version stored is
	 * guaranteed the previous-version marker is already correct.
	 */
	private function upgrade_check(): void {
		if ( ! is_admin() ) {
			return;
		}

		$stored = get_option( 'archived_post_status_version', false );

		// Option absent means a fresh install or an upgrade from pre-0.4.0,
		// which never wrote options. Existing archived content is the only
		// thing that distinguishes the two, and only right now — a fresh
		// install accumulates archived posts of its own later. add_option
		// no-ops if a concurrent request already created the option.
		if ( false === $stored ) {
			if ( $this->has_pre_040_content() ) {
				update_option( 'archived_post_status_previous_version', 'pre-0.4.0', false );
			}

			add_option( 'archived_post_status_version', $this->version, '', false );
			return;
		}

		// Both writes belong to the same logical transition.
		if ( $stored !== $this->version ) {
			update_option( 'archived_post_status_previous_version', $stored, false );
			update_option( 'archived_post_status_version', $this->version, false );
		}
	}

	/**
	 * Whether any pre-0.4.0 archived content exists.
	 *
	 * Runs at most once per site lifetime, while the version option is absent.
	 *
	 * A direct query is required: this runs on plugins_loaded, before the
	 * archived status is registered, and WP_Query drops unregistered statuses
	 * from its WHERE clause. The literal 'archive' — not the filterable slug —
	 * is the correct probe, since pre-0.4.0 releases hardcoded it into every
	 * write regardless of `aps_post_status_slug`.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	private function has_pre_040_content(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time upgrade probe, pre-registration.
		return (bool) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'archive' LIMIT 1"
		);
	}

}
