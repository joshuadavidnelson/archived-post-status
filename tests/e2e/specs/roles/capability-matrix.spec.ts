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
 * `edit_posts` primitive; anyone else needs its `edit_others_posts` (archive /
 * unarchive) or `read_private_posts` (view). The matrix seeds each role's
 * posts AS that role, so the author row exercises the own-content grants;
 * the dedicated describe below it pins the other-authors denial.
 *
 * Capability-outcome cases (view / row actions / the bulk checkbox) run
 * against every {@link POST_TYPES} entry. `page` surfaces a real WordPress
 * difference along the way: the stock Author role holds no Page capability
 * at all (core's `populate_roles()` gives Page capabilities to Editor and
 * Administrator only), so `expectedFor()` below overrides the author row for
 * `page` rather than assuming MATRIX applies uniformly — and the author's
 * `edit.php?post_type=page` describe-block tests are skipped for the same
 * reason, with a comment at each. Cases that never reach a post-type
 * primitive (the read-only editor lockout, the anonymous view check, the
 * capability-filter boundary tests) stay on a single post type — see the
 * comment at each for why.
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
	POST_TYPES,
	roleUserIds,
	seedPost,
	uniqueTitle,
} from '../../config/seed';
import type { PostTypeUnderTest } from '../../config/seed';
import { pluginStrings } from '../../config/strings';

let READ_ONLY_MESSAGE: string;

test.beforeAll( async ( { requestUtils } ) => {
	( { read_only_message: READ_ONLY_MESSAGE } = await pluginStrings(
		requestUtils
	) );
} );

/**
 * Expected answers per role, for a post the role authored itself.
 *
 * `listsPosts` is core's own `edit_posts` gate, included so the subscriber row
 * asserts the "cannot even get to the screen" direction rather than silently
 * skipping.
 *
 * Uniform across {@link POST_TYPES} with one exception — see `expectedFor()`.
 */
const MATRIX = {
	administrator: { canView: true, canArchive: true, listsPosts: true },
	editor: { canView: true, canArchive: true, listsPosts: true },
	author: { canView: true, canArchive: true, listsPosts: true },
	subscriber: { canView: false, canArchive: false, listsPosts: false },
} as const;

type MatrixRole = keyof typeof MATRIX;

/**
 * A MATRIX cell shape, widened off the `as const` literals so `expectedFor()`
 * can return either a matrix row or a computed override.
 */
interface MatrixExpectation {
	canView: boolean;
	canArchive: boolean;
	listsPosts: boolean;
}

/**
 * Resolve the expected matrix cell for a role x post type combination.
 *
 * Uniform across post types, with one exception: WordPress's stock Author
 * role (`populate_roles()`) is never granted any Page capability at all —
 * `edit_pages` and friends are Editor+ only — so an author has no more
 * standing over a page than a subscriber does. `post` gives the author role
 * `edit_posts` on their own content, and the `aps_book` fixture deliberately
 * grants the `book`-equivalent (see `tests/e2e/fixtures/aps-cpt.php`), so
 * `post` and `book` both match `MATRIX` unmodified; only `author` x `page`
 * diverges.
 *
 * @param role     Role under test.
 * @param postType Post type under test.
 */
function expectedFor(
	role: MatrixRole,
	postType: PostTypeUnderTest
): MatrixExpectation {
	if ( 'author' === role && 'page' === postType.key ) {
		return { canView: false, canArchive: false, listsPosts: false };
	}

	return MATRIX[ role ];
}

/**
 * The `wp_die()` copy shown when a role cannot reach a post type's list
 * screen (the `! expected.listsPosts` branch below).
 *
 * Normally WordPress's own admin-menu gate
 * (`user_can_access_admin_page()` in `wp-admin/includes/menu.php`) blocks the
 * request before `edit.php` ever runs its own checks, producing the generic
 * message — that gate is keyed off `$pagenow` (`edit.php`), not the
 * `post_type` query arg, so it cannot tell Posts and Pages apart. The one
 * combination under test where that matters: the author role holds
 * `edit_posts`, which gives it a real `edit.php` menu entry (for Posts), so
 * it clears this coarse, type-blind gate for `page` too — then `edit.php`'s
 * own `current_user_can( $post_type_object->cap->edit_posts )` check, which
 * IS type-aware, stops it a moment later with its own distinct copy.
 * Confirmed against the running site rather than assumed; see the sibling
 * combinations (subscriber, every type) for the generic message instead.
 *
 * @param role     Role under test.
 * @param postType Post type under test.
 */
function deniedScreenMessage(
	role: MatrixRole,
	postType: PostTypeUnderTest
): string {
	if ( 'author' === role && 'page' === postType.key ) {
		return 'Sorry, you are not allowed to edit posts in this post type.';
	}

	return 'Sorry, you are not allowed to access this page.';
}

