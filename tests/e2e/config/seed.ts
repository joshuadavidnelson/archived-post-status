/**
 * Content seeding + inspection helpers shared by every Phase 2B spec.
 *
 * Everything here goes through the admin-authenticated `requestUtils` fixture,
 * so a spec can seed state regardless of which role its browser context is
 * signed in as. Archive transitions route through the `aps-test/v1` mu-plugin
 * fixture, which calls the plugin's own `aps_archive_post()` /
 * `aps_unarchive_post()` — the seeded state is produced by the real code path,
 * not by a raw status write.
 *
 * @see tests/e2e/fixtures/aps-test-api.php
 */

/**
 * External dependencies
 */
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Archive-relevant state of a post, as reported by the fixture's read route.
 */
export interface PostState {
	id: number;
	post_status: string;
	comment_status: string;
	ping_status: string;
	post_title: string;
	link: string;
	meta: {
		previous_status: string;
		archive_date: number;
		archive_user: number;
		comment_status: string;
		ping_status: string;
	};
}

/**
 * A post seeded by {@link seedPost}.
 */
export interface SeededPost {
	id: number;
	title: string;
	link: string;
}

export interface SeedPayload {
	title: string;
	status?: string;
	content?: string;
	comment_status?: 'open' | 'closed';
	ping_status?: 'open' | 'closed';
	author?: number;
	type?: 'posts' | 'pages';
}

let titleCounter = 0;

/**
 * Build a title that is unique across specs, runs and workers.
 *
 * Specs must be independently re-runnable; unique titles keep slug collisions
 * (`my-post-2`) and cross-spec locator matches from ever happening.
 *
 * @param prefix Human-readable prefix, usually the spec name.
 */
export function uniqueTitle( prefix: string ): string {
	titleCounter += 1;

	return `${ prefix } ${ Date.now().toString( 36 ) }-${ titleCounter }`;
}

/**
 * Create a post (or page) over the core REST API.
 *
 * @param requestUtils Admin request utils.
 * @param payload      Post attributes. `status` accepts any registered status.
 */
export async function seedPost(
	requestUtils: RequestUtils,
	payload: SeedPayload
): Promise< SeededPost > {
	const { type = 'posts', ...data } = payload;

	const post = await requestUtils.rest< { id: number; link: string } >( {
		method: 'POST',
		path: `/wp/v2/${ type }`,
		data: { status: 'draft', ...data },
	} );

	return { id: post.id, title: payload.title, link: post.link };
}

/**
 * Force-delete posts, ignoring ids that are already gone.
 *
 * Used from `afterEach`/`afterAll` so every spec leaves the site as it found
 * it. Failures are swallowed on purpose: a spec that deleted a post as part of
 * its assertions must still be able to run its own teardown.
 *
 * @param requestUtils Admin request utils.
 * @param ids          Post ids to remove.
 * @param type         REST base of the post type. Defaults to `posts`.
 */
