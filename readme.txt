=== Archived Post Status ===
Contributors:      joshuadnelson, fjarrett
Donate link:       https://joshuadnelson.com/donate/
Tags:              archive, archived, status, post status
Requires at least: 6.4
Requires PHP:      8.1
Tested up to:      7.0
Stable tag:        0.4.0
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Use an "Archive" status to unpublish content without having to trash it.

== Description ==

> **New** expanded user interface, easier ways to archive and unarchive. Learn more on the [new documentation site](https://docs.archivedpoststat.us/).

This plugin gives you the power to archive your WordPress content.

WordPress supports a publishing workflow by marking content with a post status:
* 3 for pre-published states: _draft_, _pending_, and _future_. These are editable and pre-viewable, not yet ready for public view.
* 2 types of published statuses: _publish_ and _private_. These are editable and viewable to the public or specific users.
* 1 non-published status: _trash_. Trashed content is not editable or viewable and [automatically deleted](https://codex.wordpress.org/Trash_status#Default_Days_before_Permanently_Deleted) after 30 days.

The one thing missing here is a status for content that is _viewable_ but **not** editable.

=== Introducing the Archive ===

The 'archive' status marks content to a _post-published_ state, viewable to some but no longer edited. Examples might include:

* an out-of-date walkthrough
* a review of a discontinued product
* a rough draft replaced with a final version
* an old, time-specific post that is now irrelevant

Whatever the reason, incorporating the 'Archive' status can be a useful addition to your WordPress editing workflow.

* Unpublish your posts and pages without having to trash them
* Compatible with posts, pages, and public custom post types
* Ideal for sites where certain kinds of content is not meant to be evergreen
* Archive content is hidden from public view, only users with Editor or higher roles can see archived content.

**Learn how to use and extend the plugin at [docs.archivedpoststat.us](https://docs.archivedpoststat.us/)**

**[Over 13](https://translate.wordpress.org/projects/wp-plugins/archived-post-status/)** languages supported

**Did you find this plugin helpful? Please consider [leaving a 5-star review](https://wordpress.org/support/view/plugin-reviews/archived-post-status).**

**Development of this plugin is done [on GitHub](https://github.com/joshuadavidnelson/archived-post-status). Pull requests welcome. Please see [issues reported](https://github.com/joshuadavidnelson/archived-post-status/issues) there before going to the plugin forum.**

== Frequently Asked Questions ==

= New FAQ & Docs Site =

Refer to the FAQs at [docs.archivedpoststat.us](https://docs.archivedpoststat.us/)

= Help! I need support =

Please reach out on the [Github Issues](https://github.com/joshuadavidnelson/archived-post-status/issues) or in the WordPress [support forums](https://wordpress.org/support/plugin/archived-post-status/).

= I have a feature request =

Please reach out on the [Github Issues](https://github.com/joshuadavidnelson/archived-post-status/issues) or in the WordPress [support forums](https://wordpress.org/support/plugin/archived-post-status/).

== Screenshots ==

1. The [Posts Screen](https://wordpress.org/documentation/article/posts-screen/) "All" view does not show archived content, but hover over a post to expose the "Archive" link. See screenshots 6-8 for viewing archived content.
2. Bulk archive option in [Posts Screen](https://wordpress.org/documentation/article/posts-screen/).
3. Block editor view, the archive button appears above the "save as draft" and "move to trash" buttons.
4. Classic editor view, the "Archive" link appears next to "Move to Trash" in the Publish box.
5. The [Posts Screen](https://wordpress.org/documentation/article/posts-screen/) with the "Archived" filter. Archived content appears in this view with "Last Modified Date," "Previous Status," and "Archived Date." columns.
6. The [Posts Screen](https://wordpress.org/documentation/article/posts-screen/) with the "Archived" filter. Hover over a post to expose the "Unarchive" link.
7. The [Posts Screen](https://wordpress.org/documentation/article/posts-screen/) with the "Archived" filter. Bulk "Unarchive" option.
8. Viewing archived content on the front end, with the "Archived" label on the title. By default only users with Editor or higher roles can see archived content.

== Changelog ==

= 0.4.0 - July 25, 2026 =

The biggest release since the plugin was first published. Archiving is now available everywhere you work - the block editor, the classic editor, the posts list, bulk actions, and WP-CLI - and archived posts remember where they came from, so restoring one puts it back the way it was.

New documentation site at [docs.archivedpoststat.us](https://docs.archivedpoststat.us/)

**Added**

- **Block editor support** - an "Archive" button in the editor's post summary panel, with a confirmation prompt before it runs.
- **Classic editor support** - an "Archive" link next to "Move to Trash" in the Publish box.
- **Bulk archive and unarchive** - "Archive" and "Unarchive" now appear in the Bulk actions dropdown on the posts list. Posts that can't be processed are skipped instead of stopping the whole batch, and a notice tells you why each one was skipped: someone else is editing it, you don't have permission, it no longer exists, or its status isn't eligible.
- **Undo** - the "moved to the Archive" notice includes an Undo link that restores everything you just archived back to its previous status.
- **Inline row actions** - hover any post in the list to reveal "Archive", or "Unarchive" when viewing the Archived filter. This replaces the "Archived" option that 0.3.x injected into the status dropdown in Quick Edit and the post editor.
- **"Archived" admin column** - when you filter the posts list by Archived, a sortable column shows who archived each post and when.
- **Archive metadata** - archiving now records the previous post status, the previous comment and ping status, the archive date, and the user who archived it. Unarchiving restores all of it. (Posts archived before 0.4.0 have no metadata and still restore to Draft.)
- **WP-CLI commands** - `wp post archive <id>...` and `wp post unarchive <id>...`, each accepting one or more IDs. `wp post archive` takes `--force` to skip the eligible-status check, `wp post unarchive` takes `--status=<status>` to restore to a specific status, and both take `--defer-term-counting` for large batches.
- **`aps_archive_post()` and `aps_unarchive_post()`** - real API functions modeled on core's `wp_trash_post()` and `wp_untrash_post()`, with `aps_pre_archive_post` / `aps_pre_unarchive_post` short-circuit filters and `aps_archived_post` / `aps_unarchived_post` actions. `aps_get_archive_post_link()` and `aps_get_unarchive_post_link()` return the URLs that trigger them; `aps_get_archived_post_link()`, modeled on core's `get_preview_post_link()`, returns the link to view an archived post.
- **Front-end protection** - a visitor who isn't allowed to see archived content now gets a 404 when they request a single archived post. The check runs consistently for every visitor rather than varying with WordPress's private-post rules; a post's own author can always view their own archived content.
- **Per-action, ownership-aware capabilities** - `aps_current_user_can_view()`, `aps_current_user_can_archive()`, `aps_current_user_can_unarchive()`, and `aps_current_user_can_edit()`, filterable through `aps_default_read_capability`, `aps_default_archive_capability`, `aps_default_unarchive_capability`, and `aps_default_edit_capability`. Authors can archive, unarchive, and view their own content (via the post type's `edit_posts` capability); acting on other authors' content requires `edit_others_posts`. Viewing others' archived content defaults to `read_private_posts`.
- **Settings groundwork** - settings are now stored in a single `aps_settings` option, which currently holds one value, `is_read_only`. There is no settings screen yet; the admin UI is planned for a future release and all behavior stays filter-driven in 0.4.0.
- More filters throughout: `aps_supported_post_types`, `aps_archivable_statuses`, `aps_is_classic_editor`, `aps_enable_archive_meta`, `aps_enable_notices` (suppresses the post-action admin notices when set to `false`), `aps_is_read_only`, `aps_status_arg_public`, `aps_status_arg_private`, `aps_status_arg_protected`, `aps_status_arg_exclude_from_search`, `aps_status_arg_show_in_admin_all_list`, `aps_status_arg_show_in_admin_status_list`, `aps_archive_post_comment_status` and `aps_archive_post_ping_status` (archive-side mirrors of the two below), `aps_unarchive_post_status`, `aps_unarchive_post_comment_status`, `aps_unarchive_post_ping_status`, `aps_archived_post_link`, `aps_get_archive_post_link`, and `aps_get_unarchive_post_link`. See the [documentation site](https://docs.archivedpoststat.us/) for the full reference.

**Changed**

- The plugin was rebuilt from a single procedural file into small, focused, namespaced classes under `src/`, loaded by a lightweight autoloader. Composer is a development tool only - no extra dependencies ship to your site.
- Archived posts are now kept out of the default "All" view on the posts list, the same way Trash is. Use the "Archived" filter link to see them.
- Archiving is now restricted to `public` post types. 0.3.x allowed archiving any post type that wasn't explicitly excluded via `aps_excluded_post_types`, including non-public ones; 0.4.0 starts from the public post types and subtracts the excluded set (`aps_get_supported_post_types()` returns the resulting list). If you need to archive a non-public custom post type, add it back with the `aps_supported_post_types` filter.
- Added PHPUnit and Jest test suites, static analysis, and coding standards checks to the project.
- Tested up to WordPress 7.0.
- The minimum supported WordPress version is now 6.4 (was 5.9). Admin notices are rendered with core's `wp_admin_notice()`, added in 6.4. Sites below 6.4 are a small and shrinking share of installs.

**Removed**

Eleven functions that were global in 0.3.x no longer exist, because the rewrite replaced the plugin's procedural internals with classes. All of them were undocumented internals rather than a published API, but they were callable, so this is a breaking change for any site that referenced one.

Calling any of these now causes a fatal error: `aps_post_status_slug()`, `aps_is_frontend()`, `aps_the_title()`, `aps_save_post()`, `aps_display_post_states()`, `aps_register_archive_post_status()`, `aps_i18n()`, `aps_i18n_strings()`, `aps_post_screen_js()`, `aps_edit_screen_js()`, `aps_load_post_screen()`.

Replacements, where one exists:

- `aps_post_status_slug()` - apply the `aps_post_status_slug` filter yourself, or read `$post->post_status` directly.
- `aps_is_frontend()` - use `! is_admin()`.
- `aps_the_title()` - the title filter is registered internally; control it with `aps_title_label`, `aps_title_label_before`, and `aps_title_separator`. To drop the "Archived: " prefix entirely, return an empty string from `aps_title_label`.
- The rest are internal behavior with no direct replacement: status registration is controlled by the `aps_status_arg_*` filters, the "Archived" post-state label and editor access are handled internally, and the 0.3.x status-dropdown injection was replaced by row actions, bulk actions, and editor buttons.

If you unhooked one of these, that call is now a silent no-op rather than an error. WordPress ignores `remove_filter()` / `remove_action()` for a callback that was never registered, so nothing breaks - but nothing is disabled either, and the behavior you meant to switch off is still active. This affects, in particular:

- `remove_filter( 'the_title', 'aps_the_title' )` - return an empty string from `aps_title_label` instead.
- `remove_action( 'save_post', 'aps_save_post', 10 )` - closing comments and pings on an archived post now happens in `Status\PostStatusGuard`. There is no filter to switch that behavior off in 0.4.0; the only supported opt-out is to drop the post type with `aps_supported_post_types` or `aps_excluded_post_types`, which turns off archiving for that type entirely.
- `remove_action( 'admin_footer-post.php', 'aps_post_screen_js' )` and its `admin_footer-edit.php` counterpart - the injected status dropdown no longer exists to suppress.

Note that 0.4.0 registers its hooks as callbacks on internal object instances, which third-party code cannot reach. Unhooking is therefore no longer an extension mechanism for any of the plugin's behavior; use the documented filters and actions instead. If you need an opt-out that the current filters do not provide, please open an issue.

**Localization**

- `languages/archived-post-status.pot` regenerated from scratch (the bundled copy had been stale since ~0.3.1). Of the plugin's 28 translatable strings, 20 are net-new in 0.4.0 (mostly the block editor, bulk actions, row actions, and notice text) and 1 changed wording (`WordPress Error` -> `WordPress &rsaquo; Error`, matching WP core's own `wp_die()` title convention); nothing was removed. The bundled `.po`/`.mo` catalogs (cs_CZ, de_DE, es_ES, fr_FR, nl_NL, pt_PT, ru_RU) predate this and cover only the original handful of strings - translators will need to pick up the new and changed text.

**Deprecated**

- `aps_is_excluded_post_type()` - use `! aps_is_supported_post_type( $post_type )` instead. The old function still works and now emits a standard WordPress deprecation notice.

**Fixed**

- The `aps_post_status_slug` filter is now honored at every point the plugin checks or writes the status. Under 0.3.x the filter only changed the slug the status was registered under - every internal comparison and every database write still used the literal `archive`. 0.4.0 resolves the filtered slug at all of those points. If you use this filter, read the upgrade note below before updating.

**Upgrade note for sites using the `aps_post_status_slug` filter**

This only applies if you changed the status slug with the `aps_post_status_slug` filter. Everyone else can update normally.

The status slug is still `archive`, exactly as in 0.3.x. Nothing to do here unless your site adds an `aps_post_status_slug` filter to rename it - if you have never used that filter, skip this section and update normally.

What changed is where the filter is honored. 0.3.x applied it only when registering the status and then saved the literal `archive` to the database regardless, so a site that renamed the slug ends up with rows the plugin no longer matches once 0.4.0 honors the custom name everywhere. If that is your site: back up your database first, then run `UPDATE wp_posts SET post_status = 'your-custom-slug' WHERE post_status = 'archive';` - substituting your own slug for "your-custom-slug" and your own table prefix for `wp_`. Do not run that query if you are not filtering the slug; it would rename your archived posts to a status the plugin does not recognize.

= 0.3.12 - Feb 16, 2026 =

- Tested up to WordPress 6.9.1
- Tested up to PHP 8.4
- Move over to composer for phpcs, phpstan, and linting checks
- Upgrade Github actions to actions/checkout@v6 running on php 8.4

= 0.3.11 - June 15, 2024 =
- Fix release and versioning issues that shipped with 0.3.10

= 0.3.10 - June 15, 2024 =
- Test & update support for WP 6.5.4
- Increase minimum supported PHP to 8.1, as 8.0 is end of life.
- Increase minimum WordPress version to 5.9, to align with the PHP version.
- Darken logo colors for better contrast.
- Improve German translations, h/t @mdibella-dev

= 0.3.9.1 - January 19, 2024 =
- Fixing version numbers in files, missing from 0.3.9 release.

= 0.3.9 - January 19, 2024 =
- Fix deprecated php warning on `filter_input`, using native WP functions for escaping & getting query var. Fixes another issue, where archived posts couldn't be trashed (Closes #35)
- Add `aps_archived_label_string` filter to modify the "Archived" string used for the label.
- Add `aps_title_separator` and `aps_title_label` to filter the post title prefix and separator, defaults to 'Archived' with a `:` separator. Disable the title label entirely by using `add_filter( 'aps_title_prefix', '__return_false' );` in your `functions.php` file or custom plugin file. Closes #21
- Added `aps_title_label_before` filter, defaults to `true` - pass `false` to have the label appear after the title instead of before it. This change along with the label string filter above closes #31
- Add PHPUnit tests & github actions.
- Update some comments and documentation, readmes, etc

= 0.3.8 - December 15, 2023 =

Ownership of this plugin is being transferred to [Joshua David Nelson](https://profiles.wordpress.org/joshuadnelson/). A huge thank you to @fjarrett for his work on this plugin to this point. More info to come soon, keep an eye on the [Github Repository](https://github.com/joshuadavidnelson/archived-post-status/)!

This update includes:
- Tested up to WordPress 6.4.2
- Added minimum PHP of 7.4
- Bumped minimum WordPress to 5.3
- Added Github actions for deployment to WP repo
- Updated contributors in readmes
- Added PHPStan and PHPCS Github actions

= 0.3.7 - December 23, 2016 =

* Tweak: Indicate support for WordPress 4.7.

= 0.3.6 - April 13, 2016 =

* Fix: Bug causing Archived status label to always appear on edit screen.

Props [fjarrett](https://github.com/fjarrett)

= 0.3.5 - April 13, 2016 =

* New: Indicate support for WordPress 4.5.
* New: Added language support for `cs_CZ`.
* New: Add filter to allow Archived content to be editable ([#12](https://github.com/fjarrett/archived-post-status/pull/12)).

Props [fjarrett](https://github.com/fjarrett)

= 0.3.4 - December 14, 2015 =

* New: Indicate support for WordPress 4.4.
* Fix: Broken title when post format icon is present ([#9](https://github.com/fjarrett/archived-post-status/pull/9)).

Props [fjarrett](https://github.com/fjarrett), [brandbrilliance](https://github.com/brandbrilliance)

= 0.3.3 - September 12, 2015 =

* New: Indicate support for WordPress 4.3.

Props [fjarrett](https://github.com/fjarrett)

= 0.3.2 - March 25, 2015 =

* Fix: Non-object warnings when `$post` is null ([#6](https://github.com/fjarrett/archived-post-status/issues/6)).

Props [fjarrett](https://github.com/fjarrett), [stevethemechanic](https://github.com/stevethemechanic), [edwin-yard](https://profiles.wordpress.org/edwin-yard/)

= 0.3.1 - January 27, 2015 =

* New: Added language support for `nl_NL`.
* Tweak: Refreshed existing language files.
* Fix: Missing argument warning on `the_title` filter.

Props [fjarrett](https://github.com/fjarrett), [RavanH](https://github.com/RavanH), [htrex](https://profiles.wordpress.org/htrex/)

= 0.3.0 - January 26, 2015 =

* New: Added language support for `de_DE`, `es_ES`, `fr_FR`, `pt_PT` and `ru_RU`.
* New: Users with the `read_private_posts` capability can now view Archived content.
* New: Automatically close comments and pings when content is archived.
* Tweak: Allow multiple post states to exist alongside Archived in edit screen.
* Fix: The `aps_excluded_post_types` filter now works as expected on Edit screens.

Props [fjarrett](https://github.com/fjarrett)

= 0.2.0 - January 21, 2015 =

* New: Make Archived content read-only.

Props [fjarrett](https://github.com/fjarrett), [pollyplummer](https://github.com/pollyplummer)

= 0.1.0 - January 4, 2015 =

* Initial release.

Props [fjarrett](https://github.com/fjarrett)

== Upgrade Notice ==

= 0.4.0 =

Major rewrite: PHP 8.1+ is now required, eleven 0.3.x global functions were removed, and archived posts no longer appear in the default "All" list. See the changelog for the full list of changes, including the migration note for sites using the `aps_post_status_slug` filter.

= 0.3.12 - Feb 16, 2026 =

- Tested up to WordPress 6.9.1
- Tested up to PHP 8.4
- Move over to composer for phpcs, phpstan, and linting checks
- Upgrade Github actions to actions/checkout@v6 running on php 8.4

= 0.3.11 - June 15, 2024 =

- Fix release and versioning issues that shipped with 0.3.10

= 0.3.10 - June 15, 2024 =

- Test & update support for WP 6.5.4
- Increase minimum supported PHP to 8.1, as 8.0 is end of life.
- Increase minimum WordPress version to 5.9, to align with the PHP version.
- Darken logo colors for better contrast.
- Improve German translations, h/t @mdibella-dev

= 0.3.9.1 - January 19, 2024 =

- Fixing version numbers in files, missing from 0.3.9 release.

= 0.3.9 =

- Fix deprecated php warning on `filter_input`.
- Add filters for label and title string & separator, see changelog.

= 0.3.8 =

- Tested up to WordPress 6.4.2
- Add minimum PHP of 7.4
- Bump minimum WordPress to 5.3
- Add Github actions for deployment & coding standards
- Update contributors in readmes
