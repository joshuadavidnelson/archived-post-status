<!-- DO NOT EDIT THIS FILE; it is auto-generated from readme.txt -->
# Archived Post Status

![Banner](.wordpress-org/banner-1544x500.png)

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/archived-post-status)](https://wordpress.org/plugins/archived-post-status/) ![Downloads](https://img.shields.io/wordpress/plugin/dt/archived-post-status.svg) ![Rating](https://img.shields.io/wordpress/plugin/r/archived-post-status.svg)

[![WP compatibility](https://plugintests.com/plugins/wporg/archived-post-status/wp-badge.svg)](https://plugintests.com/plugins/wporg/archived-post-status/latest) [![PHP compatibility](https://plugintests.com/plugins/wporg/archived-post-status/php-badge.svg)](https://plugintests.com/plugins/wporg/archived-post-status/latest)

Allows posts and pages to be archived so you can unpublish content without having to trash it.

**Contributors:** [joshuadavidnelson](https://github.com/joshuadavidnelson), [fjarrett](https://profiles.wordpress.org/fjarrett)  
**Tags:** [admin](https://wordpress.org/plugins/tags/admin), [posts](https://wordpress.org/plugins/tags/posts), [pages](https://wordpress.org/plugins/tags/pages), [status](https://wordpress.org/plugins/tags/status), [workflow](https://wordpress.org/plugins/tags/workflow)  
**Minimum PHP version supported:** 7.4
**Minimum WP Version supported:** 5.3  
**Tested up to:** 6.4.2  
**Stable tag:** 0.3.9  
**License:** [GPL-2.0](https://www.gnu.org/licenses/gpl-2.0.html)  

## Description

This plugin allows you to archive your WordPress content similar to the way you archive your e-mail.

* Unpublish your posts and pages without having to trash them
* Archives content, making it hidden from public view 
* Compatible with posts, pages and call public ustom post types
* Ideal for sites where certain kinds of content is not meant to be evergreen
* Easily extended (see walkthroughs below)


**[Over 13](https://translate.wordpress.org/projects/wp-plugins/archived-post-status/)** languages supported

**Pull requests welcome, please follow [these guidelines](/code-of-conduct.md).**

**Please see [issues reported](https://github.com/joshuadavidnelson/archived-post-status/issues) there before going to the plugin forum.**

**Did you find this plugin helpful? Please consider [leaving a 5-star review](https://wordpress.org/support/view/plugin-reviews/archived-post-status).**

## Frequently Asked Questions

### Isn't this the same as using the Draft or Private statuses?

Actually, no, they are not the same thing.

The Draft status is a "pre-published" status that is reserved for content that is still being worked on. You can still make changes to content marked as Draft, and you can preview your changes.

The Private status is a special kind of published status. It means the content is published, but only certain logged-in users can view it.

The Archived post status, on the other hand, is meant to be a "post-published" status. Once a post has been set to Archived it can no longer be edited or viewed.

Of course, you can always change the status back to Draft or Publish if you want to be able to edit its content again.

### Can't I just trash old content I don't want anymore?

Yes, there is nothing wong with trashing old content. And the behavior of the Archived status is very similar to that of trashing.

However, WordPress permanently deletes trashed posts after 30 days ([see here](https://codex.wordpress.org/Trash_status#Default_Days_before_Permanently_Deleted)).

This is what makes the Archived post status handy. You can unpublish content without having to delete it forever.

### Where are the options for this plugin?

This plugin does not have a settings page. However, there are numerous hooks available in the plugin so you can customize default behaviors. Many of those hooks are listed below in this FAQ.

### Why are Archived posts appearing on the front-end?
This is most likely because you are viewing your site while being logged in as an Editor or Administrator.

By default, any user with the [`read_private_posts`](http://codex.wordpress.org/Roles_and_Capabilities#read_private_posts) capability will see Archived posts appear on the front-end of your site.

You can change the default read capability by adding this hook to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
function my_aps_default_read_capability( $capability ) {
	$capability = 'read';

	return $capability;
}
add_filter( 'aps_default_read_capability', 'my_aps_default_read_capability' );
```

### Can I make Archived posts appear on the front-end for all users?
Yes, add these hooks to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
add_filter( 'aps_status_arg_public', '__return_true' );
add_filter( 'aps_status_arg_private', '__return_false' );
add_filter( 'aps_status_arg_exclude_from_search', '__return_false' );
```

### Can I change the status name?

You can change the post status name, the "Archived" string, by adding the code snippet to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```
add_filter( 'aps_archived_label_string', function( $label ) {
	$label = 'Custom Label'; // replace with your custom label
	return $label;
});
```

This will change the name used in the admin and on the post title label (see below).

### How to modify or disable the "Archived" label added to the post title

This plugin automatically adds `Archived:` to the title of archived content. (Note that archived content is only viewable to logged in users with the [`read_private_posts`](http://codex.wordpress.org/Roles_and_Capabilities#read_private_posts) capability).

You can modify the label text, the separator, whether it appears before or after the title, or disable it entirely. 

Follow the examples below, adding the code snippet to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins).

#### Remove the label

`add_filter( 'aps_title_label', '__return_false' );`

#### Place the label _after_ the title

`add_filter( 'aps_title_label_before', '__return_false' );`

#### Change the separator

The separator is the string between the "Archived" label and the post title, _including spaces_. When the label appears before the title, the separator is a colon and space `: `, if the label is placed after the title it is a dash with spaces on each side ` - `.

You can customize the separator with the following filter:
```
add_filter( 'aps_title_separator', function( $sep ) {
	$sep = ' ~ '; // replace with your separator
	return $sep;
});
```

### Can I make Archived posts hidden from the "All" list in the WP Admin, similar to Trashed posts?

Add these hooks to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
add_filter( 'aps_status_arg_public', '__return_false' );
add_filter( 'aps_status_arg_private', '__return_false' );
add_filter( 'aps_status_arg_show_in_admin_all_list', '__return_false' );
```

Please note that there is a [bug in core](https://core.trac.wordpress.org/ticket/24415) that requires public and private to be set to false in order for the `aps_status_arg_show_in_admin_all_list` to also be false. 

### Can I exclude the Archived status from appearing on certain post types?

Add this hook to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
function my_aps_excluded_post_types( $post_types ) {
	$post_types[] = 'my_custom_post_type';

	return $post_types;
}
add_filter( 'aps_excluded_post_types', 'my_aps_excluded_post_types' );
```

### My archived posts have disappeared when I deactivate the plugin!

Don't worry, your content is _not_ gone it's just __inaccessible__. Unfortunately, using a custom post status like `archive` is only going to work while the plugin is active.

If you have archived content and deactivate or delete this plugin, that content will disappear from _view_. Your content is in the database - WordPress just no longer recognizes the `post_status` because this plugin is not there to set this post status up. 

If you no longer need the plugin but want to retain your archived content:
1. Activate this plugin
2. Switch all the archived posts/pages/post types to a native post status, like 'draft' or 'publish'
3. THEN deactivate/delete the plugin.


## Screenshots

### Post list table screen.

![Post list table screen.](.wordpress-org/screenshot-1.png)

### Quick Edit mode.

![Quick Edit mode.](.wordpress-org/screenshot-2.png)

### Publish metabox controls.

![Publish metabox controls.](.wordpress-org/screenshot-3.png)

## Contributing

All contributions are welcomed and considered, please refer to [contributing.md](contributing.md).

### Pull requests
All pull requests should be directed at the `develop` branch, and will be reviewed prior to merging. No pull requests will be merged with failing tests, but it's okay if you don't initially pass tests. Please create a draft pull request for proof of concept code or changes you'd like to have input on prior to review.

Please make on a branch specific to a single issue or feature. For instance, if you are suggest a solution to an issue, please create fork with a branch like `issue-894`. Or if you are proposing a new feature, create a fork with the branch name indicating the feature like `feature-example-bananas`

All improvements are merged into `develop` and then queued up for release before being merged into `stable`. Releases are deployed via github actions to wordpress.org on tagging a new release.

### Main Branches

The `stable` branch is reserved for releases and intended to be a mirror of the official current release, or `trunk` on wordpress.org.

The `develop` branch is the most current working branch. _Please direct all pull requests to the `develop` branch_

### Local Development

**Requirements:**
- Docker
- Node Package Manager (npm)

This repo contains the files needed to boot up a local development environment using [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

Run `npm install` and the `npm run env:start` to boot up a local environment. 
