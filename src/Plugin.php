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

		// check for upgrades.
		$this->upgrade_check();

		// No load_plugin_textdomain() call: WordPress has loaded translations
		// just in time since 4.6, using the plugin slug and the Domain Path
		// header, so an explicit call adds nothing. Calling it here — on
		// plugins_loaded, before init — is also what trips WP 6.7+'s
		// "translation loading was triggered too early" notice.

		// Load all hookables and register their hooks.
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
	 * This is the authoritative list of everything the plugin registers
	 * with WordPress. To add a new feature, add a new line here.
	 *
	 * `PostEditor` and `Notices` live in the `is_admin()` branch alongside
	 * `PostList` / `ArchiveColumn` / `PluginScreen`: every hook each of them
	 * registers — `admin_enqueue_scripts`, `post_submitbox_start` (PostEditor),
	 * `admin_notices` (Notices) — only ever fires on an actual wp-admin page
	 * load, never on the front end or in a WP-CLI request, so instantiating
	 * and hooking them on every request was pure overhead outside admin.
	 * `PostEditorGuard` stays unconditional: its `map_meta_cap` filter runs
	 * on every capability check anywhere (front end, REST, CLI), not just
	 * inside wp-admin.
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
	 * Atomicity invariant (0.4.0):
	 *   Each disjoint state — fresh install, in-place upgrade, no-op —
	 *   executes ONE branch with a self-contained write sequence. The
	 *   previous-version marker is only written as part of the upgrade
	 *   branch, never as a standalone write that could be observed without
	 *   the matching version bump. A reader who sees
	 *   `archived_post_status_version === $this->version` is guaranteed
	 *   that `archived_post_status_previous_version` already reflects the
	 *   upgrade source (or never existed, on a first install). No
	 *   "half-upgraded" observable state.
	 *
	 *   `add_option` is used for the first-install path so a concurrent
	 *   request that raced ahead and already created the option does not
	 *   get overwritten — `add_option` no-ops if the option already exists,
	 *   while `update_option` would clobber. The upgrade branch uses
	 *   `update_option` because by definition the option exists.
	 */
	private function upgrade_check(): void {
		if ( ! is_admin() ) {
			return;
		}

		$stored = get_option( 'archived_post_status_version', false );

		// Option absent: either a fresh install or an upgrade from a
		// pre-0.4.0 release (which never wrote any options). Archived
		// content is the only evidence that distinguishes the two — record
		// the marker while it is still reliable, since a fresh install can
		// accumulate archived posts of its own later. add_option is the
		// race-safe creator (no-op if a concurrent request beat us to it).
		if ( false === $stored ) {
			if ( $this->has_pre_040_content() ) {
				update_option( 'archived_post_status_previous_version', 'pre-0.4.0', false );
			}

			add_option( 'archived_post_status_version', $this->version, '', false );
			return;
		}

		// Upgrade: stored version differs from current. Record the previous
		// version, then swap to the new one. Both writes belong to the same
		// logical transition.
		if ( $stored !== $this->version ) {
			update_option( 'archived_post_status_previous_version', $stored, false );
			update_option( 'archived_post_status_version', $this->version, false );
		}

		// No-op: stored version matches current. No writes required.
	}

	/**
	 * Whether any pre-0.4.0 archived content exists.
	 *
	 * Runs at most once per site lifetime (only while the version option is
	 * absent). A direct query is required: this runs on plugins_loaded,
	 * before the archived status is registered, and WP_Query drops
	 * unregistered statuses from its WHERE clause. The literal 'archive'
	 * string — not the filterable slug — is the correct probe: pre-0.4.0
	 * releases hardcoded it into every write regardless of the
	 * aps_post_status_slug filter.
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
