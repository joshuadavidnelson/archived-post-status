/**
 * Pins the Archive control in both editors.
 *
 * Block editor: `assets/js/block-editor.js` renders an Archive button into the
 * post-status panel and guards it with a confirm dialog.
 * Classic editor: `Admin\PostEditor::post_submitbox_archive_button()` renders an
 * Archive link in the submit box, and `Admin\EditorContext::is_classic_editor()`
 * (via the `aps_is_classic_editor` filter) keeps the block-editor bundle off the
 * classic screen.
 */

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { ARCHIVED_STATUS_SLUG } from '../../config/roles';
import { noticeWith } from '../../config/admin';
import {
	FIXTURE_TOGGLES,
	resetFixtures,
	setFixtures,
} from '../../config/fixtures';
import {
	deletePosts,
	postState,
	seedPost,
	uniqueTitle,
} from '../../config/seed';

/**
 * Class the block-editor bundle puts on its Archive button.
 */
const BLOCK_ARCHIVE_BUTTON = 'a.editor-post-archive';

/**
 * Container the classic submit box renders the Archive link into.
 */
const CLASSIC_ARCHIVE_LINK = '#archive-action a';

/**
 * Handle of the block-editor script, as WordPress renders its `<script>` id.
 */
const BLOCK_SCRIPT = 'script#aps-block-editor-js';

test.describe( 'editor: block editor archive button', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'the Archive button is rendered and archives after the confirm is accepted', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Block archive' ),
			status: 'draft',
		} );
		created.push( post.id );

		await admin.editPost( post.id );
		await editor.openDocumentSettingsSidebar();

		const button = page.locator( BLOCK_ARCHIVE_BUTTON );
		await expect( button ).toBeVisible();
		await expect( button ).toHaveText( 'Archive' );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await Promise.all( [ page.waitForURL( /edit\.php/ ), button.click() ] );

		await expect(
			noticeWith( page, '1 post moved to the Archive.' )
		).toBeVisible();
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			ARCHIVED_STATUS_SLUG
		);
	} );

	test( 'dismissing the confirm leaves the post alone', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Block archive cancelled' ),
			status: 'draft',
		} );
		created.push( post.id );

		await admin.editPost( post.id );
		await editor.openDocumentSettingsSidebar();

		const button = page.locator( BLOCK_ARCHIVE_BUTTON );

		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		await button.click();

		// The click handler calls preventDefault(), so the editor stays put.
		await expect( button ).toBeVisible();
		expect( page.url() ).toContain( 'post.php' );
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			'draft'
		);
	} );
} );

test.describe( 'editor: classic editor archive link', () => {
	const created: number[] = [];

	test.beforeEach( async ( { requestUtils } ) => {
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.classicEditor ]: true,
		} );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
		await resetFixtures( requestUtils, [ FIXTURE_TOGGLES.classicEditor ] );
	} );

	test( 'the submit box carries an Archive link and the block bundle is not loaded', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Classic archive' ),
			status: 'draft',
		} );
		created.push( post.id );

		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);

		// Classic screen, not the block editor.
		await expect( page.locator( '#submitdiv' ) ).toBeVisible();

		const link = page.locator( CLASSIC_ARCHIVE_LINK );
		await expect( link ).toBeVisible();
		await expect( link ).toHaveText( 'Archive' );

		// `aps_is_classic_editor` short-circuits the enqueue, which matters:
		// the bundle dereferences wp.element / wp.editPost and would throw
		// on a classic screen.
		await expect( page.locator( BLOCK_SCRIPT ) ).toHaveCount( 0 );
	} );

	test( 'the Archive link archives the post and returns to the list', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Classic archive click' ),
			status: 'publish',
		} );
		created.push( post.id );

		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);

		await Promise.all( [
			page.waitForURL( /edit\.php/ ),
			page.locator( CLASSIC_ARCHIVE_LINK ).click(),
		] );

		await expect(
			noticeWith( page, '1 post moved to the Archive.' )
		).toBeVisible();
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			ARCHIVED_STATUS_SLUG
		);
	} );
} );
