# Custom Post Type Support

To add support for custom post types, use the `aps_supported_post_types`filter.

Add this hook to your theme’s `functions.php` file or as an [MU plugin](https://codex.wordpress.org/Must_Use_Plugins):

```php
function my_aps_supported_post_types( $post_types ) {
    $post_types[] = 'my_custom_post_type';

    return $post_types;
}
add_filter( 'aps_supported_post_types', 'my_aps_supported_post_types' );
```

Be sure to update the function name to something unique and the post type slug to the post type(s) you are supporting!

See [hooks-filters-and-actions.md](hooks-filters-and-actions.md "mention")for more
