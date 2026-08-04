/**
 * The Quick Edit journey against the real inline-edit row.
 *
 * 0.4.0 removed the 0.3.x status-dropdown injection: archiving happens
 * through row/bulk actions and the editor button, never through Quick
 * Edit's status select. Both directions are pinned here — the select on an
 * archivable post offers no archived option, and an archived row offers no
 * Quick Edit at all.
 *
 * Neither test loops {@link POST_TYPES}: for both, no per-type registration
 * in this plugin gates the observed behaviour, so there is no per-type
 * mutation a registration-loop bug could produce.
 *
 *   - The status select: WordPress core builds `<select name="_status">`'s
 *     option list itself (`WP_Posts_List_Table::inline_edit()` in
 *     wp-admin/includes/class-wp-posts-list-table.php), independent of
 *     `register_post_status()`'s args, and nothing in `src/` hooks the
 *     `quick_edit_statuses` filter (or the legacy `quick_edit_custom_box` /
 *     `bulk_edit_custom_box`) for any post type — confirmed by grep. There
 *     is no plugin-owned code path here, per-type or otherwise, to break.
 *   - The Quick Edit trigger: core only adds the `'inline hide-if-no-js'`
 *     row action (the `button.editinline` this test looks for) when
 *     `current_user_can( 'edit_post', ... )` is true (same file,
 *     `handle_row_actions()`). `PostEditorGuard::deny_editing_archived()`
 *     denies that capability for every archived post while read-only mode
 *     is active — this suite's stock default, unset by any test here — via
 *     a `map_meta_cap` filter keyed only on post status, never post type.
 *     Core itself withholds the button before `RowActionPolicy`'s own
 *     per-type-gated removal of the same action ever gets a chance to
 *     matter — the same "core gate front-runs the plugin check" trap that
 *     defeated the original, unparameterized capability-matrix sweep.
 *     Confirmed by mutating `PostList::hooks()`'s per-type loop to wire
 *     `post` only and re-running this exact check against an archived
 *     `page`: `button.editinline` stayed absent (0) both before and after
 *     the mutation, while the row's Unarchive link — genuinely gated by
 *     that same loop — dropped from 1 to 0. See the task report for the
 *     full mutation results.
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
	seedPost,
	uniqueTitle,
} from '../../config/seed';

test.describe( 'post list: Quick Edit', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'the status select offers no archived option for an archivable post', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Quick edit archivable' ),
			status: 'publish',
		} );
		created.push( post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );

		const row = rowLocator( page, post.id );
		await expect( row ).toBeVisible();

		// Row actions are revealed on hover; the Quick Edit trigger is the
		// core .editinline button.
		await row.hover();
		await row.locator( 'button.editinline' ).click();

		const inlineRow = page.locator( `#edit-${ post.id }` );
		await expect( inlineRow ).toBeVisible();

		const statusSelect = inlineRow.locator( 'select[name="_status"]' );
		await expect( statusSelect ).toBeVisible();

		const values = await statusSelect
			.locator( 'option' )
			.evaluateAll( ( options ) =>
				options.map( ( option ) => option.getAttribute( 'value' ) )
			);
		expect( values.length ).toBeGreaterThan( 0 );
		expect( values ).not.toContain( ARCHIVED_STATUS_SLUG );

		await inlineRow.locator( 'button.cancel' ).click();
		await expect( inlineRow ).toBeHidden();
	} );

	test( 'an archived row exposes no Quick Edit trigger', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Quick edit archived' ),
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

		await row.hover();
		await expect( row.locator( 'button.editinline' ) ).toHaveCount( 0 );
	} );
} );
