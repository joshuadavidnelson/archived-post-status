=== Archive Content with Archived Post Status ===
Contributors:      joshuadnelson, fjarrett
Tags:              admin, posts, pages, status, workflow
Requires at least: 5.3
Requires PHP:      7.4
Tested up to:      6.4.2
Stable tag:        0.3.8
License:           GPL-2.0
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Use an "Archived" status to unpublish content without having to trash it.

== Description ==

This plugin allows you to archive your WordPress content similar to the way you archive your e-mail.

* Makes a new post status available in the dropdown called Archived
* Unpublish your posts and pages without having to trash them
* Compatible with posts, pages and custom post types
* Ideal for sites where certain kinds of content is not meant to be evergreen

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

However, WordPress automatically purges trashed posts every 7 days (by default).

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

= Can I make Archived posts hidden from the "All" list in the WP Admin, similar to Trashed posts? =

Add these hooks to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

<pre lang="php">
add_filter( 'aps_status_arg_public', '__return_false' );
add_filter( 'aps_status_arg_private', '__return_false' );
add_filter( 'aps_status_arg_show_in_admin_all_list', '__return_false' );
</pre>

Please note that there is a [bug in core](https://core.trac.wordpress.org/ticket/24415) that requires public and private to be set to false in order for the `aps_status_arg_show_in_admin_all_list` to also be false. There are many bugs in core surrounding registering custom post statuses, so if something doesn't work the way you want on the first try be prepared to do some digging through trac :-)

= Can I exclude the Archived status from appearing on certain post types? =

Add this hook to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

<pre lang="php">
function my_aps_excluded_post_types( $post_types ) {
	$post_types[] = 'my_custom_post_type';

	return $post_types;
}
add_filter( 'aps_excluded_post_types', 'my_aps_excluded_post_types' );
</pre>

= Help! I need support =

Please reach out on the Github Issues or in the WordPress support forums.

= I have a feature request =

Please reach out on the Github Issues or in the WordPress support forums.

== Screenshots ==

1. Post list table screen.
2. Quick Edit mode.
3. Publish metabox controls.

== Changelog ==

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
