<?php
/**
 * E2E mu-plugin fixture: override the archived title label, position and separator.
 *
 * TOGGLE: option `aps_test_title_filters_enabled` (boolean, default OFF).
 *
 *   REST:   PUT /wp-json/wp/v2/settings { "aps_test_title_filters_enabled": true }
 *   WP-CLI: wp option update aps_test_title_filters_enabled 1
 *           wp option update aps_test_title_filters_enabled 0
 *
 * This file is mapped into `wp-content/mu-plugins/` by `.wp-env.json`, so it
 * loads on EVERY request in the test environment. The filters are therefore
 * registered unconditionally but return the incoming value until the option is
 * switched on, so specs that do not opt in see the stock `Archived: Title`
 * output.
 *
 * Behaviour when enabled ({@see \ArchivedPostStatus\Frontend\ArchiveTitle}):
 *   - `aps_title_label`        -> 'Retired'
 *   - `aps_title_label_before` -> false  (label moves after the title)
 *   - `aps_title_separator`    -> ' :: ' (replaces the ' - ' after-position default)
 *
 * so an archived post titled "Foo" renders as "Foo :: Retired". The three
 * filters are exercised together because the after-position branch reverses the
 * label/separator pair — asserting the composed string pins the ordering as well
 * as the individual values.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Whether the title-filter fixture is switched on.
 *
 * @return bool
 */
function aps_test_title_filters_enabled() {
	return (bool) get_option( 'aps_test_title_filters_enabled', false );
}

/**
 * Label used by the fixture when enabled.
 */
define( 'APS_TEST_TITLE_LABEL', 'Retired' );

/**
 * Separator used by the fixture when enabled.
 */
define( 'APS_TEST_TITLE_SEPARATOR', ' :: ' );

add_action(
	'init',
	function () {
		register_setting(
			'options',
			'aps_test_title_filters_enabled',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
				'description'  => 'E2E fixture: override aps_title_label / _before / aps_title_separator.',
			)
		);
	}
);

add_filter(
	'aps_title_label',
	function ( $label ) {
		return aps_test_title_filters_enabled() ? APS_TEST_TITLE_LABEL : $label;
	}
);

add_filter(
	'aps_title_label_before',
	function ( $before ) {
		return aps_test_title_filters_enabled() ? false : $before;
	}
);

add_filter(
	'aps_title_separator',
	function ( $separator ) {
		return aps_test_title_filters_enabled() ? APS_TEST_TITLE_SEPARATOR : $separator;
	}
);
