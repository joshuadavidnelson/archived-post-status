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
 * Touches WP_CLI::success()/::warning() to report per-post outcomes.
 * {@see \ArchivedPostStatus\CLI\Registrar} is the other class that touches
 * WP_CLI::* — it calls WP_CLI::add_command() to register the commands this
 * runner executes.
 *
 * Owns: the iteration over post ids, the batch-wide term-counting deferral
 * and post-cache priming, progress-bar branching above the count limit,
 * per-post emit() of success/warning, exit-code aggregation, and the
 * terminating exit() call (wrapped in terminate() so tests can
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
	 * The status is accumulated across every item (`max()`), so a batch
	 * exits non-zero if ANY item failed — independent of ordering and of
	 * which output branch ran.
	 *
	 * `--defer-term-counting` brackets the WHOLE loop, not each item: term
	 * recounting is deferred once before the batch and flushed once after,
	 * so a multi-post `wp post archive 1 2 3 --defer-term-counting` pays for
	 * one recount instead of one per post. Previously this bracket lived
	 * inside {@see Command::execute()}, wrapping a single perform() call —
	 * called once per loop iteration here, that delivered none of the
	 * advertised batching benefit. The try/finally guarantees term counting
	 * is re-enabled even if an item throws, mirroring the invariant
	 * {@see Command::execute()} used to document for its own (now removed)
	 * per-item bracket.
	 *
	 * Post ids are also primed into the post cache once, before the loop,
	 * via `_prime_post_caches()` — a single query for the whole batch
	 * instead of the one-uncached-`get_post()`-per-id that each command's
	 * validation/capability/perform() chain would otherwise trigger.
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
					// The progress bar suppresses per-post output above the
					// limit, but never the exit code: a failure anywhere in
					// the batch still has to reach the caller.
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
