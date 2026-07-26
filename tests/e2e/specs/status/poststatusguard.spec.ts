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
	deletePosts,
	postState,
	rawStatusUpdate,
	seedPost,
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
} );
