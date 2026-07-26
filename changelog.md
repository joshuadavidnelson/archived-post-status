# Archived Post Status Changelog
---

## 0.4.0 - July 25, 2026

The biggest release since the plugin was first published. Archiving is now available everywhere you work — the block editor, the classic editor, the posts list, bulk actions, and WP-CLI — and archived posts remember where they came from, so restoring one puts it back the way it was.

New documentation site at [docs.archivedpoststat.us](https://docs.archivedpoststat.us/)

### Added

- **Block editor support** - an "Archive" button in the editor's post summary panel, with a confirmation prompt before it runs.
- **Classic editor support** - an "Archive" link next to "Move to Trash" in the Publish box.
- **Bulk archive and unarchive** - "Archive" and "Unarchive" now appear in the Bulk actions dropdown on the posts list. Posts that can't be processed are skipped instead of stopping the whole batch, and a notice tells you why each one was skipped: someone else is editing it, you don't have permission, it no longer exists, or its status isn't eligible.
- **Undo** - the "moved to the Archive" notice includes an Undo link that restores everything you just archived back to its previous status.
- **Inline row actions** - hover any post in the list to reveal "Archive", or "Unarchive" when viewing the Archived filter. This replaces the "Archived" option that 0.3.x injected into the status dropdown in Quick Edit and the post editor.
- **"Archived" admin column** - when you filter the posts list by Archived, a sortable column shows who archived each post and when.
- **Archive metadata** - archiving now records the previous post status, the previous comment and ping status, the archive date, and the user who archived it. Unarchiving restores all of it. (Posts archived before 0.4.0 have no metadata and still restore to Draft.)
- **WP-CLI commands** - `wp post archive <id>...` and `wp post unarchive <id>...`, each accepting one or more IDs. `wp post archive` takes `--force` to skip the eligible-status check, `wp post unarchive` takes `--status=<status>` to restore to a specific status, and both take `--defer-term-counting` for large batches.
- **`aps_archive_post()` and `aps_unarchive_post()`** - real API functions modeled on core's `wp_trash_post()` and `wp_untrash_post()`, with `aps_pre_archive_post` / `aps_pre_unarchive_post` short-circuit filters and `aps_archived_post` / `aps_unarchived_post` actions.
- **Front-end protection** - a visitor who isn't allowed to see archived content now gets a 404 when they request a single archived post. The check runs consistently for every visitor, including a post's own author, rather than varying with WordPress's private-post rules.
- **Per-action capabilities** - `aps_current_user_can_archive()`, `aps_current_user_can_unarchive()`, and `aps_current_user_can_edit()`, filterable through `aps_default_archive_capability`, `aps_default_unarchive_capability`, and `aps_default_edit_capability` (defaults `edit_others_posts`, `edit_others_posts`, and `edit_post`). Viewing is unchanged - `aps_default_read_capability`, default `read_private_posts`.
- **Settings groundwork** - settings are now stored in a single `aps_settings` option, which currently holds one value, `is_read_only`. There is no settings screen yet; the admin UI is planned for a future release and all behavior stays filter-driven in 0.4.0.
- More filters throughout: `aps_supported_post_types`, `aps_archivable_statuses`, `aps_is_classic_editor`, `aps_enable_archive_meta`, `aps_status_arg_dashicon`, `aps_status_arg_protected`, `aps_unarchive_post_status`, `aps_unarchive_post_comment_status`, `aps_unarchive_post_ping_status`, `aps_archived_post_link`, `aps_get_archive_post_link`, and `aps_get_unarchive_post_link`. See the [documentation site](https://docs.archivedpoststat.us/) for the full reference.

### Changed

- The plugin was rebuilt from a single procedural file into small, focused, namespaced classes under `src/`, loaded by a lightweight autoloader. Composer is a development tool only - no extra dependencies ship to your site.
- Archived posts are now kept out of the default "All" view on the posts list, the same way Trash is. Use the "Archived" filter link to see them.
- **The `aps_save_post()` function has been removed.** Closing comments and pings on an archived post now happens in `Status\PostStatusGuard`, so `remove_action( 'save_post', 'aps_save_post', 10 )` no longer unhooks anything and fails silently. There is no filter to switch that behavior off in 0.4.0 - the only supported opt-out is to drop the post type with `aps_supported_post_types` or `aps_excluded_post_types`, which turns off archiving for that type entirely.
- **The `aps_is_frontend()` function has been removed.** 0.3.x defined it only so it could hook itself to `aps_status_arg_exclude_from_search`, and 0.4.0 computes that default directly instead. Any code calling `aps_is_frontend()` will fatal - use `! is_admin()` in its place.
- Added PHPUnit and Jest test suites, static analysis, and coding standards checks to the project.

### Localization

- `languages/archived-post-status.pot` regenerated from scratch (the bundled copy had been stale since ~0.3.1). Of the plugin's 28 translatable strings, 20 are net-new in 0.4.0 (mostly the block editor, bulk actions, row actions, and notice text) and 1 changed wording (`WordPress Error` → `WordPress &rsaquo; Error`, matching WP core's own `wp_die()` title convention); nothing was removed. The bundled `.po`/`.mo` catalogs (cs_CZ, de_DE, es_ES, fr_FR, nl_NL, pt_PT, ru_RU) predate this and cover only the original handful of strings - translators will need to pick up the new and changed text.

### Deprecated

- `aps_is_excluded_post_type()` - use `! aps_is_supported_post_type( $post_type )` instead. The old function still works and now emits a standard WordPress deprecation notice.

### Fixed

- **The `aps_post_status_slug` filter is now honored at every point the plugin checks or writes the status.** Under 0.3.x the filter only changed the slug the status was registered under - every internal comparison and every database write still used the literal `archive`. 0.4.0 resolves the filtered slug at all of those points. If you use this filter, read the upgrade note below before updating.

### Upgrade note for sites using the `aps_post_status_slug` filter

This only applies if you changed the status slug with the `aps_post_status_slug` filter. Everyone else can update normally.

Because 0.3.x saved the literal `archive` to the database no matter what your filter returned, posts archived before this update still carry that old value and will no longer be recognized as archived once you upgrade. **Back up your database first**, then run a one-off query to bring the old rows in line:

```sql
UPDATE wp_posts SET post_status = 'archived' WHERE post_status = 'archive';
```

Replace `archived` with whatever slug your filter returns, and replace the `wp_` prefix with your site's actual table prefix if it differs.

## 0.3.12 - Feb 16, 2026

- Tested up to WordPress 6.9.1
- Tested up to PHP 8.4
- Move over to composer for phpcs, phpstan, and linting checks
- Upgrade Github actions to actions/checkout@v6 running on php 8.4

## 0.3.11 - June 15, 2024

- Fix release and versioning issues that shipped with 0.3.10

## 0.3.10 - June 15, 2024

- Test & update support for WP 6.5.4
- Increase minimum supported PHP to 8.1, as 8.0 is end of life.
- Increase minimum WordPress version to 5.9, to align with the PHP version.
- Darken logo colors for better contrast.
- Improve German translations, h/t @mdibella-dev

## 0.3.9.1 - January 19, 2024

- Fixing version numbers in files, missing from 0.3.9 release.

## 0.3.9 - January 19, 2024

- Fix deprecated php warning on `filter_input`, using native WP functions for escaping & getting query var. Fixes another issue, where archived posts couldn't be trashed (Closes #35)
- Add `aps_archived_label_string` filter to modify the "Archived" string used for the label.
- Add `aps_title_separator` and `aps_title_label` to filter the post title prefix and separator, defaults to 'Archived' with a `:` separator. Disable the title label entirely by using `add_filter( 'aps_title_prefix', '__return_false' );` in your `functions.php` file or custom plugin file. Closes #21
- Added `aps_title_label_before` filter, defaults to `true` - pass `false` to have the label appear after the title instead of before it. This change along with the label string filter above closes #31
- Add PHPUnit tests & github actions.
- Update some comments and documentation, readmes, etc

## 0.3.8 - December 15, 2023

Ownership of this plugin is being transferred to [Joshua David Nelson](https://github.com/joshuadavidnelson/). A huge thank you to @fjarrett for his work on this plugin to this point. More info to come soon!

This update includes:
- Tested up to WordPress 6.4.2
- Add minimum PHP of 7.4
- Bump minimum WordPress to 5.3
- Add Github actions for deployment to WP repo
- Update contributors in readmes
- Add PHPStan and PHPCS Github actions

## 0.3.7 - December 23, 2016

* Tweak: Indicate support for WordPress 4.7.

## 0.3.6 - April 13, 2016

* Fix: Bug causing Archived status label to always appear on edit screen.

Props [fjarrett](https://github.com/fjarrett)

## 0.3.5 - April 13, 2016

* New: Indicate support for WordPress 4.5.
* New: Added language support for `cs_CZ`.
* New: Add filter to allow Archived content to be editable ([#12](https://github.com/fjarrett/archived-post-status/pull/12)).

Props [fjarrett](https://github.com/fjarrett)

## 0.3.4 - December 14, 2015

* New: Indicate support for WordPress 4.4.
* Fix: Broken title when post format icon is present ([#9](https://github.com/fjarrett/archived-post-status/pull/9)).

Props [fjarrett](https://github.com/fjarrett), [brandbrilliance](https://github.com/brandbrilliance)

## 0.3.3 - September 12, 2015

* New: Indicate support for WordPress 4.3.

Props [fjarrett](https://github.com/fjarrett)

## 0.3.2 - March 25, 2015

* Fix: Non-object warnings when `$post` is null ([#6](https://github.com/fjarrett/archived-post-status/issues/6)).

Props [fjarrett](https://github.com/fjarrett), [stevethemechanic](https://github.com/stevethemechanic), [edwin-yard](https://profiles.wordpress.org/edwin-yard/)

## 0.3.1 - January 27, 2015

* New: Added language support for `nl_NL`.
* Tweak: Refreshed existing language files.
* Fix: Missing argument warning on `the_title` filter.

Props [fjarrett](https://github.com/fjarrett), [RavanH](https://github.com/RavanH), [htrex](https://profiles.wordpress.org/htrex/)

## 0.3.0 - January 26, 2015

* New: Added language support for `de_DE`, `es_ES`, `fr_FR`, `pt_PT` and `ru_RU`.
* New: Users with the `read_private_posts` capability can now view Archived content.
* New: Automatically close comments and pings when content is archived.
* Tweak: Allow mulitple post states to exist alongside Archived in edit screen.
* Fix: The `aps_excluded_post_types` filter now works as expected on Edit screens.

Props [fjarrett](https://github.com/fjarrett)

## 0.2.0 - January 21, 2015

* New: Make Archived content read-only.

Props [fjarrett](https://github.com/fjarrett), [pollyplummer](https://github.com/pollyplummer)

## 0.1.0 - January 4, 2015

* Initial release.

Props [fjarrett](https://github.com/fjarrett)
