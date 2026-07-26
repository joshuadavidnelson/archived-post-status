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
	postState,
	seedPost,
	setArchiveMeta,
	uniqueTitle,
} from '../../config/seed';
import { pluginStrings } from '../../config/strings';
import { wpCli } from '../../config/wp-cli';

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
	const created: number[] = [];
	let strings: Awaited< ReturnType< typeof pluginStrings > >;

	test.beforeAll( async ( { requestUtils } ) => {
		strings = await pluginStrings( requestUtils );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'the column is registered on the archived view and absent from All', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Column visibility' ),
			status: 'publish',
		} );
		created.push( post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await expect( page.locator( `th#${ COLUMN_KEY }` ) ).toHaveCount( 0 );

		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage( 'edit.php', ARCHIVED_VIEW );
		await expect( page.locator( `th#${ COLUMN_KEY }` ) ).toContainText(
			strings.archived_column_label
		);
	} );

	test( 'the cell attributes the archive to the user and shows the date', async ( {
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
			title: uniqueTitle( 'Column attribution' ),
			status: 'publish',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		expect( archived.meta.archive_user ).toBeGreaterThan( 0 );

		await setArchiveMeta( requestUtils, post.id, {
			archive_date: FIXED_TIMESTAMP,
		} );

		await admin.visitAdminPage( 'edit.php', ARCHIVED_VIEW );

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

	test( 'a post archived by anonymous WP-CLI is attributed to "system"', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Column system' ),
			status: 'publish',
		} );
		created.push( post.id );

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

	test( 'the column sorts by archive date and the order flips on a second click', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const older = await seedPost( requestUtils, {
			title: uniqueTitle( 'Column sort older' ),
			status: 'publish',
		} );
		const newer = await seedPost( requestUtils, {
			title: uniqueTitle( 'Column sort newer' ),
			status: 'publish',
		} );
		created.push( older.id, newer.id );

		await archivePost( requestUtils, older.id );
		await archivePost( requestUtils, newer.id );

		await setArchiveMeta( requestUtils, older.id, {
			archive_date: FIXED_TIMESTAMP,
		} );
		await setArchiveMeta( requestUtils, newer.id, {
			archive_date: FIXED_TIMESTAMP + 3600,
		} );

		await admin.visitAdminPage( 'edit.php', ARCHIVED_VIEW );

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
} );
