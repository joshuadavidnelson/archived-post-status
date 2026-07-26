<?php
/**
 * E2E mu-plugin fixture: require `manage_options` to archive AND unarchive.
 *
 * TOGGLE: option `aps_test_cap_filter_enabled` (boolean, default OFF).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_cap_filter_enabled": true }
 *   WP-CLI: wp option update aps_test_cap_filter_enabled 1
 *           wp option update aps_test_cap_filter_enabled 0
 *
 * This file is mapped into `wp-content/mu-plugins/` by `.wp-env.json`, so it
 * loads on EVERY request in the test environment. The filters are therefore
 * registered unconditionally but are inert until the option is switched on, so
 * specs that do not opt in see stock plugin capabilities.
 *
 * Behaviour when enabled: both `aps_default_archive_capability` and
 * `aps_default_unarchive_capability` return `manage_options`, which lets specs
 * verify that
 *
 *   - anonymous WP-CLI (no `--user`) bypasses the cap check (WP-CLI elevated
 *     context, matching `wp post update|delete|create`);
 *   - authenticated WP-CLI (`--user=<editor>`) is denied for lack of
 *     `manage_options`;
 *   - the admin row-action / bulk-action UI path stays correct under the filter.
 *
 * Ported from `tests/manual/fixtures/aps-cap-filter.php` (E2E scenarios
 * A6/A7); the original is kept there untouched for manual runs.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Whether the capability-filter fixture is switched on.
 *
 * @return bool
 */
function aps_test_cap_filter_enabled() {
	return (bool) get_option( 'aps_test_cap_filter_enabled', false );
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_cap_filter_enabled',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: require manage_options to archive and unarchive.',
			)
		);
	}
);

add_filter(
	'aps_default_archive_capability',
	function ( $capability ) {
		return aps_test_cap_filter_enabled() ? 'manage_options' : $capability;
	}
);

add_filter(
	'aps_default_unarchive_capability',
	function ( $capability ) {
		return aps_test_cap_filter_enabled() ? 'manage_options' : $capability;
	}
);
