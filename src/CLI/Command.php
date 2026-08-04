<?php
/**
 * Abstract base for WP-CLI archive/unarchive commands.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Archive\ArchiveAction;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Validate-then-execute template for WP-CLI commands.
 *
 * Each subclass declares which ArchiveAction it performs and any
 * command-specific validation; the base owns the shared gates.
 * `--defer-term-counting` is handled one level up in
 * {@see CommandRunner::run()}, which brackets the whole batch.
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
	 * @param int                  $post_id    The post ID to process.
	 * @param array<string, mixed> $assoc_args Associative CLI flags.
	 * @return CliResult
	 */
	final public function run( int $post_id, array $assoc_args ): CliResult {
		$validation_error = $this->validate( $post_id, $assoc_args );
		if ( $validation_error instanceof CliResult ) {
			return $validation_error;
		}

		return $this->execute( $post_id );
	}

	/**
	 * Execute the action via the enum. Returns a success or error CliResult
	 * shaped from the enum's past-tense verb.
	 *
	 * @param int $post_id The post ID to process.
	 * @return CliResult
	 */
	final protected function execute( int $post_id ): CliResult {
		$action = $this->action();
		$result = $action->perform( $post_id );
		if ( ! $result ) {
			return new CliResult( false, "Failed to {$action->value} post {$post_id}." );
		}

		return new CliResult( true, "{$action->past_tense()} post {$post_id}." );
	}

	/**
	 * Enforce the capability filters for `wp --user=<id> post archive|unarchive`.
	 * The `aps_*` functions deliberately do not enforce them, so they stay
	 * usable from privileged contexts like cron.
	 *
	 * Anonymous CLI (user 0) bypasses the check, matching core `wp post
	 * update|delete|create` — WP-CLI's default server context is privileged
	 * by design.
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
	 * support.
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
	 * Deliberately stricter than core's `wp post update`, which does not check
	 * post locks at all. Matching this plugin's admin bulk-action paths, which
	 * already skip locked posts, was preferred over matching core.
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
