/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	ARCHIVED_STATUS_LABEL,
	ARCHIVED_STATUS_SLUG,
	ROLE_USERS,
	storageStatePath,
} from '../config/roles';

/**
 * Plugin file as WordPress reports it on the plugins screen.
 */
const PLUGIN_FILE = 'archived-post-status/archived-post-status.php';

test.describe( 'e2e harness smoke', () => {
	test( 'the plugin is active on the plugins screen', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'plugins.php' );

		const row = page.locator( `tr[data-plugin="${ PLUGIN_FILE }"]` );

		await expect( row ).toBeVisible();
		await expect( row ).toHaveClass( /(^|\s)active(\s|$)/ );
	} );

	test( 'the archived post status is registered', async ( {
		requestUtils,
	} ) => {
		const status = await requestUtils.rest( {
			path: `/wp/v2/statuses/${ ARCHIVED_STATUS_SLUG }`,
		} );

		expect( status.slug ).toBe( ARCHIVED_STATUS_SLUG );
		expect( status.name ).toBe( ARCHIVED_STATUS_LABEL );
	} );
} );

test.describe( 'e2e harness smoke — per-role storage state', () => {
	test.use( { storageState: storageStatePath( 'editor' ) } );

	test( 'the stored editor state signs in as the editor user', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'profile.php' );

		await expect( page.locator( '#user_login' ) ).toHaveValue(
			ROLE_USERS.editor.username
		);
	} );
} );
