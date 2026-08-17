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
 * WP-CLI adapter: registers every `wp post ...` / `wp aps ...` command this
 * plugin exposes and delegates each invocation to its injected command
 * object.
 *
 * @since 0.4.0
 */
final class Registrar implements HookableInterface {

	public function __construct(
		private readonly CommandRunner $runner,
		private readonly ArchiveCommand $archive_command,
		private readonly UnarchiveCommand $unarchive_command,
		private readonly ScheduleCommand $schedule_command,
		private readonly UnscheduleCommand $unschedule_command,
		private readonly ExplainCommand $explain_command,
		private readonly SettingsCommand $settings_command,
		private readonly QueueCommand $queue_command,
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
		WP_CLI::add_command( 'post schedule-archive', array( $this, 'schedule_archive' ) );
		WP_CLI::add_command( 'post unschedule-archive', array( $this, 'unschedule_archive' ) );
		WP_CLI::add_command( 'post archive-rule', array( $this, 'archive_rule' ) );
		WP_CLI::add_command( 'aps settings list', array( $this, 'settings_list' ) );
		WP_CLI::add_command( 'aps settings get', array( $this, 'settings_get' ) );
		WP_CLI::add_command( 'aps settings update', array( $this, 'settings_update' ) );
		WP_CLI::add_command( 'aps queue run', array( $this, 'queue_run' ) );
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

	/**
	 * Schedule a post to archive at a specific date and time.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more IDs of posts to schedule.
	 *
	 * --at=<datetime>
	 * : Site-local wall-clock date/time the post should archive at, e.g.
	 * "2027-03-03 14:30:00" or "2027-03-03T14:30".
	 *
	 * [--defer-term-counting]
	 * : Recalculate term count in batch, for a performance boost.
	 *
	 * ## EXAMPLES
	 *
	 *     # Schedule a post to archive at a specific date and time
	 *     wp post schedule-archive 123 --at="2027-03-03 14:30:00"
	 *
	 *     # Schedule multiple posts at once
	 *     wp post schedule-archive 123 456 --at="2027-03-03 14:30:00"
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional post IDs passed to the command.
	 * @param array<string, mixed>   $assoc_args Associative flags (--at, --defer-term-counting).
	 */
	public function schedule_archive( array $args, array $assoc_args ): void {
		$this->runner->run( $this->schedule_command, $args, $assoc_args );
	}

	/**
	 * Clear a post's scheduled archive.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more IDs of posts to unschedule.
	 *
	 * [--defer-term-counting]
	 * : Recalculate term count in batch, for a performance boost.
	 *
	 * ## EXAMPLES
	 *
	 *     wp post unschedule-archive 123
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional post IDs passed to the command.
	 * @param array<string, mixed>   $assoc_args Associative flags (--defer-term-counting).
	 */
	public function unschedule_archive( array $args, array $assoc_args ): void {
		$this->runner->run( $this->unschedule_command, $args, $assoc_args );
	}

	/**
	 * Explain why a post is (or is not) scheduled to auto-archive: the
	 * resolved outcome, and the cascade level chain that produced it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the post to explain.
	 *
	 * [--format=<format>]
	 * : Render the per-level chain table in an alternate format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show why a post is (or is not) scheduled to auto-archive
	 *     wp post archive-rule 42
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the post ID.
	 * @param array<string, mixed>   $assoc_args Associative flags (--format).
	 */
	public function archive_rule( array $args, array $assoc_args ): void {
		$this->explain_command->explain( $args, $assoc_args );
	}

	/**
	 * List the plugin's settings and their current values.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : List the network-level settings instead of the site's. Requires a multisite install.
	 *
	 * [--format=<format>]
	 * : Render the table in an alternate format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp aps settings list
	 *     wp aps settings list --network
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Unused.
	 * @param array<string, mixed>   $assoc_args Associative flags (--network, --format).
	 */
	public function settings_list( array $args, array $assoc_args ): void {
		$this->settings_command->list_settings( $args, $assoc_args );
	}

	/**
	 * Get the current value of one setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting key to read.
	 *
	 * [--network]
	 * : Read the network-level setting instead of the site's. Requires a multisite install.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aps settings get auto_archive_days
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the key.
	 * @param array<string, mixed>   $assoc_args Associative flags (--network).
	 */
	public function settings_get( array $args, array $assoc_args ): void {
		$this->settings_command->get_setting( $args, $assoc_args );
	}

	/**
	 * Update one setting's value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting key to write.
	 *
	 * <value>
	 * : The new value. Sanitized the same way the settings screen and REST sanitize it.
	 *
	 * [--network]
	 * : Write the network-level setting instead of the site's. Requires a multisite install.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aps settings update auto_archive_days 30
	 *     wp aps settings update auto_archive_enabled true
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the key, $args[1] the value.
	 * @param array<string, mixed>   $assoc_args Associative flags (--network).
	 */
	public function settings_update( array $args, array $assoc_args ): void {
		$this->settings_command->update_setting( $args, $assoc_args );
	}

	/**
	 * Drive a queue's batch processor directly, with no WP-Cron time limit.
	 *
	 * ## OPTIONS
	 *
	 * <queue>
	 * : Which queue to drive: sweep (archives due posts) or stamp (applies auto-archive rules).
	 *
	 * [--all]
	 * : Loop until the queue is fully drained, rather than running a single batch.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run one batch of the sweep queue
	 *     wp aps queue run sweep
	 *
	 *     # Drain the entire stamp queue backlog after enabling a new rule
	 *     wp aps queue run stamp --all
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the queue name.
	 * @param array<string, mixed>   $assoc_args Associative flags (--all).
	 */
	public function queue_run( array $args, array $assoc_args ): void {
		$this->queue_command->run( $args, $assoc_args );
	}
}
