/**
 * Pins the 0.3.12 round-trip contract: unarchiving restores what archiving
 * captured.
 *
 * `Archive\ArchiveMetaListener` snapshots the pre-archive status, comment status
 * and ping status into post meta; `Archive\UnarchiveOperation` reads that
 * snapshot back. A post therefore comes out of the archive in the state it went
 * in — not in a hardcoded default.
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
	FIXTURE_TOGGLES,
	resetFixtures,
	setFixtures,
} from '../../config/fixtures';
import {
	archivePost,
	deletePosts,
	postState,
	seedPost,
	unarchivePost,
	uniqueTitle,
} from '../../config/seed';

test.describe( 'archive / unarchive round trip', () => {
	const created: number[] = [];

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
	} );

	test( 'a draft comes back as a draft', async ( { requestUtils } ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Round trip draft' ),
			status: 'draft',
			comment_status: 'closed',
			ping_status: 'closed',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		expect( archived.post_status ).toBe( ARCHIVED_STATUS_SLUG );
		expect( archived.meta.previous_status ).toBe( 'draft' );

		const restored = await unarchivePost( requestUtils, post.id );

		expect( restored.post_status ).toBe( 'draft' );
		expect( restored.comment_status ).toBe( 'closed' );
		expect( restored.ping_status ).toBe( 'closed' );
	} );

	test( 'a published post comes back published with discussion re-opened', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Round trip publish' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		expect( archived.post_status ).toBe( ARCHIVED_STATUS_SLUG );
		expect( archived.comment_status ).toBe( 'closed' );
		expect( archived.ping_status ).toBe( 'closed' );

		const restored = await unarchivePost( requestUtils, post.id );

		expect( restored.post_status ).toBe( 'publish' );
		expect( restored.comment_status ).toBe( 'open' );
		expect( restored.ping_status ).toBe( 'open' );
	} );

	test( 'a private post comes back private', async ( { requestUtils } ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Round trip private' ),
			status: 'private',
			comment_status: 'open',
			ping_status: 'closed',
		} );
		created.push( post.id );

		await archivePost( requestUtils, post.id );
		const restored = await unarchivePost( requestUtils, post.id );

		expect( restored.post_status ).toBe( 'private' );
		expect( restored.comment_status ).toBe( 'open' );
		expect( restored.ping_status ).toBe( 'closed' );
	} );

	test( 'the archive meta is written on archive and cleared on unarchive', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Round trip meta' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		expect( archived.meta.previous_status ).toBe( 'publish' );
		expect( archived.meta.archive_date ).toBeGreaterThan( 0 );
		expect( archived.meta.archive_user ).toBeGreaterThan( 0 );

		await unarchivePost( requestUtils, post.id );
		const cleaned = await postState( requestUtils, post.id );

		expect( cleaned.meta.previous_status ).toBe( '' );
		expect( cleaned.meta.archive_date ).toBe( 0 );
		expect( cleaned.meta.archive_user ).toBe( 0 );
	} );

	test( 'a second round trip restores the same state again', async ( {
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Round trip twice' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		await archivePost( requestUtils, post.id );
		await unarchivePost( requestUtils, post.id );
		await archivePost( requestUtils, post.id );
		const restored = await unarchivePost( requestUtils, post.id );

		expect( restored.post_status ).toBe( 'publish' );
		expect( restored.comment_status ).toBe( 'open' );
		expect( restored.ping_status ).toBe( 'open' );
	} );
} );

/**
 * `Plugin::hookables()` only registers `Archive\ArchiveMetaListener` when
 * `aps_enable_archive_meta` resolves truthy (default `true`). With a site
 * filtering it to `false`, archiving still transitions the status (and
 * still forces comment/ping closed — that is `ArchiveOperation`'s own
 * behaviour, independent of the listener), but no
 * `_aps_archive_meta_*` postmeta is ever written. `UnarchiveOperation::
 * resolve_restore_values()`'s legacy branch — the one pre-0.4.0 archived
 * content always hits, since that meta never existed before 0.4.0 — is
 * the only thing left to restore from, so unarchiving such a post must
 * fall back to `draft` / `closed` / `closed` regardless of what the post
 * held before it was archived.
 */
test.describe( 'archive / unarchive round trip: aps_enable_archive_meta disabled', () => {
	const created: number[] = [];

	test.beforeEach( async ( { requestUtils } ) => {
		await setFixtures( requestUtils, {
			[ FIXTURE_TOGGLES.disableArchiveMeta ]: true,
		} );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deletePosts( requestUtils, created.splice( 0 ) );
		await resetFixtures( requestUtils, [
			FIXTURE_TOGGLES.disableArchiveMeta,
		] );
	} );

	test( 'archiving writes no archive meta', async ( { requestUtils } ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'No meta archive' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );

		expect( archived.post_status ).toBe( ARCHIVED_STATUS_SLUG );
		// ArchiveOperation itself still forces discussion closed on
		// archive — that part of the contract has no dependency on the
		// meta listener.
		expect( archived.comment_status ).toBe( 'closed' );
		expect( archived.ping_status ).toBe( 'closed' );

		expect( archived.meta.previous_status ).toBe( '' );
		expect( archived.meta.archive_date ).toBe( 0 );
		expect( archived.meta.archive_user ).toBe( 0 );
	} );

	test( 'unarchiving a no-meta archived post falls back to the legacy draft/closed/closed restore', async ( {
		requestUtils,
	} ) => {
		// Published with discussion open: if a (nonexistent) meta snapshot
		// were somehow consulted, the post would come back 'publish' /
		// 'open' / 'open'. Only the legacy fallback produces
		// 'draft' / 'closed' / 'closed' here, so this is what actually
		// tells the two restore paths apart.
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'No meta unarchive' ),
			status: 'publish',
			comment_status: 'open',
			ping_status: 'open',
		} );
		created.push( post.id );

		const archived = await archivePost( requestUtils, post.id );
		expect( archived.meta.archive_date ).toBe( 0 );

		const restored = await unarchivePost( requestUtils, post.id );

		expect( restored.post_status ).toBe( 'draft' );
		expect( restored.comment_status ).toBe( 'closed' );
		expect( restored.ping_status ).toBe( 'closed' );
	} );
} );
