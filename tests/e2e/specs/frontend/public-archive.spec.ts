/**
 * Pins the published recipe for making archived content publicly readable.
 *
 * The plugin's readme documents three filters for this:
 *
 *   add_filter( 'aps_status_arg_public', '__return_true' );
 *   add_filter( 'aps_status_arg_private', '__return_false' );
 *   add_filter( 'aps_status_arg_exclude_from_search', '__return_false' );
 *
 * That worked in 0.3.x, where front-end visibility came entirely from the
 * registered status arguments. 0.4.0 added `Frontend\AccessGuard`, which
 * independently re-checks the view capability on `template_redirect` — and
 * because a logged-out visitor holds no capabilities at all, it silently
 * overrode the recipe with a 404 that no filter could lift. The guard now
 * stands down when the status is registered public.
 *
 * Both directions are asserted: with the recipe off, anonymous visitors are
 * still refused (the guard has not been defanged); with it on, the documented
 * behaviour works.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	FIXTURE_TOGGLES,
	resetFixtures,
	setFixtures,
} from '../../config/fixtures';
import {
	archivePost,
	deletePosts,
	seedPost,
	uniqueTitle,
} from '../../config/seed';

test.describe( 'frontend: publicly readable archived content', () => {
	// Anonymous: no cookies, no stored authentication.
	test.use( { storageState: { cookies: [], origins: [] } } );

	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await resetFixtures( requestUtils );
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'anonymous visitors are refused by default', async ( {
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Public archive default' );
		const post = await seedPost( requestUtils, { title, status: 'publish' } );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		const response = await page.goto( archived.link );

		expect( response?.status() ).toBe( 404 );
		await expect( page.getByText( title ) ).toHaveCount( 0 );
	} );

	test( 'the documented status-argument recipe makes them readable', async ( {
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Public archive recipe' );
		const post = await seedPost( requestUtils, { title, status: 'publish' } );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );

		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.publicArchive ]: true,
		} );

		const response = await page.goto( archived.link );

		expect( response?.status() ).toBe( 200 );
		await expect( page.getByText( title ).first() ).toBeVisible();
	} );
} );
