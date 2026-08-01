<?php
/**
 * CLI registration surface tests.
 *
 * Behavior lives in src/CLI/* (Command,
 * ArchiveCommand, UnarchiveCommand, CommandRunner). This file only tests
 * the WP-CLI registration surface that stays in src/CLI/Registrar.php —
 * `hooks()` returning the `cli_init` descriptor and `cli()` registering
 * the two commands with WP_CLI::add_command.
 *
 * The behavior tests that previously lived here (the eight
 * `test_handle_action_*` methods, reached via `\ReflectionMethod`) have
 * moved to tests/php/CLI/ArchiveCommandTest.php and
 * tests/php/CLI/UnarchiveCommandTest.php as direct calls into
 * `Command::run()`.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\Registrar
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value polyfill.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\CLI\ArchiveCommand;
	use ArchivedPostStatus\CLI\CommandRunner;
	use ArchivedPostStatus\CLI\Registrar;
	use ArchivedPostStatus\CLI\UnarchiveCommand;
	use ArchivedPostStatus\Hooks\HookDescriptor;

	/**
	 * @since 0.4.0
	 * @covers ArchivedPostStatus\CLI\Registrar
	 */
	class RegistrarTest extends TestCase {

		private Registrar $cli;

		public function set_up() {
			parent::set_up();
			$this->cli = new Registrar(
				new CommandRunner(),
				new ArchiveCommand(),
				new UnarchiveCommand(),
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
		 * cli() registers `wp post archive` and `wp post unarchive` with
		 * WP_CLI::add_command(). These are the only two public CLI surfaces
		 * the plugin exposes, and each name must bind to its own method —
		 * `post archive` to archive(), `post unarchive` to unarchive() —
		 * not the other way around.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::cli
		 */
		public function test_cli_registers_archive_and_unarchive_commands() {
			$this->cli->cli();

			$names     = array_column( \WP_CLI::$commands, 0 );
			$callbacks = array_column( \WP_CLI::$commands, 1, 0 );

			$this->assertContains( 'post archive', $names );
			$this->assertContains( 'post unarchive', $names );
			$this->assertCount( 2, \WP_CLI::$commands );
			$this->assertSame( array( $this->cli, 'archive' ), $callbacks['post archive'] );
			$this->assertSame( array( $this->cli, 'unarchive' ), $callbacks['post unarchive'] );
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
			$cli = new Registrar( $runner, new ArchiveCommand(), new UnarchiveCommand() );

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
			$this->assertSame( 10, $removed_priority );
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
			$cli = new Registrar( $runner, new ArchiveCommand(), new UnarchiveCommand() );

			\WP_Mock::userFunction( 'remove_filter' )->never();

			$cli->unarchive( array(), array() );

			$this->addToAssertionCount( 1 );
		}
	}
}
