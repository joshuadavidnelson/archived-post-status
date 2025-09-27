<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase as BaseTestCase;

/**
 * We will extend this test case to make WP_Mock set up easier
 */
class TestCase extends BaseTestCase {

	/**
	 * Set up with WP_Mock
	 *
	 * @since  0.8
	 */
	public function set_up() {
		\WP_Mock::setUp();
	}

	/**
	 * Tear down with WP_Mock
	 *
	 * @since  0.8
	 */
	public function tear_down() {
		\WP_Mock::tearDown();
	}

	/**
	 * Create a mock post with default values
	 */
	protected function createMockPost( array $args = [] ) {
		$defaults = [
			'ID' => 123,
			'post_type' => 'post',
			'post_status' => 'publish',
			'comment_status' => 'open',
			'ping_status' => 'open',
			'post_title' => 'Test Post'
		];

		$args = array_merge( $defaults, $args );
		$post = \Mockery::mock( 'WP_Post' );

		foreach ( $args as $key => $value ) {
			$post->$key = $value;
		}

		return $post;
	}

	/**
	 * Create a mock screen object
	 */
	protected function createMockScreen( array $args = [] ) {
		$defaults = [
			'base' => 'edit',
			'post_type' => 'post'
		];

		$args = array_merge( $defaults, $args );
		$screen = \Mockery::mock( 'WP_Screen' );

		foreach ( $args as $key => $value ) {
			$screen->$key = $value;
		}

		return $screen;
	}
}
