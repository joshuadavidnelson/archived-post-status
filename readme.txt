=== Archive Content with Archived Post Status ===
Contributors:      joshuadnelson, fjarrett
Tags:              archive, archived, post status, archive post, admin, status, workflow
Requires at least: 5.9
Requires PHP:      8.1
Tested up to:      6.5.4
Stable tag:        0.3.11
License:           GPL-2.0
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Use an "Archived" status to unpublish content without having to trash it.

== Description ==

This plugin allows you to archive your WordPress content similar to the way you archive your e-mail.

* Unpublish your posts and pages without having to trash them
* Archive content is hidden from public view
* Compatible with posts, pages, and public custom post types
* Ideal for sites where certain kinds of content is not meant to be evergreen
* Easily extended (see below)

**[Over 13](https://translate.wordpress.org/projects/wp-plugins/archived-post-status/)** languages supported

**Did you find this plugin helpful? Please consider [leaving a 5-star review](https://wordpress.org/support/view/plugin-reviews/archived-post-status).**

**Development of this plugin is done [on GitHub](https://github.com/joshuadavidnelson/archived-post-status). Pull requests welcome. Please see [issues reported](https://github.com/joshuadavidnelson/archived-post-status/issues) there before going to the plugin forum.**

== Frequently Asked Questions ==

= Isn't this the same as using the Draft or Private statuses? =

Actually, no, they are not the same thing.

The Draft status is a "pre-published" status that is reserved for content that is still being worked on. You can still make changes to content marked as Draft, and you can preview your changes.

The Private status is a special kind of published status. It means the content is published, but only certain logged-in users can view it.

The Archived post status, on the other hand, is meant to be a "post-published" status. Once a post has been set to Archived it can no longer be edited or viewed.

Of course, you can always change the status back to Draft or Publish if you want to be able to edit its content again.

= Can't I just trash old content I don't want anymore? =

Yes, there is nothing wong with trashing old content. And the behavior of the Archived status is very similar to that of trashing.

However, WordPress permanently deletes trashed posts after 30 days ([see here](https://codex.wordpress.org/Trash_status#Default_Days_before_Permanently_Deleted)).

This is what makes the Archived post status handy. You can unpublish content without having to delete it forever.

= Where are the options for this plugin? =

This plugin does not have a settings page. However, there are numerous hooks available in the plugin so you can customize default behaviors. Many of those hooks are listed below in this FAQ.

= Why are Archived posts appearing on the front-end? =

Archived content is by default viewable for users with the any user with the [`read_private_posts`](http://codex.wordpress.org/Roles_and_Capabilities#read_private_posts) capability.

This means if you are viewing your site while being logged in as an Editor or Administrator, you will see the archived content. However, lower user roles and non-logged-in users will not see the archived content.

You can change the default read capability by adding this hook to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

<pre lang="php">
function my_aps_default_read_capability( $capability ) {
	$capability = 'read';

	return $capability;
}
add_filter( 'aps_default_read_capability', 'my_aps_default_read_capability' );
</pre>

= Can I make Archived posts appear on the front-end for all users? =

Add these hooks to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

<pre lang="php">
add_filter( 'aps_status_arg_public', '__return_true' );
add_filter( 'aps_status_arg_private', '__return_false' );
add_filter( 'aps_status_arg_exclude_from_search', '__return_false' );
</pre>

= Can I change the status name? =

You can change the post status name, the "Archived" string, by adding the code snippet to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

<pre lang="php">
add_filter( 'aps_archived_label_string', function( $label ) {
	$label = 'Custom Label'; // replace with your custom label
	return $label;
});
</pre>

This will change the name used in the admin and on the post title label (see below).

= How to modify or disable the "Archived" label added to the post title =

This plugin automatically adds `Archived:` to the title of archived content. (Note that archived content is only viewable to logged in users with the [`read_private_posts`](http://codex.wordpress.org/Roles_and_Capabilities#read_private_posts) capability).

You can modify the label text, the separator, whether it appears before or after the title, or disable it entirely.

Follow the examples below, adding the code snippet to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins).

**Remove the label**

`add_filter( 'aps_title_label', '__return_false' );`

**Place the label _after_ the title**

`add_filter( 'aps_title_label_before', '__return_false' );`

**Change the separator**

The separator is the string between the "Archived" label and the post title, _including spaces_. When the label appears before the title, the separator is a colon and space `: `, if the label is placed after the title it is a dash with spaces on each side ` - `.

You can customize the separator with the following filter:

<pre lang="php">
add_filter( 'aps_title_separator', function( $sep ) {
	$sep = ' ~ '; // replace with your separator
	return $sep;
});
</pre>

= Can I make Archived posts hidden from the "All" list in the WP Admin, similar to Trashed posts? =

Add these hooks to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

<pre lang="php">
add_filter( 'aps_status_arg_public', '__return_false' );
add_filter( 'aps_status_arg_private', '__return_false' );
add_filter( 'aps_status_arg_show_in_admin_all_list', '__return_false' );
</pre>

Please note that there is a [bug in core](https://core.trac.wordpress.org/ticket/24415) that requires public and private to be set to false in order for the `aps_status_arg_show_in_admin_all_list` to also be false.

= Can I exclude the Archived status from appearing on certain post types? =

Add this hook to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

<pre lang="php">
function my_aps_excluded_post_types( $post_types ) {
	$post_types[] = 'my_custom_post_type';

	return $post_types;
}
add_filter( 'aps_excluded_post_types', 'my_aps_excluded_post_types' );
</pre>

= My archived posts have disappeared when I deactivate the plugin! =

Don't worry, your content is _not_ gone it's just __inaccessible__. Unfortunately, using a custom post status like `archive` is only going to work while the plugin is active.

If you have archived content and deactivate or delete this plugin, that content will disappear from _view_. Your content is in the database - WordPress just no longer recognizes the `post_status` because this plugin is not there to set this post status up.

If you no longer need the plugin but want to retain your archived content:
1. Activate this plugin
2. Switch all the archived posts/pages/post types to a native post status, like 'draft' or 'publish'
3. THEN deactivate/delete the plugin.

= Help! I need support =

Please reach out on the [Github Issues](https://github.com/joshuadavidnelson/archived-post-status/issues) or in the WordPress [support forums](https://wordpress.org/support/plugin/archived-post-status/).

= I have a feature request =

Please reach out on the [Github Issues](https://github.com/joshuadavidnelson/archived-post-status/issues) or in the WordPress [support forums](https://wordpress.org/support/plugin/archived-post-status/).

== Screenshots ==

1. Post list table screen.
2. Quick Edit mode.
3. Publish metabox controls.

== Changelog ==

= 0.3.11 - June 15, 2024 =
- Fix release and versioning issues that shipped with 0.3.10

= 0.3.10 - June 15, 2024 =
- Test & update support for WP 6.5.4
- Increase minimum supported php to 8.1, as 8.0 is end of life.
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
* Tweak: Allow mulitple post states to exist alongside Archived in edit screen.
* Fix: The `aps_excluded_post_types` filter now works as expected on Edit screens.

Props [fjarrett](https://github.com/fjarrett)

= 0.2.0 - January 21, 2015 =

* New: Make Archived content read-only.

Props [fjarrett](https://github.com/fjarrett), [pollyplummer](https://github.com/pollyplummer)

= 0.1.0 - January 4, 2015 =

* Initial release.

Props [fjarrett](https://github.com/fjarrett)

== Upgrade Notice ==

= 0.3.11 - June 15, 2024 =
- Fix release and versioning issues that shipped with 0.3.10

= 0.3.10 - June 15, 2024 =
- Test & update support for WP 6.5.4
- Increase minimum supported php to 8.1, as 8.0 is end of life.
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