export async function deletePosts(
	requestUtils: RequestUtils,
	ids: number[],
	type: 'posts' | 'pages' = 'posts'
): Promise< void > {
	await Promise.all(
		ids.map( async ( id ) => {
			try {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/${ type }/${ id }`,
					params: { force: true },
				} );
			} catch {
				// Already deleted — teardown stays best-effort.
			}
		} )
	);
}

/**
 * Archive a post through `aps_archive_post()`.
 *
 * @param requestUtils Admin request utils.
 * @param id           Post id.
 * @return The post's state after the transition.
 */
export async function archivePost(
	requestUtils: RequestUtils,
	id: number
): Promise< PostState > {
	return requestUtils.rest< PostState >( {
		method: 'POST',
		path: `/aps-test/v1/archive/${ id }`,
	} );
}

/**
 * Unarchive a post through `aps_unarchive_post()`.
 *
 * @param requestUtils Admin request utils.
 * @param id           Post id.
 * @return The post's state after the transition.
 */
export async function unarchivePost(
	requestUtils: RequestUtils,
	id: number
): Promise< PostState > {
	return requestUtils.rest< PostState >( {
		method: 'POST',
		path: `/aps-test/v1/unarchive/${ id }`,
	} );
}

/**
 * Read a post's archive-relevant state.
 *
 * Preferred over `/wp/v2/posts/<id>` because it also returns the archive meta
 * keys, which the core controller does not expose.
 *
 * @param requestUtils Admin request utils.
 * @param id           Post id.
 */
export async function postState(
	requestUtils: RequestUtils,
	id: number
): Promise< PostState > {
	return requestUtils.rest< PostState >( {
		method: 'GET',
		path: `/aps-test/v1/post/${ id }`,
	} );
}

/**
 * Set a post's status with a bare `wp_update_post()` call — no plugin API.
 *
 * The bypass path `Status\PostStatusGuard` exists to correct.
 *
 * @param requestUtils Admin request utils.
 * @param id           Post id.
 * @param data         Status fields to write verbatim.
 */
export async function rawStatusUpdate(
	requestUtils: RequestUtils,
	id: number,
	data: {
		post_status: string;
		comment_status: 'open' | 'closed';
		ping_status: 'open' | 'closed';
	}
): Promise< PostState > {
	return requestUtils.rest< PostState >( {
		method: 'POST',
		path: `/aps-test/v1/post/${ id }/raw-status`,
		data,
	} );
}

/**
 * Hold (or release) the post-edit lock on behalf of another user.
 *
 * @param requestUtils Admin request utils.
 * @param id           Post id.
 * @param userId       User holding the lock; 0 releases it.
 */
export async function lockPost(
	requestUtils: RequestUtils,
	id: number,
	userId: number
): Promise< void > {
	await requestUtils.rest( {
		method: 'POST',
		path: `/aps-test/v1/post/${ id }/lock`,
		data: { user_id: userId },
	} );
}

/**
 * Overwrite the stored archive date / user for a post.
 *
 * `ArchiveMeta::from_post()` stamps `time()`, so posts archived within the same
 * second tie. Sort assertions set explicit timestamps instead of sleeping.
 *
 * @param requestUtils Admin request utils.
 * @param id           Post id.
 * @param meta         Values to write.
 */
export async function setArchiveMeta(
	requestUtils: RequestUtils,
	id: number,
	meta: { archive_date?: number; archive_user?: number }
): Promise< PostState > {
	return requestUtils.rest< PostState >( {
		method: 'POST',
		path: `/aps-test/v1/post/${ id }/archive-meta`,
		data: meta,
	} );
}

/**
 * Write the plugin's `aps_settings` option from outside `Settings\Store`.
 *
 * @param requestUtils Admin request utils.
 * @param isReadOnly   Value for the `is_read_only` setting.
 */
export async function setPluginSettings(
	requestUtils: RequestUtils,
	isReadOnly: boolean
): Promise< { is_read_only: boolean } > {
	return requestUtils.rest( {
		method: 'POST',
		path: '/aps-test/v1/settings',
		data: { is_read_only: isReadOnly },
	} );
}

/**
 * Delete the plugin's `aps_settings` option, restoring stock defaults.
 *
 * @param requestUtils Admin request utils.
 */
export async function resetPluginSettings(
	requestUtils: RequestUtils
): Promise< void > {
	await requestUtils.rest( {
		method: 'POST',
		path: '/aps-test/v1/settings',
		data: { reset: true },
	} );
}

/**
 * Resolve the user ids of the role logins created by `auth.setup.ts`.
 *
 * @param requestUtils Admin request utils.
 * @return Map of `user_login` to user id.
 */
export async function roleUserIds(
	requestUtils: RequestUtils
): Promise< Record< string, number > > {
	const users = await requestUtils.rest<
		Array< { id: number; username: string } >
	>( {
		path: '/wp/v2/users',
		params: { per_page: 100, context: 'edit', search: 'aps_' },
	} );

	return Object.fromEntries(
		users.map( ( user ) => [ user.username, user.id ] )
	);
}
