# Archived Post Status Changelog
---

## 0.5.0 - Unreleased

The feature this plugin has been asked for the longest: automatic archiving. You can now schedule an exact date for a single post, or set up rules that archive content on their own after a period you choose — per network, per site, per category, or per post, with each level able to override the ones above it. **None of this changes anything on an existing site by itself.** Scheduling a post is something an editor does deliberately, and the rule-based side of auto-archive is off until you turn it on.

### Added

- **Schedule an exact archive date** - both the block editor and the classic editor gain a date/time picker (an "Auto archive" panel in the block editor, a metabox field in the classic editor). Pick a date, save the post, and it archives itself when that time comes - no need to remember to come back and do it by hand. Clearing the date cancels the schedule.
- **Automatic archiving by rule (the cascade)** - turn on "Auto archive" and set a number of days, and matching content archives itself that many days after it was published or last modified, on its own, forever. Rules can be set at four levels, each overriding the ones above it for the content it covers:
  - **Network** (multisite, when network-activated) - a default and an on/off switch for every site.
  - **Site** - `Settings → Archived Post Status`, the same screen the read-only setting has lived on since 0.4.0.
  - **Category** (or any taxonomy you opt in) - a field on the term's own edit screen.
  - **Post** - an override on the individual post.

  A more specific level always wins over a more general one, and a parent level can lock its value so the levels below it can only see it, not change it, or hide the control from them entirely. **Auto archive is off by default and stays off until you turn it on** - see `== Upgrade Notice ==` in readme.txt for what that means for your existing content, and the cascade table further down in this file for exactly how the four levels combine.
- **A grace period protects old content.** Turning on a rule over years of back-catalogue does not archive it all on the next run. Anything already past its due date is scheduled about a week out instead (configurable via the `auto_archive_grace_days` setting, default 7, or per post via the `aps_auto_archive_grace_period` filter) and shows up in the new "Scheduled" column first, so you have a chance to notice and adjust before anything actually archives.
- **An editor's own date always wins.** Picking an exact date on a post is a direct instruction, and it beats the cascade outright - even a rule that a network or site admin has locked. The one exception is a post explicitly exempted from a rule (via the "Clear" action), which is never re-stamped by the cascade even if the rule that produced it changes later. A site that wants the opposite - a locked rule always overrides an editor's own date - can flip this with the `aps_schedule_absolute_date_wins` filter.
- **A due post that's no longer archivable is skipped, not silently dropped.** If the sweep finds a scheduled post that's already been trashed, or otherwise no longer eligible, it clears the schedule by default (`aps_schedule_stale_action`, default `'clear'`). A failed archive attempt is retried up to 3 times (`aps_schedule_max_attempts`) before being abandoned - by default, exempted from future auto-archiving (`aps_schedule_abandon_action`, default `'exempt'`). **If you filter `aps_schedule_stale_action` to return `'keep'` for a post, use it only as an escape hatch for individual posts you intend to resolve by hand** - `'keep'` has no attempts counter by design, and enough posts left in that state will pile up at the front of the sweep queue and block everything behind them from ever being reached.
- **"Scheduled" column** on the posts list, sortable, showing the pending archive date and how it was set (manually, or by which rule). Unlike the existing "Archived" column, this one shows on the normal list views, since a pending schedule is something you can still act on.
- **Quick Edit and Bulk Edit** gain schedule controls. Quick Edit lets you set or clear a single post's exact date inline. Bulk Edit's schedule field defaults to "No change" - a genuine no-op that never touches a post's existing schedule unless you deliberately choose "Set" or "Clear," so bulk-editing a batch of posts for something unrelated (category, author, …) can never silently wipe out schedules that were already set. **This is not the 0.3.x status dropdown coming back to Quick Edit** - 0.4.0 removed that on purpose, and it is still gone. This is future-dated scheduling of an archive that has not happened yet, a different feature entirely.
- **Settings screens**: a site screen at `Settings → Archived Post Status` (extending the existing 0.4.0 screen with the new scheduling and cascade options), and, on a network-activated multisite install, a matching Network Admin screen for the network-level defaults and locks.
- **WP-CLI**: `wp post schedule-archive <id>... --at=<datetime>` and `wp post unschedule-archive <id>...`; `wp post archive-rule <id>` prints the resolved cascade for a post, level by level, so you can see exactly why (or when) a post will archive; `wp aps settings list|get|update [--network]` reads and writes settings from the command line; `wp aps queue run <sweep|stamp> [--all]` drains the sweep or stamp queue immediately, without waiting on cron - the tool to reach for right after turning on a rule over a large backlog.
- **New `aps_*` functions**: `aps_schedule_archive()`, `aps_unschedule_archive()`, and `aps_get_scheduled_archive_time()` for the per-post schedule; `aps_get_auto_archive_rule()` returns the resolved cascade for a post (the same object the UI and CLI use); `aps_current_user_can_manage_settings()` and `aps_current_user_can_manage_network_settings()`, filterable via `aps_default_settings_capability` and `aps_default_network_settings_capability` (`manage_options` and `manage_network_options` by default).
- **A cron health check.** If the sweep that turns due schedules into archives hasn't run in a while - most often because `DISABLE_WP_CRON` is set with no real system cron configured to replace it - the settings screen now says so, instead of posts simply never archiving with no explanation.
- **A pluggable queue.** Both the sweep (archiving due posts) and the stamp (applying rules to newly-matching content) run in small, resumable batches, so a host with a tight `max_execution_time` or `memory_limit` never times out mid-run and never leaves a post half-archived - the next tick just picks up where the last one stopped. The `aps_queue_runner` filter lets a site swap the built-in WP-Cron-driven runner for a different scheduler entirely - see "Extending the queue" in readme.md for a complete, copy-pasteable Action Scheduler adapter.
- **`aps_archived_post` fires for scheduled and rule-archived posts too**, not only manual ones, so anything you've already hooked onto it - including a notification email, which this plugin still does not send on its own - keeps working without changes. Two lines gets you an email on every scheduled archive:
  ```php
  add_action( 'aps_archived_post', function ( $post_id ) {
      wp_mail( get_option( 'admin_email' ), 'Post archived', get_the_title( $post_id ) . ' was just archived.' );
  } );
  ```
