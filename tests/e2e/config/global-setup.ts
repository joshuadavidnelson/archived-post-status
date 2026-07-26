/**
 * External dependencies
 */
import { request } from '@playwright/test';
import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	ADMIN_STORAGE_STATE,
	ARCHIVED_STATUS_SLUG,
	PLUGIN_SLUG,
} from './roles';

/**
 * Upper bound on sweep iterations, so a delete that never takes effect fails
 * loudly instead of hanging the whole run.
 */
const MAX_SWEEP_PAGES = 50;

/**
 * Force-delete every post of a type that carries the archived status.
 *
 * `deleteAllPosts()` / `deleteAllPages()` upstream hardcode the core status
 * list (`publish,future,draft,pending,private,trash`), which never includes the
 * status this plugin owns — so archived content survives them and leaks between
 * runs. Paginates rather than assuming a single 100-item page.
 *
 * @param requestUtils Authenticated admin request utils.
 * @param postType     REST base of the post type to sweep (`posts`, `pages`).
 */
async function deleteAllArchived(
	requestUtils: RequestUtils,
	postType: string
): Promise< void > {
	for ( let page = 0; page < MAX_SWEEP_PAGES; page++ ) {
		const archived: Array< { id: number } > = await requestUtils.rest( {
			path: `/wp/v2/${ postType }`,
			params: {
				status: ARCHIVED_STATUS_SLUG,
				per_page: 100,
				context: 'edit',
			},
		} );

		if ( ! archived.length ) {
			return;
		}

		await Promise.all(
			archived.map( ( post ) =>
				requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/${ postType }/${ post.id }`,
					params: { force: true },
				} )
			)
		);
	}

	throw new Error(
		`Failed to clear "${ ARCHIVED_STATUS_SLUG }" ${ postType } after ${ MAX_SWEEP_PAGES } passes.`
	);
}

/**
 * Prepare the wp-env test site once, before any project runs.
 *
 * Signs in as the wp-env admin and persists the authenticated state so the
 * `auth` setup project and every spec have a session to start from, makes sure
 * the plugin under test is active, and clears posts and pages — including the
 * archived ones the upstream helpers cannot see — so specs begin from a
 * known-empty content set.
 *
 * @param config Resolved Playwright config.
 */
async function globalSetup( config: FullConfig ): Promise< void > {
	const { baseURL } = config.projects[ 0 ].use;

	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		baseURL,
		storageStatePath: ADMIN_STORAGE_STATE,
	} );

	// Authenticate and write the admin storage state to disk.
	await requestUtils.setupRest();

	// The plugin under test must be active for every spec — and it has to be
	// active before the sweep below, since the archived status only exists
	// while the plugin is running.
	await requestUtils.activatePlugin( PLUGIN_SLUG );

	// Start from a clean content set.
	await requestUtils.deleteAllPosts();
	await requestUtils.deleteAllPages();
	await deleteAllArchived( requestUtils, 'posts' );
	await deleteAllArchived( requestUtils, 'pages' );

	await requestContext.dispose();
}

export default globalSetup;
