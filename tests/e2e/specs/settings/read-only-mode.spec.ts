/**
 * Pins the settings bridge for read-only mode.
 *
 * `Settings\HookAdapter` maps the stored `aps_settings['is_read_only']` value
 * onto the `aps_is_read_only` filter at priority 20, and wires
 * `Settings\Store::flush_cache()` to the option's add/update/delete hooks so an
 * external write is picked up without any cache-clearing hack at the call site.
 *
 * Both observable consequences of the setting are asserted: the editor guard and
 * the server-side removal of edit affordances on archived rows.
 *
 * None of the tests below loop {@link POST_TYPES}. `Store`, `HookAdapter`, and
 * the `aps_is_read_only` filter they wire carry no post-type parameter at all —
 * the setting is a single site-wide flag — and `PostEditorGuard`'s consumption
 * of it keys only on post status, never `$post->post_type`. The per-type sweep
 * of the row affordances this file also touches lives in
 * `post-list/row-actions.spec.ts` and `editor/read-only-guard.spec.ts`.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { editUrl, postListQuery } from '../../config/admin';
import {
	archivePost,
	deletePosts,
	resetPluginSettings,
	seedPost,
	setPluginSettings,
	uniqueTitle,
} from '../../config/seed';
import { pluginStrings } from '../../config/strings';
import { wpCli } from '../../config/wp-cli';

test.describe( 'settings: read-only mode', () => {
	const created: number[] = [];
	let READ_ONLY_MESSAGE: string;

	test.beforeAll( async ( { requestUtils } ) => {
		( { read_only_message: READ_ONLY_MESSAGE } = await pluginStrings(
			requestUtils
		) );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
		await resetPluginSettings( requestUtils );
	} );

	test( 'turning the setting off unblocks the editor, turning it on blocks it again', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only setting' ),
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		// Default (no stored setting): read-only is on.
		let response = await page.goto( editUrl( post.id ) );
		expect( response?.status() ).toBe( 500 );
		await expect( page.locator( 'body' ) ).toContainText(
			READ_ONLY_MESSAGE
		);

		// Stored false: the very next request behaves differently — no cache
		// bust, no restart.
		await setPluginSettings( requestUtils, false );
		response = await page.goto( editUrl( post.id ) );
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);

		// Stored true: back to blocked.
		await setPluginSettings( requestUtils, true );
		response = await page.goto( editUrl( post.id ) );
		expect( response?.status() ).toBe( 500 );
		await expect( page.locator( 'body' ) ).toContainText(
			READ_ONLY_MESSAGE
		);
	} );

	test( 'archived rows lose their edit affordances while read-only is on', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const title = uniqueTitle( 'Read only title link' );
		const post = await seedPost( requestUtils, {
			title,
			status: 'publish',
		} );
		created.push( post.id );
		await archivePost( requestUtils, post.id );

		// Read-only on (default): PostEditorGuard denies edit_post on the
		// archived row, so core renders the title as plain text — no
		// row-title link, no Edit or Quick Edit actions. This replaced the
		// old client-side edit-screen.js DOM stripping.
		await admin.visitAdminPage(
			'edit.php',
			postListQuery( { postStatus: 'archive' } )
		);
		const row = page.locator( `#post-${ post.id }` );
		await expect( row.locator( '.column-title' ) ).toContainText( title );
		await expect( row.locator( 'a.row-title' ) ).toHaveCount( 0 );
		await expect( row.locator( '.row-actions .edit' ) ).toHaveCount( 0 );
		await expect( row.locator( '.row-actions .inline' ) ).toHaveCount( 0 );

		// Read-only off: the title link returns (RowActionPolicy still
		// strips the Edit/Quick Edit row actions for archived rows).
		await setPluginSettings( requestUtils, false );
		await admin.visitAdminPage(
			'edit.php',
			postListQuery( { postStatus: 'archive' } )
		);
		await expect( row.locator( 'a.row-title' ) ).toHaveCount( 1 );
	} );

	test( 'an external update_option is visible to Store within the same request', async () => {
		// The browser assertions above cross a process boundary, where a stale
		// in-memory cache could never show up. This one runs the write and both
		// reads inside a single PHP process, which is the only place
		// Store::flush_cache() can actually be observed.
		const result = await wpCli( [
			'eval',
			'$before = \\ArchivedPostStatus\\Settings\\Store::get( "is_read_only" );' +
				'update_option( "aps_settings", array( "is_read_only" => false ) );' +
				'$after = \\ArchivedPostStatus\\Settings\\Store::get( "is_read_only" );' +
				'echo wp_json_encode( array( "before" => $before, "after" => $after, "filtered" => aps_is_read_only() ) );',
		] );

		expect( result.exitCode ).toBe( 0 );
		expect( JSON.parse( result.stdout ) ).toEqual( {
			before: true,
			after: false,
			filtered: false,
		} );
	} );
} );
