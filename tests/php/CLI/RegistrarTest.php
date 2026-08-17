<?php
/**
 * CLI registration surface tests.
 *
 * Only the WP-CLI registration surface: `hooks()` returning the `cli_init`
 * descriptor, `cli()` registering every command, and each thin delegate
 * method forwarding to its command object. Command behavior itself is
 * covered in each command's own test file (ArchiveCommandTest,
 * UnarchiveCommandTest, ScheduleCommandTest, UnscheduleCommandTest,
 * ExplainCommandTest, SettingsCommandTest, QueueCommandTest).
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\Registrar
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\AutoArchive\RuleChain;
	use ArchivedPostStatus\CLI\ArchiveCommand;
	use ArchivedPostStatus\CLI\CommandRunner;
	use ArchivedPostStatus\CLI\ExplainCommand;
	use ArchivedPostStatus\CLI\QueueCommand;
	use ArchivedPostStatus\CLI\Registrar;
	use ArchivedPostStatus\CLI\ScheduleCommand;
	use ArchivedPostStatus\CLI\SettingsCommand;
	use ArchivedPostStatus\CLI\UnarchiveCommand;
	use ArchivedPostStatus\CLI\UnscheduleCommand;
	use ArchivedPostStatus\Hooks\HookDescriptor;
	use ArchivedPostStatus\Schedule\Queue\BatchProcessorInterface;

	/**
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\CLI\Registrar
	 */
	class RegistrarTest extends TestCase {

		private Registrar $cli;
		private ExplainCommand $explain_command;
		private SettingsCommand $settings_command;
		private QueueCommand $queue_command;

		public function set_up() {
			parent::set_up();

			global $aps_test_format_items_calls;
			$aps_test_format_items_calls = array();

			$this->explain_command  = new ExplainCommand( new RuleChain( array() ) );
			$this->settings_command = new SettingsCommand();
			$this->queue_command    = new QueueCommand(
				\Mockery::mock( BatchProcessorInterface::class ),
				\Mockery::mock( BatchProcessorInterface::class )
			);

			$this->cli = new Registrar(
				new CommandRunner(),
				new ArchiveCommand(),
				new UnarchiveCommand(),
				new ScheduleCommand(),
				new UnscheduleCommand(),
				$this->explain_command,
				$this->settings_command,
				$this->queue_command,
			);
			\WP_CLI::reset();
		}

		/**
		 * hooks() declares a single cli_init action descriptor that registers
		 * the plugin's CLI commands lazily — so non-CLI requests never touch
		 * WP_CLI.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::hooks
		 */
		public function test_hooks_returns_cli_init_action_descriptor() {
			$hooks = $this->cli->hooks();

			$this->assertCount( 1, $hooks );
			$this->assertInstanceOf( HookDescriptor::class, $hooks[0] );
			$this->assertTrue( $hooks[0]->is_action() );
			$this->assertSame( 'cli_init', $hooks[0]->hook );
			$this->assertSame( array( $this->cli, 'cli' ), $hooks[0]->callback );
		}

		/**
		 * cli() registers every `wp post ...` / `wp aps ...` command this
		 * plugin exposes, each bound to its own dedicated method — never a
		 * name bound to the wrong callback.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::cli
		 */
		public function test_cli_registers_every_command_with_its_own_callback() {
			$this->cli->cli();

			$names     = array_column( \WP_CLI::$commands, 0 );
			$callbacks = array_column( \WP_CLI::$commands, 1, 0 );

			$expected = array(
				'post archive'            => 'archive',
				'post unarchive'          => 'unarchive',
				'post schedule-archive'   => 'schedule_archive',
				'post unschedule-archive' => 'unschedule_archive',
				'post archive-rule'       => 'archive_rule',
				'aps settings list'       => 'settings_list',
				'aps settings get'        => 'settings_get',
				'aps settings update'     => 'settings_update',
				'aps queue run'           => 'queue_run',
			);

			$this->assertCount( count( $expected ), \WP_CLI::$commands );

			foreach ( $expected as $name => $method ) {
				$this->assertContains( $name, $names );
				$this->assertSame( array( $this->cli, $method ), $callbacks[ $name ] );
			}
		}

		/**
		 * archive() delegates straight to CommandRunner with the injected
		 * ArchiveCommand — this pre-existing method had no direct Registrar-
		 * level test before phase 13 (only ArchiveCommand's own behavior was
		 * covered elsewhere); adding it here alongside the new delegate tests
		 * closes that gap using the same pattern.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::archive
		 */
		public function test_archive_delegates_to_the_command_runner() {
			$runner = new class() extends CommandRunner {
				protected function terminate( int $code ): void {
					// No-op: keep the test process alive.
				}
			};
			$cli = new Registrar(
				$runner,
				new ArchiveCommand(),
				new UnarchiveCommand(),
				new ScheduleCommand(),
				new UnscheduleCommand(),
				$this->explain_command,
				$this->settings_command,
				$this->queue_command,
			);

			\WP_Mock::userFunction( '_prime_post_caches' );
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );

			$cli->archive( array( 42 ), array() );

			$this->assertCount( 1, \WP_CLI::$warnings );
		}

		/**
		 * schedule_archive() delegates straight to CommandRunner with the
		 * injected ScheduleCommand, mirroring archive()/unarchive()'s own
		 * shape. Proven by a real run() call against a stubbed post — the
		 * override-terminate() seam used elsewhere in this suite lets run()
		 * return instead of exiting the test process.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::schedule_archive
		 */
		public function test_schedule_archive_delegates_to_the_command_runner() {
			$runner = new class() extends CommandRunner {
				protected function terminate( int $code ): void {
					// No-op: keep the test process alive.
				}
			};
			$cli = new Registrar(
				$runner,
				new ArchiveCommand(),
				new UnarchiveCommand(),
				new ScheduleCommand(),
				new UnscheduleCommand(),
				$this->explain_command,
				$this->settings_command,
				$this->queue_command,
			);

			\WP_Mock::userFunction( '_prime_post_caches' );
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );

			$cli->schedule_archive( array( 42 ), array( 'at' => '2027-03-03 14:30:00' ) );

			$this->assertCount( 1, \WP_CLI::$warnings, 'the unsupported-post-type failure must reach the runner and be reported' );
		}

		/**
		 * unschedule_archive() delegates straight to CommandRunner with the
		 * injected UnscheduleCommand.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::unschedule_archive
		 */
		public function test_unschedule_archive_delegates_to_the_command_runner() {
			$runner = new class() extends CommandRunner {
				protected function terminate( int $code ): void {
					// No-op: keep the test process alive.
				}
			};
			$cli = new Registrar(
				$runner,
				new ArchiveCommand(),
				new UnarchiveCommand(),
				new ScheduleCommand(),
				new UnscheduleCommand(),
				$this->explain_command,
				$this->settings_command,
				$this->queue_command,
			);

			\WP_Mock::userFunction( '_prime_post_caches' );
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );

			$cli->unschedule_archive( array( 42 ), array() );

			$this->assertCount( 1, \WP_CLI::$warnings );
		}

		/**
		 * archive_rule() delegates to ExplainCommand::explain() with the
		 * same args/assoc_args it received.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::archive_rule
		 */
		public function test_archive_rule_delegates_to_explain_command() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );
			\WP_Mock::userFunction( 'absint' )->with( 42 )->andReturn( 42 );

			$this->cli->archive_rule( array( 42 ), array() );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( '42', \WP_CLI::$errors[0] );
		}

		/**
		 * settings_list()/settings_get()/settings_update() each delegate to
		 * their SettingsCommand counterpart method.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::settings_list
		 * @covers ArchivedPostStatus\CLI\Registrar::settings_get
		 * @covers ArchivedPostStatus\CLI\Registrar::settings_update
		 */
		public function test_settings_subcommands_delegate_to_settings_command() {
			\WP_Mock::userFunction( 'get_option' )->andReturn( array() );

			$this->cli->settings_list( array(), array() );
			global $aps_test_format_items_calls;
			$this->assertNotEmpty( $aps_test_format_items_calls );

			$this->cli->settings_get( array( 'not_a_real_key' ), array() );
			$this->assertCount( 1, \WP_CLI::$errors );

			\WP_CLI::reset();
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			$this->cli->settings_update( array( 'not_a_real_key', 'x' ), array() );
			$this->assertCount( 1, \WP_CLI::$errors );
		}

		/**
		 * queue_run() delegates to QueueCommand::run() with the same
		 * args/assoc_args it received.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::queue_run
		 */
		public function test_queue_run_delegates_to_queue_command() {
			$this->cli->queue_run( array( 'nonsense' ), array() );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'Unknown queue', \WP_CLI::$errors[0] );
		}

		/**
		 * unarchive() with --status brackets the `aps_unarchive_post_status`
		 * filter around the command run, mirroring
		 * {@see \ArchivedPostStatus\Admin\BulkActionHandler::bulk_unarchive()}'s
		 * add_filter/remove_filter pairing, so the override doesn't leak
		 * into a later unarchive call in the same PHP process. Uses the
		 * same `CommandRunner::terminate()` override seam as
		 * {@see test_unarchive_does_not_touch_the_status_filter_when_status_flag_is_absent()}
		 * to let `run()` return normally instead of exiting the test
		 * process, so the `remove_filter()` call after it is observable.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::unarchive
		 */
		public function test_unarchive_removes_the_status_filter_after_the_command_runs() {
			$runner = new class() extends CommandRunner {
				protected function terminate( int $code ): void {
					// No-op: keep the test process alive.
				}
			};
			$cli = new Registrar(
				$runner,
				new ArchiveCommand(),
				new UnarchiveCommand(),
				new ScheduleCommand(),
				new UnscheduleCommand(),
				$this->explain_command,
				$this->settings_command,
				$this->queue_command,
			);

			$removed_hook     = null;
			$removed_callback = null;
			$removed_priority = null;

			\WP_Mock::userFunction( 'remove_filter' )
				->once()
				->andReturnUsing(
					static function ( $hook, $callback, $priority = 10 ) use ( &$removed_hook, &$removed_callback, &$removed_priority ) {
						$removed_hook     = $hook;
						$removed_callback = $callback;
						$removed_priority = $priority;
						return true;
					}
				);

			$cli->unarchive( array(), array( 'status' => 'draft' ) );

			// add_filter() is one of the handful of functions WP_Mock always
			// routes through its own hook-simulation layer rather than a
			// userFunction() double, so the closure it received is read back
			// from that layer's cached HookedCallback object instead.
			$hooked_callback    = \WP_Mock::onFilterAdded( 'aps_unarchive_post_status' );
			$callback_property  = new \ReflectionProperty( $hooked_callback, 'callback' );
			$callback_property->setAccessible( true );
			$added_callback = $callback_property->getValue( $hooked_callback );

			$this->assertSame( 'aps_unarchive_post_status', $removed_hook );
			$this->assertSame( PHP_INT_MAX, $removed_priority );
			$this->assertSame(
				$added_callback,
				$removed_callback,
				'remove_filter must target the exact override closure add_filter registered, or WordPress will not unhook it'
			);
			$this->assertSame(
				'draft',
				$removed_callback(),
				'the removed closure must resolve to the --status override value'
			);
		}

		/**
		 * Without --status, unarchive() must not touch the filter at all —
		 * the per-post default status resolution stays in effect.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::unarchive
		 */
		public function test_unarchive_does_not_touch_the_status_filter_when_status_flag_is_absent() {
			$runner = new class() extends CommandRunner {
				protected function terminate( int $code ): void {
					// No-op: keep the test process alive.
				}
			};
			$cli = new Registrar(
				$runner,
				new ArchiveCommand(),
				new UnarchiveCommand(),
				new ScheduleCommand(),
				new UnscheduleCommand(),
				$this->explain_command,
				$this->settings_command,
				$this->queue_command,
			);

			\WP_Mock::userFunction( 'remove_filter' )->never();

			$cli->unarchive( array(), array() );

			$this->addToAssertionCount( 1 );
		}
	}
}
