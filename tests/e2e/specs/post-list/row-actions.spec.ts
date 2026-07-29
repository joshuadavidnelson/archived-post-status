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
	POST_TYPES,
	postState,
	seedPost,
	uniqueTitle,
} from '../../config/seed';
import type { PostTypeUnderTest } from '../../config/seed';
import { pluginStrings } from '../../config/strings';

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
	const created: CreatedPost[] = [];
	let strings: Awaited< ReturnType< typeof pluginStrings > >;

	test.beforeAll( async ( { requestUtils } ) => {
		strings = await pluginStrings( requestUtils );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deleteCreated( requestUtils, created.splice( 0 ) );
	} );

	// The four tests below loop every {@link POST_TYPES} entry: the Archive /
	// Unarchive row action links only exist because
	// `RowActionPolicy::for_post()` is wired per post type via the
	// `{type}_row_actions` filter (src/Admin/PostList.php::hooks()) — a
	// registration loop that only wired `post` would leave `page`/`book`
	// rows with no Archive/Unarchive link at all. Confirmed observable by
	// mutating that loop directly: `page`'s Unarchive link disappeared while
	// `post`'s did not (see the task report for the mutation results). The
	// Edit / Quick Edit absence asserted inside the third test below is
	// NOT part of that signal — core withholds both unconditionally once
	// PostEditorGuard denies `edit_post` for any archived post (read-only
	// mode, the suite's stock default), regardless of whether
	// `{type}_row_actions` ever runs. They stay asserted because they are
	// still true and worth pinning; the per-type coverage in this describe
	// block comes from the archive/unarchive assertions.
	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: the Archive row action archives the post and returns to the list`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const title = uniqueTitle( `Row archive ${ postType.key }` );
			const post = await seedPost( requestUtils, {
				title,
				status: 'publish',
				type: postType.restBase,
			} );
			created.push( { id: post.id, type: postType.restBase } );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( { postType: postType.queryArg } )
			);

			const action = rowActionLocator( page, post.id, 'archive' );
			await expect( action ).toHaveText( strings.archive_row_action );

			await clickRowAction( page, post.id, 'archive' );

			await expect(
				noticeWith( page, strings.archived_notice_one )
			).toBeVisible();
			expect(
				( await postState( requestUtils, post.id ) ).post_status
			).toBe( ARCHIVED_STATUS_SLUG );
		} );
	}

	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: the Unarchive row action restores the previous status`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Row unarchive ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			created.push( { id: post.id, type: postType.restBase } );
			await archivePost( requestUtils, post.id );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} )
			);

			const action = rowActionLocator( page, post.id, 'unarchive' );
			await expect( action ).toHaveText( strings.unarchive_row_action );

			await clickRowAction( page, post.id, 'unarchive' );

			await expect(
				noticeWith( page, strings.unarchived_notice_one )
			).toBeVisible();
			expect(
				( await postState( requestUtils, post.id ) ).post_status
			).toBe( 'publish' );
		} );
	}

	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: an archived row offers Unarchive but not Archive, Edit or Quick Edit`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Row actions removed ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			created.push( { id: post.id, type: postType.restBase } );
			await archivePost( requestUtils, post.id );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( {
					postType: postType.queryArg,
					postStatus: ARCHIVED_STATUS_SLUG,
				} )
			);

			const row = rowLocator( page, post.id );
			await expect( row ).toBeVisible();

			await expect(
				rowActionLocator( page, post.id, 'unarchive' )
			).toHaveCount( 1 );
			await expect(
				rowActionLocator( page, post.id, 'archive' )
			).toHaveCount( 0 );
			await expect( row.locator( '.row-actions .edit' ) ).toHaveCount(
				0
			);
			await expect(
				row.locator( '.row-actions .inline' )
			).toHaveCount( 0 );
		} );
	}

	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: an archivable row offers Archive but not Unarchive`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Row draft actions ${ postType.key }` ),
				status: 'draft',
				type: postType.restBase,
			} );
			created.push( { id: post.id, type: postType.restBase } );

			await admin.visitAdminPage(
				'edit.php',
				postListQuery( { postType: postType.queryArg } )
			);

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
	}

	// Deliberately NOT parameterized over POST_TYPES: the "All" view
	// exclusion comes from register_post_status()'s single
	// `show_in_admin_all_list` arg (src/Status/PostStatus.php::status_args()),
	// registered ONCE for every supported post type in one
	// register_post_status() call (`'post_type' => aps_get_supported_post_types()`)
	// — there is no per-type hook here for a registration-loop bug to break.
	test( 'archived posts are hidden from the All view', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Hidden from all' ),
			status: 'publish',
		} );
		created.push( { id: post.id, type: 'posts' } );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await expect( rowLocator( page, post.id ) ).toBeVisible();

		await archivePost( requestUtils, post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await expect( rowLocator( page, post.id ) ).toHaveCount( 0 );
	} );
} );
