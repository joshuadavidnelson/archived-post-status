/**
 * Pins the settings bridge for read-only mode.
 *
 * `Settings\HookAdapter` maps the stored `aps_settings['is_read_only']` value
 * onto the `aps_is_read_only` filter at priority 20, and wires
 * `Settings\Store::flush_cache()` to the option's add/update/delete hooks so an
 * external write is picked up without any cache-clearing hack at the call site.
 *
 * Both observable consequences of the setting are asserted: the editor guard and
 * the list-table script enqueue.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { postListQuery } from '../../config/admin';
import {
	archivePost,
	deletePosts,
	resetPluginSettings,
	seedPost,
	setPluginSettings,
	uniqueTitle,
} from '../../config/seed';
import { wpCli } from '../../config/wp-cli';

const READ_ONLY_MESSAGE =
	"You can't edit this item because it has been Archived. Please change the post status and try again.";

/**
 * Handle of the list-table script, as WordPress renders its `<script>` id.
 */
const EDIT_SCREEN_SCRIPT = 'script#aps-edit-screen-js';

test.describe( 'settings: read-only mode', () => {
	const created: number[] = [];

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

		const editUrl = `/wp-admin/post.php?post=${ post.id }&action=edit`;

		// Default (no stored setting): read-only is on.
		let response = await page.goto( editUrl );
		expect( response?.status() ).toBe( 500 );
		await expect( page.locator( 'body' ) ).toContainText(
			READ_ONLY_MESSAGE
		);

		// Stored false: the very next request behaves differently — no cache
		// bust, no restart.
		await setPluginSettings( requestUtils, false );
		response = await page.goto( editUrl );
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);

		// Stored true: back to blocked.
		await setPluginSettings( requestUtils, true );
		response = await page.goto( editUrl );
		expect( response?.status() ).toBe( 500 );
		await expect( page.locator( 'body' ) ).toContainText(
			READ_ONLY_MESSAGE
		);
	} );

	test( 'the list-table script follows the setting', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only enqueue' ),
			status: 'publish',
		} );
		created.push( post.id );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await expect( page.locator( EDIT_SCREEN_SCRIPT ) ).toHaveCount( 1 );

		await setPluginSettings( requestUtils, false );

		await admin.visitAdminPage( 'edit.php', postListQuery() );
		await expect( page.locator( EDIT_SCREEN_SCRIPT ) ).toHaveCount( 0 );
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
