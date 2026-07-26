/**
 * Pins the read-only editor guard (`Admin\PostEditorGuard`).
 *
 * With read-only mode on (the plugin default), opening an archived post in the
 * editor is stopped with `wp_die()` and an exact message; the post-save
 * round-trip is redirected to the list table instead of re-rendering the
 * editor; and `action=unarchive` is always allowed through so the row-action
 * flow can complete.
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

/**
 * The wp_die() copy, verbatim from `Admin\PostEditorGuard::enforce_read_only()`.
 */
const READ_ONLY_MESSAGE =
	"You can't edit this item because it has been Archived. Please change the post status and try again.";

/**
 * Admin URL for the classic edit screen of a post.
 *
 * @param id     Post id.
 * @param action Value of the `action` query arg.
 */
function editUrl( id: number, action = 'edit' ): string {
	return `/wp-admin/post.php?post=${ id }&action=${ action }`;
}

test.describe( 'editor: read-only guard', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'opening an archived post in the editor is blocked with the exact message', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only blocked' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		const response = await page.goto( editUrl( post.id ) );

		expect( response?.status() ).toBe( 500 );
		await expect( page.locator( 'body' ) ).toContainText(
			READ_ONLY_MESSAGE
		);
	} );

	test( 'a non-archived post still opens in the editor', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only allowed' ),
			status: 'draft',
		} );
		created.push( post.id );

		const response = await page.goto( editUrl( post.id ) );

		// Control: the guard is scoped to archived posts, not to the screen.
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);
	} );

	test( 'the post-save round trip lands on the list table', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only save' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		// action=edit&message=1 is where WordPress sends the browser after a
		// successful save; the guard turns that into a list-table redirect
		// rather than the blocked-editor screen.
		await page.goto( `${ editUrl( post.id ) }&message=1` );

		expect( page.url() ).toContain( '/wp-admin/edit.php' );
		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);
	} );

	test( 'action=unarchive is never blocked by the guard', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only unarchive' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		// Without a nonce the request still gets past the guard — it fails
		// later, on WordPress's own CSRF check, and never shows the read-only
		// message.
		await page.goto( editUrl( post.id, 'unarchive' ) );
		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			ARCHIVED_STATUS_SLUG
		);

		// With the nonce the row action carries, the same action completes.
		await page.goto(
			`/wp-admin/edit.php?${ postListQuery( {
				postStatus: ARCHIVED_STATUS_SLUG,
			} ) }`
		);
		await rowLocator( page, post.id ).hover();
		const href = await rowActionLocator(
			page,
			post.id,
			'unarchive'
		).getAttribute( 'href' );

		await page.goto( href as string );

		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			'publish'
		);
	} );
} );
