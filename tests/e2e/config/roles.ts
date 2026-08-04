/**
 * External dependencies
 */
import path from 'node:path';

/**
 * Directory holding the authenticated browser storage state for each role.
 *
 * Written by `auth.setup.ts` (non-admin roles) and `global-setup.ts` (admin),
 * consumed by `playwright.config.ts` and by specs via
 * `test.use( { storageState: storageStatePath( 'editor' ) } )`.
 *
 * Git-ignored: the files hold live session cookies.
 */
export const AUTH_DIR = path.join( __dirname, '..', '..', '..', 'playwright', '.auth' );

/**
 * Roles the harness keeps a signed-in browser state for.
 *
 * `admin` already exists in a stock wp-env install; the rest are created by
 * `auth.setup.ts`.
 */
export const ROLES = [ 'administrator', 'editor', 'author', 'subscriber' ] as const;

export type Role = ( typeof ROLES )[ number ];

/**
 * Non-admin users created by `auth.setup.ts`, keyed by role.
 *
 * Passwords are fixed so the state can be regenerated deterministically; this
 * is a throwaway local environment.
 */
export const ROLE_USERS: Record<
	Exclude< Role, 'administrator' >,
	{ username: string; email: string; password: string }
> = {
	editor: {
		username: 'aps_editor',
		email: 'aps_editor@example.com',
		password: 'aps-editor-password',
	},
	author: {
		username: 'aps_author',
		email: 'aps_author@example.com',
		password: 'aps-author-password',
	},
	subscriber: {
		username: 'aps_subscriber',
		email: 'aps_subscriber@example.com',
		password: 'aps-subscriber-password',
	},
};

/**
 * Absolute path to the stored authentication state for a role.
 *
 * @param role Role to resolve the storage state for.
 */
export function storageStatePath( role: Role ): string {
	return path.join( AUTH_DIR, `${ role }.json` );
}

/**
 * Storage state used by every project unless a spec opts into another role.
 */
export const ADMIN_STORAGE_STATE = storageStatePath( 'administrator' );

/**
 * Slug of the plugin under test, as `RequestUtils.activatePlugin()` keys it
 * (kebab-cased plugin name from `/wp/v2/plugins`).
 */
export const PLUGIN_SLUG = 'archived-post-status';

/**
 * Default slug of the archived post status.
 *
 * @see src/Status/PostStatusValue.php — `case Slug = 'archive'`, filterable via
 * `aps_post_status_slug`. Declared once here so the global setup sweep and the
 * specs cannot drift apart.
 */
export const ARCHIVED_STATUS_SLUG = 'archive';

/**
 * Default label of the archived post status.
 *
 * @see src/Status/PostStatusValue.php — `case Label = 'Archived'`, filterable
 * via `aps_archived_label_string`.
 */
export const ARCHIVED_STATUS_LABEL = 'Archived';
