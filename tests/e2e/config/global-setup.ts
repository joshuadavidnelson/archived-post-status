/**
 * External dependencies
 */
import { request } from '@playwright/test';
import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { resetFixtures } from './fixtures';
import {
	ADMIN_STORAGE_STATE,
	ARCHIVED_STATUS_SLUG,
	PLUGIN_SLUG,
} from './roles';
import { POST_TYPES, resetPluginSettings } from './seed';

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
 * @param postType     REST base of the post type to sweep (see {@link POST_TYPES}).
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
 * the plugin under test is active, clears posts, pages and the CPT fixture's
 * content — including the archived items the upstream helpers cannot see —
 * and resets the mutable options the suite depends on, so specs begin from a
 * known-empty content set and stock plugin/fixture behaviour.
 *
 * The option reset matters because a run that dies before a spec's `afterEach`
 * strands state in the database: a leftover `aps_settings` with
 * `is_read_only => false` silently un-blocks the editor guard and fails every
 * read-only assertion in the next run, and a leftover `aps_test_*` toggle
 * leaves a fixture filter hooked for specs that never opted into it. Both
 * present as code failures with no hint that state is the cause.
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

	// Start from a clean content set. `deleteAllPosts()` / `deleteAllPages()`
	// are upstream helpers with no equivalent for the `aps_book` fixture post
	// type (tests/e2e/fixtures/aps-cpt.php), so the archived-content sweep
	// below runs for every post type under test, `aps_book` included — an
	// archived post is otherwise invisible to both upstream helpers regardless
	// of type, per `deleteAllArchived()`'s own docblock.
	await requestUtils.deleteAllPosts();
	await requestUtils.deleteAllPages();
	for ( const { restBase } of POST_TYPES ) {
		await deleteAllArchived( requestUtils, restBase );
	}

	// Start from stock plugin settings and every fixture toggle off.
	await resetPluginSettings( requestUtils );
	await resetFixtures( requestUtils );

	await requestContext.dispose();
}

export default globalSetup;
