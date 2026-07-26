<?php
/**
 * CLI registration surface tests.
 *
 * After the Phase 3 refactor, behavior lives in src/CLI/* (Command,
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
	// Shared WP_CLI in-memory stub + get_flag_value polyfill (Phase 5 of 0.4.0 cleanup).
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
		 * the plugin exposes.
		 *
		 * @covers ArchivedPostStatus\CLI\Registrar::cli
		 */
		public function test_cli_registers_archive_and_unarchive_commands() {
			$this->cli->cli();

			$names = array_column( \WP_CLI::$commands, 0 );

			$this->assertContains( 'post archive', $names );
			$this->assertContains( 'post unarchive', $names );
			$this->assertCount( 2, \WP_CLI::$commands );
		}
	}
}
