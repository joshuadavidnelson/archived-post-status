=== Archived Post Status ===
Contributors:      joshuadnelson, fjarrett
Donate link:       https://joshuadnelson.com/donate/
Tags:              archive, archived, status, post status
Requires at least: 5.9
Requires PHP:      8.1
Tested up to:      6.9.1
Stable tag:        0.4.0
License:           GPL-2.0
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Use an "Archive" status to unpublish content without having to trash it.

== Description ==

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

= 0.4.0 - Feb X, 2026 =

- New documentation site at [docs.archivedpoststat.us](https://docs.archivedpoststat.us/)
- Added block editor support
- Added bulk edit support
- Added WP Cli command
- Replaced Quick Edit dropdown with Inline Row Action support
- New core `aps_archive_post` and `aps_unarchive_post` functions, modeling the way WordPress handles "trashing" a post.
- Update classic editor support, new "Archive" link next to "Trash" in post editor
- Deprecated `aps_is_excluded_post_type`, using new `aps_is_supported_post_type` instead.
- Expanded filters and documentation blocks
- Refactored the core plugin into feature classes
- Added basic php unit tests

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

= 0.4.0 - February X, 2025 =
Major update with block editor support, bulk edit, WP CLI commands, and new documentation site. Refactored codebase with new core functions and expanded filters. See changelog for full details.

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
