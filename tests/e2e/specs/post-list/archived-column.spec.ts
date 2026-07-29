/**
 * Pins the 0.4.0 Archived column added by `Admin\ArchiveColumn`.
 *
 * The column only appears on the archived filter, attributes each row to the
 * user who archived it (falling back to "system" when there was no user, e.g.
 * anonymous WP-CLI), renders the archive timestamp, and is sortable by that
 * timestamp.
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
	POST_TYPES,
	postState,
	seedPost,
	setArchiveMeta,
	uniqueTitle,
} from '../../config/seed';
import type { PostTypeUnderTest } from '../../config/seed';
import { pluginStrings } from '../../config/strings';
import { wpCli } from '../../config/wp-cli';

/**
 * A post created during a test, tagged with the REST base `deletePosts()`
 * needs to remove it again. Several tests below loop {@link POST_TYPES},
 * seeding more than one type into the same `created` array, so a single
 * hardcoded base would silently fail to delete anything but `post`.
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
 * Column key registered by `ArchiveColumn::COLUMN_KEY`.
 */
const COLUMN_KEY = 'aps_archived';

/**
 * A timestamp with an unambiguous rendering: 2001-09-09 01:46:40 UTC.
 *
 * Pinning the stored `archive_date` keeps both the rendered-date assertion and
 * the sort assertion deterministic — `ArchiveMeta::from_post()` stamps `time()`,
 * so two posts archived in the same second would otherwise tie.
 */
const FIXED_TIMESTAMP = 1_000_000_000;

/**
 * `wp_date( get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ), FIXED_TIMESTAMP )`
 * with the stock WordPress settings asserted below.
 */
const FIXED_TIMESTAMP_DISPLAY = 'September 9, 2001 at 1:46 am';

const ARCHIVED_VIEW = postListQuery( { postStatus: ARCHIVED_STATUS_SLUG } );

