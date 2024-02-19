<?php
/**
 * Simple common WP classes/functions
 */

/**
 * Mock wp_cache_get() function.
 *
 * @since 0.3.9
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
 * @since 0.3.9
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
 * @since 0.3.9
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
 * Mock the sanitize_key() function.
 *
 * @since 0.4.0
 * @return array
 */
function sanitize_key( $key ) {

	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );

}
