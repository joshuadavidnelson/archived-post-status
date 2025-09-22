<?php
/**
 * Simple common WP classes/functions
 */

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
 * Mock register_activation_hook() function.
 */
function register_activation_hook( $file, $callback ) {
	// Do nothing.
}

/**
 * Mock register_deactivation_hook() function.
 */
function register_deactivation_hook( $file, $callback ) {
	// Do nothing.
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
 * MOve sanitize_title function.
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
	return true;
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
	return true;
}

/**
 * Mock current_user_can() function.
 */
function current_user_can( $capability, ...$args ) {
	return true;
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
 * Mock get_post() function.
 */
function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) {
	$mock_post = new stdClass();
	$mock_post->ID = 123;
	$mock_post->post_status = 'publish';
	$mock_post->post_type = 'post';
	return $mock_post;
}

/**
 * Mock wp_die() function.
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Exception( $message );
}

/**
 * Mock _deprecated_function() function.
 */
function _deprecated_function( $function, $version, $replacement = null ) {
	// Do nothing in tests.
}
