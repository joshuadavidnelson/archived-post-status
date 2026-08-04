/**
 * Pins that an anonymous visitor's front-end search results omit archived
 * posts. Exercises the archived status's `public => false` registration in
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

test.describe( 'frontend: archived posts are excluded from search results', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'an anonymous visitor finds the published post but not the archived one', async ( {
		page,
		requestUtils,
	} ) => {
		const term = uniqueTitle( 'Searchable' );
		const visibleTitle = `${ term } published`;
		const hiddenTitle = `${ term } archived`;

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

		await page.goto( `/?s=${ encodeURIComponent( term ) }` );

		// The control post proves the search actually ran against this term,
		// so the absence assertion below cannot pass vacuously. Scoped to the
		// level-2 result heading: the theme's "More posts" widget renders
		// unrelated recent posts as h3s on the same page.
		await expect(
			page
				.getByRole( 'heading', { level: 2, name: visibleTitle } )
				.getByRole( 'link' )
		).toBeVisible();
		await expect( page.getByText( hiddenTitle ) ).toHaveCount( 0 );
	} );
} );
