<?php
/**
 * WP runtime polyfills loaded at PHPUnit bootstrap.
 *
 * The polyfill concern (provide global functions that the SUT calls but
 * PHPUnit never declares) lives in one place here.
 *
 * Every function here exists for one reason only: the unit-test runtime
 * does not load wp-includes, so functions the production code uses
 * unconditionally would otherwise fatal. Each polyfill returns a
 * deterministic default; tests that exercise the behavior of a specific
 * function override via WP_Mock::userFunction() rather than redefining
 * the function.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

// Define common WordPress constants
if ( ! defined( 'DOING_AUTOSAVE' ) ) {
	define( 'DOING_AUTOSAVE', false );
}
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', false );
}
if ( ! defined( 'DOING_CRON' ) ) {
	define( 'DOING_CRON', false );
}

/**
 * Mock plugin_basename() function.
 */
function plugin_basename( $file ) {
	return 'archived-post-status/archived-post-status.php';
}

/**
 * Mock plugins_url() function.
 */
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.com/wp-content/plugins/archived-post-status/' . ltrim( $path, '/' );
}

/**
 * Mock wp_cache_get() function.
 *
 * @since 0.4.0
 *
 * @param string $key
 * @param string $group
 * @return mixed
 */
function wp_cache_get( $key, $group ) {
	return false;
}

/**
 * Mock wp_cache_set() function.
 *
 * @since 0.4.0
 *
 * @param string $key
 * @param mixed  $value
 * @param string $group
 * @return bool
 */
function wp_cache_set( $key, $value, $group ) {
	return true;
}

/**
 * Mock absint() function.
 *
 * @since 0.4.0
 *
 * @param mixed $maybeint
 * @return int
 */
function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

/**
 * Mock _n_noop() function.
 */
function _n_noop( $singular, $plural, $domain = null ) {
	return array(
		0          => $singular,
		1          => $plural,
		'singular' => $singular,
		'plural'   => $plural,
		'context'  => null,
		'domain'   => $domain,
	);
}

/**
 * Mock sanitize_title() function.
 *
 * @since 0.4.0
 * @param mixed $title
 * @return string
 */
function sanitize_title( $title ) {
	return strtolower( str_replace( ' ', '-', $title ) );
}

/**
 * Mock the post_type_exists() function.
 *
 * @since 0.4.0
 * @param string $post_type
 * @return bool
 */
function post_type_exists( $post_type ) {
	return false;
}

/**
 * Mock sanitize_key() function.
 *
 * @since 0.4.0
 * @return array
 */
function sanitize_key( $key ) {

	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );

}

/**
 * Mock wp_unslash() function.
 *
 * Deterministic stand-in so any code path that unslashes request data can be
 * exercised without a WordPress install. Previously only one test mocked this
 * through WP_Mock, which defines it process-wide — so whether a later test
 * could call it depended on execution order, and the suite passed locally
 * (result-cache ordering) while failing in CI.
 *
 * @since 0.4.0
 * @param string|array<mixed> $value
 * @return string|array<mixed>
 */
function wp_unslash( $value ) {

	return is_array( $value ) ? array_map( 'stripslashes', $value ) : stripslashes( (string) $value );

}

/**
 * Mock plugin_dir_path() function.
 */
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

/**
 * Mock load_plugin_textdomain() function.
 */
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
	return true;
}

/**
 * Mock is_admin() function.
 */
function is_admin() {
	return false;
}

/**
 * Mock is_multisite() function.
 *
 * Defaults false so `Plugin::hookables()` — invoked unconditionally at
 * suite bootstrap via `aps_run_plugin()` and again in every `PluginTest` /
 * `PluginHookablesParityTest` case — can call
 * `Settings\NetworkActivation::active()` without every test needing to stub
 * this explicitly. Tests exercising the network-activated path override via
 * `WP_Mock::userFunction( 'is_multisite' )->andReturn( true )`, the same
 * pattern already used throughout `UninstallTest`.
 *
 * @since 0.5.0
 * @return bool
 */
function is_multisite() {
	return false;
}

