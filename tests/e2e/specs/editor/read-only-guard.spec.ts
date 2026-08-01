/**
 * Pins the read-only editor guard (`Admin\PostEditorGuard`).
 *
 * With read-only mode on (the plugin default), opening an archived post in the
 * editor is stopped with `wp_die()` and an exact message; the post-save
 * round-trip is redirected to the list table instead of re-rendering the
 * editor; and `action=unarchive` is always allowed through so the row-action
 * flow can complete.
 *
 * Only the post-save redirect test below loops {@link POST_TYPES}. The other
 * three pin `enforce_read_only()`'s wp_die and pass-through branches, and
 * `deny_editing_archived()`'s map_meta_cap deny — all three key off post
 * status only, never a post-type primitive, so every type produces an
 * identical result. The redirect target, though, is built by
 * `PostListUrlBuilder::for_post_type( $post->post_type, true )` from the
 * post's actual type — `post` gets a bare `edit.php`, everything else gets
 * `post_type={type}` appended — so a regression that hardcoded the redirect
 * to `post`'s shape would only be visible on a non-`post` type.
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
	editUrl,
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
 * needs to remove it again. The post-save redirect test below loops
 * {@link POST_TYPES}, seeding more than one type into the same `created`
 * array, so a single hardcoded base would silently fail to delete anything
 * but `post`.
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

test.describe( 'editor: read-only guard', () => {
	const created: CreatedPost[] = [];
	let READ_ONLY_MESSAGE: string;

	test.beforeAll( async ( { requestUtils } ) => {
		( { read_only_message: READ_ONLY_MESSAGE } = await pluginStrings(
			requestUtils
		) );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deleteCreated( requestUtils, created.splice( 0 ) );
	} );

	test( 'opening an archived post in the editor is blocked with the exact message', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only blocked' ),
			status: 'publish',
		} );
		created.push( { id: post.id, type: 'posts' } );
		await archivePost( requestUtils, post.id );

		const response = await page.goto( editUrl( post.id ) );

		expect( response?.status() ).toBe( 500 );
		await expect( page.locator( 'body' ) ).toContainText(
			READ_ONLY_MESSAGE
		);
	} );

	test( 'a non-archived post still opens in the editor', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only allowed' ),
			status: 'draft',
		} );
		created.push( { id: post.id, type: 'posts' } );

		const response = await page.goto( editUrl( post.id ) );

		// Control: the guard is scoped to archived posts, not to the screen.
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);
	} );

	// PostEditorGuard::redirect_to_list() passes $post->post_type straight
	// through to PostListUrlBuilder::for_post_type() — 'post' comes back as a
	// bare edit.php, every other type gets post_type={type} appended (see the
	// file header). Looping here, with an assertion that actually reads the
	// query string, is what makes that distinction observable: a `post`-only
	// run can't tell "used the real post type" from "hardcoded post", since
	// both produce an identical URL for `post`.
	for ( const postType of POST_TYPES ) {
		test( `${ postType.label }: the post-save round trip lands on the list table`, async ( {
			page,
			requestUtils,
		} ) => {
			const post = await seedPost( requestUtils, {
				title: uniqueTitle( `Read only save ${ postType.key }` ),
				status: 'publish',
				type: postType.restBase,
			} );
			created.push( { id: post.id, type: postType.restBase } );
			await archivePost( requestUtils, post.id );

			// action=edit&message=1 is where WordPress sends the browser after a
			// successful save; the guard turns that into a list-table redirect
			// rather than the blocked-editor screen.
			await page.goto( `${ editUrl( post.id ) }&message=1` );

			const url = new URL( page.url() );
			expect( url.pathname ).toContain( '/wp-admin/edit.php' );
			expect( url.searchParams.get( 'post_type' ) ).toBe(
				'post' === postType.key ? null : postType.queryArg
			);
			await expect( page.locator( 'body' ) ).not.toContainText(
				READ_ONLY_MESSAGE
			);
		} );
	}

	test( 'action=unarchive is never blocked by the guard', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await seedPost( requestUtils, {
			title: uniqueTitle( 'Read only unarchive' ),
			status: 'publish',
		} );
		created.push( { id: post.id, type: 'posts' } );
		await archivePost( requestUtils, post.id );

		// Without a nonce the request still gets past the guard — it fails
		// later, on WordPress's own CSRF check, and never shows the read-only
		// message.
		await page.goto( editUrl( post.id, 'unarchive' ) );
		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			ARCHIVED_STATUS_SLUG
		);

		// With the nonce the row action carries, the same action completes.
		await page.goto(
			`/wp-admin/edit.php?${ postListQuery( {
				postStatus: ARCHIVED_STATUS_SLUG,
			} ) }`
		);
		await rowLocator( page, post.id ).hover();
		const href = await rowActionLocator(
			page,
			post.id,
			'unarchive'
		).getAttribute( 'href' );

		await page.goto( href as string );

		await expect( page.locator( 'body' ) ).not.toContainText(
			READ_ONLY_MESSAGE
		);
		expect( ( await postState( requestUtils, post.id ) ).post_status ).toBe(
			'publish'
		);
	} );
} );
