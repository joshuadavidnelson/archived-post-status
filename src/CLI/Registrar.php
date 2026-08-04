<?php
/**
 * CLI commands for managing archived post status.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * WP-CLI adapter: registers the archive/unarchive commands and delegates each
 * invocation to the injected runner.
 *
 * @since 0.4.0
 */
final class Registrar implements HookableInterface {

	public function __construct(
		private readonly CommandRunner $runner,
		private readonly ArchiveCommand $archive_command,
		private readonly UnarchiveCommand $unarchive_command,
	) {}

	/**
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array( HookDescriptor::action( 'cli_init', array( $this, 'cli' ) ) );
	}

	// phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle -- registers CLI commands, not a constructor.
	public function cli(): void {
		WP_CLI::add_command( 'post archive', array( $this, 'archive' ) );
		WP_CLI::add_command( 'post unarchive', array( $this, 'unarchive' ) );
	}

	/**
	 * Archive a post.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more IDs of posts to archive.
	 *
	 * [--force]
	 * : Only supported public post types with core non-trashed statuses can be archived. Use this flag to skip current status check.
	 *
	 * [--defer-term-counting]
	 * : Recalculate term count in batch, for a performance boost.
	 *
	 * ## EXAMPLES
	 *
	 *     # Archive a post
	 *     wp post archive 123
	 *
	 *     # Archive multiple posts
	 *     wp post archive 123 456 789
	 *
	 *     # Archive a post without checking the current status
	 *     wp post archive 123 --force
	 *
	 * @since 0.4.0
	 * @param array<int, string|int> $args       Positional post IDs passed to the command.
	 * @param array<string, mixed>   $assoc_args Associative flags (e.g. --force, --defer-term-counting).
	 */
	public function archive( array $args, array $assoc_args ): void {
		$this->runner->run( $this->archive_command, $args, $assoc_args );
	}

	/**
	 * Unarchive a post.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more IDs of posts to delete.
	 *
	 * [--status=<status>]
	 * : Override the new status of the post(s).
	 *
	 * [--defer-term-counting]
	 * : Recalculate term count in batch, for a performance boost.
	 *
	 * ## EXAMPLES
	 *
	 *     wp post unarchive 123
	 *
	 * @since 0.4.0
	 * @param array<int, string|int> $args       Positional post IDs passed to the command.
	 * @param array<string, mixed>   $assoc_args Associative flags (e.g. --status, --defer-term-counting).
	 */
	public function unarchive( array $args, array $assoc_args ): void {
		$new_status = Utils\get_flag_value( $assoc_args, 'status', false );

		if ( ! $new_status ) {
			$this->runner->run( $this->unarchive_command, $args, $assoc_args );
			return;
		}

		// PHP_INT_MAX is the priority reserved for the plugin's own overrides
		// on this hook, so a site that hooks aps_unarchive_post_status at the
		// default priority is never disturbed by the remove_filter() below.
		// A standard WP-CLI invocation exits inside run(), so that cleanup only
		// matters where run() returns — e.g. an overridden terminate().
		$status_filter = static fn() => $new_status;

		add_filter( 'aps_unarchive_post_status', $status_filter, PHP_INT_MAX );
		$this->runner->run( $this->unarchive_command, $args, $assoc_args );
		remove_filter( 'aps_unarchive_post_status', $status_filter, PHP_INT_MAX );
	}
}