/**
 * Mock current_user_can() function.
 */
function current_user_can( $capability, ...$args ) {
	return false;
}

/**
 * Mock get_post_types() function.
 */
function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
	return array( 'post', 'page' );
}

/**
 * Mock register_post_status() function.
 */
function register_post_status( $post_status, $args = array() ) {
	return true;
}

/**
 * Mock register_deactivation_hook() function.
 *
 * archived-post-status.php calls this unconditionally at bootstrap time,
 * outside of any test's set_up() -- WP_Mock's action/filter system is not
 * involved, so this has to exist as a real function before the suite loads
 * a single test, the same reason register_post_status() above does.
 */
function register_deactivation_hook( $file, $callback ) {
	return true;
}

/**
 * Mock wp_die() function.
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Exception( esc_html( $message ) );
}

/**
 * Mock _deprecated_function() function.
 */
function _deprecated_function( $function, $version, $replacement = null ) {
	// Do nothing in tests.
}

/**
 * Mock remove_query_arg() function.
 */
function remove_query_arg( $key, $query = false ) {
	return 'http://example.com/wp-admin/edit.php';
}

/**
 * Mock add_query_arg() function.
 */
function add_query_arg( $args, $uri = false ) {
	return 'http://example.com/wp-admin/edit.php';
}

/**
 * Mock get_post_status() function.
 */
function get_post_status( $post = null ) {
	return isset( $post->post_status ) ? $post->post_status : 'publish';
}

/**
 * Mock get_post_type() function.
 */
function get_post_type( $post = null ) {
	return isset( $post->post_type ) ? $post->post_type : 'post';
}

/**
 * Mock is_plugin_active() function.
 */
function is_plugin_active( $plugin, $mock = false ) {
	return $mock;
}

/**
 * Mock wp_admin_notice() function.
 */
function wp_admin_notice( $message, $args = array() ) {
	echo '<div class="notice">' . esc_html( $message ) . '</div>';
}

/**
 * Mock wp_check_post_lock() function.
 */
function wp_check_post_lock( $post_id ) {
	return false; // No lock by default
}

/**
 * Mock remove_filter() function.
 */
function remove_filter( $tag, $function_to_remove, $priority = 10 ) {
	return true;
}

/**
 * Mock get_current_screen() function.
 *
 * @since 0.4.0
 * @return object
 */
function get_current_screen() {
	$screen = new stdClass();
	$screen->base = 'edit';
	$screen->id = 'edit-post';
	return $screen;
}

/**
 * Mock get_the_ID() function.
 *
 * @since 0.4.0
 * @return int
 */
function get_the_ID() {
	return 123;
}

/**
 * Mock wp_nonce_url() function.
 *
 * @since 0.4.0
 * @param string $actionurl
 * @param string $action
 * @param string $name
 * @return string
 */
function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
	return 'http://example.com/wp-admin/edit.php?_wpnonce=abc123';
}

/**
 * Mock get_edit_post_link() function.
 *
 * @since 0.4.0
 * @param int $id
 * @param string $context
 * @return string
 */
function get_edit_post_link( $id = 0, $context = 'display' ) {
	return 'http://example.com/wp-admin/post.php?post=' . $id . '&action=edit';
}

/**
 * Mock get_post_type_object() function.
 *
 * @since 0.4.0
 * @param string $post_type
 * @return object
 */
function get_post_type_object( $post_type ) {
	$object = new stdClass();
	$object->name = $post_type;
	$object->labels = new stdClass();
	$object->labels->name = ucfirst( $post_type ) . 's';
	$object->labels->singular_name = ucfirst( $post_type );
	$object->_edit_link = 'post.php?post=%d&action=edit';
	return $object;
}

/**
 * Mock wp_is_post_revision() function.
 *
 * @since 0.4.0
 * @param int|WP_Post $post
 * @return bool|int
 */
function wp_is_post_revision( $post ) {
	return false;
}

/**
 * Mock get_query_var() function.
 *
 * @since 0.4.0
 * @param string $var
 * @param mixed $default
 * @return mixed
 */
