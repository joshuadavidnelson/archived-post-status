/**
 * Pins the pre-0.4.0 upgrade marker written by `Plugin::upgrade_check()`.
 *
 * On the first admin page load where the `archived_post_status_version`
 * option does not exist, the plugin distinguishes a genuinely fresh install
 * from an upgrade out of a release that predates the option entirely (every
 * pre-0.4.0 release never wrote it). `Plugin::has_pre_040_content()` probes
 * for the literal `archive` post_status — the only signal that survives from
 * a pre-0.4.0 site, since that status was hardcoded into every write
 * regardless of the `aps_post_status_slug` filter. When that probe finds
 * content, the plugin records `archived_post_status_previous_version =
 * 'pre-0.4.0'` alongside the current version; with no such content, only the
 * version option is written.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { wpCli, wpCliOk } from '../../config/wp-cli';

/**
 * Option names `Plugin::upgrade_check()` reads and writes.
 *
 * @see src/Plugin.php
 */
const VERSION_OPTION = 'archived_post_status_version';
const PREVIOUS_VERSION_OPTION = 'archived_post_status_previous_version';

/**
 * Shape returned by {@link readUpgradeMarkerState}.
 */
interface UpgradeMarkerState {
	version: string;
	stored_version: string | false;
	previous_version: string | false;
}

/**
 * Delete both upgrade-marker options via a raw `delete_option()` call rather
 * than `wp option delete`, which exits non-zero when the option is already
 * absent — this needs to be a clean, always-succeeds reset.
 */
async function resetUpgradeMarkers(): Promise< void > {
	await wpCliOk( [
		'eval',
		`delete_option( '${ VERSION_OPTION }' ); delete_option( '${ PREVIOUS_VERSION_OPTION }' );`,
	] );
}

/**
 * Purge every post carrying the plugin's archived status, including ones
 * created directly at the database layer (raw `post_status=archive`) that
 * the REST-based cleanup helpers in `config/seed.ts` cannot see, so
 * `has_pre_040_content()`'s literal-status probe starts from a known-empty
 * slate.
 */
async function purgeArchivedPosts(): Promise< void > {
	await wpCli( [
		'eval',
		'foreach ( get_posts( array( "post_status" => "archive", "post_type" => "any", "numberposts" => -1, "fields" => "ids" ) ) as $stray ) { wp_delete_post( $stray, true ); }',
	] );
}

/**
 * Read the plugin's version constant plus both upgrade-marker options in one
 * round trip.
 */
async function readUpgradeMarkerState(): Promise< UpgradeMarkerState > {
	const output = await wpCliOk( [
		'eval',
		`echo json_encode( array(
			'version'          => ARCHIVED_POST_STATUS_VERSION,
			'stored_version'   => get_option( '${ VERSION_OPTION }', false ),
			'previous_version' => get_option( '${ PREVIOUS_VERSION_OPTION }', false )
		) );`,
	] );

	return JSON.parse( output );
}

test.describe( 'plugin: pre-0.4.0 upgrade marker', () => {
	test.beforeEach( async () => {
		await resetUpgradeMarkers();
		await purgeArchivedPosts();
	} );

	test.afterEach( async () => {
		await purgeArchivedPosts();
	} );

	test( 'raw "archive" content predating the version option records the pre-0.4.0 marker on the next admin load', async ( {
		admin,
	} ) => {
		// Bypass the plugin's own API entirely: a bare `wp post create`
		// writes the literal status straight to wp_posts with no
		// aps_archive_post() call and therefore no archive meta — exactly
		// the shape pre-0.4.0 archived content takes, since archive meta
		// did not exist before 0.4.0 either.
		const legacyPostId = Number(
			await wpCliOk( [
				'post',
				'create',
				'--post_status=archive',
				'--post_title=Pre-0.4.0 legacy content',
				'--porcelain',
			] )
		);
		expect( legacyPostId ).toBeGreaterThan( 0 );

		// Any admin page load reaches Plugin::run() -> upgrade_check().
		await admin.visitAdminPage( 'edit.php' );

		const state = await readUpgradeMarkerState();
		expect( state.stored_version ).toBe( state.version );
		expect( state.previous_version ).toBe( 'pre-0.4.0' );
	} );

	test( 'with no archived content, only the version option is written', async ( {
		admin,
	} ) => {
		await admin.visitAdminPage( 'edit.php' );

		const state = await readUpgradeMarkerState();
		expect( state.stored_version ).toBe( state.version );
		expect( state.previous_version ).toBe( false );
	} );
} );
