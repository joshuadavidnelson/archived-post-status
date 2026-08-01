/**
 * Pins the 0.3.12 rule that archiving closes discussion on a post.
 *
 * `Archive\ArchiveOperation::perform()` writes `comment_status` and
 * `ping_status` to `closed` as part of the same `wp_update_post()` call that
 * moves the status, and the pre-archive values are snapshotted into archive meta
 * so unarchiving can restore them.
 *
 * None of the tests below loop {@link POST_TYPES}: `ArchiveOperation` and
 * `ArchiveMetaListener` key entirely on the post's status and the fixed
 * `comment_status` / `ping_status` fields passed to `wp_update_post()`,
 * resolved the same way for a `WP_Post` regardless of its type — see
 * `editor/archive-unarchive-roundtrip.spec.ts` for the same invariant on the
 * meta round trip.
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
	seedPost,
	uniqueTitle,
} from '../../config/seed';

/**
 * The comment form rendered by the theme's post-comments-form block.
 */
const COMMENT_FORM = 'form.comment-form';

test.describe( 'frontend: archiving closes comments and pings', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'an open post has both statuses closed after archiving', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Open discussion' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const before = await postState( requestUtils, post.id );
		expect( before.comment_status ).toBe( 'open' );
		expect( before.ping_status ).toBe( 'open' );

		const after = await archivePost( requestUtils, post.id );

		expect( after.post_status ).toBe( ARCHIVED_STATUS_SLUG );
		expect( after.comment_status ).toBe( 'closed' );
		expect( after.ping_status ).toBe( 'closed' );
	} );

	test( 'the pre-archive discussion state is snapshotted into archive meta', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Snapshot discussion' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const after = await archivePost( requestUtils, post.id );

		// The listener records the pre-archive object, not a re-read (contract
		// C3) — so the snapshot must be `open`, not the `closed` the same
		// update just wrote.
		expect( after.meta.comment_status ).toBe( 'open' );
		expect( after.meta.ping_status ).toBe( 'open' );
		expect( after.meta.previous_status ).toBe( 'publish' );
	} );

	test( 'the front-end comment form is present before archiving and gone after', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Commentable' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		await page.goto( post.link );
		await expect( page.locator( COMMENT_FORM ) ).toBeVisible();

		const archived = await archivePost( requestUtils, post.id );

		await page.goto( archived.link );
		await expect( page.locator( COMMENT_FORM ) ).toHaveCount( 0 );
	} );
} );
