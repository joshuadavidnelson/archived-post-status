/**
 * Toggles for the mu-plugin fixtures mapped into `wp-content/mu-plugins/`.
 *
 * Every fixture registers its hooks unconditionally and stays inert until its
 * option is switched on, so a spec that does not opt in sees stock plugin
 * behaviour. Each toggle is a registered setting with `show_in_rest`, so it can
 * be flipped over `PUT /wp/v2/settings` — one request instead of a WP-CLI round
 * trip.
 *
 * Only ever flip these on the tests instance (:8889); the same files are mapped
 * into the :8888 dev site and a toggle left on there survives until it is
 * deleted. `resetFixtures()` in an `afterEach`/`afterAll` is not optional.
 *
 * @see tests/e2e/fixtures/
 */

/**
 * External dependencies
 */
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Option name of each fixture toggle, keyed by a readable alias.
 */
export const FIXTURE_TOGGLES = {
	/** `aps_default_archive|unarchive_capability` -> `manage_options`. */
	capFilter: 'aps_test_cap_filter_enabled',
	/** `aps_archivable_statuses` -> `['publish']`. */
	restrictStatuses: 'aps_test_restrict_statuses_enabled',
	/** Capture the `aps_archived_post` / `aps_unarchived_post` args. */
	hooks: 'aps_test_hooks_enabled',
	/** `aps_title_label` / `_before` / `aps_title_separator` overrides. */
	titleFilters: 'aps_test_title_filters_enabled',
	/** Force the classic editor and trip `aps_is_classic_editor`. */
	classicEditor: 'aps_test_classic_editor_enabled',
	/** Deny the archive/unarchive capability for one post id (0 = off). */
	deniedPostId: 'aps_test_denied_post_id',
} as const;

export type FixtureToggle =
	( typeof FIXTURE_TOGGLES )[ keyof typeof FIXTURE_TOGGLES ];

/**
 * Value each toggle returns to when a spec is done with it.
 */
const TOGGLE_OFF: Record< FixtureToggle, boolean | number > = {
	[ FIXTURE_TOGGLES.capFilter ]: false,
	[ FIXTURE_TOGGLES.restrictStatuses ]: false,
	[ FIXTURE_TOGGLES.hooks ]: false,
	[ FIXTURE_TOGGLES.titleFilters ]: false,
	[ FIXTURE_TOGGLES.classicEditor ]: false,
	[ FIXTURE_TOGGLES.deniedPostId ]: 0,
};

/**
 * Label the title fixture writes when it is switched on.
 *
 * @see tests/e2e/fixtures/aps-title-filters.php — `APS_TEST_TITLE_LABEL`.
 */
export const FIXTURE_TITLE_LABEL = 'Retired';

/**
 * Separator the title fixture writes when it is switched on.
 *
 * @see tests/e2e/fixtures/aps-title-filters.php — `APS_TEST_TITLE_SEPARATOR`.
 */
export const FIXTURE_TITLE_SEPARATOR = ' :: ';

/**
 * Switch one or more fixture toggles.
 *
 * @param requestUtils Admin request utils.
 * @param toggles      Map of option name to value.
 */
export async function setFixtures(
	requestUtils: RequestUtils,
	toggles: Partial< Record< FixtureToggle, boolean | number > >
): Promise< void > {
	await requestUtils.rest( {
		method: 'PUT',
		path: '/wp/v2/settings',
		data: toggles,
	} );
}

/**
 * Switch the named toggles back off.
 *
 * @param requestUtils Admin request utils.
 * @param toggles      Toggles to reset. Defaults to every known toggle.
 */
export async function resetFixtures(
	requestUtils: RequestUtils,
	toggles: FixtureToggle[] = Object.values( FIXTURE_TOGGLES )
): Promise< void > {
	const payload: Partial< Record< FixtureToggle, boolean | number > > = {};

	for ( const toggle of toggles ) {
		payload[ toggle ] = TOGGLE_OFF[ toggle ];
	}

	await setFixtures( requestUtils, payload );
}
