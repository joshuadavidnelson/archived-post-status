<?php
/**
 * Abstract base for WP-CLI archive/unarchive commands.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Archive\ArchiveAction;
use WP_CLI\Utils;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Validate-then-execute template for WP-CLI commands.
 *
 * Each concrete subclass declares which ArchiveAction it performs and any
 * command-specific validation. The base owns the shared primitives
 * (capability gate, post-type gate, defer-term-counting + perform()), and
 * the run()/execute() invariants are `final` so subclasses cannot break
 * the template's contract.
 *
 * @since 0.4.0
 */
abstract class Command {

	/**
	 * The ArchiveAction this command performs.
	 *
	 * @return ArchiveAction
	 */
	abstract protected function action(): ArchiveAction;

	/**
	 * Human-readable label used by the progress bar.
	 *
	 * @return string
	 */
	abstract public function progress_label(): string;

	/**
	 * Command-specific validation. Return `null` to pass, or a CliResult
	 * describing the failure.
	 *
	 * @param int                  $post_id    The post ID to validate.
	 * @param array<string, mixed> $assoc_args Associative CLI flags.
	 * @return CliResult|null Error result or null if valid.
	 */
	abstract protected function validate( int $post_id, array $assoc_args ): ?CliResult;

	/**
	 * Run the command against a single post id: validate, then execute.
	 *
	 * Subclasses cannot override the validate-then-execute invariant; sibling
	 * commands are the extension point.
	 *
	 * @param int                  $post_id    The post ID to process.
	 * @param array<string, mixed> $assoc_args Associative CLI flags.
	 * @return CliResult
	 */
	final public function run( int $post_id, array $assoc_args ): CliResult {
		$validation_error = $this->validate( $post_id, $assoc_args );
		if ( $validation_error instanceof CliResult ) {
			return $validation_error;
		}

		return $this->execute( $post_id, $assoc_args );
	}

	/**
	 * Execute the action via the enum, wrapping with wp_defer_term_counting()
	 * when the flag is set. Returns a success or error CliResult shaped from
	 * the enum's past-tense verb.
	 *
	 * Invariant: when --defer-term-counting was set, term counting MUST be
	 * re-enabled on every exit path — including perform() returning false and
	 * perform() throwing. A try/finally guards both paths; leaving term
	 * counting deferred across a batch could leak the global state into
	 * unrelated WordPress operations after the CLI run.
	 *
	 * @param int                  $post_id    The post ID to process.
	 * @param array<string, mixed> $assoc_args Associative CLI flags.
	 * @return CliResult
	 */
	final protected function execute( int $post_id, array $assoc_args ): CliResult {
		$action          = $this->action();
		$deferred_counts = (bool) Utils\get_flag_value( $assoc_args, 'defer-term-counting' );

		if ( $deferred_counts ) {
			wp_defer_term_counting( true );
		}

		try {
			$result = $action->perform( $post_id );
			if ( ! $result ) {
				return new CliResult( false, "Failed to {$action->value} post {$post_id}." );
			}

			return new CliResult( true, "{$action->past_tense()} post {$post_id}." );
		} finally {
			if ( $deferred_counts ) {
				wp_defer_term_counting( false );
			}
		}
	}

	/**
	 * Enforce the capability filter on the user-facing CLI surface so that
	 * `aps_default_archive_capability` / `aps_default_unarchive_capability`
	 * are respected for `wp --user=<id> post archive|unarchive`. The
	 * template functions in src/functions/functions.php intentionally do not enforce
	 * this so they remain usable from privileged contexts (e.g. cron).
	 *
	 * The gate only fires when the CLI runs as an authenticated user
	 * (typically via `--user=...`). Anonymous CLI (user 0) bypasses the
	 * check, matching the convention of core `wp post update|delete|create`
	 * — WP-CLI's default elevated server context is intentionally
	 * privileged.
	 *
	 * @param int $post_id The post ID being acted on.
	 * @return CliResult|null Error result or null if the user is permitted.
	 */
	final protected function capability_check( int $post_id ): ?CliResult {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$action              = $this->action();
		$capability_function = $action->capability_function();
		if ( ! $capability_function( $post_id ) ) {
			return new CliResult(
				false,
				"User does not have capability to {$action->value} post {$post_id}."
			);
		}

		return null;
	}

	/**
	 * Pre-flight: reject posts whose post type is not registered for archive
	 * support. Mirrors aps_is_supported_post_type() guard rails used across
	 * the rest of the codebase.
	 *
	 * @param int $post_id The post ID to check.
	 * @return CliResult|null Error result or null if the type is supported.
	 */
	final protected function ensure_supported_post_type( int $post_id ): ?CliResult {
		$post_type = get_post_type( $post_id );
		if ( ! aps_is_supported_post_type( $post_type ) ) {
			return new CliResult( false, "Post {$post_id} is not a supported post type." );
		}

		return null;
	}

	/**
	 * Reject a post that is currently locked for editing by another user.
	 *
	 * Mirrors the admin bulk-action path — {@see
	 * \ArchivedPostStatus\Admin\BulkActionHandler::process_archive_post()}
	 * and {@see \ArchivedPostStatus\Admin\BulkActionHandler::process_unarchive_post()}
	 * both bucket a locked post as a skip reason rather than acting on it —
	 * so the CLI and admin surfaces treat a lock the same way on both
	 * directions. This is a deliberate departure from core's own
	 * `wp post update` convention, which does not check post locks at all;
	 * the maintainer's choice is to match this plugin's existing admin
	 * behavior instead, since the admin paths already treat a lock as a
	 * skip reason.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to check.
	 * @return CliResult|null Error result, or null if the post is not locked.
	 */
	final protected function ensure_not_locked( int $post_id ): ?CliResult {
		if ( wp_check_post_lock( $post_id ) ) {
			return new CliResult( false, "Post {$post_id} is locked for editing." );
		}

		return null;
	}
}