- Many more filters throughout the scheduling and cascade code - grace period (`aps_auto_archive_grace_period`), stale/retry/abandon handling (`aps_schedule_stale_action`, `aps_schedule_max_attempts`, `aps_schedule_abandon_action`), the absolute-date override (`aps_schedule_absolute_date_wins`), batch sizes, tie-breaking between terms, and every default described above. See the cascade table and "Extending the queue" section in readme.md, and the [documentation site](https://docs.archivedpoststat.us/) for the full reference.

### Changed

- The plugin's short description now mentions scheduled and automatic archiving alongside the manual "Archive" status.
- `Settings → Archived Post Status` now has more on it than the single read-only toggle 0.4.0 shipped; the read-only setting itself is unchanged.

### The cascade, worked

This is the table that decides what actually happens. Reproduced in full in readme.txt and readme.md as well, since it is the part of this release most likely to need a second look.

| Scenario | Walk (general → specific) | Result |
|---|---|---|
| Site 12d (Open), Category "News" 3d (Open), post override 6d | 12 → 3 → 6 | **6 days** — the most specific level with a value wins |
| Site 12d (Open), Category "News" 3d (Open), no post override | 12 → 3 | **3 days** |
| Site 12d (Open), Category "News" 3d (**Locked**), post override 6d | 12 → 3, then frozen | **3 days** — the lock holds; the post override is ignored |
| Network 365d (**Locked**), site 12d | 365, then frozen | **365 days** — the site's own value is read-only |
| Network 365d (**Off**), site controls hidden entirely | 365, then frozen | **365 days** — the site never even sees the field |
| Site 12d (Open), no category rule, no post override | 12 | **12 days** |

**The one case worth calling out specifically:** a post filed in two categories - "News" (6 days, Locked) and "Features" (3 days, Open) - resolves to **3 days, and frozen**. The soonest value among the post's categories wins, and the strictest lock among them wins, independently of each other and independently of which category supplied which. That is stricter than either category states on its own, and it is the one place someone who configured every individual rule correctly can still be surprised by the result. Both halves are filterable (`aps_auto_archive_term_rule_days` for the soonest-wins tie, `aps_auto_archive_term_rule_child_mode` for the strictest-lock tie) if your site needs different behavior.

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
- **`aps_archive_post()` and `aps_unarchive_post()`** - real API functions modeled on core's `wp_trash_post()` and `wp_untrash_post()`, with `aps_pre_archive_post` / `aps_pre_unarchive_post` short-circuit filters and `aps_archived_post` / `aps_unarchived_post` actions. `aps_get_archive_post_link()` and `aps_get_unarchive_post_link()` return the URLs that trigger them; `aps_get_archived_post_link()`, modeled on core's `get_preview_post_link()`, returns the link to view an archived post.
- **Front-end protection** - a visitor who isn't allowed to see archived content now gets a 404 when they request a single archived post. The check runs consistently for every visitor rather than varying with WordPress's private-post rules; a post's own author can always view their own archived content.
- **Per-action, ownership-aware capabilities** - `aps_current_user_can_view()`, `aps_current_user_can_archive()`, `aps_current_user_can_unarchive()`, and `aps_current_user_can_edit()`, filterable through `aps_default_read_capability`, `aps_default_archive_capability`, `aps_default_unarchive_capability`, and `aps_default_edit_capability`. Authors can archive, unarchive, and view their own content (via the post type's `edit_posts` capability); acting on other authors' content requires `edit_others_posts`. Viewing others' archived content defaults to `read_private_posts`.
- **Settings groundwork** - settings are now stored in a single `aps_settings` option, which currently holds one value, `is_read_only`. There is no settings screen yet; the admin UI is planned for a future release and all behavior stays filter-driven in 0.4.0.
- More filters throughout: `aps_supported_post_types`, `aps_archivable_statuses`, `aps_is_classic_editor`, `aps_enable_archive_meta`, `aps_enable_notices` (suppresses the post-action admin notices when set to `false`), `aps_is_read_only`, `aps_status_arg_public`, `aps_status_arg_private`, `aps_status_arg_protected`, `aps_status_arg_exclude_from_search`, `aps_status_arg_show_in_admin_all_list`, `aps_status_arg_show_in_admin_status_list`, `aps_archive_post_comment_status` and `aps_archive_post_ping_status` (archive-side mirrors of the two below), `aps_unarchive_post_status`, `aps_unarchive_post_comment_status`, `aps_unarchive_post_ping_status`, `aps_archived_post_link`, `aps_get_archive_post_link`, and `aps_get_unarchive_post_link`. See the [documentation site](https://docs.archivedpoststat.us/) for the full reference.

### Changed

- The plugin was rebuilt from a single procedural file into small, focused, namespaced classes under `src/`, loaded by a lightweight autoloader. Composer is a development tool only - no extra dependencies ship to your site.
- Archived posts are now kept out of the default "All" view on the posts list, the same way Trash is. Use the "Archived" filter link to see them.
- Archiving is now restricted to `public` post types. 0.3.x allowed archiving any post type that wasn't explicitly excluded via `aps_excluded_post_types`, including non-public ones; 0.4.0 starts from the public post types and subtracts the excluded set (`aps_get_supported_post_types()` returns the resulting list). If you need to archive a non-public custom post type, add it back with the `aps_supported_post_types` filter.
- Added PHPUnit and Jest test suites, static analysis, and coding standards checks to the project.
- Tested up to WordPress 7.0.

### Removed

The rewrite replaced the plugin's procedural internals with classes, and eleven functions that were global in 0.3.x no longer exist. All of them were undocumented internals rather than a published API, but they were callable, so this is a breaking change for any site that referenced one.

**Calling any of these now causes a fatal error.** Where a replacement exists, use it:

| Removed | Replacement |
| --- | --- |
| `aps_post_status_slug()` | `apply_filters( 'aps_post_status_slug', 'archive' )`, or read `$post->post_status` directly |
| `aps_is_frontend()` | `! is_admin()` |
| `aps_the_title()` | none needed - the title filter is registered internally and is controlled with `aps_title_label`, `aps_title_label_before`, and `aps_title_separator` |
| `aps_save_post()` | none - see the note below |
| `aps_display_post_states()` | none - the "Archived" post state label is applied internally |
| `aps_register_archive_post_status()` | none - registration is internal; the `aps_status_arg_*` filters control its arguments |
| `aps_i18n()` | none - text domain loading is internal |
| `aps_i18n_strings()` | none |
| `aps_post_screen_js()` | none - the 0.3.x status-dropdown injection was replaced by row actions, bulk actions, and editor buttons |
| `aps_edit_screen_js()` | none - read-only enforcement is now server-side, so the script it enqueued was deleted |
| `aps_load_post_screen()` | none - editor access is enforced by `Admin\PostEditorGuard` |

**If you unhooked one of these, that call is now a silent no-op** rather than an error. WordPress ignores `remove_filter()` / `remove_action()` for a callback that was never registered, so nothing breaks - but nothing is disabled either, and the behavior you meant to switch off is still active. This affects, in particular:

- `remove_filter( 'the_title', 'aps_the_title' )` - to remove the "Archived: " prefix instead, return an empty string from `aps_title_label`:
  ```php
  add_filter( 'aps_title_label', '__return_empty_string' );
  ```
- `remove_action( 'save_post', 'aps_save_post', 10 )` - closing comments and pings on an archived post now happens in `Status\PostStatusGuard`. There is no filter to switch that behavior off in 0.4.0; the only supported opt-out is to drop the post type with `aps_supported_post_types` or `aps_excluded_post_types`, which turns off archiving for that type entirely.
- `remove_action( 'admin_footer-post.php', 'aps_post_screen_js' )` and its `admin_footer-edit.php` counterpart - the injected status dropdown no longer exists to suppress.

Note that 0.4.0 registers its hooks as callbacks on internal object instances, which third-party code cannot reach. Unhooking is therefore no longer an extension mechanism for any of the plugin's behavior; use the documented filters and actions instead. If you need an opt-out that the current filters do not provide, please open an issue.

### Localization

- `languages/archived-post-status.pot` regenerated from scratch (the bundled copy had been stale since ~0.3.1). Of the plugin's 28 translatable strings, 20 are net-new in 0.4.0 (mostly the block editor, bulk actions, row actions, and notice text) and 1 changed wording (`WordPress Error` → `WordPress &rsaquo; Error`, matching WP core's own `wp_die()` title convention); nothing was removed. The bundled `.po`/`.mo` catalogs (cs_CZ, de_DE, es_ES, fr_FR, nl_NL, pt_PT, ru_RU) predate this and cover only the original handful of strings - translators will need to pick up the new and changed text.

### Deprecated

- `aps_is_excluded_post_type()` - use `! aps_is_supported_post_type( $post_type )` instead. The old function still works and now emits a standard WordPress deprecation notice.

### Fixed

- **The `aps_post_status_slug` filter is now honored at every point the plugin checks or writes the status.** Under 0.3.x the filter only changed the slug the status was registered under - every internal comparison and every database write still used the literal `archive`. 0.4.0 resolves the filtered slug at all of those points. If you use this filter, read the upgrade note below before updating.

### Upgrade note for sites using the `aps_post_status_slug` filter

**The status slug is still `archive`, exactly as in 0.3.x. Nothing to do here unless your site adds an `aps_post_status_slug` filter to rename it - if you have never used that filter, skip this section and update normally.**

What changed is where the filter is honored. 0.3.x applied it only when registering the status and then saved the literal `archive` to the database regardless, so a site that renamed the slug ended up with rows the plugin no longer matches once 0.4.0 starts honoring the custom name everywhere.

If that is your site: **back up your database first**, then bring the old rows in line, substituting your own slug for `your-custom-slug` and your own table prefix for `wp_`:

```sql
UPDATE wp_posts SET post_status = 'your-custom-slug' WHERE post_status = 'archive';
```

Do not run that query if you are not filtering the slug - it would rename your archived posts to a status the plugin does not recognize.

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
