# Viewing Archived Content

### Archived Content is viewable to logged in users only

By default archived content is only viewable by logged in users with the `read_private_posts` [capability](https://wordpress.org/documentation/article/roles-and-capabilities/#read_private_pages). This is typically Editor roles and higher.

Logged in users without that capability, or users that are not logged in (public), will see a 404 page when attempting to view archived content.

**A future release is planned** to provide more control over this behavior (aiming for v0.5.0). For now, below are examples of how to change the plugin's default behavior.

### Show Archived Content to All Users

Archived content can be made viewable to _all_ users with some filters.

Add these hooks to your theme’s `functions.php` file or as an [MU plugin](https://codex.wordpress.org/Must_Use_Plugins):

{% code overflow="wrap" %}
```php
add_filter( 'aps_status_arg_public', '__return_true' );
add_filter( 'aps_status_arg_private', '__return_false' );
add_filter( 'aps_status_arg_exclude_from_search', '__return_false' );
```
{% endcode %}

### Change which users can view archived content

The plugin defaults to showing content to users with the `read_private_posts` [capability](https://wordpress.org/documentation/article/roles-and-capabilities/#read_private_pages). Change that with the `aps_default_read_capability` filter.

Add this snippet to your theme’s `functions.php` file or as an [MU plugin](https://codex.wordpress.org/Must_Use_Plugins):

{% code overflow="wrap" %}
```php
add_filter( 'aps_default_read_capability', function( $capability ) {
    return 'aps_user_view_capability'; // change to the capability of your choice
});
```
{% endcode %}

### Archived Title Label

This plugin automatically adds `Archived:` to the title of archived content.&#x20;

You can modify the label text, the separator, whether it appears before or after the title, or disable it entirely.

Follow the examples below, adding the code snippet to your theme’s `functions.php` file or as an [MU plugin](https://codex.wordpress.org/Must_Use_Plugins).

#### **Place the label&#x20;**_**after**_**&#x20;the title**

{% code overflow="wrap" %}
```php
add_filter( 'aps_title_label_before', '__return_false' );
```
{% endcode %}

#### **Change the separator**

The separator is the string between the “Archived” label and the post title, _including spaces_. When the label appears before the title, the separator is a colon and space `:`, if the label is placed after the title it is a dash with spaces on each side `-`.

You can customize the separator with the following filter:

{% code overflow="wrap" %}
```php
add_filter( 'aps_title_separator', function( $sep ) {
    $sep = ' ~ '; // replace with your separator
    return $sep;
});
```
{% endcode %}

#### **Remove the label**

{% code overflow="wrap" %}
```php
add_filter( 'aps_title_label', '__return_false' );
```
{% endcode %}
