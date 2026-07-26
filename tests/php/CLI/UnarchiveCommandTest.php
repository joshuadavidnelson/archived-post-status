<?php
/**
 * UnarchiveCommand validation/execution tests.
 *
 * Absorbs the unarchive-flow scenarios that previously lived in CLITest
 * under the `test_handle_action_*` names. Each test exercises the pipeline
 * via the public Command::run() entry point — no reflection. No WP_CLI
 * static stub is needed.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\UnarchiveCommand
 * @covers ArchivedPostStatus\CLI\Command
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill (Phase 5 of 0.4.0 cleanup).
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\CLI\CliResult;
	use ArchivedPostStatus\CLI\UnarchiveCommand;

	class UnarchiveCommandTest extends TestCase {

		private UnarchiveCommand $cmd;

		public function set_up() {
			parent::set_up();
			$this->cmd = new UnarchiveCommand();
		}

		/**
		 * Unsupported post types short-circuit with an error before any
		 * status read or capability check.
		 *
		 * @covers ArchivedPostStatus\CLI\UnarchiveCommand::validate
		 */
		public function test_returns_error_for_unsupported_post_type() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );

			$result = $this->cmd->run( 42, array() );

			$this->assertInstanceOf( CliResult::class, $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'not a supported post type', $result->message );
		}

		/**
		 * Unarchiving a post that isn't currently archived returns an error
		 * — the inverse of the archive flow's already-archived guard.
		 *
		 * @covers ArchivedPostStatus\CLI\UnarchiveCommand::validate
		 */
		public function test_returns_error_when_unarchiving_non_archived_post() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'publish' );
			\WP_Mock::userFunction( 'aps_unarchive_post' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'cannot be unarchived', $result->message );
		}

		/**
		 * When the authenticated CLI user lacks the unarchive capability,
		 * the command returns an error CliResult and never dispatches to
		 * aps_unarchive_post().
		 *
		 * @covers ArchivedPostStatus\CLI\UnarchiveCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_returns_error_when_user_lacks_unarchive_capability() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_unarchive' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_unarchive_post' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'capability', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * Anonymous WP-CLI invocations bypass the capability gate on the
		 * unarchive path too. Mirror of the archive bypass test: anonymous
		 * CLI under the elevated-context convention completes the unarchive
		 * without consulting the capability function.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_skips_capability_check_when_no_user_authenticated() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_current_user_can_unarchive' )->never();
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'archive' );
			\WP_Mock::userFunction( 'aps_unarchive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( 'unarchived', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * The progress_label() and action() accessors describe how the
		 * command identifies itself to CommandRunner.
		 *
		 * @covers ArchivedPostStatus\CLI\UnarchiveCommand::progress_label
		 * @covers ArchivedPostStatus\CLI\UnarchiveCommand::action
		 */
		public function test_describes_itself_as_the_unarchive_action_with_unarchiving_label() {
			$this->assertSame( 'Unarchiving', $this->cmd->progress_label() );

			$action = new \ReflectionMethod( $this->cmd, 'action' );
			$action->setAccessible( true );
			$this->assertSame(
				\ArchivedPostStatus\Archive\ArchiveAction::Unarchive,
				$action->invoke( $this->cmd )
			);
		}

		/**
		 * On a successful unarchive, the command returns a success CliResult
		 * whose message contains the past-tense verb from the enum.
		 *
		 * @covers ArchivedPostStatus\CLI\UnarchiveCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_returns_success_result_on_successful_unarchive() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'archive' );
			\WP_Mock::userFunction( 'aps_unarchive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( 'unarchived', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}
	}
}
