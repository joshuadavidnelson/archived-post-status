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
