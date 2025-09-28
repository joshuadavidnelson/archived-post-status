<?php
/**
 * Plugin Name: Archived Post Status - Test Helpers
 * Description: Test helper plugin for Cypress E2E tests. Provides test users, custom post types, and testing hooks.
 * Version: 1.0.0
 * Author: Test Suite
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create test users for permission testing
 */
function aps_create_test_users() {
    $test_users = [
        'test_admin' => 'administrator',
        'test_editor' => 'editor',
        'test_author' => 'author',
        'test_contributor' => 'contributor',
        'test_subscriber' => 'subscriber'
    ];

    foreach ($test_users as $username => $role) {
        if (!username_exists($username) && !email_exists($username . '@example.com')) {
            $user_id = wp_create_user(
                $username,
                'testpassword123',
                $username . '@example.com'
            );

            if (!is_wp_error($user_id)) {
                $user = new WP_User($user_id);
                $user->set_role($role);

                // Add some meta for testing
                update_user_meta($user_id, 'test_user', true);
                update_user_meta($user_id, 'created_by_test_helper', current_time('timestamp'));
            }
        }
    }
}

/**
 * Register custom post type for testing archive functionality
 */
function aps_register_test_post_types() {
    // Custom post type that supports archive
    register_post_type('test_post', [
        'labels' => [
            'name' => 'Test Posts',
            'singular_name' => 'Test Post',
            'menu_name' => 'Test Posts',
            'add_new' => 'Add New Test Post',
            'edit_item' => 'Edit Test Post',
            'view_item' => 'View Test Post',
            'all_items' => 'All Test Posts'
        ],
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_admin_bar' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'test-posts'],
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-admin-post'
    ]);

    // Custom post type that does NOT support archive (for testing restrictions)
    register_post_type('restricted_post', [
        'labels' => [
            'name' => 'Restricted Posts',
            'singular_name' => 'Restricted Post',
            'menu_name' => 'Restricted Posts'
        ],
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor'],
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-lock'
    ]);
}

/**
 * Add test-specific capability filters
 */
function aps_add_test_capability_filters() {
    // Allow testing of capability restrictions
    add_filter('user_can_archive_post', function($can, $post_id) {
        // Check if this is a test scenario
        $test_mode = get_option('aps_test_restrict_capabilities', false);
        if ($test_mode) {
            // Restrict capabilities for testing error scenarios
            if (get_post_meta($post_id, '_test_restrict_archive', true)) {
                return false;
            }
        }
        return $can;
    }, 10, 2);
}

/**
 * Add test-specific action hooks for testing integration
 */
function aps_add_test_hooks() {
    // Hook to track archive actions for testing
    add_action('aps_archive_post', function($post_id) {
        $archived_posts = get_option('test_archived_posts', []);
        $archived_posts[] = [
            'post_id' => $post_id,
            'timestamp' => current_time('timestamp'),
            'user_id' => get_current_user_id()
        ];
        update_option('test_archived_posts', $archived_posts);

        // Also set individual option for easy CLI testing
        update_option('test_last_archived_post', $post_id);
    });

    // Hook to track unarchive actions
    add_action('aps_unarchive_post', function($post_id) {
        $unarchived_posts = get_option('test_unarchived_posts', []);
        $unarchived_posts[] = [
            'post_id' => $post_id,
            'timestamp' => current_time('timestamp'),
            'user_id' => get_current_user_id()
        ];
        update_option('test_unarchived_posts', $unarchived_posts);

        // Also set individual option for easy CLI testing
        update_option('test_last_unarchived_post', $post_id);
    });
}

/**
 * Add custom taxonomy for testing
 */
function aps_register_test_taxonomy() {
    register_taxonomy('test_category', ['post', 'test_post'], [
        'labels' => [
            'name' => 'Test Categories',
            'singular_name' => 'Test Category',
            'menu_name' => 'Test Categories'
        ],
        'public' => true,
        'hierarchical' => true,
        'show_ui' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'test-category']
    ]);
}

/**
 * Clean up test data (for use in test teardown)
 */
