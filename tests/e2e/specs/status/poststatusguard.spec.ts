/**
 * Pins `Status\PostStatusGuard`.
 *
 * `aps_archive_post()` closes comments and pings as part of archiving. Anything
 * that sets the archived status with a bare `wp_update_post()` skips that, so
 * the guard corrects the state on `save_post`. Its documented limit is equally
 * load-bearing: the bypass path gets state enforcement but no archive meta,
 * because meta is only written from the `aps_archived_post` action.
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
	archivePost,
	deletePosts,
	postState,
	rawStatusUpdate,
	seedPost,
	unarchivePost,
	uniqueTitle,
} from '../../config/seed';
import { wpCli } from '../../config/wp-cli';

test.describe( 'status: PostStatusGuard corrects direct status writes', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'a bare wp_update_post() through WP-CLI gets comments and pings closed', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Guard cli' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		// Deliberately not `wp post archive` — this is the raw status write the
		// guard exists to catch.
		const result = await wpCli( [
			'eval',
			`wp_update_post( array( "ID" => ${ post.id }, "post_status" => "${ ARCHIVED_STATUS_SLUG }", ` +
				'"comment_status" => "open", "ping_status" => "open" ) );' +
				`clean_post_cache( ${ post.id } );` +
				`$post = get_post( ${ post.id } );` +
				'echo wp_json_encode( array( "status" => $post->post_status, ' +
				'"comment" => $post->comment_status, "ping" => $post->ping_status ) );',
		] );

		expect( result.exitCode ).toBe( 0 );
		expect( JSON.parse( result.stdout ) ).toEqual( {
			status: ARCHIVED_STATUS_SLUG,
			comment: 'closed',
			ping: 'closed',
		} );
	} );

	test( 'a bare wp_update_post() through a web request gets the same treatment', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Guard web' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const after = await rawStatusUpdate( requestUtils, post.id, {
			post_status: ARCHIVED_STATUS_SLUG,
			comment_status: 'open',
			ping_status: 'open',
		} );

		expect( after.post_status ).toBe( ARCHIVED_STATUS_SLUG );
		expect( after.comment_status ).toBe( 'closed' );
		expect( after.ping_status ).toBe( 'closed' );
	} );

	test( 'the bypass path writes no archive meta', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Guard meta' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const after = await rawStatusUpdate( requestUtils, post.id, {
			post_status: ARCHIVED_STATUS_SLUG,
			comment_status: 'open',
			ping_status: 'open',
		} );

		// Documented in PostStatusGuard: state is enforced, meta is not written.
		// It matters downstream — with no snapshot, unarchiving falls back to
		// the legacy draft/closed/closed default.
		expect( after.meta.previous_status ).toBe( '' );
		expect( after.meta.archive_date ).toBe( 0 );
		expect( after.meta.archive_user ).toBe( 0 );
	} );

	test( 'a direct write to another status is left alone', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Guard scope' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		// Control: the guard keys on the archived status, so an ordinary
		// status change must not close discussion.
		await rawStatusUpdate( requestUtils, post.id, {
			post_status: 'draft',
			comment_status: 'open',
			ping_status: 'open',
		} );

		const after = await postState( requestUtils, post.id );

		expect( after.post_status ).toBe( 'draft' );
		expect( after.comment_status ).toBe( 'open' );
		expect( after.ping_status ).toBe( 'open' );
	} );

	test( 'a legacy meta-less archived post unarchives to the draft fallback', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Legacy fallback' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		// A pre-0.4.0 archive: status set directly, no archive meta written.
		await rawStatusUpdate( requestUtils, post.id, {
			post_status: ARCHIVED_STATUS_SLUG,
			comment_status: 'closed',
			ping_status: 'closed',
		} );

		await unarchivePost( requestUtils, post.id );
		const after = await postState( requestUtils, post.id );

		// With no recorded snapshot, restore falls back to draft/closed/closed.
		expect( after.post_status ).toBe( 'draft' );
		expect( after.comment_status ).toBe( 'closed' );
		expect( after.ping_status ).toBe( 'closed' );
	} );

	test( 'the aps_unarchive_post_status filter moves the meta-less fallback', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Legacy fallback filter' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		await rawStatusUpdate( requestUtils, post.id, {
			post_status: ARCHIVED_STATUS_SLUG,
			comment_status: 'closed',
			ping_status: 'closed',
		} );

		// `wp post unarchive --status` registers a callback on the same
		// `aps_unarchive_post_status` filter a developer would use, so this
		// drives the real filter path against real WordPress state.
		const result = await wpCli( [
			'post',
			'unarchive',
			String( post.id ),
			'--status=pending',
		] );
		expect( result.exitCode ).toBe( 0 );

		const after = await postState( requestUtils, post.id );
		expect( after.post_status ).toBe( 'pending' );
	} );

	test( 'a trash round-trip defers the exit, then untrash concludes it cleanly', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Guard trash round-trip' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		await archivePost( requestUtils, post.id );

		// Trash defers the exit: the meta snapshot survives intact and
		// comments are NOT reopened while the post sits in trash.
		const trashResult = await wpCli( [
			'post',
			'delete',
			String( post.id ),
		] );
		expect( trashResult.exitCode ).toBe( 0 );
		const trashed = await postState( requestUtils, post.id );
		expect( trashed.post_status ).toBe( 'trash' );
		expect( trashed.comment_status ).toBe( 'closed' );
		expect( trashed.meta.previous_status ).toBe( 'publish' );

		// Core's untrash lands on draft by default (WP 5.6+). That
		// concludes the archive lifecycle: the guard restores the
		// pre-archive discussion state and clears the meta snapshot.
		const untrashResult = await wpCli( [
			'eval',
			`wp_untrash_post( ${ post.id } );`,
		] );
		expect( untrashResult.exitCode ).toBe( 0 );

		const untrashed = await postState( requestUtils, post.id );
		expect( untrashed.post_status ).toBe( 'draft' );
		expect( untrashed.comment_status ).toBe( 'open' );
		expect( untrashed.ping_status ).toBe( 'open' );
		expect( untrashed.meta.previous_status ).toBe( '' );
		expect( untrashed.meta.archive_date ).toBe( 0 );
	} );

	test( 'an out-of-band exit from the archived status restores state and clears meta', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Guard exit' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		// A real 0.4.0 archive: meta snapshot written, discussion closed.
		await archivePost( requestUtils, post.id );
		const archived = await postState( requestUtils, post.id );
		expect( archived.meta.previous_status ).toBe( 'publish' );
		expect( archived.comment_status ).toBe( 'closed' );

		// Out-of-band exit — core Bulk Edit or any direct status write; the
		// plugin's UnarchiveOperation never runs.
		const result = await wpCli( [
			'post',
			'update',
			String( post.id ),
			'--post_status=publish',
		] );
		expect( result.exitCode ).toBe( 0 );

		const after = await postState( requestUtils, post.id );

		// The exit guard restored the discussion snapshot and removed the
		// now-stale archive meta.
		expect( after.post_status ).toBe( 'publish' );
		expect( after.comment_status ).toBe( 'open' );
		expect( after.ping_status ).toBe( 'open' );
		expect( after.meta.previous_status ).toBe( '' );
		expect( after.meta.archive_date ).toBe( 0 );
		expect( after.meta.archive_user ).toBe( 0 );
	} );
} );
