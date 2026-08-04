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
 * Owns the iteration over post ids, the batch-wide term-counting deferral and
 * post-cache priming, progress-bar branching above the count limit, per-post
 * success/warning output, exit-code aggregation, and the terminating exit().
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
	 * The status accumulates via `max()`, so a batch exits non-zero if any item
	 * failed, regardless of ordering or which output branch ran.
	 *
	 * `--defer-term-counting` brackets the whole loop rather than each item, so
	 * a multi-post batch pays for one recount instead of one per post. The
	 * try/finally re-enables term counting even if an item throws.
	 *
	 * `_prime_post_caches()` runs once before the loop: one query for the batch
	 * instead of an uncached `get_post()` per id from each command's
	 * validation/capability/perform chain.
	 *
	 * @param Command                $command    The command to run against each id.
	 * @param array<int, string|int> $args       Post IDs passed positionally.
	 * @param array<string, mixed>   $assoc_args Associative CLI flags.
	 * @return void
	 */
	public function run( Command $command, array $args, array $assoc_args ): void {
		$status   = 0;
		$post_ids = array_map( 'intval', $args );
		$counting = ( count( $post_ids ) > $this->count_limit );
		$progress = $counting
			? Utils\make_progress_bar( $command->progress_label(), count( $post_ids ) )
			: null;

		if ( $post_ids ) {
			_prime_post_caches( $post_ids );
		}

		$deferred_counts = (bool) Utils\get_flag_value( $assoc_args, 'defer-term-counting' );
		if ( $deferred_counts ) {
			wp_defer_term_counting( true );
		}

		try {
			foreach ( $post_ids as $post_id ) {
				$result = $command->run( $post_id, $assoc_args );

				if ( $counting ) {
					// The progress bar suppresses per-post output but never the
					// exit code — a failure still has to reach the caller.
					$status = max( $status, $result->is_success ? 0 : 1 );
					$progress->tick();
					continue;
				}

				$status = max( $status, $this->emit( $result ) );
			}
		} finally {
			if ( $deferred_counts ) {
				wp_defer_term_counting( false );
			}
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
	 * `warning`, not `error`: the latter terminates the process and would break
	 * the iteration above.
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
	 * `protected` so tests can override it and capture the code without
	 * `exit()` firing.
	 *
	 * @param int $code Exit status code.
	 * @return void
	 */
	protected function terminate( int $code ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- exit() with integer status code is safe.
		exit( $code );
	}
}
