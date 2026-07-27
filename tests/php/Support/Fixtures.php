<?php
/**
 * Test fixture builders.
 *
 * the 0.4.0 refactor split this file out of
 * `tests/php/includes/common.php`. Where {@see Support/WpPolyfills.php}
 * provides global function declarations the SUT calls unconditionally,
 * THIS file provides per-test factories that build deterministic fixtures
 * (WP_Post doubles, get_post stubs, etc.) for tests that need richer
 * control over the input data than a default polyfill provides.
 *
 * The hardcoded `ID=123` `get_post` polyfill that lived at
 * `common.php:177-183` is replaced by {@see make_get_post_stub()} — a
 * per-id factory tests call from `set_up()` (or any individual test) to
 * pin the post the SUT will receive. Tests that don't need a specific
 * post call nothing and let the WP_Mock `get_post` userFunction default
 * take effect.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\Tests\Support;

/**
 * Register a `get_post` WP_Mock userFunction for a specific post id,
 * returning an stdClass with sensible defaults that the test can override
 * via $overrides.
 *
 * Replaces the legacy `get_post` polyfill's hardcoded ID=123 shape so a
 * test can register the post it actually wants without monkey-patching
 * the global declaration.
 *
 * Tests that need to assert observable get_post behavior (status, type)
 * can call this in set_up() or per-test, then assert on whatever the SUT
 * does with the returned post.
 *
 * @param int                  $id        The post id the SUT will pass to get_post.
 * @param array<string, mixed> $overrides Override any default field on the
 *                                         returned post (ID is forced to $id;
 *                                         post_status defaults to 'publish',
 *                                         post_type to 'post', comment_status
 *                                         and ping_status to 'open').
 */
function make_get_post_stub( int $id, array $overrides = array() ): void {
	$defaults = array(
		'post_status'    => 'publish',
		'post_type'      => 'post',
		'comment_status' => 'open',
		'ping_status'    => 'open',
		'post_title'     => 'Test Post',
	);
	$args     = array_merge( $defaults, $overrides );

	$post     = new \stdClass();
	$post->ID = $id;
	foreach ( $args as $key => $value ) {
		$post->$key = $value;
	}

	\WP_Mock::userFunction( 'get_post' )
		->with( $id )
		->andReturn( $post );
}
