<?php
/**
 * UnscheduleCommand validation/execution tests.
 *
 * Same shape as ScheduleCommandTest: validate() performs the shared gates
 * AND the write itself, so Command::execute() is never reached.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\UnscheduleCommand
 * @covers ArchivedPostStatus\CLI\Command
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\CLI\CliResult;
	use ArchivedPostStatus\CLI\UnscheduleCommand;

	/**
	 * @since 0.5.0
	 * @covers ArchivedPostStatus\CLI\UnscheduleCommand
	 */
	class UnscheduleCommandTest extends TestCase {

		private UnscheduleCommand $cmd;

		public function set_up() {
			parent::set_up();
			$this->cmd = new UnscheduleCommand();
		}

		/**
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::validate
		 */
		public function test_returns_error_for_unsupported_post_type() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertInstanceOf( CliResult::class, $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'not a supported post type', $result->message );
		}

		/**
		 * Reuses aps_current_user_can_archive(), per plan §5.7 — no
		 * capability of its own.
		 *
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_returns_error_when_user_lacks_archive_capability() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'capability', $result->message );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::ensure_not_locked
		 */
		public function test_returns_error_when_post_is_locked() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( 7 );
			\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'locked', $result->message );
		}

		/**
		 * A post with no schedule to clear (aps_unschedule_archive() returns
		 * false) is a failure result, not a fatal.
		 *
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::validate
		 */
		public function test_returns_error_when_post_has_no_schedule_to_clear() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_unschedule_archive' )->with( 42 )->andReturn( false );

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'no schedule', $result->message );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::validate
		 */
		public function test_returns_success_when_schedule_is_cleared() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_unschedule_archive' )->once()->with( 42 )->andReturn( true );

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( 'Unscheduled', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * Anonymous WP-CLI invocations bypass the capability gate.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_skips_capability_check_when_no_user_authenticated() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_unschedule_archive' )->andReturn( true );

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::progress_label
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::action
		 */
		public function test_describes_itself_with_the_archive_action_and_unscheduling_label() {
			$this->assertSame( 'Unscheduling', $this->cmd->progress_label() );

			$action = new \ReflectionMethod( $this->cmd, 'action' );
			$action->setAccessible( true );
			$this->assertSame(
				\ArchivedPostStatus\Archive\ArchiveAction::Archive,
				$action->invoke( $this->cmd )
			);
		}

		/**
		 * validate() never returns null — Command::execute() is never
		 * reached, so aps_unarchive_post()/aps_archive_post() must never
		 * fire from this command.
		 *
		 * @covers ArchivedPostStatus\CLI\UnscheduleCommand::validate
		 */
		public function test_never_reaches_the_inherited_archive_action_execute_path() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_unschedule_archive' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_archive_post' )->never();
			\WP_Mock::userFunction( 'aps_unarchive_post' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
		}
	}
}
