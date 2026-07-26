/**
 * Pins the inline row actions added by `Admin\RowActionPolicy`.
 *
 * Archivable rows offer Archive, archived rows offer Unarchive, each action
 * performs the transition and lands back on the list table, and archived rows
 * lose the row actions that do not apply to read-only content.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { ARCHIVED_STATUS_SLUG } from '../../config/roles';
import {
	noticeWith,
	postListQuery,
	rowActionLocator,
	rowLocator,
} from '../../config/admin';
import {
	archivePost,
	deletePosts,
	postState,
	seedPost,
	uniqueTitle,
} from '../../config/seed';
import { pluginStrings } from '../../config/strings';

/**
 * Reveal a row's action links, which the list table keeps off-screen until the
 * row is hovered, then click one.
 *
 * @param page   Page under test.
 * @param id     Post id.
 * @param action Row action key.
 */
async function clickRowAction(
	page: import('@playwright/test').Page,
	id: number,
	action: string
): Promise< void > {
	await rowLocator( page, id ).hover();
	await Promise.all( [
		page.waitForURL( /edit\.php/ ),
		rowActionLocator( page, id, action ).click(),
	] );
}

test.describe( 'post list: row actions', () => {
	const created: number[] = [];
	let strings: Awaited< ReturnType< typeof pluginStrings > >;

	test.beforeAll( async ( { requestUtils } ) => {
		strings = await pluginStrings( requestUtils );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'the Archive row action archives the post and returns to the list', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Row archive' );
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
		} );
		created.push( post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );

		const action = rowActionLocator( page, post.id, 'archive' );
		await expect( action ).toHaveText( strings.archive_row_action );

		await clickRowAction( page, post.id, 'archive' );

		await expect(
			noticeWith( page, strings.archived_notice_one )
		).toBeVisible();
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			ARCHIVED_STATUS_SLUG
		);
	} );

	test( 'the Unarchive row action restores the previous status', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Row unarchive' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage(
			'edit.php',
			postListQuery( { postStatus: ARCHIVED_STATUS_SLUG } )
		);

		const action = rowActionLocator( page, post.id, 'unarchive' );
		await expect( action ).toHaveText( strings.unarchive_row_action );

		await clickRowAction( page, post.id, 'unarchive' );

		await expect(
			noticeWith( page, strings.unarchived_notice_one )
		).toBeVisible();
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			'publish'
		);
	} );

	test( 'an archived row offers Unarchive but not Archive, Edit or Quick Edit', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Row actions removed' ),
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

		await expect(
			rowActionLocator( page, post.id, 'unarchive' )
		).toHaveCount( 1 );
		await expect(
			rowActionLocator( page, post.id, 'archive' )
		).toHaveCount( 0 );
		await expect( row.locator( '.row-actions .edit' ) ).toHaveCount( 0 );
		await expect( row.locator( '.row-actions .inline' ) ).toHaveCount( 0 );
	} );

	test( 'an archivable row offers Archive but not Unarchive', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Row draft actions' ),
			status: 'draft',
		} );
		created.push( post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );

		await expect(
			rowActionLocator( page, post.id, 'archive' )
		).toHaveCount( 1 );
		await expect(
			rowActionLocator( page, post.id, 'unarchive' )
		).toHaveCount( 0 );
		// Editing an unarchived post is untouched by the plugin.
		await expect(
			rowLocator( page, post.id ).locator( '.row-actions .edit' )
		).toHaveCount( 1 );
	} );

	test( 'archived posts are hidden from the All view', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Hidden from all' ),
			status: 'publish',
		} );
		created.push( post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await expect( rowLocator( page, post.id ) ).toBeVisible();

		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await expect( rowLocator( page, post.id ) ).toHaveCount( 0 );
	} );
} );
