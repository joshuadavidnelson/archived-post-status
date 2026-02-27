# Frequently Asked Questions

## Frequently Asked Questions

<details>

<summary>Isn't this the same as using the Draft or Private statuses?</summary>

Actually, no, they are not the same thing.

The Draft status is a "pre-published" status that is reserved for content that is still being worked on. You can still make changes to content marked as Draft, and you can preview your changes.

The Private status is a special kind of published status. It means the content is published, but only certain logged-in users can view it.

The Archived post status, on the other hand, is meant to be a "post-published" status. Once a post has been set to Archived it can no longer be edited or viewed.

Of course, you can always change the status back to Draft or Publish if you want to be able to edit its content again.

</details>

<details>

<summary>Can't I just trash old content I don't want anymore?</summary>

Yes, there is nothing wong with trashing old content. And the behavior of the Archived status is very similar to that of trashing.

However, WordPress permanently deletes trashed posts after 30 days ([see here](https://codex.wordpress.org/Trash_status#Default_Days_before_Permanently_Deleted)).

This is what makes the Archived post status handy. You can unpublish content without having to delete it forever.

</details>

<details>

<summary>Where are the options for this plugin?</summary>

This plugin does not (yet) have a settings page. However, there are numerous hooks available in the plugin so you can customize default behaviors.&#x20;

Many of those hooks are listed below in this FAQ and through the documentation, see the [Extending The Plugin](/broken/pages/RqoQ8ehVqU9OQFdcXKkN) section.

</details>

<details>

<summary>Why are Archived posts appearing on the front-end?</summary>

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

</details>

<details>

<summary>Can I make Archived posts appear on the front-end for all users?</summary>

Yes! Add these hooks to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
add_filter( 'aps_status_arg_public', '__return_true' );
add_filter( 'aps_status_arg_private', '__return_false' );
add_filter( 'aps_status_arg_exclude_from_search', '__return_false' );
```

</details>

<details>

<summary>Can I change the status name?</summary>

You can change the post status name, the "Archived" string, by adding the code snippet to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
add_filter( 'aps_archived_label_string', function( $label ) {
	$label = 'Custom Label'; // replace with your custom label
	return $label;
});
```

This will change the name used in the admin and on the post title label (see below).

</details>

<details>

<summary>How can I edit archived content?</summary>

By default archived content is _not_ editable. However, you can change this with a filter

Add this hook to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
add_filter( 'aps_is_read_only', '__return_false' );
```

</details>

<details>

<summary>How to modify or disable the "Archived" label added to the post title</summary>

This plugin automatically adds `Archived:` to the title of archived content. (Note that archived content is only viewable to logged in users with the [`read_private_posts`](http://codex.wordpress.org/Roles_and_Capabilities#read_private_posts) capability).

You can modify the label text, the separator, whether it appears before or after the title, or disable it entirely.

Follow the examples below, adding the code snippet to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins).

#### Remove the label

`add_filter( 'aps_title_label', '__return_false' );`

#### Place the label _after_ the title

`add_filter( 'aps_title_label_before', '__return_false' );`

#### Change the separator

The separator is the string between the "Archived" label and the post title, _including spaces_. When the label appears before the title, the separator is a colon and space `:` , if the label is placed after the title it is a dash with spaces on each side `-`.

You can customize the separator with the following filter:

```php
add_filter( 'aps_title_separator', function( $sep ) {
	$sep = ' ~ '; // replace with your separator
	return $sep;
});
```

</details>

<details>

<summary>Can I exclude the Archived status from appearing on certain post types?</summary>

Add this hook to your theme's `functions.php` file or as an [MU plugin](http://codex.wordpress.org/Must_Use_Plugins):

```php
function my_aps_excluded_post_types( $post_types ) {
	$post_types[] = 'my_custom_post_type';
	return $post_types;
}
add_filter( 'aps_excluded_post_types', 'my_aps_excluded_post_types' );
```

</details>

<details>

<summary>My archived posts have disappeared when I deactivate the plugin!</summary>

Fear Not! Your content is _not_ gone it's just **inaccessible**. Unfortunately, using a custom post status like `archive` is only going to work while the plugin is active or something else registers the `archive` status .

If you have archived content and deactivate or delete this plugin, that content will disappear from _view_. Your content is in the database - WordPress just no longer recognizes the `post_status` because this plugin is not there to set this post status up.

If you no longer need the plugin but want to retain your archived content:

1. Activate this plugin
2. Switch all the archived posts/pages/post types to a native post status, like 'draft' or 'publish'
3. THEN deactivate/delete the plugin.

</details>
