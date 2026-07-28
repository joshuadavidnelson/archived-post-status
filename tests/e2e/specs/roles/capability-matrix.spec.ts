/**
 * The capability matrix: role x action, asserted in BOTH directions.
 *
 * This is the class of bug the PHP unit suite cannot see — a capability that is
 * resolved correctly in isolation but never actually enforced at the surface a
 * user touches. Every case below therefore drives the real screen or the real
 * URL as the role in question.
 *
 * Defaults under test (`Archive\ViewCapability`, `Archive\ArchiveCapability`)
 * are ownership-aware: a post's own author passes via the post type's
 * `edit_posts` primitive; anyone else needs `edit_others_posts` (archive /
 * unarchive) or `read_private_posts` (view). The matrix seeds each role's
 * posts AS that role, so the author row exercises the own-content grants;
 * the dedicated describe below it pins the other-authors denial.
 *
 * The last describe re-runs the archive axis with `aps_default_archive_capability`
 * and `aps_default_unarchive_capability` filtered to `manage_options`, which is
 * the boundary-move scenario: the filter must actually move the boundary, not just be
 * consulted.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	ARCHIVED_STATUS_SLUG,
	ROLE_USERS,
	storageStatePath,
} from '../../config/roles';
import {
	editUrl,
	POST_TITLE,
	postListQuery,
	rowActionLocator,
	rowCheckboxLocator,
	rowLocator,
} from '../../config/admin';
import {
	FIXTURE_TOGGLES,
	resetFixtures,
	setFixtures,
} from '../../config/fixtures';
import {
	archivePost,
	deletePosts,
	roleUserIds,
	seedPost,
	uniqueTitle,
} from '../../config/seed';
import { pluginStrings } from '../../config/strings';

let READ_ONLY_MESSAGE: string;

test.beforeAll( async ( { requestUtils } ) => {
	( { read_only_message: READ_ONLY_MESSAGE } = await pluginStrings(
		requestUtils
	) );
} );

/**
 * Expected answers per role.
 *
 * `listsPosts` is core's own `edit_posts` gate, included so the subscriber row
 * asserts the "cannot even get to the screen" direction rather than silently
 * skipping.
 */
const MATRIX = {
	administrator: { canView: true, canArchive: true, listsPosts: true },
	editor: { canView: true, canArchive: true, listsPosts: true },
	author: { canView: true, canArchive: true, listsPosts: true },
	subscriber: { canView: false, canArchive: false, listsPosts: false },
} as const;

type MatrixRole = keyof typeof MATRIX;

/**
 * Resolve the user id a role's seeded content should be authored by.
 *
 * Posts are authored by the role under test so the author row exercises "my own
 * post", which is the only case where an author has any standing at all.
 *
 * @param requestUtils Admin request utils.
 * @param role         Role under test.
 */
async function authorIdFor(
	requestUtils: Parameters< typeof roleUserIds >[ 0 ],
	role: MatrixRole
): Promise< number > {
	if ( 'administrator' === role ) {
		return 1;
	}

	const users = await roleUserIds( requestUtils );

	return users[ ROLE_USERS[ role ].username ];
}

