/**
 * Pins the deactivation warning on the Plugins screen.
 *
 * `assets/js/plugin-screen.js` (enqueued by `Admin\PluginScreen`) confirms
 * before deactivating this plugin whenever archived content exists —
 * deactivating unregisters the archived post status, which would leave that
 * content in limbo. With no archived content, the confirm is skipped
 * entirely and the Deactivate link behaves like any other plugin's. The same
 * confirm also fires on Bulk Actions -> Deactivate when this plugin's row is
 * among the checked items.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { PLUGIN_SLUG } from '../../config/roles';
import { archivePost, deletePosts, seedPost, uniqueTitle } from '../../config/seed';
import { wpCli } from '../../config/wp-cli';

/**
 * Handle of the deactivation-warning script, as WordPress renders its
 * `<script>` id.
 */
const PLUGIN_SCREEN_SCRIPT = 'script#aps-plugin-screen-js';

/**
 * The plugin row on `plugins.php`, keyed by the same `data-slug` the
 * production JS itself queries.
 */
const PLUGIN_ROW = `tr[data-slug="${ PLUGIN_SLUG }"]`;

/**
 * Whether the plugin under test is currently active, per the core plugins
 * REST endpoint.
 *
 * @param requestUtils Admin request utils.
 */
async function isPluginActive( requestUtils: RequestUtils ): Promise< boolean > {
	const plugins = await requestUtils.rest< Array< { plugin: string; status: string } > >( {
		path: '/wp/v2/plugins',
	} );

	const plugin = plugins.find( ( candidate ) =>
		candidate.plugin.startsWith( `${ PLUGIN_SLUG }/` )
	);

	return 'active' === plugin?.status;
}

test.describe( 'plugins screen: deactivation warning', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
		// Safety net regardless of which path a test took above — every
		// other spec depends on the plugin being active.
		await requestUtils.activatePlugin( PLUGIN_SLUG );
	} );

	test( 'the script is enqueued on the Plugins screen', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'plugins.php' );

		await expect( page.locator( PLUGIN_SCREEN_SCRIPT ) ).toHaveCount( 1 );
	} );

	test( 'accepting the warning deactivates the plugin', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Deactivation warning accept' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage( 'plugins.php' );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await Promise.all( [
			page.waitForURL( /deactivate=true/ ),
			page.locator( PLUGIN_ROW ).locator( '.deactivate a' ).click(),
		] );

		expect( await isPluginActive( requestUtils ) ).toBe( false );
	} );

	test( 'dismissing the warning leaves the plugin active', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Deactivation warning dismiss' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage( 'plugins.php' );

		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		// The click handler calls preventDefault() on dismiss, so no
		// navigation follows — nothing here to await beyond the click.
		await page.locator( PLUGIN_ROW ).locator( '.deactivate a' ).click();

		expect( page.url() ).toContain( 'plugins.php' );
		expect( await isPluginActive( requestUtils ) ).toBe( true );
	} );

	test( 'accepting the warning via Bulk Actions -> Deactivate deactivates the plugin', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Deactivation warning bulk accept' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage( 'plugins.php' );

		await page.locator( PLUGIN_ROW ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'deactivate-selected' );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await Promise.all( [
			page.waitForURL( /deactivate-multi=true/ ),
			page.locator( '#doaction' ).click(),
		] );

		expect( await isPluginActive( requestUtils ) ).toBe( false );
	} );

	test( 'dismissing the warning via Bulk Actions -> Deactivate leaves the plugin active', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Deactivation warning bulk dismiss' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage( 'plugins.php' );

		await page.locator( PLUGIN_ROW ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'deactivate-selected' );

		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		// As with the single-link dismiss test above, preventDefault() on
		// dismiss blocks the form submit outright — nothing here to await
		// beyond the click.
		await page.locator( '#doaction' ).click();

		expect( page.url() ).toContain( 'plugins.php' );
		expect( await isPluginActive( requestUtils ) ).toBe( true );
	} );

	test( 'with no archived content, the Deactivate link needs no confirmation', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// This test's premise is a truly clean slate: purge any archived
		// posts left behind by an abnormally terminated earlier run (a
		// cleanup trap cannot fire on SIGKILL).
		await wpCli( [
			'eval',
			'foreach ( get_posts( array( "post_status" => "archive", "post_type" => "any", "numberposts" => -1, "fields" => "ids" ) ) as $stray ) { wp_delete_post( $stray, true ); }',
		] );

		let dialogSeen = false;
		page.on( 'dialog', ( dialog ) => {
			dialogSeen = true;
			dialog.dismiss();
		} );

		await admin.visitAdminPage( 'plugins.php' );

		await Promise.all( [
			page.waitForURL( /deactivate=true/ ),
			page.locator( PLUGIN_ROW ).locator( '.deactivate a' ).click(),
		] );

		expect( dialogSeen ).toBe( false );
		expect( await isPluginActive( requestUtils ) ).toBe( false );
	} );
} );