function get_query_var( $var, $default = '' ) {
	return $default;
}

/**
 * Mock admin_url() function.
 *
 * @since 0.4.0
 * @param string $path
 * @param string $scheme
 * @return string
 */
function admin_url( $path = '', $scheme = 'admin' ) {
	return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
}

/**
 * Mock wp_update_post() function.
 *
 * @since 0.4.0
 * @param array|object $postarr
 * @param bool $wp_error
 * @return int|WP_Error
 */
function wp_update_post( $postarr, $wp_error = false ) {
	return 123;
}

/**
 * Mock add_post_meta() function.
 *
 * @since 0.4.0
 * @param int $post_id
 * @param string $meta_key
 * @param mixed $meta_value
 * @param bool $unique
 * @return int|false
 */
function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
	return 1;
}

/**
 * Mock wp_enqueue_script() function.
 *
 * @since 0.4.0
 * @param string $handle
 * @param string $src
 * @param array $deps
 * @param string|bool|null $ver
 * @param bool $in_footer
 * @return void
 */
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	// Do nothing in tests
}

/**
 * Mock wp_localize_script() function.
 *
 * @since 0.4.0
 * @param string $handle
 * @param string $object_name
 * @param array $l10n
 * @return bool
 */
function wp_localize_script( $handle, $object_name, $l10n ) {
	return true;
}

/**
 * Mock wp_set_script_translations() function.
 *
 * @since 0.4.0
 * @param string $handle
 * @param string $domain
 * @param string $path
 * @return bool
 */
function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
	return true;
}

/**
 * Mock wp_get_referer() function.
 *
 * @since 0.4.0
 * @return string|false
 */
function wp_get_referer() {
	return 'http://example.com/wp-admin/edit.php';
}

/**
 * Mock wp_safe_redirect() function.
 *
 * @since 0.4.0
 * @param string $location
 * @param int $status
 * @param string $x_redirect_by
 * @return bool
 */
function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) { // phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExitInConditional
	return true;
}

/**
 * Mock check_admin_referer() function.
 *
 * @since 0.4.0
 * @param int|string $action
 * @param string $query_arg
 * @return int|false
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	return 1;
}

/**
 * Mock get_userdata() function.
 *
 * @since 0.4.0
 * @param int $user_id
 * @return WP_User|false
 */
function get_userdata( $user_id ) {
	$user = new stdClass();
	$user->ID = $user_id;
	$user->display_name = 'Test User';
	return $user;
}

/**
 * Mock wp_kses_post() function.
 *
 * @since 0.4.0
 * @param string $data
 * @return string
 */
function wp_kses_post( $data ) {
	return strip_tags( $data, '<a><strong><em><br><p><ul><ol><li>' ); // phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsTwoParameters
}

/**
 * Mock number_format_i18n() function.
 *
 * @since 0.4.0
 * @param float $number
 * @param int $decimals
 * @return string
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( $number, $decimals );
}

/**
 * Mock delete_post_meta() function.
 */
function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
	return true;
}

/**
 * Mock get_post_timestamp() function.
 */
function get_post_timestamp( $post = null, $field = 'date' ) {
	return time();
}

/**
 * Mock get_current_user_id() function.
 */
function get_current_user_id() {
	return 1;
}

/**
 * Mock remove_action() function.
 */
function remove_action( $hook_name, $callback, $priority = 10 ) {
	return true;
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Mock get_option() function.
	 *
	 * @since 0.4.0
	 * @param string $option
	 * @param mixed $default
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Mock update_option() function.
	 *
	 * @since 0.4.0
	 * @param string $option
	 * @param mixed $value
	 * @return bool
	 */
	function update_option( $option, $value ) {
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Mock add_option() function.
	 *
	 * Backs upgrade_check()'s first-install branch, which uses
	 * add_option (race-safe create-if-missing) rather than update_option.
	 *
	 * @since 0.4.0
	 * @param string $option
	 * @param mixed $value
	 * @param string $deprecated
	 * @param bool $autoload
	 * @return bool
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = true ) {
		return true;
	}
}
