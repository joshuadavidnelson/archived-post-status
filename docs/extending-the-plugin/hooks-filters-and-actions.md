# Hooks, Filters, and Actions

This document outlins all the plugin filters and actions.&#x20;

Use these to modify the plugin behavior and customize it's functionality.&#x20;

### Filters

#### **`aps_archived_label_string`**

Filters the label used throughout the plugin for archived content.

**Since:** 0.3.9

**Parameters:**

* `string $label` - The "Archived" label (default: "Archived")

**Returns:** `string`

**Example:**

```php
add_filter( 'aps_archived_label_string', function( $label ) {
    return 'Archived Content';
} );
```

#### **`aps_title_label`**

Filters the label that appears in archived post titles on the frontend.

**Since:** 0.3.9

**Parameters:**

* `string $label` - The label text for archived posts (default: result of `aps_archived_label_string()`)
* `int $post_id` - The post ID
* `string $title` - The post title

**Returns:** `string`

**Example:**

```php
add_filter( 'aps_title_label', function( $label, $post_id, $title ) {
    return '[ARCHIVED]';
}, 10, 3 );
```

#### **`aps_title_label_before`**

Controls whether the archived label appears before or after the post title.

**Since:** 0.3.9

**Parameters:**

* `bool $before` - True to place before the title, false to place after (default: `true`)
* `int $post_id` - The post ID

**Returns:** `bool`

**Example:**

```php
add_filter( 'aps_title_label_before', function( $before, $post_id ) {
    return false; // Place label after title
}, 10, 2 );
```

#### **`aps_title_separator`**

Filters the separator used between the archived label and post title.

**Since:** 0.3.9

**Parameters:**

* `string $sep` - The separator string (default: ": " when before, " - " when after)
* `int $post_id` - The post ID

**Returns:** `string`

**Example:**

```php
add_filter( 'aps_title_separator', function( $sep, $post_id ) {
    return ' | ';
}, 10, 2 );
```

#### **`aps_excluded_post_types`**

Filters the post types that should not support the archived status.

**Since:** 0.1.0

**Parameters:**

* `array $post_types` - Array of post type slugs to exclude (default: `['attachment']`)

**Returns:** `array`

**Example:**

```php
add_filter( 'aps_excluded_post_types', function( $post_types ) {
    $post_types[] = 'custom_post_type';
    return $post_types;
} );
```

#### **`aps_supported_post_types`**

Filters the post types that can use the archived post status.

**Since:** 0.4.0

**Parameters:**

* `array $post_types` - Array of supported post type slugs

**Returns:** `array`

**Example:**

```php
add_filter( 'aps_supported_post_types', function( $post_types ) {
    return array_merge( $post_types, ['custom_post_type'] );
} );
```

### **Status Argument Filters**

These filters control the behavior of the archived post status registration:

#### **`aps_status_arg_public`**

Controls whether archived posts are public.

**Since:** 0.4.0

**Parameters:**

* `bool $public` - True to make public (default: `!is_admin() && aps_current_user_can_view()`)

**Returns:** `bool`

#### **`aps_status_arg_private`**

Controls whether archived posts are private.

**Since:** 0.4.0

**Parameters:**

* `bool $private` - True to make private (default: `!is_admin()`)

**Returns:** `bool`

#### **`aps_status_arg_protected`**

Controls whether archived posts are protected.

**Since:** 0.4.0

**Parameters:**

* `bool $protected` - True to make protected (default: `false`)

**Returns:** `bool`

#### **`aps_status_arg_exclude_from_search`**

Controls whether archived posts are excluded from search.

**Since:** 0.4.0

**Parameters:**

* `bool $exclude` - True to exclude from search (default: `!(is_admin() && aps_current_user_can_view())`)

**Returns:** `bool`

#### **`aps_status_arg_show_in_admin_all_list`**

Controls whether archived posts appear in the admin "All" list.

**Since:** 0.4.0

**Parameters:**

* `bool $show` - True to show in admin all list (default: `false`)

