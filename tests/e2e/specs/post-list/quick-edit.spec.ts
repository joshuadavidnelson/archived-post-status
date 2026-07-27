/**
 * The Quick Edit journey against the real inline-edit row.
 *
 * 0.4.0 removed the 0.3.x status-dropdown injection: archiving happens
 * through row/bulk actions and the editor button, never through Quick
 * Edit's status select. Both directions are pinned here — the select on an
 * archivable post offers no archived option, and an archived row offers no
 * Quick Edit at all.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { ARCHIVED_STATUS_SLUG } from '../../config/roles';
import { postListQuery, rowLocator } from '../../config/admin';
import {
	archivePost,
	deletePosts,
	seedPost,
	uniqueTitle,
} from '../../config/seed';

test.describe( 'post list: Quick Edit', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'the status select offers no archived option for an archivable post', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Quick edit archivable' ),
			status: 'publish',
		} );
		created.push( post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );

		const row = rowLocator( page, post.id );
		await expect( row ).toBeVisible();

		// Row actions are revealed on hover; the Quick Edit trigger is the
		// core .editinline button.
		await row.hover();
		await row.locator( 'button.editinline' ).click();

		const inlineRow = page.locator( `#edit-${ post.id }` );
		await expect( inlineRow ).toBeVisible();

		const statusSelect = inlineRow.locator( 'select[name="_status"]' );
		await expect( statusSelect ).toBeVisible();

		const values = await statusSelect
			.locator( 'option' )
			.evaluateAll( ( options ) =>
				options.map( ( option ) => option.getAttribute( 'value' ) )
			);
		expect( values.length ).toBeGreaterThan( 0 );
		expect( values ).not.toContain( ARCHIVED_STATUS_SLUG );

		await inlineRow.locator( 'button.cancel' ).click();
		await expect( inlineRow ).toBeHidden();
	} );

	test( 'an archived row exposes no Quick Edit trigger', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Quick edit archived' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage(
			'edit.php',
			postListQuery( { postStatus: ARCHIVED_STATUS_SLUG } )
		);

		const row = rowLocator( page, post.id );
		await expect( row ).toBeVisible();

		await row.hover();
		await expect( row.locator( 'button.editinline' ) ).toHaveCount( 0 );
	} );
} );
