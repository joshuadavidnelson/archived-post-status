/**
 * Pins the 0.3.12 front-end title contract and the listing exclusion.
 *
 * - `Frontend\ArchiveTitle` prefixes archived titles with the archived label and
 *   a separator, and both are filterable (`aps_title_label`,
 *   `aps_title_label_before`, `aps_title_separator`).
 * - Archived posts do not leak into the blog listing for visitors who cannot
 *   view archived content.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { POST_TITLE } from '../../config/admin';
import {
	FIXTURE_TITLE_LABEL,
	FIXTURE_TITLE_SEPARATOR,
	FIXTURE_TOGGLES,
	resetFixtures,
	setFixtures,
} from '../../config/fixtures';
import {
	ARCHIVED_STATUS_LABEL,
	ARCHIVED_STATUS_SLUG,
} from '../../config/roles';
import {
	archivePost,
	deletePosts,
	seedPost,
	uniqueTitle,
} from '../../config/seed';

test.describe( 'frontend: archived title label', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
		await resetFixtures( requestUtils, [ FIXTURE_TOGGLES.titleFilters ] );
	} );

	test( 'a singular archived post is titled "Archived: <title>"', async ( {
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Title prefix' );
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		expect( archived.post_status ).toBe( ARCHIVED_STATUS_SLUG );

		const response = await page.goto( archived.link );
		expect( response?.status() ).toBe( 200 );

		await expect( page.locator( POST_TITLE ) ).toHaveText(
			`${ ARCHIVED_STATUS_LABEL }: ${ title }`
		);
	} );

	test( 'a published post is not prefixed', async ( {
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Unprefixed' );
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
		} );
		created.push( post.id );

		await page.goto( post.link );

		await expect( page.locator( POST_TITLE ) ).toHaveText( title );
	} );

	test( 'the label, position and separator filters compose the title', async ( {
		page,
		requestUtils,
	} ) => {
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.titleFilters ]: true,
		} );

		const title = uniqueTitle( 'Filtered title' );
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		await page.goto( archived.link );

		// `aps_title_label_before` is false, so ArchiveTitle appends the
		// separator and label in that order.
		await expect( page.locator( POST_TITLE ) ).toHaveText(
			`${ title }${ FIXTURE_TITLE_SEPARATOR }${ FIXTURE_TITLE_LABEL }`
		);
	} );
} );

test.describe( 'frontend: archived posts are excluded from the blog listing', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'an anonymous visitor sees the published post but not the archived one', async ( {
		page,
		requestUtils,
	} ) => {
		const visibleTitle = uniqueTitle( 'Listed post' );
		const hiddenTitle = uniqueTitle( 'Archived post' );

		const visible = await seedPost( requestUtils, {
			title: visibleTitle,
			status: 'publish',
		} );
		const hidden = await seedPost( requestUtils, {
			title: hiddenTitle,
			status: 'publish',
		} );
		created.push( visible.id, hidden.id );

		await archivePost( requestUtils, hidden.id );

		await page.goto( '/' );

		// The control post proves the listing rendered at all, so the absence
		// assertion below cannot pass vacuously.
		await expect(
			page.getByRole( 'link', { name: visibleTitle } )
		).toBeVisible();
		await expect( page.getByText( hiddenTitle ) ).toHaveCount( 0 );
	} );
} );
