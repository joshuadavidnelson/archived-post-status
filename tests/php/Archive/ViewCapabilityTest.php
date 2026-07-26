<?php
/**
 * Archive\ViewCapability Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ViewCapability
 *
 * Phase 3A extraction (0.4.0): mirrors the facade test
 * `FunctionsTest::test_aps_current_user_can_view_filter` but exercises the
 * lifted SUT directly. The two complementary tests below cover the default
 * capability (`read_private_posts` flows to current_user_can()) and the
 * filter override path.
 */

use ArchivedPostStatus\Archive\ViewCapability;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ViewCapability
 */
class ViewCapabilityTest extends TestCase {

	/**
	 * Default-path: without any filter, the SUT forwards the default
	 * `'read_private_posts'` capability to `current_user_can()`.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_default_capability_is_read_private_posts() {
		$received_capability = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability, $post_id ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_read_capability', 'read_private_posts', 0 );

		$result = ViewCapability::granted();

		$this->assertTrue( $result );
		$this->assertSame( 'read_private_posts', $received_capability );
	}

	/**
	 * Filter-override path: the `aps_default_read_capability` filter mutates
	 * the capability passed to `current_user_can()` — the public extension
	 * point sites use to widen / narrow the read gate.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_filter_replaces_capability_passed_to_current_user_can() {
		$received_capability = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability, $post_id ) use ( &$received_capability ) {
					$received_capability = $capability;
					return $capability === 'read_private_posts';
				},
			)
		);

		\WP_Mock::onFilter( 'aps_default_read_capability' )
			->with( 'read_private_posts', 0 )
			->reply( 'read' );

		$result = ViewCapability::granted();

		$this->assertFalse( $result );
		$this->assertSame( 'read', $received_capability );
	}

	/**
	 * Edge case: a non-zero post id passes through to both the filter and
	 * `current_user_can()`, matching the procedural facade's contract.
	 *
	 * @covers ArchivedPostStatus\Archive\ViewCapability::granted
	 */
	public function test_granted_passes_post_id_to_filter_and_current_user_can() {
		$received_post_id = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability, $post_id ) use ( &$received_post_id ) {
					$received_post_id = $post_id;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_read_capability', 'read_private_posts', 42 );

		ViewCapability::granted( 42 );

		$this->assertSame( 42, $received_post_id );
	}
}
