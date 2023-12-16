# Archived Post Status Changelog
---

## 0.3.8 - December 15, 2023

Ownership of this plugin is being transfered to [Joshua David Nelson](https://github.com/joshuadavidnelson/). A huge thank you to @fjarrett for his work on this plugin to this point. More info to come soon!

This update includes:
- Tested up to WordPress 6.4.2
- Added minimum PHP of 7.4
- Bumped minimum WordPress to 5.3
- Added Github actions for deployment to WP repo
- Updated contributors in readmes
- Added PHPStan and PHPCS Github actions

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


