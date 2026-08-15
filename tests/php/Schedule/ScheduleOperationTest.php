<?php
/**
 * Schedule\ScheduleOperation Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\ScheduleOperation
 */

use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleOperation;
use ArchivedPostStatus\Schedule\ScheduleSource;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\ScheduleOperation
 */
class ScheduleOperationTest extends TestCase {

	/**
	 * Stub the WP-boundary functions aps_is_supported_post_type() traverses,
	 * so the real SupportedPostTypes resolver runs (rather than being
	 * stubbed itself — see TestCase.php's class docblock).
	 *
	 * @param string[] $supported Post type slugs the boundary reports as public.
	 */
	private function mockSupportedPostTypesBoundary( array $supported = array( 'post' ) ): void {
		\WP_Mock::userFunction( 'get_post_types' )
			->andReturn( array_combine( $supported, $supported ) );
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( array( 'attachment' ) )
			->reply( array( 'attachment' ) );
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( $supported )
			->reply( $supported );
	}

	// -----------------------------------------------------------------------
	// set() — guards
	// -----------------------------------------------------------------------

	/**
	 * Guard 1: a missing post rejects before any other check runs.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::set
	 */
	public function test_set_returns_false_when_post_does_not_exist() {
		\WP_Mock::userFunction( 'get_post' )->with( 999 )->andReturn( null );
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->assertFalse( ScheduleOperation::set( 999, time() + 3600, ScheduleSource::Manual ) );
	}

	/**
	 * Guard 2: an unsupported post type rejects even though the post exists.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::set
	 */
	public function test_set_returns_false_when_post_type_is_not_supported() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'attachment' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->mockSupportedPostTypesBoundary( array( 'post' ) );
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->assertFalse( ScheduleOperation::set( 42, time() + 3600, ScheduleSource::Manual ) );
	}

	/**
	 * Guard 3: a non-positive timestamp rejects, even for a supported post.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::set
	 */
	public function test_set_returns_false_when_timestamp_is_not_positive() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->mockSupportedPostTypesBoundary();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->assertFalse( ScheduleOperation::set( 42, 0, ScheduleSource::Manual ) );
	}

	/**
	 * Guard 4: `aps_pre_schedule_archive` short-circuits the whole
	 * operation — a non-null return is cast to bool and returned as-is,
	 * with no meta write and no `aps_scheduled_archive` action.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::set
	 */
	public function test_set_short_circuits_when_pre_filter_returns_non_null() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->mockSupportedPostTypesBoundary();

		\WP_Mock::onFilter( 'aps_pre_schedule_archive' )
			->with( null, 42, 1700000000, ScheduleSource::Manual )
			->reply( false );

		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->assertFalse( ScheduleOperation::set( 42, 1700000000, ScheduleSource::Manual ) );
	}

	// -----------------------------------------------------------------------
	// set() — success path
	// -----------------------------------------------------------------------

	/**
	 * Default path: all guards pass, the pre-filter returns null, the
	 * schedule meta is written with attempts reset to 0 and the current
	 * user as owner, and `aps_scheduled_archive` fires with the source's
	 * string value.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::set
	 */
	public function test_set_writes_schedule_meta_and_fires_action_when_all_guards_pass() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		$this->mockSupportedPostTypesBoundary();

		\WP_Mock::onFilter( 'aps_pre_schedule_archive' )
			->with( null, 42, 1700000000, ScheduleSource::Rule )
			->reply( null );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_TIME, 1700000000 )->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_SOURCE, 'rule' )->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_USER, 1 )->andReturn( true ); // polyfill get_current_user_id() == 1
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_RULE_VERSION, 3 )->andReturn( true );
		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_ATTEMPTS, 0 )->andReturn( true );

		\WP_Mock::expectAction( 'aps_scheduled_archive', 42, 1700000000, 'rule' );

		$this->assertTrue( ScheduleOperation::set( 42, 1700000000, ScheduleSource::Rule, 3 ) );
	}

	// -----------------------------------------------------------------------
	// clear() — guards
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::clear
	 */
	public function test_clear_returns_false_when_post_does_not_exist() {
		\WP_Mock::userFunction( 'get_post' )->with( 999 )->andReturn( null );
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->assertFalse( ScheduleOperation::clear( 999 ) );
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::clear
	 */
	public function test_clear_returns_false_when_no_schedule_exists() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->assertFalse( ScheduleOperation::clear( 42 ) );
	}

	// -----------------------------------------------------------------------
	// clear() — tombstone vs. full delete
	// -----------------------------------------------------------------------

	/**
	 * A rule-stamped schedule is tombstoned by default: source flips to
	 * `exempt`, the time key is dropped, the other keys are left alone, and
	 * `aps_unscheduled_archive` fires.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::clear
	 */
	public function test_clear_tombstones_a_rule_stamped_schedule_by_default() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'rule' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '3' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );

		\WP_Mock::onFilter( 'aps_schedule_tombstone_on_clear' )
			->with( true, 42, ScheduleSource::Rule )
			->reply( true );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_SOURCE, 'exempt' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_TIME )->andReturn( true );
		// No other keys touched on a tombstone.
		\WP_Mock::userFunction( 'delete_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )
			->with( 42, ScheduleMeta::META_USER )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS )->never();

		\WP_Mock::expectAction( 'aps_unscheduled_archive', 42 );

		$this->assertTrue( ScheduleOperation::clear( 42 ) );
	}

	/**
	 * A manually-set schedule is deleted outright by default — all five
	 * meta keys removed, none tombstoned.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::clear
	 */
	public function test_clear_deletes_a_manual_schedule_outright_by_default() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'manual' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );

		\WP_Mock::onFilter( 'aps_schedule_tombstone_on_clear' )
			->with( false, 42, ScheduleSource::Manual )
			->reply( false );

		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_TIME )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_SOURCE )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_USER )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_RULE_VERSION )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_ATTEMPTS )->andReturn( true );

		\WP_Mock::expectAction( 'aps_unscheduled_archive', 42 );

		$this->assertTrue( ScheduleOperation::clear( 42 ) );
	}

	/**
	 * The `aps_schedule_tombstone_on_clear` filter overrides the default in
	 * both directions. This pins the "site wants rule schedules gone
	 * outright" override: source is Rule (default tombstone = true) but the
	 * filter forces full deletion instead.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::clear
	 */
	public function test_clear_honors_filter_override_forcing_full_delete_of_a_rule_schedule() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'rule' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '3' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );

		\WP_Mock::onFilter( 'aps_schedule_tombstone_on_clear' )
			->with( true, 42, ScheduleSource::Rule )
			->reply( false );

		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_TIME )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_SOURCE )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_USER )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_RULE_VERSION )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_ATTEMPTS )->andReturn( true );

		$this->assertTrue( ScheduleOperation::clear( 42 ) );
	}

	/**
	 * Mirror override: source is Manual (default tombstone = false) but the
	 * filter forces the tombstone path instead.
	 *
	 * @covers ArchivedPostStatus\Schedule\ScheduleOperation::clear
	 */
	public function test_clear_honors_filter_override_forcing_tombstone_of_a_manual_schedule() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'get_post' )->with( 42 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'manual' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );

		\WP_Mock::onFilter( 'aps_schedule_tombstone_on_clear' )
			->with( false, 42, ScheduleSource::Manual )
			->reply( true );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, ScheduleMeta::META_SOURCE, 'exempt' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )
			->once()->with( 42, ScheduleMeta::META_TIME )->andReturn( true );

		$this->assertTrue( ScheduleOperation::clear( 42 ) );
	}
}
