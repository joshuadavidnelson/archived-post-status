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
	POST_TYPES,
	postState,
	roleUserIds,
	seedPost,
	uniqueTitle,
} from '../../config/seed';
import type { PostTypeUnderTest } from '../../config/seed';
import { pluginStrings } from '../../config/strings';

/**
 * A post created during a test, tagged with the REST base `deletePosts()`
 * needs to remove it again. The two tests that loop {@link POST_TYPES} seed
 * more than one type into the same `created` array, so a single hardcoded
 * base would silently fail to delete anything but `post`.
 */
interface CreatedPost {
	id: number;
	type: PostTypeUnderTest[ 'restBase' ];
}

/**
 * Delete every tracked post, grouped by REST base.
 *
 * @param requestUtils Admin request utils.
 * @param posts        Posts pushed onto the describe block's `created` array.
 */
async function deleteCreated(
	requestUtils: Parameters< typeof deletePosts >[ 0 ],
	posts: CreatedPost[]
): Promise< void > {
	await Promise.all(
		POST_TYPES.map( ( { restBase } ) =>
			deletePosts(
				requestUtils,
				posts
					.filter( ( post ) => post.type === restBase )
					.map( ( post ) => post.id ),
				restBase
			)
		)
	);
}

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
	const created: CreatedPost[] = [];
	let strings: Awaited< ReturnType< typeof pluginStrings > >;

	test.beforeAll( async ( { requestUtils } ) => {
		strings = await pluginStrings( requestUtils );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deleteCreated( requestUtils, created.splice( 0 ) );
		await resetFixtures( requestUtils, [
			FIXTURE_TOGGLES.deniedPostId,
			FIXTURE_TOGGLES.restrictStatuses,
		] );
	} );

	// The two tests below loop every {@link POST_TYPES} entry: both the
	// dropdown option (`PostList::bulk_actions()`) and the handler
	// (`BulkActionHandler::handle()`) are wired per post type via
	// `bulk_actions-edit-{type}` / `handle_bulk_actions-edit-{type}`
	// (src/Admin/PostList.php::hooks()) — a registration loop that only
	// wired `post` would leave `page`/`book` with no bulk Archive/Unarchive
	// option at all, or an option that silently does nothing on submit.
	// Together the two tests exercise both directions of that wiring: this
	// one covers the Archive branch (dropdown option + handler) plus Undo,
	// which round-trips back through the Unarchive handler via a GET link
	// rather than the dropdown.
	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: bulk Archive archives every selected post and offers Undo`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const first = await seedPost( requestUtils, {
				title: uniqueTitle( `Bulk archive one ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			const second = await seedPost( requestUtils, {
				title: uniqueTitle( `Bulk archive two ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			created.push(
				{ id: first.id, type: postType.restBase },
				{ id: second.id, type: postType.restBase }
			);

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( { postType: postType.queryArg } )
			);
			await selectRows( page, [ first.id, second.id ] );
			const params = await applyBulkAction( page, 'archive' );

			await expect(
				noticeWith( page, strings.archived_notice_many )
			).toBeVisible();
			expect( params.get( 'archived' ) ).toBe( '2' );
			// The form submits ids in list-table order, so compare as a set.
			expect(
				params.get( 'ids' )?.split( ',' ).map( Number ).sort()
			).toEqual( [ first.id, second.id ].sort() );
			expect( params.has( 'skipped' ) ).toBe( false );
			await expectNoInvalidBucket( page, params );

			for ( const id of [ first.id, second.id ] ) {
				expect(
					( await postState( requestUtils, id ) ).post_status
				).toBe( ARCHIVED_STATUS_SLUG );
			}

			// Undo restores the status each post held before archiving.
			const undo = noticeLocator( page ).getByRole( 'link', {
				name: strings.undo_label,
			} );
			await expect( undo ).toBeVisible();

			await Promise.all( [
				page.waitForURL( /edit\.php/ ),
				undo.click(),
			] );

			await expect(
				noticeWith( page, strings.unarchived_notice_many )
			).toBeVisible();

			for ( const id of [ first.id, second.id ] ) {
				expect(
					( await postState( requestUtils, id ) ).post_status
				).toBe( 'publish' );
			}
		} );
	}

	// The Unarchive-branch counterpart to the Archive test above: reaches
	// the archived-view dropdown's Unarchive option directly (rather than
	// Undo's GET link), and additionally pins that each post restores its
	// OWN previous status rather than a shared default — a check that has
	// nothing to do with post type but is cheap to keep per-type since the
	// loop is already here for the wiring proof.
	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: bulk Unarchive restores every selected post`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const published = await seedPost( requestUtils, {
				title: uniqueTitle( `Bulk unarchive publish ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			const draft = await seedPost( requestUtils, {
				title: uniqueTitle( `Bulk unarchive draft ${ postType.key }` ),
				status: 'draft',
				type: postType.restBase,
			} );
			created.push(
				{ id: published.id, type: postType.restBase },
				{ id: draft.id, type: postType.restBase }
			);

			await archivePost( requestUtils, published.id );
			await archivePost( requestUtils, draft.id );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} )
			);
			await selectRows( page, [ published.id, draft.id ] );
			const params = await applyBulkAction( page, 'unarchive' );

			await expect(
				noticeWith( page, strings.unarchived_notice_many )
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
	}

	// Deliberately NOT parameterized over POST_TYPES from here down: every
	// bucket below (locked / denied / wrong_status / the aggregate) is
	// decided by BulkActionHandler::process_archive_post() /
	// process_unarchive_post(), and neither branches on post type —
	// wp_check_post_lock(), ArchiveCapability::can_archive(),
	// get_post_status() and ArchivableStatuses::includes() all take a bare
	// post id, nothing type-shaped. The one thing that DOES vary per type —
	// whether handle_bulk_actions-edit-{type} is wired at all — is already
	// proven by the two loops above, which exercise both the Archive and
	// Unarchive branches for every {@link POST_TYPES} entry. Looping the
	// bucket tests too would re-prove that same wiring three more times per
	// test for no additional signal.
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
		created.push(
			{ id: free.id, type: 'posts' },
			{ id: locked.id, type: 'posts' }
		);

		const users = await roleUserIds( requestUtils );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ free.id, locked.id ] );

		// Lock after selecting: core hides the checkbox on rows it already
		// renders as locked, and "somebody started editing between select and
		// apply" is the race this bucket exists for.
		await lockPost( requestUtils, locked.id, users.aps_editor );

		const params = await applyBulkAction( page, 'archive' );

		await expect(
			noticeWith( page, strings.archived_notice_one )
		).toBeVisible();
		await expect(
			noticeWith( page, strings.locked_notice_one )
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
		created.push(
			{ id: allowed.id, type: 'posts' },
			{ id: denied.id, type: 'posts' }
		);

		// Per-post denial: the screen-level gate in BulkActionHandler::handle()
		// still passes, so the batch runs and exactly one item is skipped.
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.deniedPostId ]: denied.id,
		} );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ allowed.id, denied.id ] );
		const params = await applyBulkAction( page, 'archive' );

		await expect(
			noticeWith( page, strings.archived_notice_one )
		).toBeVisible();
		await expect(
			noticeWith( page, strings.denied_notice_one )
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
		created.push(
			{ id: published.id, type: 'posts' },
			{ id: draft.id, type: 'posts' }
		);

		// `aps_archivable_statuses` narrowed to publish only.
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.restrictStatuses ]: true,
		} );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await selectRows( page, [ published.id, draft.id ] );
		const params = await applyBulkAction( page, 'archive' );

		await expect(
			noticeWith( page, strings.archived_notice_one )
		).toBeVisible();
		await expect(
			noticeWith( page, strings.wrong_status_notice_one )
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
		created.push(
			{ id: ok.id, type: 'posts' },
			{ id: locked.id, type: 'posts' },
			{ id: denied.id, type: 'posts' }
		);

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
			noticeWith( page, strings.locked_notice_one )
		).toBeVisible();
		await expect(
			noticeWith( page, strings.denied_notice_one )
		).toBeVisible();
		await expect(
			noticeWith( page, strings.not_found_notice_one )
		).toBeVisible();
		await expectNoInvalidBucket( page, params );
	} );
} );
