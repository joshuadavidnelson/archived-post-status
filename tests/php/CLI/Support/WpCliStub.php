<?php
/**
 * Shared WP_CLI in-memory stub for CLI tests.
 *
 * Five CLI test files (ArchiveCommandTest, CommandRunnerTest, CommandTest,
 * RegistrarTest, UnarchiveCommandTest) previously each carried a
 * conditionally-defined `class WP_CLI` plus a `namespace WP_CLI\Utils {}`
 * block with `get_flag_value()` and (in CommandRunnerTest) `make_progress_bar()`.
 * the 0.4.0 refactor centralized them here so the duplication
 * (~140 LOC across 5 files) lives in one place.
 *
 * Loaded by each CLI test via `require_once __DIR__ . '/Support/WpCliStub.php';`
 * at the top of the file, *before* the test class declaration. Each stub is
 * defined conditionally (`! class_exists` / `! function_exists`) so the file
 * is safe to require from multiple test entry points in the same PHP
 * process — PHPUnit loads every test class up front, and the `WP_CLI`
 * symbols only need to exist once across the run.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace {
	/**
	 * In-memory WP_CLI stub: records every success/warning/add_command call
	 * so the runner / registrar / command tests can assert which messages or
	 * registrations occurred. Conditionally defined so it does not collide
	 * with a real WP_CLI class that might already be loaded by some other
	 * environment (or by an earlier-loaded copy of this same file).
	 */
	if ( ! class_exists( '\WP_CLI' ) ) {
		class WP_CLI {
			/** @var array<int, array{0:string, 1:mixed}> */
			public static array $commands  = array();
			/** @var array<int, string> */
			public static array $successes = array();
			/** @var array<int, string> */
			public static array $warnings  = array();

			public static function add_command( $name, $callable, $args = array() ) {
				self::$commands[] = array( $name, $callable );
			}

			public static function success( $message ) {
				self::$successes[] = $message;
			}

			public static function warning( $message ) {
				self::$warnings[] = $message;
			}

			public static function reset() {
				self::$commands  = array();
				self::$successes = array();
				self::$warnings  = array();
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\get_flag_value' ) ) {
		/**
		 * Polyfill for `WP_CLI\Utils\get_flag_value()` — the real function
		 * lives in wp-cli/wp-cli and is not loaded during the unit-test
		 * pass. The body mirrors the real one's "return assoc_args[flag]
		 * with default fallback" contract.
		 */
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}
	}

	if ( ! function_exists( 'WP_CLI\\Utils\\make_progress_bar' ) ) {
		/**
		 * Polyfill for `WP_CLI\Utils\make_progress_bar()`. Records the
		 * call's arguments into the `$aps_test_progress_bar_calls` global
		 * and returns an anonymous progress-bar double whose `tick()` and
		 * `finish()` methods record their own invocations so a test can
		 * assert iteration count and finalization.
		 *
		 * Only CommandRunnerTest exercises the progress-bar branch; this
		 * polyfill is the shared seam.
		 */
		function make_progress_bar( $message, $count ) {
			global $aps_test_progress_bar_calls;
			$aps_test_progress_bar_calls[] = array( $message, $count );

			return new class() {
				public int  $ticks    = 0;
				public bool $finished = false;

				public function tick(): void {
					++$this->ticks;
				}

				public function finish(): void {
					$this->finished = true;
				}
			};
		}
	}
}
