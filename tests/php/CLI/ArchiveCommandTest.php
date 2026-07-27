<?php
/**
 * ArchiveCommand validation/execution tests.
 *
 * Absorbs the archive-flow scenarios that previously lived in CLITest under
 * the `test_handle_action_*` names. Each test exercises the pipeline via the
 * public Command::run() entry point — no reflection. No WP_CLI static stub
 * is needed (those concerns live in CommandRunnerTest).
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\ArchiveCommand
 * @covers ArchivedPostStatus\CLI\Command
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill
	// (shared across the CLI suites).
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\CLI\ArchiveCommand;
	use ArchivedPostStatus\CLI\CliResult;

	class ArchiveCommandTest extends TestCase {

		private ArchiveCommand $cmd;

		public function set_up() {
			parent::set_up();
			$this->cmd = new ArchiveCommand();
		}

		/**
		 * Unsupported post types short-circuit with an error before any
		 * status read or capability check.
		 *
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::validate
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
		 * Archiving a post whose status is already 'archive' short-circuits
		 * before any wp_update_post() call. Validation order also pins that
		 * this fires before the capability check.
		 *
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::validate
		 */
		public function test_returns_error_when_archiving_already_archived_post() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'archive' );
			\WP_Mock::userFunction( 'aps_archive_post' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'already archived', $result->message );
		}

		/**
		 * Archive validation rejects posts whose current status is not in
		 * ArchivableStatuses::all(). The default archivable set excludes
		 * 'draft', so attempting to archive a draft must error.
		 *
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::validate
		 */
		public function test_rejects_unarchivable_status_without_force() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'draft' );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => (string) $v );
			\WP_Mock::onFilter( 'aps_archivable_statuses' )
				->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
				->reply( array( 'publish' ) );
			\WP_Mock::userFunction( 'aps_archive_post' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( "'draft' is not an archivable status", $result->message );
		}

		/**
		 * The --force flag bypasses the archivable-status check, so a draft
		 * post can still be archived. The action layer is reached and a
		 * success CliResult is returned.
		 *
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::validate
		 */
		public function test_with_force_flag_archives_unarchivable_status() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'draft' );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			$result = $this->cmd->run( 42, array( 'force' => true ) );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * On a successful archive, the command returns a success CliResult
		 * whose message contains the past-tense verb from the enum.
		 *
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_returns_success_result_on_successful_archive() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'publish' );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => (string) $v );
			\WP_Mock::onFilter( 'aps_archivable_statuses' )
				->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
				->reply( array( 'publish' ) );
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( 'archived', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * When the underlying perform() returns false, the command surfaces
		 * an error CliResult ("Failed to ...") instead of pretending the
		 * action succeeded.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_returns_error_when_perform_returns_falsy() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'publish' );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $v ) => (string) $v );
			\WP_Mock::onFilter( 'aps_archivable_statuses' )
				->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
				->reply( array( 'publish' ) );
			\WP_Mock::userFunction( 'aps_archive_post' )->andReturn( false );

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'Failed to archive', $result->message );
		}

		/**
		 * When the authenticated CLI user lacks the archive capability, the
		 * command returns an error CliResult and never dispatches to
		 * aps_archive_post(). Authenticated invocations consult the capability
		 * filter; the validation-order contract requires capability to fire
		 * after the already-archived check but before status validation, so
		 * this test runs against a publish-status post.
		 *
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::validate
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_returns_error_when_user_lacks_archive_capability() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'publish' );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );
			\WP_Mock::userFunction( 'aps_archive_post' )->never();

			$result = $this->cmd->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'capability', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * The progress_label() and action() accessors describe how the
		 * command identifies itself to CommandRunner. Pin both so a future
		 * label-typo or enum mix-up is caught immediately.
		 *
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::progress_label
		 * @covers ArchivedPostStatus\CLI\ArchiveCommand::action
		 */
		public function test_describes_itself_as_the_archive_action_with_archiving_label() {
			$this->assertSame( 'Archiving', $this->cmd->progress_label() );

			$action = new \ReflectionMethod( $this->cmd, 'action' );
			$action->setAccessible( true );
			$this->assertSame(
				\ArchivedPostStatus\Archive\ArchiveAction::Archive,
				$action->invoke( $this->cmd )
			);
		}

		/**
		 * Anonymous WP-CLI invocations (no `--user`, so `get_current_user_id()`
		 * is 0 and `is_user_logged_in()` is false) MUST bypass the capability
		 * gate. This pins the documented WP-CLI elevated-context convention
		 * — `aps_current_user_can_archive` is never consulted, and the
		 * archive completes successfully.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_skips_capability_check_when_no_user_authenticated() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'publish' );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( 'archived', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}
	}
}
