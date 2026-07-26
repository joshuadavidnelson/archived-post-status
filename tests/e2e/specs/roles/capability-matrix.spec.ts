/**
 * The capability matrix: role x action, asserted in BOTH directions.
 *
 * This is the class of bug the PHP unit suite cannot see — a capability that is
 * resolved correctly in isolation but never actually enforced at the surface a
 * user touches. Every case below therefore drives the real screen or the real
 * URL as the role in question.
 *
 * Defaults under test (`Archive\ViewCapability`, `Archive\ArchiveCapability`):
 *   - view      -> `read_private_posts`  (administrator, editor)
 *   - archive   -> `edit_others_posts`   (administrator, editor)
 *   - unarchive -> `edit_others_posts`   (administrator, editor)
 *
 * The last describe re-runs the archive axis with `aps_default_archive_capability`
 * and `aps_default_unarchive_capability` filtered to `manage_options`, which is
 * the A6/A7 scenario: the filter must actually move the boundary, not just be
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
	postListQuery,
	rowActionLocator,
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

const POST_TITLE = 'h1.wp-block-post-title';

const READ_ONLY_MESSAGE =
	"You can't edit this item because it has been Archived. Please change the post status and try again.";

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
	author: { canView: false, canArchive: false, listsPosts: true },
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

			const response = await page.goto(
				`/wp-admin/post.php?post=${ post.id }&action=edit`
			);

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

test.describe( 'roles: aps_default_*_capability moves the boundary (A6/A7)', () => {
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
