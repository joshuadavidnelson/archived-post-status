<?php
/**
 * ScheduleCommand validation/execution tests.
 *
 * validate() performs the shared gates AND the write itself — the inherited
 * Command::execute() is never reached (it is wired to ArchiveAction::perform(),
 * which has no "schedule" case). Every test therefore exercises the pipeline
 * via the public Command::run() entry point, same as ArchiveCommandTest.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\ScheduleCommand
 * @covers ArchivedPostStatus\CLI\Command
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\CLI\CliResult;
	use ArchivedPostStatus\CLI\ScheduleCommand;

	/**
	 * @since 0.5.0
	 * @covers ArchivedPostStatus\CLI\ScheduleCommand
	 */
	class ScheduleCommandTest extends TestCase {

		private ScheduleCommand $cmd;

		public function set_up() {
			parent::set_up();
			$this->cmd = new ScheduleCommand();
		}

		/**
		 * Unsupported post types short-circuit with an error before any
		 * capability check or --at parsing.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 */
		public function test_returns_error_for_unsupported_post_type() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertInstanceOf( CliResult::class, $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'not a supported post type', $result->message );
		}

		/**
		 * A user lacking the archive capability is rejected before --at is
		 * even parsed — capability_check() reuses aps_current_user_can_archive(),
		 * per plan §5.7, never a capability of its own.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_returns_error_when_user_lacks_archive_capability() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'capability', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * A post locked for editing by another user is rejected before --at
		 * is parsed, mirroring ArchiveCommand's lock gate.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::ensure_not_locked
		 */
		public function test_returns_error_when_post_is_locked() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( 7 );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'locked', $result->message );
		}

		/**
		 * An unparseable --at value is a per-id CliResult failure naming the
		 * accepted format, never a fatal — the gates above all pass, but
		 * ScheduleTime::to_timestamp() returns null for garbage input.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 */
		public function test_returns_error_for_unparseable_at_value_without_a_fatal() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

			$result = $this->cmd->run( 42, array( 'at' => 'not-a-date' ) );

			$this->assertInstanceOf( CliResult::class, $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( '--at', $result->message );
			$this->assertStringContainsString( 'Y-m-d', $result->message );
		}

		/**
		 * A missing --at flag (default '') is treated the same as an
		 * unparseable value, not a separate code path.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 */
		public function test_returns_error_when_at_flag_is_missing() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '--at', $result->message );
		}

		/**
		 * --at is parsed exclusively through ScheduleTime::to_timestamp() —
		 * this pins that a real, valid value reaches aps_schedule_archive()
		 * as the correct epoch, proving there is no hand-rolled date parse
		 * anywhere in this command.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 */
		public function test_a_valid_at_value_schedules_the_post_via_the_canonical_timezone_boundary() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );

			$utc = new \DateTimeZone( 'UTC' );
			\WP_Mock::userFunction( 'wp_timezone' )->andReturn( $utc );

			$expected_timestamp = ( new \DateTimeImmutable( '2027-03-03 14:30:00', $utc ) )->getTimestamp();

			\WP_Mock::userFunction( 'aps_schedule_archive' )
				->once()
				->with( 42, $expected_timestamp, 'manual' )
				->andReturn( true );

			\WP_Mock::userFunction( 'get_option' )->with( 'date_format' )->andReturn( 'Y-m-d' );
			\WP_Mock::userFunction( 'get_option' )->with( 'time_format' )->andReturn( 'H:i' );
			\WP_Mock::userFunction( 'wp_date' )->andReturn( '2027-03-03 14:30' );

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'Scheduled', $result->message );
		}

		/**
		 * A non-UTC site timezone is the case that would silently break if
		 * this command ever bypassed ScheduleTime::to_timestamp() for a
		 * hand-rolled strtotime() parse: strtotime() reads PHP's own default
		 * timezone (unrelated to wp_timezone()), so under a UTC PHP default
		 * and a non-UTC wp_timezone() the two disagree by the zone's offset.
		 * The UTC-only test above cannot expose that regression, because
		 * both parses agree when the two timezones happen to coincide.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 */
		public function test_a_valid_at_value_is_interpreted_in_a_non_utc_site_timezone() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );

			$tz = new \DateTimeZone( 'America/New_York' );
			\WP_Mock::userFunction( 'wp_timezone' )->andReturn( $tz );

			// 2027-03-03 14:30:00 America/New_York (EST, UTC-5) is 19:30:00 UTC --
			// five hours away from what a UTC-default strtotime() would compute.
			$expected_timestamp = ( new \DateTimeImmutable( '2027-03-03 14:30:00', $tz ) )->getTimestamp();

			\WP_Mock::userFunction( 'aps_schedule_archive' )
				->once()
				->with( 42, $expected_timestamp, 'manual' )
				->andReturn( true );

			\WP_Mock::userFunction( 'get_option' )->andReturn( 'Y-m-d H:i' );
			\WP_Mock::userFunction( 'wp_date' )->andReturn( '2027-03-03 14:30' );

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertTrue( $result->is_success );
		}

		/**
		 * When aps_schedule_archive() itself fails (e.g. an aps_pre_schedule_archive
		 * veto), the command surfaces a failure CliResult rather than
		 * claiming success.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 */
		public function test_returns_error_when_aps_schedule_archive_returns_false() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->andReturn( false );

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'Failed to schedule', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * Anonymous WP-CLI invocations bypass the capability gate, matching
		 * every other command's documented elevated-context convention.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_skips_capability_check_when_no_user_authenticated() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
			\WP_Mock::userFunction( 'get_option' )->andReturn( 'Y-m-d H:i' );
			\WP_Mock::userFunction( 'wp_date' )->andReturn( '2027-03-03 14:30' );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->andReturn( true );

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertTrue( $result->is_success );
		}

		/**
		 * progress_label() and action() describe how the command identifies
		 * itself to CommandRunner and Command::capability_check() — action()
		 * returns ArchiveAction::Archive purely to resolve
		 * aps_current_user_can_archive(), not because scheduling performs an
		 * ArchiveAction.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::progress_label
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::action
		 */
		public function test_describes_itself_with_the_archive_action_and_scheduling_label() {
			$this->assertSame( 'Scheduling', $this->cmd->progress_label() );

			$action = new \ReflectionMethod( $this->cmd, 'action' );
			$action->setAccessible( true );
			$this->assertSame(
				\ArchivedPostStatus\Archive\ArchiveAction::Archive,
				$action->invoke( $this->cmd )
			);
		}

		/**
		 * validate() never returns null — proves Command::execute() (final,
		 * wired to ArchiveAction::perform()) is never reached by this
		 * command, so aps_archive_post() must never be called.
		 *
		 * @covers ArchivedPostStatus\CLI\ScheduleCommand::validate
		 */
		public function test_never_reaches_the_inherited_archive_action_execute_path() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
			\WP_Mock::userFunction( 'get_option' )->andReturn( 'Y-m-d H:i' );
			\WP_Mock::userFunction( 'wp_date' )->andReturn( '2027-03-03 14:30' );
			\WP_Mock::userFunction( 'aps_schedule_archive' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_archive_post' )->never();

			$result = $this->cmd->run( 42, array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertTrue( $result->is_success );
		}
	}
}
