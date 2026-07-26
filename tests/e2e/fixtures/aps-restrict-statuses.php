<?php
/**
 * E2E mu-plugin fixture: restrict archivable statuses to `publish` only.
 *
 * TOGGLE: option `aps_test_restrict_statuses_enabled` (boolean, default OFF).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_restrict_statuses_enabled": true }
 *   WP-CLI: wp option update aps_test_restrict_statuses_enabled 1
 *           wp option update aps_test_restrict_statuses_enabled 0
 *
 * This file is mapped into `wp-content/mu-plugins/` by `.wp-env.json`, so it
 * loads on EVERY request in the test environment. The filter is therefore
 * registered unconditionally but is inert until the option is switched on, so
 * specs that do not opt in see stock plugin behaviour.
 *
 * Behaviour when enabled: `aps_archivable_statuses` is narrowed to
 * `array( 'publish' )`, so archiving a non-published post is rejected.
 *
 * Ported from `tests/manual/fixtures/aps-restrict-statuses.php` (E2E scenario
 * A1); the original is kept there untouched for manual runs.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Whether the restricted-statuses fixture is switched on.
 *
 * @return bool
 */
function aps_test_restrict_statuses_enabled() {
	return (bool) get_option( 'aps_test_restrict_statuses_enabled', false );
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_restrict_statuses_enabled',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: restrict aps_archivable_statuses to publish only.',
			)
		);
	}
);

add_filter(
	'aps_archivable_statuses',
	function ( $statuses ) {
		if ( ! aps_test_restrict_statuses_enabled() ) {
			return $statuses;
		}

		return array( 'publish' );
	}
);