/**
 * Explain why a screen-dependent "author acting on another author's content"
 * test is skipped for `page` — the author cannot reach that type's list
 * screen at all, so there is no "reaches the list, denied per-row" scenario
 * left to assert; see `deniedScreenMessage()` for the screen-level denial
 * this collapses into instead.
 *
 * @param postType Post type under test.
 */
function authorUnreachableReason( postType: PostTypeUnderTest ): string {
	return (
		'the stock Author role holds no Page capability at all, so the ' +
		'author cannot reach ' +
		`edit.php?post_type=${ postType.queryArg } in the first place — ` +
		"that blanket denial is already pinned by the MATRIX role loop's " +
		"author x page case."
	);
}

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

/**
 * A post created during a test, tagged with the REST base `deletePosts()`
 * needs to remove it again. A describe block that loops `POST_TYPES` seeds
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
 * @param posts        Posts pushed onto a describe block's `created` array.
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

for ( const role of Object.keys( MATRIX ) as MatrixRole[] ) {
	test.describe( `roles: ${ role }`, () => {
		test.use( { storageState: storageStatePath( role ) } );

		const created: CreatedPost[] = [];

		test.afterEach( async ( { requestUtils } ) => {
			await deleteCreated( requestUtils, created.splice( 0 ) );
		} );

		for ( const postType of POST_TYPES ) {
			const expected = expectedFor( role, postType );

			test( `${ postType.label }: can${
				expected.canView ? '' : 'not'
			} view an archived post on the front end`, async ( {
				page,
				requestUtils,
			} ) => {
				const title = uniqueTitle(
					`Matrix view ${ role } ${ postType.key }`
				);
				const post = await seedPost( requestUtils, {
					title,
					status: 'publish',
					type: postType.restBase,
					author: await authorIdFor( requestUtils, role ),
				} );
				created.push( { id: post.id, type: postType.restBase } );

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

			test( `${ postType.label }: ${
				expected.canArchive ? 'sees' : 'does not see'
			} the Archive row action`, async ( { page, requestUtils } ) => {
				const post = await seedPost( requestUtils, {
					title: uniqueTitle(
						`Matrix archive ${ role } ${ postType.key }`
					),
					status: 'publish',
					type: postType.restBase,
					author: await authorIdFor( requestUtils, role ),
				} );
				created.push( { id: post.id, type: postType.restBase } );

				await page.goto(
					`/wp-admin/edit.php?${ postListQuery( {
						postType: postType.queryArg,
					} ) }`
				);

				if ( ! expected.listsPosts ) {
					// Core stops the role at the screen itself, before the
					// plugin is ever consulted — the subscriber for every
					// type, and the author for `page` specifically (the
					// stock Author role holds no Page capability at all).
					// Which wp_die() copy depends on which gate stops them;
					// see deniedScreenMessage().
					await expect( page.locator( 'body' ) ).toContainText(
						deniedScreenMessage( role, postType )
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

			test( `${ postType.label }: ${
				expected.canArchive ? 'sees' : 'does not see'
			} the Unarchive row action`, async ( { page, requestUtils } ) => {
				const post = await seedPost( requestUtils, {
					title: uniqueTitle(
						`Matrix unarchive ${ role } ${ postType.key }`
					),
					status: 'publish',
					type: postType.restBase,
					author: await authorIdFor( requestUtils, role ),
				} );
				created.push( { id: post.id, type: postType.restBase } );
				await archivePost( requestUtils, post.id );

				await page.goto(
					`/wp-admin/edit.php?${ postListQuery( {
						postType: postType.queryArg,
						postStatus: ARCHIVED_STATUS_SLUG,
					} ) }`
				);

				if ( ! expected.listsPosts ) {
					await expect( page.locator( 'body' ) ).toContainText(
						deniedScreenMessage( role, postType )
					);
					return;
				}

				await expect( rowLocator( page, post.id ) ).toBeVisible();
				await expect(
					rowActionLocator( page, post.id, 'unarchive' )
				).toHaveCount( expected.canArchive ? 1 : 0 );
			} );
		}

		// Deliberately NOT parameterized over POST_TYPES: PostEditorGuard's
		// map_meta_cap deny (src/Admin/PostEditorGuard.php::deny_editing_archived())
		// appends an unconditional 'do_not_allow' for any archived post while
		// read-only mode is active. It keys off post status only — never a
		// post-type primitive — so every type produces the identical 500 +
		// message. Looping here would triple the runtime for no signal.
		test( 'cannot open an archived post in the editor', async ( {
			page,
			requestUtils,
		} ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Matrix edit ${ role }` ),
				status: 'publish',
				author: await authorIdFor( requestUtils, role ),
			} );
			created.push( { id: post.id, type: 'posts' } );
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

	// Deliberately NOT parameterized over POST_TYPES: ViewCapability's
	// ownership fallback (author_owns_and_can_edit()) returns false before it
	// ever reads a post-type primitive, because an anonymous request has no
	// current user (`$user_id` is always 0) — there is no primitive
	// resolution here to exercise for any type.
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

	const created: CreatedPost[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deleteCreated( requestUtils, created.splice( 0 ) );
	} );

	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: cannot view another author's archived post on the front end`, async ( {
			page,
			requestUtils,
		} ) => {
			const title = uniqueTitle(
				`Matrix other-author view ${ postType.key }`
			);
			// Authored by the admin (user 1) — not the author under test.
			const post = await seedPost( requestUtils, {
				title,
				status: 'publish',
				type: postType.restBase,
				author: 1,
			} );
			created.push( { id: post.id, type: postType.restBase } );

			const archived = await archivePost( requestUtils, post.id );
			const response = await page.goto( archived.link );

			expect( response?.status() ).toBe( 404 );
			await expect( page.getByText( title ) ).toHaveCount( 0 );
		} );
	}

	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: sees no Archive or Unarchive row action on another author's posts`, async ( {
			page,
			requestUtils,
		} ) => {
			test.skip(
				'page' === postType.key,
				authorUnreachableReason( postType )
			);

			const active = await seedPost( requestUtils, {
				title: uniqueTitle(
					`Matrix other-author archive ${ postType.key }`
				),
				status: 'publish',
				type: postType.restBase,
				author: 1,
			} );
			created.push( { id: active.id, type: postType.restBase } );

			const archivedSeed = await seedPost( requestUtils, {
				title: uniqueTitle(
					`Matrix other-author unarchive ${ postType.key }`
				),
				status: 'publish',
				type: postType.restBase,
				author: 1,
			} );
			created.push( { id: archivedSeed.id, type: postType.restBase } );
			await archivePost( requestUtils, archivedSeed.id );

			await page.goto(
				`/wp-admin/edit.php?${ postListQuery( {
					postType: postType.queryArg,
				} ) }`
			);
			await expect( rowLocator( page, active.id ) ).toBeVisible();
			await expect(
				rowActionLocator( page, active.id, 'archive' )
			).toHaveCount( 0 );

			await page.goto(
				`/wp-admin/edit.php?${ postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} ) }`
			);
			await expect( rowLocator( page, archivedSeed.id ) ).toBeVisible();
			await expect(
				rowActionLocator( page, archivedSeed.id, 'unarchive' )
			).toHaveCount( 0 );
		} );
	}

	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: shows the bulk-select checkbox only on the row the author can unarchive`, async ( {
			page,
			requestUtils,
		} ) => {
			test.skip(
				'page' === postType.key,
				authorUnreachableReason( postType )
			);

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
				title: uniqueTitle(
					`Matrix other-author checkbox foreign ${ postType.key }`
				),
				status: 'publish',
				type: postType.restBase,
				author: 1,
			} );
			created.push( { id: foreign.id, type: postType.restBase } );
			await archivePost( requestUtils, foreign.id );

			// Own: authored by the author under test. They hold edit_posts on
			// their own content, so the restore must fire. Seeded into the same
			// archived list view as the foreign row above: without this half, a
			// regression that hid every checkbox unconditionally would pass
			// silently.
			const own = await seedPost( requestUtils, {
				title: uniqueTitle(
					`Matrix other-author checkbox own ${ postType.key }`
				),
				status: 'publish',
				type: postType.restBase,
				author: await authorIdFor( requestUtils, 'author' ),
			} );
			created.push( { id: own.id, type: postType.restBase } );
			await archivePost( requestUtils, own.id );

			await page.goto(
				`/wp-admin/edit.php?${ postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} ) }`
			);

			// Pin both rows first: a checkbox assertion means nothing against a
			// row that never rendered.
			await expect( rowLocator( page, foreign.id ) ).toBeVisible();
			await expect( rowLocator( page, own.id ) ).toBeVisible();

			await expect( rowCheckboxLocator( page, foreign.id ) ).toHaveCount(
				0
			);
			await expect( rowCheckboxLocator( page, own.id ) ).toHaveCount( 1 );
		} );
	}
} );

// Deliberately NOT parameterized over POST_TYPES: the fixture filter
// (tests/e2e/fixtures/aps-cap-filter.php) returns the literal 'manage_options'
// whenever it is enabled, ignoring the default capability it was passed —
// so it overrides ArchiveCapability's post-type-primitive resolution outright
// rather than narrowing it. There is no per-type behaviour left to observe
// once the filter is on.
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
