/**
 * Pins that an anonymous visitor's `/feed/` omits archived posts. Exercises
 * the archived status's `public => false` registration in
 * `PostStatus::status_args()`.
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

test.describe( 'frontend: archived posts are excluded from the site feed', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'the feed lists the published post but not the archived one', async ( {
		page,
		requestUtils,
	} ) => {
		const visibleTitle = uniqueTitle( 'Feed listed' );
		const hiddenTitle = uniqueTitle( 'Feed archived' );

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

		const response = await page.goto( '/feed/' );
		const body = ( await response?.text() ) ?? '';

		// The control post proves the feed body was actually fetched, so the
		// absence assertion below cannot pass vacuously.
		expect( body ).toContain( visibleTitle );
		expect( body ).not.toContain( hiddenTitle );
	} );
} );
