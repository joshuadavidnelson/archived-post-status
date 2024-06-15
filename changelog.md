# Archived Post Status Changelog
---

## 0.3.11 - June 15, 2024
- Fix release and versioning issues that shipped with 0.3.10

## 0.3.10 - June 15, 2024
- Test & update support for WP 6.5.4
- Increase minimum supported php to 8.1, as 8.0 is end of life.
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


