/**
 * Pins the 0.4.0 bulk archive / unarchive flow.
 *
 * `Admin\BulkActionHandler` keeps going after a per-item failure and buckets the
 * reason (`locked`, `denied`, `not_found`, `wrong_status`);
 * `Admin\BulkActionResult` puts the counts on the redirect URL, including a
 * single aggregate `skipped`; `Admin\NoticeBuilder` renders one line per bucket
 * plus the success line with its Undo link.
 *
 * The `invalid` bucket was deleted in 0.4.0 — no notice and no query arg may
 * reintroduce it.
 *
 * Counters are read off the redirect `Location` header rather than the address
 * bar; see `applyBulkAction()` for why.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { ARCHIVED_STATUS_SLUG } from '../../config/roles';
import {
	applyBulkAction,
	noticeLocator,
	noticeWith,
	postListQuery,
	selectRows,
} from '../../config/admin';
import {
	FIXTURE_TOGGLES,
	resetFixtures,
	setFixtures,
} from '../../config/fixtures';
import {
	archivePost,
	deletePosts,
	lockPost,
	postState,
	roleUserIds,
	seedPost,
	uniqueTitle,
} from '../../config/seed';

const ARCHIVED_VIEW = postListQuery( { postStatus: ARCHIVED_STATUS_SLUG } );

/**
 * Assert that nothing reintroduces the `invalid` bucket deleted in 0.4.0.
 *
 * @param page   Page under test.
 * @param params Redirect query args returned by the bulk action.
 */
async function expectNoInvalidBucket(
	page: Page,
	params: URLSearchParams
): Promise< void > {
	expect( params.has( 'invalid' ) ).toBe( false );
	await expect( noticeWith( page, /invalid/i ) ).toHaveCount( 0 );
}

