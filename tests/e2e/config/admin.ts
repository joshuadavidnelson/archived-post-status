/**
 * Locators and URL builders for the post list table screen (`edit.php`).
 *
 * Shared by the post-list, editor and role specs so the screen's markup
 * contract lives in one place: if WordPress renames the notice container or the
 * bulk-action controls, exactly one file changes.
 */

/**
 * External dependencies
 */
import type { Locator, Page } from '@playwright/test';

/**
 * The theme renders the post title through the post-title block, which is what
 * runs the `the_title` filter on the front end. Scoped to the `h1` because the
 * single template reuses the same block class for the related-posts headings
 * further down the page.
 */
export const POST_TITLE = 'h1.wp-block-post-title';

/**
 * Admin URL for the classic edit screen of a post.
 *
 * @param id     Post id.
 * @param action Value of the `action` query arg.
 */
export function editUrl( id: number, action = 'edit' ): string {
	return `/wp-admin/post.php?post=${ id }&action=${ action }`;
}

/**
 * Query string for the post list table, filtered to a status when given.
 *
 * @param options.postType   Post type slug. Defaults to `post`.
 * @param options.postStatus Optional status filter (e.g. the archived slug).
 * @param options
 */
export function postListQuery(
	options: {
		postType?: string;
		postStatus?: string;
	} = {}
): string {
	const { postType = 'post', postStatus } = options;
	const params = new URLSearchParams( { post_type: postType } );

	if ( postStatus ) {
		params.set( 'post_status', postStatus );
	}

	return params.toString();
}

/**
 * Every admin notice container on the screen.
 *
 * `wp_admin_notice()` is called with `id => message`, so the container id is
 * the stable hook regardless of the notice classes WordPress adds around it.
 *
 * More than one can be present: core's own bulk-message block on `edit.php`
 * also renders `id="message"` whenever it recognises a counter in the URL
 * (`locked` is shared vocabulary), so callers should reach for
 * {@link noticeWith} rather than asserting on this locator directly.
 *
 * @param page Page under test.
 */
export function noticeLocator( page: Page ): Locator {
	return page.locator( '#message' );
}

/**
 * The admin notice containing the given text.
 *
 * @param page Page under test.
 * @param text Substring or pattern the notice must contain.
 */
export function noticeWith( page: Page, text: string | RegExp ): Locator {
	return noticeLocator( page ).filter( { hasText: text } );
}

/**
 * A single row of the list table.
 *
 * @param page Page under test.
 * @param id   Post id.
 */
export function rowLocator( page: Page, id: number ): Locator {
	return page.locator( `#post-${ id }` );
}

/**
 * A row action link inside a post's row.
 *
 * @param page   Page under test.
 * @param id     Post id.
 * @param action Row action key (`archive`, `unarchive`, `edit`, `view`, …).
 */
export function rowActionLocator(
	page: Page,
	id: number,
	action: string
): Locator {
	return rowLocator( page, id ).locator( `.row-actions .${ action } a` );
}

/**
 * The bulk-select checkbox inside a post's row.
 *
 * Core names every row's checkbox `post[]`; scoping to the row (rather than a
 * page-wide `input[name="post[]"]` selector) is what makes the locator
 * resolve to exactly one element per post id.
 *
 * @param page Page under test.
 * @param id   Post id.
 */
export function rowCheckboxLocator( page: Page, id: number ): Locator {
	return rowLocator( page, id ).locator( 'input[name="post[]"]' );
}

/**
 * Tick the bulk-action checkbox for each of the given posts.
 *
 * @param page Page under test.
 * @param ids  Post ids to select.
 */
export async function selectRows( page: Page, ids: number[] ): Promise< void > {
	for ( const id of ids ) {
		await rowCheckboxLocator( page, id ).check();
	}
}

/**
 * Choose a bulk action, apply it, and return the result counters.
 *
 * The counters come from the `Location` header of the redirect the bulk
 * handler produced, NOT from `page.url()`: `wp_removable_query_args()`
 * (wp-includes/functions.php) lists `ids`, `locked` and `skipped`, and the
 * admin strips every listed argument out of the visible URL with
 * `history.replaceState()` once the page has rendered. The redirect target is
 * what `Admin\BulkActionResult::apply_to_url()` actually composed, so it is the
 * contract worth pinning — and the only place the stripped counters can still
 * be observed.
 *
 * @param page   Page under test.
 * @param action Bulk action value (`archive` / `unarchive`).
 * @return Query args of the redirect the handler returned.
 */
export async function applyBulkAction(
	page: Page,
	action: string
): Promise< URLSearchParams > {
	await page.locator( '#bulk-action-selector-top' ).selectOption( action );

	const [ redirect ] = await Promise.all( [
		page.waitForResponse(
			( response ) =>
				'document' === response.request().resourceType() &&
				response.status() >= 300 &&
				response.status() < 400
		),
		page.waitForURL( /edit\.php/ ),
		page.locator( '#doaction' ).click(),
	] );

	const location = redirect.headers().location;

	if ( ! location ) {
		throw new Error(
			'The bulk action redirect carried no Location header.'
		);
	}

	return new URL( location, page.url() ).searchParams;
}

/**
 * Read the `bulk-posts` nonce out of the rendered list table form.
 *
 * Bulk actions on `edit.php` submit over GET, so a spec can compose a request
 * WordPress accepts — which is the only way to feed the handler an id that has
 * no checkbox on screen (a deleted post, or an archived post on a view that
 * does not offer the Archive action).
 *
 * @param page Page under test.
 */
export async function readBulkNonce( page: Page ): Promise< string > {
	const nonce = await page
		.locator( '#posts-filter input[name="_wpnonce"]' )
		.first()
		.inputValue();

	if ( ! nonce ) {
		throw new Error( 'Could not read the bulk-posts nonce from edit.php.' );
	}

	return nonce;
}
