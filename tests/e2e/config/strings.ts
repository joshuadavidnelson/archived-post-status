/**
 * User-facing strings a spec asserts against, fetched from the plugin's own
 * source instead of duplicated as literals.
 *
 * The `aps-test/v1/strings` route (see `tests/e2e/fixtures/aps-test-api.php`)
 * returns copy read straight from the plugin classes that own it, so a wording
 * change can't silently desync the suite. Fetched once per run and memoized —
 * the strings are static for the duration of the suite, so there is no reason
 * to pay a request per test.
 *
 * @see tests/e2e/fixtures/aps-test-api.php
 */

/**
 * External dependencies
 */
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Shape of the `aps-test/v1/strings` response.
 */
export interface PluginStrings {
	read_only_message: string;
	archive_row_action: string;
	unarchive_row_action: string;
	archived_column_label: string;
	system_attribution: string;
	unknown_attribution: string;
	attribution_template: string;
	undo_label: string;
	archived_notice_one: string;
	archived_notice_many: string;
	unarchived_notice_one: string;
	unarchived_notice_many: string;
	locked_notice_one: string;
	denied_notice_one: string;
	not_found_notice_one: string;
	wrong_status_notice_one: string;
}

let cached: PluginStrings | null = null;

/**
 * Fetch the plugin's user-facing strings, memoized across the suite.
 *
 * @param requestUtils Admin request utils.
 */
export async function pluginStrings(
	requestUtils: RequestUtils
): Promise< PluginStrings > {
	if ( ! cached ) {
		const fetched = await requestUtils.rest< PluginStrings >( {
			path: '/aps-test/v1/strings',
		} );

		// Guard the single choke point every spec reads through: an empty
		// value would make a toContainText()-style assertion vacuously true,
		// so a broken route must throw here rather than pass silently.
		for ( const [ key, value ] of Object.entries( fetched ) ) {
			if ( ! value ) {
				throw new Error(
					`aps-test/v1/strings returned an empty value for "${ key }".`
				);
			}
		}

		cached = fetched;
	}

	return cached;
}