for ( const role of Object.keys( MATRIX ) as MatrixRole[] ) {
	const expected = MATRIX[ role ];

	test.describe( `roles: ${ role }`, () => {
		test.use( { storageState: storageStatePath( role ) } );

		const created: number[] = [];

		test.afterEach( async ( { requestUtils } ) => {
			await deletePosts( requestUtils, created.splice( 0 ) );
		} );

		test( `can${
			expected.canView ? '' : 'not'
		} view an archived post on the front end`, async ( {
			page,
			requestUtils,
		} ) => {
			const title = uniqueTitle( `Matrix view ${ role }` );
			const post = await seedPost( requestUtils, {
				title,
				status: 'publish',
				author: await authorIdFor( requestUtils, role ),
			} );
			created.push( post.id );

			const archived = await archivePost( requestUtils, post.id );
			const response = await page.goto( archived.link );

			if ( expected.canView ) {
				expect( response?.status() ).toBe( 200 );
				await expect( page.locator( POST_TITLE ) ).toContainText(
					title
				);
			} else {
				expect( response?.status() ).toBe( 404 );
				await expect( page.getByText( title ) ).toHaveCount( 0 );
			}
		} );

		test( `${
			expected.canArchive ? 'sees' : 'does not see'
		} the Archive row action`, async ( { page, requestUtils } ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Matrix archive ${ role }` ),
				status: 'publish',
				author: await authorIdFor( requestUtils, role ),
			} );
			created.push( post.id );

			await page.goto( `/wp-admin/edit.php?${ postListQuery() }` );

			if ( ! expected.listsPosts ) {
				// Core stops the subscriber at the screen itself, before the
				// plugin is ever consulted.
				await expect( page.locator( 'body' ) ).toContainText(
					'Sorry, you are not allowed to access this page.'
				);
				return;
			}

			// Pin the row first: a "no Archive link" assertion would pass just
			// as happily against a list that rendered no rows at all.
			await expect( rowLocator( page, post.id ) ).toBeVisible();
			await expect(
				rowActionLocator( page, post.id, 'archive' )
			).toHaveCount( expected.canArchive ? 1 : 0 );
		} );

		test( `${
			expected.canArchive ? 'sees' : 'does not see'
		} the Unarchive row action`, async ( { page, requestUtils } ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Matrix unarchive ${ role }` ),
				status: 'publish',
				author: await authorIdFor( requestUtils, role ),
			} );
			created.push( post.id );
			await archivePost( requestUtils, post.id );

			await page.goto(
				`/wp-admin/edit.php?${ postListQuery( {
					postStatus: ARCHIVED_STATUS_SLUG,
				} ) }`
			);

			if ( ! expected.listsPosts ) {
				await expect( page.locator( 'body' ) ).toContainText(
					'Sorry, you are not allowed to access this page.'
				);
				return;
			}

			await expect( rowLocator( page, post.id ) ).toBeVisible();
			await expect(
				rowActionLocator( page, post.id, 'unarchive' )
			).toHaveCount( expected.canArchive ? 1 : 0 );
		} );

		test( 'cannot open an archived post in the editor', async ( {
			page,
			requestUtils,
		} ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Matrix edit ${ role }` ),
				status: 'publish',
				author: await authorIdFor( requestUtils, role ),
			} );
			created.push( post.id );
			await archivePost( requestUtils, post.id );

			const response = await page.goto( editUrl( post.id ) );

			// Read-only mode is on for everybody — no role is an exception.
			expect( response?.status() ).toBe( 500 );
			await expect( page.locator( 'body' ) ).toContainText(
				READ_ONLY_MESSAGE
			);
			await expect( page.locator( '#editor' ) ).toHaveCount( 0 );
		} );
	} );
}

test.describe( 'roles: anonymous', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'cannot view an archived post on the front end', async ( {
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Matrix view anonymous' );
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		const response = await page.goto( archived.link );

		expect( response?.status() ).toBe( 404 );
		await expect( page.getByText( title ) ).toHaveCount( 0 );
	} );
} );

test.describe( "roles: author on another author's content", () => {
	test.use( { storageState: storageStatePath( 'author' ) } );

	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'cannot view another author\'s archived post on the front end', async ( {
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Matrix other-author view' );
		// Authored by the admin (user 1) — not the author under test.
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
			author: 1,
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		const response = await page.goto( archived.link );

		expect( response?.status() ).toBe( 404 );
		await expect( page.getByText( title ) ).toHaveCount( 0 );
	} );

	test( 'sees no Archive or Unarchive row action on another author\'s posts', async ( {
		page,
		requestUtils,
	} ) => {
		const active = await seedPost( requestUtils, {
			title: uniqueTitle( 'Matrix other-author archive' ),
			status: 'publish',
			author: 1,
		} );
		created.push( active.id );

		const archivedSeed = await seedPost( requestUtils, {
			title: uniqueTitle( 'Matrix other-author unarchive' ),
			status: 'publish',
			author: 1,
		} );
		created.push( archivedSeed.id );
		await archivePost( requestUtils, archivedSeed.id );

		await page.goto( `/wp-admin/edit.php?${ postListQuery() }` );
		await expect( rowLocator( page, active.id ) ).toBeVisible();
		await expect(
			rowActionLocator( page, active.id, 'archive' )
		).toHaveCount( 0 );

		await page.goto(
			`/wp-admin/edit.php?${ postListQuery( {
				postStatus: ARCHIVED_STATUS_SLUG,
			} ) }`
		);
		await expect( rowLocator( page, archivedSeed.id ) ).toBeVisible();
		await expect(
			rowActionLocator( page, archivedSeed.id, 'unarchive' )
		).toHaveCount( 0 );
	} );

	test( 'shows the bulk-select checkbox only on the row the author can unarchive', async ( {
		page,
		requestUtils,
	} ) => {
		// PostEditorGuard denies edit_post on every archived post while
		// read-only mode is active — including to this row's own author's
		// account, if it belonged to them — so core itself would drop both
		// checkboxes below. PostList::show_archived_row_checkbox() restores
		// the checkbox per row, ownership-aware, via
		// aps_current_user_can_unarchive(). This test pins both outcomes of
		// that restore in one list view.

		// Foreign: authored by the admin (user 1). The author under test has
		// neither edit_others_posts nor authorship of this row, so the
		// restore must not fire and the checkbox must stay absent.
		const foreign = await seedPost( requestUtils, {
			title: uniqueTitle( 'Matrix other-author checkbox foreign' ),
			status: 'publish',
			author: 1,
		} );
		created.push( foreign.id );
		await archivePost( requestUtils, foreign.id );

		// Own: authored by the author under test. They hold edit_posts on
		// their own content, so the restore must fire. Seeded into the same
		// archived list view as the foreign row above: without this half, a
		// regression that hid every checkbox unconditionally would pass
		// silently.
		const own = await seedPost( requestUtils, {
			title: uniqueTitle( 'Matrix other-author checkbox own' ),
			status: 'publish',
			author: await authorIdFor( requestUtils, 'author' ),
		} );
		created.push( own.id );
		await archivePost( requestUtils, own.id );

		await page.goto(
			`/wp-admin/edit.php?${ postListQuery( {
				postStatus: ARCHIVED_STATUS_SLUG,
			} ) }`
		);

		// Pin both rows first: a checkbox assertion means nothing against a
		// row that never rendered.
		await expect( rowLocator( page, foreign.id ) ).toBeVisible();
		await expect( rowLocator( page, own.id ) ).toBeVisible();

		await expect( rowCheckboxLocator( page, foreign.id ) ).toHaveCount( 0 );
		await expect( rowCheckboxLocator( page, own.id ) ).toHaveCount( 1 );
	} );
} );

test.describe( 'roles: aps_default_*_capability moves the boundary', () => {
	const created: number[] = [];

	test.beforeEach( async ( { requestUtils } ) => {
		// Both archive capabilities become `manage_options`.
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.capFilter ]: true,
		} );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
		await resetFixtures( requestUtils, [ FIXTURE_TOGGLES.capFilter ] );
	} );

	test.describe( 'as the editor', () => {
		test.use( { storageState: storageStatePath( 'editor' ) } );

		test( 'loses both row actions', async ( { page, requestUtils } ) => {
			const archivable = await seedPost( requestUtils, {
				title: uniqueTitle( 'Filtered cap editor archivable' ),
				status: 'publish',
			} );
			const archived = await seedPost( requestUtils, {
				title: uniqueTitle( 'Filtered cap editor archived' ),
				status: 'publish',
			} );
			created.push( archivable.id, archived.id );
			await archivePost( requestUtils, archived.id );

			await page.goto( `/wp-admin/edit.php?${ postListQuery() }` );
			// Both rows are still listed to the editor — only the action is
			// gone. Without this pin the assertions below would survive a
			// regression that dropped the rows entirely.
			await expect( rowLocator( page, archivable.id ) ).toBeVisible();
			await expect(
				rowActionLocator( page, archivable.id, 'archive' )
			).toHaveCount( 0 );

			await page.goto(
				`/wp-admin/edit.php?${ postListQuery( {
					postStatus: ARCHIVED_STATUS_SLUG,
				} ) }`
			);
			await expect( rowLocator( page, archived.id ) ).toBeVisible();
			await expect(
				rowActionLocator( page, archived.id, 'unarchive' )
			).toHaveCount( 0 );
		} );
	} );

	test.describe( 'as the administrator', () => {
		test( 'keeps both row actions', async ( { page, requestUtils } ) => {
			const archivable = await seedPost( requestUtils, {
				title: uniqueTitle( 'Filtered cap admin archivable' ),
				status: 'publish',
			} );
			const archived = await seedPost( requestUtils, {
				title: uniqueTitle( 'Filtered cap admin archived' ),
				status: 'publish',
			} );
			created.push( archivable.id, archived.id );
			await archivePost( requestUtils, archived.id );

			// Control for the editor case above: the filter narrowed the
			// capability, it did not disable the feature.
			await page.goto( `/wp-admin/edit.php?${ postListQuery() }` );
			await expect(
				rowActionLocator( page, archivable.id, 'archive' )
			).toHaveCount( 1 );

			await page.goto(
				`/wp-admin/edit.php?${ postListQuery( {
					postStatus: ARCHIVED_STATUS_SLUG,
				} ) }`
			);
			await expect(
				rowActionLocator( page, archived.id, 'unarchive' )
			).toHaveCount( 1 );
		} );
	} );
} );
