<?php
/**
 * Per-id loop + WP-CLI output surface for archive/unarchive commands.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use WP_CLI;
use WP_CLI\Utils;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The only class outside CLI.php that touches WP_CLI::*.
 *
 * Owns: the iteration over post ids, progress-bar branching above the
 * count limit, per-post emit() of success/warning, exit-code aggregation,
 * and the terminating exit() call (wrapped in terminate() so tests can
 * subclass-and-override it).
 *
 * @since 0.4.0
 */
class CommandRunner {

	/**
	 * @param int $count_limit Above this number of items, switch to a progress
	 *                         bar instead of per-post emit. Constructor-injected
	 *                         so tests can exercise the branch without feeding
	 *                         21 ids.
	 */
	public function __construct(
		private readonly int $count_limit = 20,
	) {}

	/**
	 * Iterate post ids, dispatch each through $command->run(), emit per-post
	 * results below the count limit (progress bar above it), and exit with
	 * the aggregated status code.
	 *
	 * @param Command                $command    The command to run against each id.
	 * @param array<int, string|int> $args       Post IDs passed positionally.
	 * @param array<string, mixed>   $assoc_args Associative CLI flags.
	 * @return void
	 */
	public function run( Command $command, array $args, array $assoc_args ): void {
		$status   = 0;
		$counting = ( count( $args ) > $this->count_limit );
		$progress = $counting
			? Utils\make_progress_bar( $command->progress_label(), count( $args ) )
			: null;

		foreach ( $args as $obj_id ) {
			$result = $command->run( (int) $obj_id, $assoc_args );

			if ( $counting ) {
				$progress->tick();
				continue;
			}

			$status = $this->emit( $result );
		}

		if ( $counting ) {
			$progress->finish();
		}

		$this->terminate( $status );
	}

	/**
	 * Display success or warning based on the CliResult; return the per-post
	 * exit code.
	 *
	 * The legacy spec called for a `match` expression on `$result->is_success`;
	 * the WP_CLI API used in each branch (`success`/`warning`) returns void,
	 * which is invalid as a match arm. We preserve the clean branching with
	 * an if/else and keep the legacy behavior contract (`warning` lets the
	 * run continue; `error` would terminate the process, which would break
	 * the iteration above).
	 *
	 * @param CliResult $result Outcome returned by Command::run().
	 * @return int Exit status code (0 success, 1 error).
	 */
	private function emit( CliResult $result ): int {
		if ( $result->is_success ) {
			WP_CLI::success( $result->message );
			return 0;
		}

		WP_CLI::warning( $result->message );
		return 1;
	}

	/**
	 * Terminate the CLI process with the given exit code.
	 *
	 * Extracted as `protected` so tests can subclass-and-override and capture
	 * the code without `exit()` actually firing.
	 *
	 * @param int $code Exit status code.
	 * @return void
	 */
	protected function terminate( int $code ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- exit() with integer status code is safe.
		exit( $code );
	}
}