**Returns:** `bool`

#### **`aps_status_arg_show_in_admin_status_list`**

Controls whether archived posts appear in the admin status list.

**Since:** 0.4.0

**Parameters:**

* `bool $show` - True to show in admin status list (default: `aps_current_user_can_view()`)

**Returns:** `bool`

#### **`aps_status_arg_dashicon`**

Filters the [dashicon](https://developer.wordpress.org/resource/dashicons/) used for archived posts in the admin.

**Since:** 0.4.0

**Parameters:**

* `string $icon` - The dashicon name (default: "dashicons-archive")

**Returns:** `string`

**Example:**

```php
add_filter( 'aps_status_arg_dashicon', function( $icon ) {
    return 'dashicons-admin-generic';
} );
```

### **Capability Filters**

#### **`aps_default_read_capability`**

Filters the capability required to view archived content.

**Since:** 0.3.0

**Parameters:**

* `string $capability` - The capability name (default: "read\_private\_posts")
* `int $post_id` - The post ID

**Returns:** `string`

**Example:**

```php
add_filter( 'aps_default_read_capability', function( $capability, $post_id ) {
    return 'read_archived_posts';
}, 10, 2 );
```

#### **`aps_default_archive_capability`**

Filters the capability required to archive content.

**Since:** 0.4.0

**Parameters:**

* `string $capability` - The capability name (default: "edit\_others\_posts")
* `int $post_id` - The post ID

**Returns:** `string`

#### **`aps_user_unarchive_capability`**

Filters the capability required to unarchive content.

**Since:** 0.4.0

**Parameters:**

* `string $capability` - The capability name (default: "edit\_others\_posts")
* `int $post_id` - The post ID

**Returns:** `string`

### **Link Filters**

#### **`aps_get_archive_post_link`**

Filters the URL for archiving a post.

**Since:** 0.4.0

**Parameters:**

* `string $link` - The archive link URL
* `int $post_id` - The post ID
* `string $context` - The link context (default: 'display')

**Returns:** `string`

#### **`aps_get_unarchive_post_link`**

Filters the URL for unarchiving a post.

**Since:** 0.4.0

**Parameters:**

* `string $link` - The unarchive link URL
* `int $post_id` - The post ID
* `string $context` - The link context (default: 'display')

**Returns:** `string`

#### **`aps_archived_post_link`**

Filters the URL used to view an archived post.

**Since:** 0.4.0

**Parameters:**

* `string $archived_link` - The archived post URL
* `WP_Post $post` - The post object

**Returns:** `string`

### **Archive/Unarchive Process Filters**

#### **`aps_pre_archive_post`**

Filters whether a post archiving should take place.

**Since:** 0.4.0

**Parameters:**

* `bool|null $archive` - Whether to proceed with archiving (default: `null`)
* `WP_Post $post` - The post object
* `string $previous_status` - The post's previous status

**Returns:** `bool|null` - Return non-null to short-circuit the archiving process

#### **`aps_pre_unarchive_post`**

Filters whether a post unarchiving should take place.

**Since:** 0.4.0

**Parameters:**

* `bool|null $unarchive` - Whether to proceed with unarchiving (default: `null`)
* `WP_Post $post` - The post object
* `string $previous_status` - The post's previous status

**Returns:** `bool|null` - Return non-null to short-circuit the unarchiving process

#### **`aps_unarchive_post_status`**

Filters the status assigned to a post when restored from archive.

**Since:** 0.4.0

**Parameters:**

* `string $new_status` - The new status (default: previous status)
* `int $post_id` - The post ID
* `string $previous_status` - The status at archiving time

**Returns:** `string`

#### **`aps_unarchive_post_comment_status`**

Filters the comment status assigned when a post is restored from archive.

**Since:** 0.4.0

**Parameters:**

* `string $comment_status` - The comment status (default: stored value)
* `int $post_id` - The post ID
* `string $previous_status` - The status at archiving time

**Returns:** `string`

#### **`aps_unarchive_post_ping_status`**

Filters the ping status assigned when a post is restored from archive.

**Since:** 0.4.0

**Parameters:**

* `string $ping_status` - The ping status (default: stored value)
* `int $post_id` - The post ID
* `string $previous_status` - The status at archiving time

**Returns:** `string`

#### **`aps_archivable_statuses`**

Filters which post statuses can be archived.

**Since:** 0.4.0

**Parameters:**

* `array $statuses` - Array of archivable status names (default: `['publish', 'future', 'draft', 'pending', 'private']`)

**Returns:** `array`

#### **`aps_is_read_only`**

Filters whether archived content is read-only.

**Since:** 0.3.5

**Parameters:**

* `bool $is_read_only` - True if read-only (default: `true`)

**Returns:** `bool`

### **Feature Toggle Filters**

Each feature class in the plugin can be toggled using a dynamic filter:

#### **`aps_{feature_name}`**

Toggles individual plugin features on or off.

**Since:** 0.4.0

**Parameters:**

* `bool $active` - True if feature is active (default: `true`)

**Returns:** `bool`

**Available Features:**

* `aps_admin_notices` - Admin notices feature
* `aps_archived_title` - Title modification feature
* `aps_bulk_edit` - Bulk edit functionality
* `aps_post_editor` - Post editor enhancements
* `aps_row_actions` - Post list row actions
* `aps_save_post` - Save post functionality

**Example:**

```php
// Disable the archived title feature
add_filter( 'aps_archived_title', '__return_false' );
```

### Actions

### Plugin Lifecycle Actions

#### **`aps_init`**

Fires when the plugin is initialized, before anything is loaded.

**Since:** 0.4.0

**Parameters:** None

**Example:**

```php
add_action( 'aps_init', function() {
    // Plugin initialization code
} );
```

#### **`aps_loaded`**

Fires when the plugin is loaded, after all dependencies are loaded.

**Since:** 0.4.0

**Parameters:** None

**Example:**

```php
add_action( 'aps_loaded', function() {
    // Code to run after plugin is fully loaded
} );
```

### Archive/Unarchive Actions

#### **`aps_archive_post`**

Fires before a post is archived.

**Since:** 0.4.0

**Parameters:**

* `int $post_id` - The post ID
* `string $previous_status` - The post's status before archiving

**Example:**

```php
add_action( 'aps_archive_post', function( $post_id, $previous_status ) {
    // Log the archiving action
    error_log( "Post {$post_id} archived from {$previous_status}" );
}, 10, 2 );
```

#### **`aps_archived_post`**

Fires after a post is archived.

**Since:** 0.4.0

**Parameters:**

* `int $post_id` - The post ID
* `string $previous_status` - The post's status before archiving

#### **`aps_unarchive_post`**

Fires before a post is unarchived.

**Since:** 0.4.0

**Parameters:**

* `int $post_id` - The post ID
* `string $previous_status` - The post's status before unarchiving

#### **`aps_unarchived_post`**

Fires after a post is unarchived.

**Since:** 0.4.0

**Parameters:**

* `int $post_id` - The post ID
* `string $previous_status` - The post's status before unarchiving

### Usage Examples

#### Custom Post Type Support

```php
// Add support for custom post types
add_filter( 'aps_supported_post_types', function( $post_types ) {
    return array_merge( $post_types, ['product', 'event'] );
} );

// Custom archive behavior for products
add_filter( 'aps_pre_archive_post', function( $archive, $post, $previous_status ) {
    if ( 'product' === $post->post_type ) {
        // Update product inventory when archived
        update_post_meta( $post->ID, '_stock_status', 'archived' );
    }
    return $archive;
}, 10, 3 );
```

#### Change Label on Archived Content

```php
// Custom archived label for different post types
add_filter( 'aps_title_label', function( $label, $post_id, $title ) {
    // On product post types
    if ( 'product' === get_post_type( $post_id ) ) {
        return 'Discontinued';
    }
    return $label;
}, 10, 3 );
```
