<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase as BaseTestCase;

/**
 * Project base test case. Sets up / tears down WP_Mock and exposes a small
 * surface of fixture helpers.
 *
 * Note:
 *   This class previously bundled stubs for plugin-owned functions
 *   (aps_is_supported_post_type, aps_current_user_can_archive,
 *   aps_get_archive_post_link, _aps_nonce_key, etc.). Those were retired
 *   because they let tests pass when the SUT itself regressed in the
 *   stubbed function. The remaining helpers are deliberately scoped to
 *   things that live at the WordPress boundary (WP_Post fixture, the
 *   `current_user_can` + `get_post_types` + `wp_update_post` cluster).
 *
 *   New tests should stub WP functions inline in the test body so the
 *   intent stays visible at the call site. Stubbing aps_* / _aps_*
 *   functions in tests is a smell — register the upstream filter the
 *   real function consumes, or assert on the SUT's observable output
 *   end-to-end instead.
 */
class TestCase extends BaseTestCase {

	/**
	 * Set up with WP_Mock.
	 *
	 * Also resets {@see \ArchivedPostStatus\Status\SupportedPostTypes}'s
	 * per-request memo — it is a plain static, so without an explicit reset
	 * here it would otherwise survive from whatever the previous test left
	 * behind and silently feed a stale post-type list into this one.
	 */
	public function set_up() {
		\WP_Mock::setUp();
		\ArchivedPostStatus\Status\SupportedPostTypes::reset();
	}

	/**
	 * Tear down with WP_Mock.
	 *
	 * Resets the same memo on the way out too — symmetric with the
	 * WP_Mock::setUp()/tearDown() pairing above, and belt-and-suspenders
	 * against leaking a cached post-type list into whichever test runs next.
	 */
	public function tear_down() {
		\ArchivedPostStatus\Status\SupportedPostTypes::reset();
		\WP_Mock::tearDown();
	}

	/**
	 * Build a Mockery WP_Post double with overridable defaults.
	 *
	 * Returns a Mockery mock rather than a stdClass so tests that need to
	 * pin method calls on the post (rare; mostly attribute access) still
	 * have the option.
	 *
	 * @param array<string, mixed> $args Overrides for the default attributes.
	 */
	protected function createMockPost( array $args = [] ) {
		$defaults = [
			'ID'             => 123,
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'post_title'     => 'Test Post',
		];

		$args = array_merge( $defaults, $args );
		$post = \Mockery::mock( 'WP_Post' );

		foreach ( $args as $key => $value ) {
			$post->$key = $value;
		}

		return $post;
	}

}
