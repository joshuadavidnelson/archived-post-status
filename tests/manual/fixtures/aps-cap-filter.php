<?php
/**
 * Manual-test mu-plugin: require `manage_options` for archive AND unarchive.
 *
 * Used in the 0.4.0 E2E scenarios A6 / A7 to verify that:
 *
 *   - Anonymous WP-CLI (no --user) bypasses the cap check (WP-CLI elevated
 *     context, matching `wp post update|delete|create`).
 *   - Authenticated WP-CLI (--user=editor1) is denied because editor1 lacks
 *     manage_options.
 *   - The admin row-action / bulk-action UI path remains correct under the
 *     filter.
 *
 * Drop into `/wp-content/mu-plugins/` for the duration of the test, then
 * remove. Preserved here so the test can be re-run.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

add_filter(
	'aps_default_archive_capability',
	function () {
		return 'manage_options';
	}
);

add_filter(
	'aps_default_unarchive_capability',
	function () {
		return 'manage_options';
	}
);
