/**
 * Pins the 0.3.12 front-end privacy rule enforced by `Frontend\AccessGuard`.
 *
 * A visitor who cannot view archived content gets a hard 404 on a singular
 * archived post — the response status, not merely different markup — while a
 * privileged user still gets the post.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	archivePost,
	deletePosts,
	seedPost,
	uniqueTitle,
} from '../../config/seed';

const POST_TITLE = 'h1.wp-block-post-title';

/**
 * Seed a published post, archive it, and hand back its permalink.
 *
 * @param requestUtils Admin request utils.
 * @param created      Id sink for teardown.
 */
async function seedArchived(
	requestUtils: Parameters< typeof archivePost >[ 0 ],
	created: number[]
): Promise< { title: string; link: string; id: number } > {
	const title = uniqueTitle( 'Guarded' );
	const post = await seedPost( requestUtils, { title, status: 'publish' } );
	created.push( post.id );

	const archived = await archivePost( requestUtils, post.id );

	return { title, link: archived.link, id: post.id };
}

test.describe( 'frontend: archived access guard (privileged)', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'an administrator can view a singular archived post', async ( {
		page,
		requestUtils,
	} ) => {
		const { title, link } = await seedArchived( requestUtils, created );

		const response = await page.goto( link );

		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( POST_TITLE ) ).toContainText( title );
	} );
} );

test.describe( 'frontend: archived access guard (logged out)', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'an anonymous visitor gets a 404 response for an archived post', async ( {
		page,
		requestUtils,
	} ) => {
		const { title, link } = await seedArchived( requestUtils, created );

		const response = await page.goto( link );

		expect( response?.status() ).toBe( 404 );
		// The status alone could still be paired with leaked content.
		await expect( page.getByText( title ) ).toHaveCount( 0 );
	} );

	test( 'the same URL is a 200 while the post is still published', async ( {
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Guard control' );
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
		} );
		created.push( post.id );

		// Control: proves the 404 above comes from the archive transition and
		// not from a bad permalink or an unpublished seed.
		const response = await page.goto( post.link );

		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( POST_TITLE ) ).toContainText( title );
	} );
} );
