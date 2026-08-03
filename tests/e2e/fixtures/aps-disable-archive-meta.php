<?php
/**
 * E2E mu-plugin fixture: force `aps_enable_archive_meta` to false.
 *
 * TOGGLE: option `aps_test_disable_archive_meta_enabled` (boolean, default OFF).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_disable_archive_meta_enabled": true }
 *   WP-CLI: wp option update aps_test_disable_archive_meta_enabled 1
 *           wp option update aps_test_disable_archive_meta_enabled 0
 *
 * This file is mapped into `wp-content/mu-plugins/` by `.wp-env.json`, so it
 * loads on EVERY request in the test environment — including the
 * `plugins_loaded` request where `Plugin::hookables()` reads
 * `aps_enable_archive_meta`. The filter below is therefore registered
 * unconditionally but is inert until the option is switched on, so specs
 * that do not opt in see stock plugin behaviour (archive meta recorded and
 * `ArchiveMetaListener` registered as usual).
 *
 * Behaviour when enabled: `aps_enable_archive_meta` returns `false`, which
 * (per `Plugin::hookables()`) skips registering `ArchiveMetaListener`
 * entirely for the request. Archiving a post then writes no
 * `_aps_archive_meta_*` postmeta at all, and `ArchiveMeta::for_post()`
 * returns `null` for it — which is exactly the pre-0.4.0-shaped state
 * `UnarchiveOperation::resolve_restore_values()`'s legacy branch exists to
 * handle: unarchiving such a post falls back to `draft`/`closed`/`closed`
 * rather than reading a snapshot that was never written.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Whether the disable-archive-meta fixture is switched on.
 *
 * @return bool
 */
function aps_test_disable_archive_meta_enabled() {
	return (bool) get_option( 'aps_test_disable_archive_meta_enabled', false );
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_disable_archive_meta_enabled',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: force aps_enable_archive_meta to false.',
			)
		);
	}
);

add_filter(
	'aps_enable_archive_meta',
	function ( $enabled ) {
		return aps_test_disable_archive_meta_enabled() ? false : $enabled;
	}
);
