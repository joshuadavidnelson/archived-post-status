<?php
/**
 * E2E mu-plugin fixture: publish archived content to the world.
 *
 * TOGGLE: option `aps_test_public_archive_enabled` (boolean, default OFF).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_public_archive_enabled": true }
 *   WP-CLI: wp option update aps_test_public_archive_enabled 1
 *           wp option update aps_test_public_archive_enabled 0
 *
 * This file is mapped into `wp-content/mu-plugins/` by `.wp-env.json`, so it
 * loads on EVERY request in the test environment. The filters are therefore
 * registered unconditionally but are inert until the option is switched on, so
 * specs that do not opt in see stock plugin behaviour.
 *
 * Behaviour when enabled: applies the three-filter recipe published in the
 * plugin's readme for making archived content publicly readable. The
 * corresponding spec pins that recipe end to end, because it is documented
 * behaviour that shipped in 0.3.x and a front-end guard added later can
 * silently override it.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Whether the public-archive fixture is switched on.
 *
 * @return bool
 */
function aps_test_public_archive_enabled() {
	return (bool) get_option( 'aps_test_public_archive_enabled', false );
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_public_archive_enabled',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: make archived content publicly readable.',
			)
		);
	}
);

foreach ( array( 'aps_status_arg_public', 'aps_status_arg_private', 'aps_status_arg_exclude_from_search' ) as $aps_test_status_arg ) {
	add_filter(
		$aps_test_status_arg,
		function ( $value ) use ( $aps_test_status_arg ) {
			if ( ! aps_test_public_archive_enabled() ) {
				return $value;
			}

			// public => true; private and exclude_from_search => false.
			return 'aps_status_arg_public' === $aps_test_status_arg;
		}
	);
}