test.describe( 'post list: bulk actions', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
		await resetFixtures( requestUtils, [
			FIXTURE_TOGGLES.deniedPostId,
			FIXTURE_TOGGLES.restrictStatuses,
		] );
	} );

	test( 'bulk Archive archives every selected post and offers Undo', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const first = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk archive one' ),
			status: 'publish',
		} );
		const second = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk archive two' ),
			status: 'publish',
		} );
		created.push( first.id, second.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ first.id, second.id ] );
		const params = await applyBulkAction( page, 'archive' );

		await expect(
			noticeWith( page, '2 posts moved to the Archive.' )
		).toBeVisible();
		expect( params.get( 'archived' ) ).toBe( '2' );
		// The form submits ids in list-table order, so compare as a set.
		expect(
			params.get( 'ids' )?.split( ',' ).map( Number ).sort()
		).toEqual( [ first.id, second.id ].sort() );
		expect( params.has( 'skipped' ) ).toBe( false );
		await expectNoInvalidBucket( page, params );

		for ( const id of [ first.id, second.id ] ) {
			expect( ( await postState( requestUtils, id ) ).post_status ).toBe(
				ARCHIVED_STATUS_SLUG
			);
		}

		// Undo restores the status each post held before archiving.
		const undo = noticeLocator( page ).getByRole( 'link', {
			name: 'Undo',
		} );
		await expect( undo ).toBeVisible();

		await Promise.all( [ page.waitForURL( /edit\.php/ ), undo.click() ] );

		await expect(
			noticeWith( page, '2 posts restored from the Archive.' )
		).toBeVisible();

		for ( const id of [ first.id, second.id ] ) {
			expect( ( await postState( requestUtils, id ) ).post_status ).toBe(
				'publish'
			);
		}
	} );

	test( 'bulk Unarchive restores every selected post', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const published = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk unarchive publish' ),
			status: 'publish',
		} );
		const draft = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk unarchive draft' ),
			status: 'draft',
		} );
		created.push( published.id, draft.id );

		await archivePost( requestUtils, published.id );
		await archivePost( requestUtils, draft.id );

		await admin.visitAdminPage( 'edit.php', ARCHIVED_VIEW );
		await selectRows( page, [ published.id, draft.id ] );
		const params = await applyBulkAction( page, 'unarchive' );

		await expect(
			noticeWith( page, '2 posts restored from the Archive.' )
		).toBeVisible();
		expect( params.get( 'unarchived' ) ).toBe( '2' );
		await expectNoInvalidBucket( page, params );

		// Each post returns to its own previous status, not a shared default.
		expect(
			( await postState( requestUtils, published.id ) ).post_status
		).toBe( 'publish' );
		expect(
			( await postState( requestUtils, draft.id ) ).post_status
		).toBe( 'draft' );
	} );

	test( 'a post locked by another user lands in the locked bucket', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const free = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk lock free' ),
			status: 'publish',
		} );
		const locked = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk lock held' ),
			status: 'publish',
		} );
		created.push( free.id, locked.id );

		const users = await roleUserIds( requestUtils );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ free.id, locked.id ] );

		// Lock after selecting: core hides the checkbox on rows it already
		// renders as locked, and "somebody started editing between select and
		// apply" is the race this bucket exists for.
		await lockPost( requestUtils, locked.id, users.aps_editor );

		const params = await applyBulkAction( page, 'archive' );

		await expect(
			noticeWith( page, '1 post moved to the Archive.' )
		).toBeVisible();
		await expect(
			noticeWith( page, '1 post not archived, somebody is editing it.' )
		).toBeVisible();
		expect( params.get( 'archived' ) ).toBe( '1' );
		expect( params.get( 'locked' ) ).toBe( '1' );
		expect( params.get( 'skipped' ) ).toBe( '1' );
		await expectNoInvalidBucket( page, params );

		expect( ( await postState( requestUtils, free.id ) ).post_status ).toBe(
			ARCHIVED_STATUS_SLUG
		);
		expect(
			( await postState( requestUtils, locked.id ) ).post_status
		).toBe( 'publish' );
	} );

	test( 'a post the user cannot archive lands in the denied bucket', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const allowed = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk denied allowed' ),
			status: 'publish',
		} );
		const denied = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk denied blocked' ),
			status: 'publish',
		} );
		created.push( allowed.id, denied.id );

		// Per-post denial: the screen-level gate in BulkActionHandler::handle()
		// still passes, so the batch runs and exactly one item is skipped.
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.deniedPostId ]: denied.id,
		} );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ allowed.id, denied.id ] );
		const params = await applyBulkAction( page, 'archive' );

		await expect(
			noticeWith( page, '1 post moved to the Archive.' )
		).toBeVisible();
		await expect(
			noticeWith(
				page,
				'1 post skipped: you are not allowed to perform this action on it.'
			)
		).toBeVisible();
		expect( params.get( 'archived' ) ).toBe( '1' );
		expect( params.get( 'denied' ) ).toBe( '1' );
		expect( params.get( 'skipped' ) ).toBe( '1' );
		await expectNoInvalidBucket( page, params );

		expect(
			( await postState( requestUtils, allowed.id ) ).post_status
		).toBe( ARCHIVED_STATUS_SLUG );
		expect(
			( await postState( requestUtils, denied.id ) ).post_status
		).toBe( 'publish' );
	} );

	test( 'a post whose status is not archivable lands in the wrong_status bucket', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const published = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk status publish' ),
			status: 'publish',
		} );
		const draft = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk status draft' ),
			status: 'draft',
		} );
		created.push( published.id, draft.id );

		// `aps_archivable_statuses` narrowed to publish only.
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.restrictStatuses ]: true,
		} );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ published.id, draft.id ] );
		const params = await applyBulkAction( page, 'archive' );

		await expect(
			noticeWith( page, '1 post moved to the Archive.' )
		).toBeVisible();
		await expect(
			noticeWith(
				page,
				'1 post skipped: its status is not eligible for this action.'
			)
		).toBeVisible();
		expect( params.get( 'archived' ) ).toBe( '1' );
		expect( params.get( 'wrong_status' ) ).toBe( '1' );
		expect( params.get( 'skipped' ) ).toBe( '1' );
		await expectNoInvalidBucket( page, params );

		expect(
			( await postState( requestUtils, draft.id ) ).post_status
		).toBe( 'draft' );
	} );

	test( 'the aggregate skipped count is the sum of every bucket', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const ok = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk mixed ok' ),
			status: 'publish',
		} );
		const locked = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk mixed locked' ),
			status: 'publish',
		} );
		const denied = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk mixed denied' ),
			status: 'publish',
		} );
		const missing = await seedPost( requestUtils, {
			title: uniqueTitle( 'Bulk mixed missing' ),
			status: 'publish',
		} );
		created.push( ok.id, locked.id, denied.id );

		const users = await roleUserIds( requestUtils );
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.deniedPostId ]: denied.id,
		} );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ ok.id, locked.id, denied.id, missing.id ] );

		// Both mutations land after the checkboxes are ticked: the form still
		// submits the ids, which is exactly the "state changed between select
		// and dispatch" case these buckets exist for.
		await lockPost( requestUtils, locked.id, users.aps_editor );
		await deletePosts( requestUtils, [ missing.id ] );

		const params = await applyBulkAction( page, 'archive' );

		expect( params.get( 'archived' ) ).toBe( '1' );
		expect( params.get( 'locked' ) ).toBe( '1' );
		expect( params.get( 'denied' ) ).toBe( '1' );
		expect( params.get( 'not_found' ) ).toBe( '1' );
		expect( params.get( 'skipped' ) ).toBe( '3' );
		expect( params.get( 'wrong_status' ) ).toBeNull();

		await expect(
			noticeWith( page, '1 post not archived, somebody is editing it.' )
		).toBeVisible();
		await expect(
			noticeWith(
				page,
				'1 post skipped: you are not allowed to perform this action on it.'
			)
		).toBeVisible();
		await expect(
			noticeWith(
				page,
				'1 post skipped: it no longer exists or its type is unsupported.'
			)
		).toBeVisible();
		await expectNoInvalidBucket( page, params );
	} );
} );