test.describe( 'post list: archived column', () => {
	const created: CreatedPost[] = [];
	let strings: Awaited< ReturnType< typeof pluginStrings > >;

	test.beforeAll( async ( { requestUtils } ) => {
		strings = await pluginStrings( requestUtils );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deleteCreated( requestUtils, created.splice( 0 ) );
	} );

	// The three tests below loop every {@link POST_TYPES} entry:
	// `ArchiveColumn::hooks()` (src/Admin/ArchiveColumn.php) registers
	// `manage_{type}_posts_columns`, `manage_{type}_posts_custom_column` and
	// `manage_edit-{type}_sortable_columns` per post type — three independent
	// registration points a partial fix could wire for `post` while missing
	// `page`/`book` (add the column but forget it sortable, for instance).
	// This test covers the first: a loop that skipped a type would never add
	// the `th#aps_archived` header there at all.
	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: the column is registered on the archived view and absent from All`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Column visibility ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			created.push( { id: post.id, type: postType.restBase } );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( { postType: postType.queryArg } )
			);
			await expect(
				page.locator( `th#${ COLUMN_KEY }` )
			).toHaveCount( 0 );

			await archivePost( requestUtils, post.id );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} )
			);
			await expect( page.locator( `th#${ COLUMN_KEY }` ) ).toContainText(
				strings.archived_column_label
			);
		} );
	}

	// Covers the second registration point: `manage_{type}_posts_custom_column`
	// is what actually renders the cell content; without it the `<td>` may
	// exist (from the columns filter above) but stay empty for the skipped
	// type.
	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: the cell attributes the archive to the user and shows the date`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			// The expected date string below assumes stock formatting; assert it so
			// a settings change fails here rather than as a puzzling text mismatch.
			const settings = await requestUtils.rest< {
				date_format: string;
				time_format: string;
				timezone: string;
			} >( { path: '/wp/v2/settings' } );
			expect( settings.date_format ).toBe( 'F j, Y' );
			expect( settings.time_format ).toBe( 'g:i a' );
			expect( settings.timezone ).toBe( '' );

			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Column attribution ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			created.push( { id: post.id, type: postType.restBase } );

			const archived = await archivePost( requestUtils, post.id );
			expect( archived.meta.archive_user ).toBeGreaterThan( 0 );

			await setArchiveMeta( requestUtils, post.id, {
				archive_date: FIXED_TIMESTAMP,
			} );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} )
			);

			const cell = rowLocator( page, post.id ).locator(
				`td.column-${ COLUMN_KEY }`
			);

			await expect( cell ).toContainText(
				strings.attribution_template.replace( '%1$s', 'admin' )
			);
			await expect( cell.locator( '.aps-archive-datetime' ) ).toHaveText(
				FIXED_TIMESTAMP_DISPLAY
			);
		} );
	}

	// Deliberately NOT parameterized over POST_TYPES: this test's signal is
	// the archive_user === 0 fallback in resolve_archive_agent_name() — logic
	// with no post-type branch — not the column's per-type registration,
	// which the two tests above already exercise for every {@link
	// POST_TYPES} entry (including the cell-rendering hook this test also
	// happens to exercise).
	test( 'a post archived by anonymous WP-CLI is attributed to "system"', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Column system' ),
			status: 'publish',
		} );
		created.push( { id: post.id, type: 'posts' } );

		// No --user: WP-CLI's elevated context has no current user, so
		// ArchiveMeta records user 0 and the column must fall back.
		const cli = await wpCli( [ 'post', 'archive', String( post.id ) ] );
		expect( cli.exitCode ).toBe( 0 );

		const state = await postState( requestUtils, post.id );
		expect( state.post_status ).toBe( ARCHIVED_STATUS_SLUG );
		expect( state.meta.archive_user ).toBe( 0 );
		expect( state.meta.archive_date ).toBeGreaterThan( 0 );

		await admin.visitAdminPage( 'edit.php', ARCHIVED_VIEW );

		const cell = rowLocator( page, post.id ).locator(
			`td.column-${ COLUMN_KEY }`
		);

		await expect( cell ).toContainText(
			strings.attribution_template.replace(
				'%1$s',
				strings.system_attribution
			)
		);
		await expect( cell.locator( '.aps-archive-datetime' ) ).not.toBeEmpty();
	} );

	// A third, independent registration point: `manage_edit-{type}_sortable_columns`
	// is what makes the header a clickable sort link at all — a type left
	// off that loop while still getting the column and cell would render a
	// plain (non-link) header, so `sortLink.click()` below would have
	// nothing to click.
	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: the column sorts by archive date and the order flips on a second click`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const older = await seedPost( requestUtils, {
				title: uniqueTitle( `Column sort older ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			const newer = await seedPost( requestUtils, {
				title: uniqueTitle( `Column sort newer ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			created.push(
				{ id: older.id, type: postType.restBase },
				{ id: newer.id, type: postType.restBase }
			);

			await archivePost( requestUtils, older.id );
			await archivePost( requestUtils, newer.id );

			await setArchiveMeta( requestUtils, older.id, {
				archive_date: FIXED_TIMESTAMP,
			} );
			await setArchiveMeta( requestUtils, newer.id, {
				archive_date: FIXED_TIMESTAMP + 3600,
			} );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} )
			);

			const rowIds = async () =>
				page
					.locator( '#the-list tr' )
					.evaluateAll( ( rows ) => rows.map( ( row ) => row.id ) );

			const sortLink = page.locator( `th#${ COLUMN_KEY } a` );

			await Promise.all( [
				page.waitForURL( /orderby=aps_archived/ ),
				sortLink.click(),
			] );
			expect( await rowIds() ).toEqual( [
				`post-${ older.id }`,
				`post-${ newer.id }`,
			] );

			await Promise.all( [
				page.waitForURL( /order=desc/ ),
				page.locator( `th#${ COLUMN_KEY } a` ).click(),
			] );
			expect( await rowIds() ).toEqual( [
				`post-${ newer.id }`,
				`post-${ older.id }`,
			] );
		} );
	}
} );
