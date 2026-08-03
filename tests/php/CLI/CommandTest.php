<?php
/**
 * Tests for the Command abstract base.
 *
 * Exercises the base-class primitives (capability_check, ensure_supported_post_type,
 * execute) via a tiny fake subclass. The concrete Archive/Unarchive command tests
 * focus on each command's specific validation pipeline; this file pins the shared
 * machinery.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\Command
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\Archive\ArchiveAction;
	use ArchivedPostStatus\CLI\CliResult;
	use ArchivedPostStatus\CLI\Command;

	if ( ! class_exists( 'FakeCommand' ) ) {
		/**
		 * Minimal Command subclass exposing the protected primitives so the
		 * test can drive them directly. Validation is a pure pass-through;
		 * the action it carries can be flipped between Archive and Unarchive
		 * at the call site.
		 */
		// phpcs:disable
		final class FakeCommand extends Command {
			public ArchiveAction $forced_action = ArchiveAction::Archive;

			protected function action(): ArchiveAction {
				return $this->forced_action;
			}

			public function progress_label(): string {
				return 'Fake';
			}

			protected function validate( int $post_id, array $assoc_args ): ?CliResult {
				return null;
			}

			public function call_capability_check( int $post_id ): ?CliResult {
				return $this->capability_check( $post_id );
			}

			public function call_ensure_supported_post_type( int $post_id ): ?CliResult {
				return $this->ensure_supported_post_type( $post_id );
			}

			public function call_ensure_not_locked( int $post_id ): ?CliResult {
				return $this->ensure_not_locked( $post_id );
			}

			public function call_execute( int $post_id, array $assoc_args ): CliResult {
				return $this->execute( $post_id, $assoc_args );
			}
		}
		// phpcs:enable
	}

	/**
	 * Behavior contract for the Command abstract base.
	 *
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\CLI\Command
	 */
	class CommandTest extends TestCase {

		private FakeCommand $cmd;

		public function set_up() {
			parent::set_up();
			$this->cmd = new FakeCommand();
		}

		/**
		 * Anonymous CLI (no `--user`) bypasses the capability gate. This
		 * preserves the documented WP-CLI elevated-context convention.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_capability_check_bypasses_when_user_not_logged_in() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

			$this->assertNull( $this->cmd->call_capability_check( 42 ) );
		}

		/**
		 * An authenticated CLI user who passes the capability function
		 * proceeds (the gate returns null).
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_capability_check_returns_null_when_user_has_capability() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );

			$this->assertNull( $this->cmd->call_capability_check( 42 ) );
		}

		/**
		 * Authenticated CLI user who fails the capability function gets an
		 * error CliResult naming the action and the post id.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_capability_check_returns_error_when_user_lacks_capability() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );

			$result = $this->cmd->call_capability_check( 42 );

			$this->assertInstanceOf( CliResult::class, $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'capability', $result->message );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'archive', $result->message );
		}

		/**
		 * Capability check uses the action enum's capability_function() so
		 * the unarchive code path calls aps_current_user_can_unarchive().
		 *
		 * @covers ArchivedPostStatus\CLI\Command::capability_check
		 */
		public function test_capability_check_uses_action_specific_capability_function_for_unarchive() {
			$this->cmd->forced_action = ArchiveAction::Unarchive;

			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_unarchive' )->with( 42 )->andReturn( false );

			$result = $this->cmd->call_capability_check( 42 );

			$this->assertNotNull( $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'unarchive', $result->message );
		}

		/**
		 * Supported post types pass through ensure_supported_post_type with
		 * a null return.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::ensure_supported_post_type
		 */
		public function test_ensure_supported_post_type_returns_null_for_supported_type() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( true );

			$this->assertNull( $this->cmd->call_ensure_supported_post_type( 42 ) );
		}

		/**
		 * Unsupported post types return an error CliResult naming the post id.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::ensure_supported_post_type
		 */
		public function test_ensure_supported_post_type_returns_error_for_unsupported_type() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );

			$result = $this->cmd->call_ensure_supported_post_type( 42 );

			$this->assertInstanceOf( CliResult::class, $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'not a supported post type', $result->message );
		}

		/**
		 * §2.6: an unlocked post passes the shared lock-check primitive with
		 * a null return, mirroring capability_check() / ensure_supported_post_type()'s
		 * pass-through shape.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::ensure_not_locked
		 */
		public function test_ensure_not_locked_returns_null_when_post_is_not_locked() {
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( false );

			$this->assertNull( $this->cmd->call_ensure_not_locked( 42 ) );
		}

		/**
		 * §2.6: a post locked by another user returns an error CliResult
		 * naming the post id, so both ArchiveCommand and UnarchiveCommand
		 * get identical lock-rejection behavior from the shared base.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::ensure_not_locked
		 */
		public function test_ensure_not_locked_returns_error_when_post_is_locked() {
			\WP_Mock::userFunction( 'wp_check_post_lock' )->with( 42 )->andReturn( 7 );

			$result = $this->cmd->call_ensure_not_locked( 42 );

			$this->assertInstanceOf( CliResult::class, $result );
			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( '42', $result->message );
			$this->assertStringContainsString( 'locked', $result->message );
		}

		/**
		 * execute() invokes the enum perform() and returns a success CliResult
		 * with the past-tense verb.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_execute_returns_success_when_perform_succeeds() {
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			$result = $this->cmd->call_execute( 42, array() );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( 'archived', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * When perform() returns false (the documented contract for "this
		 * particular post couldn't be archived"), execute() returns an
		 * error CliResult.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_execute_returns_error_when_perform_returns_false() {
			\WP_Mock::userFunction( 'aps_archive_post' )->with( 42 )->andReturn( false );

			$result = $this->cmd->call_execute( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'Failed to archive', $result->message );
			$this->assertStringContainsString( '42', $result->message );
		}

		/**
		 * run() is the validate-then-execute template entry point. When
		 * validate() returns null (the FakeCommand default), run() falls
		 * through to execute() and a success CliResult comes back. The
		 * `covers` annotation below pins the base-class method's coverage.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::run
		 */
		public function test_run_passes_through_to_execute_when_validate_returns_null() {
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			$result = $this->cmd->run( 42, array() );

			$this->assertTrue( $result->is_success );
			$this->assertStringContainsString( 'archived', $result->message );
		}

		/**
		 * When validate() returns a CliResult, run() short-circuits — the
		 * action's perform() is never invoked. FakeCommand's default
		 * validate() returns null, so this test uses a one-off subclass
		 * whose validate() returns a pre-baked error.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::run
		 */
		public function test_run_returns_validation_error_without_invoking_execute() {
			\WP_Mock::userFunction( 'aps_archive_post' )->never();

			$short_circuit = new class() extends \ArchivedPostStatus\CLI\Command {
				protected function action(): \ArchivedPostStatus\Archive\ArchiveAction {
					return \ArchivedPostStatus\Archive\ArchiveAction::Archive;
				}

				public function progress_label(): string {
					return 'Fake';
				}

				protected function validate( int $post_id, array $assoc_args ): ?CliResult {
					return new CliResult( false, "validation said no for {$post_id}" );
				}
			};

			$result = $short_circuit->run( 42, array() );

			$this->assertFalse( $result->is_success );
			$this->assertSame( 'validation said no for 42', $result->message );
		}

		/**
		 * The --defer-term-counting flag toggles wp_defer_term_counting()
		 * on (before perform()) and back off (after success). Captures both
		 * calls to pin the contract.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_execute_toggles_defer_term_counting_when_flag_set() {
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( true )
				->ordered();
			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( false )
				->ordered();

			$result = $this->cmd->call_execute( 42, array( 'defer-term-counting' => true ) );

			$this->assertTrue( $result->is_success );
		}

		/**
		 * Failure-path contract: when perform() returns false (e.g. the post
		 * couldn't be archived), the --defer-term-counting flag must still
		 * re-enable term counting before returning. Pre-Phase-2 the early
		 * return on `false` left the global deferred, leaking state into
		 * unrelated WordPress operations downstream.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_defer_term_counting_is_reenabled_when_perform_returns_false() {
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( false );

			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( true );
			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( false );

			$result = $this->cmd->call_execute( 42, array( 'defer-term-counting' => true ) );

			$this->assertFalse( $result->is_success );
			$this->assertStringContainsString( 'Failed to archive', $result->message );
		}

		/**
		 * Failure-path contract: when perform() throws, the try/finally must
		 * still re-enable term counting. The exception then propagates so
		 * the CLI runner can surface the error.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_defer_term_counting_is_reenabled_when_perform_throws() {
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturnUsing(
					static function () {
						throw new \RuntimeException( 'simulated failure inside perform()' );
					}
				);

			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( true );
			\WP_Mock::userFunction( 'wp_defer_term_counting' )
				->once()
				->with( false );

			$caught = null;
			try {
				$this->cmd->call_execute( 42, array( 'defer-term-counting' => true ) );
			} catch ( \RuntimeException $e ) {
				$caught = $e;
			}

			$this->assertNotNull( $caught, 'Exception thrown from perform() must propagate' );
			$this->assertSame( 'simulated failure inside perform()', $caught->getMessage() );
			// WP_Mock verifies that wp_defer_term_counting(false) fired on
			// the way out of the finally block.
		}

		/**
		 * When the --defer-term-counting flag is NOT set, neither toggle
		 * fires. Guards against accidentally calling wp_defer_term_counting
		 * unconditionally in the try/finally refactor.
		 *
		 * @covers ArchivedPostStatus\CLI\Command::execute
		 */
		public function test_execute_does_not_toggle_defer_term_counting_when_flag_absent() {
			\WP_Mock::userFunction( 'aps_archive_post' )
				->once()
				->with( 42 )
				->andReturn( new \WP_Post( array( 'ID' => 42 ) ) );

			\WP_Mock::userFunction( 'wp_defer_term_counting' )->never();

			$result = $this->cmd->call_execute( 42, array() );

			$this->assertTrue( $result->is_success );
		}
	}
}