function aps_cleanup_test_data() {
    // Remove test users
    $test_users = ['test_admin', 'test_editor', 'test_author', 'test_contributor', 'test_subscriber'];
    foreach ($test_users as $username) {
        $user = get_user_by('login', $username);
        if ($user) {
            wp_delete_user($user->ID);
        }
    }

    // Remove test options
    delete_option('test_archived_posts');
    delete_option('test_unarchived_posts');
    delete_option('test_last_archived_post');
    delete_option('test_last_unarchived_post');
    delete_option('aps_test_restrict_capabilities');

    // Remove test posts
    $test_posts = get_posts([
        'post_type' => ['test_post', 'restricted_post'],
        'posts_per_page' => -1,
        'post_status' => 'any'
    ]);

    foreach ($test_posts as $post) {
        wp_delete_post($post->ID, true);
    }
}

/**
 * Add WP-CLI commands for test management
 */
if (defined('WP_CLI') && WP_CLI) {

    /**
     * Manage test data for Archived Post Status plugin testing
     */
    class APS_Test_CLI_Commands extends WP_CLI_Command {

        /**
         * Create test users and data
         *
         * ## EXAMPLES
         *
         *     wp aps-test setup
         */
        public function setup() {
            aps_create_test_users();
            WP_CLI::success('Test users created successfully');
        }

        /**
         * Clean up all test data
         *
         * ## EXAMPLES
         *
         *     wp aps-test cleanup
         */
        public function cleanup() {
            aps_cleanup_test_data();
            WP_CLI::success('Test data cleaned up successfully');
        }

        /**
         * Enable capability restrictions for testing error scenarios
         *
         * ## EXAMPLES
         *
         *     wp aps-test restrict-capabilities
         */
        public function restrict_capabilities() {
            update_option('aps_test_restrict_capabilities', true);
            WP_CLI::success('Capability restrictions enabled for testing');
        }

        /**
         * Disable capability restrictions
         *
         * ## EXAMPLES
         *
         *     wp aps-test allow-capabilities
         */
        public function allow_capabilities() {
            delete_option('aps_test_restrict_capabilities');
            WP_CLI::success('Capability restrictions disabled');
        }

        /**
         * Create test posts with various configurations
         *
         * ## OPTIONS
         *
         * [--count=<count>]
         * : Number of test posts to create
         * ---
         * default: 5
         * ---
         *
         * [--post-type=<post-type>]
         * : Post type to create
         * ---
         * default: post
         * ---
         *
         * ## EXAMPLES
         *
         *     wp aps-test create-posts --count=10
         *     wp aps-test create-posts --post-type=test_post
         */
        public function create_posts($args, $assoc_args) {
            $count = $assoc_args['count'] ?? 5;
            $post_type = $assoc_args['post-type'] ?? 'post';

            $created = 0;
            for ($i = 1; $i <= $count; $i++) {
                $post_id = wp_insert_post([
                    'post_title' => "Test Post {$i} for Archive Testing",
                    'post_content' => "This is test content for post {$i}. Created by test helper plugin.",
                    'post_status' => 'publish',
                    'post_type' => $post_type,
                    'meta_input' => [
                        '_test_post' => true,
                        '_test_created_at' => current_time('timestamp')
                    ]
                ]);

                if ($post_id && !is_wp_error($post_id)) {
                    $created++;
                }
            }

            WP_CLI::success("Created {$created} test posts of type {$post_type}");
        }
    }

    WP_CLI::add_command('aps-test', 'APS_Test_CLI_Commands');
}

// Hook into WordPress
add_action('init', 'aps_create_test_users');
add_action('init', 'aps_register_test_post_types');
add_action('init', 'aps_register_test_taxonomy');
add_action('init', 'aps_add_test_capability_filters');
add_action('init', 'aps_add_test_hooks');

// Add custom CSS for test elements in admin
add_action('admin_head', function() {
    echo '<style>
        .test-helper-notice {
            background: #e8f5e8;
            border-left: 4px solid #4caf50;
            padding: 10px;
            margin: 10px 0;
        }
        .test-post-indicator {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            color: #856404;
        }
    </style>';
});

// Add admin notice when test helper is active
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        echo '<div class="notice notice-info test-helper-notice">';
        echo '<p><strong>Archived Post Status Test Helper:</strong> Test users and custom post types are available for E2E testing.</p>';
        echo '</div>';
    }
});

// Add indicator to test posts in admin
add_filter('display_post_states', function($post_states, $post) {
    if (get_post_meta($post->ID, '_test_post', true)) {
        $post_states['test_post'] = '<span class="test-post-indicator">TEST POST</span>';
    }
    return $post_states;
}, 10, 2);
