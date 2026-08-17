<?php
/**
 * `wp post schedule-archive` command — validation + execution.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Schedule\ScheduleTime;
use WP_CLI\Utils;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Sets a post's absolute scheduled archive time from a site-local
 * wall-clock string.
 *
 * Extends {@see Command} for its shared gates only —
 * {@see Command::capability_check()}, {@see Command::ensure_supported_post_type()},
 * {@see Command::ensure_not_locked()} — not for its `action()`/`execute()`
 * pairing, which is wired to {@see ArchiveAction::perform()} and has no
 * "schedule" case. {@see validate()} therefore never returns `null`: it
 * performs the write itself and always returns a {@see CliResult}, so the
 * inherited, `final` {@see Command::execute()} is never reached. `action()`
 * still has to return something — {@see ArchiveAction::Archive} is used
 * purely so {@see Command::capability_check()} resolves to
 * `aps_current_user_can_archive()`, per plan §5.7: scheduling is
 * pre-authorizing an archive, so it reuses that capability rather than
 * inventing `aps_current_user_can_schedule()`.
 *
 * `--at` is never parsed here directly: {@see ScheduleTime::to_timestamp()}
 * is the plugin's one wall-clock boundary, per plan §5.3.
 *
 * @since 0.5.0
 */
final class ScheduleCommand extends Command {

	/**
	 * @since 0.5.0
	 * @return ArchiveAction
	 */
	protected function action(): ArchiveAction {
		return ArchiveAction::Archive;
	}

	/**
	 * @since 0.5.0
	 * @return string
	 */
	public function progress_label(): string {
		// translators: progress message for the WP-CLI schedule-archive command.
		return __( 'Scheduling', 'archived-post-status' );
	}

	/**
	 * Run the shared gates, then parse and write the schedule.
	 *
	 * Narrows the parent's `?CliResult` return type covariantly — this
	 * override truly never returns null, per the class docblock, so the
	 * signature says so rather than merely commenting it.
	 *
	 * @since 0.5.0
	 * @param int                  $post_id    The post ID to schedule.
	 * @param array<string, mixed> $assoc_args Associative CLI flags (--at).
	 * @return CliResult
	 */
	protected function validate( int $post_id, array $assoc_args ): CliResult {
		$gate_error = $this->shared_gates( $post_id );
		if ( $gate_error instanceof CliResult ) {
			return $gate_error;
		}

		$timestamp = $this->parsed_timestamp( $assoc_args );
		if ( null === $timestamp ) {
			return new CliResult(
				false,
				"Post {$post_id}: --at is not a valid date/time. Use \"Y-m-d H:i:s\" (e.g. 2027-03-03 14:30:00) or \"Y-m-d\\TH:i\" (e.g. 2027-03-03T14:30)."
			);
		}

		return $this->write_schedule( $post_id, $timestamp );
	}

	/**
	 * The gates shared with every other post-id CLI command.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID to check.
	 * @return CliResult|null
	 */
	private function shared_gates( int $post_id ): ?CliResult {
		$pt_error = $this->ensure_supported_post_type( $post_id );
		if ( $pt_error instanceof CliResult ) {
			return $pt_error;
		}

		$cap_error = $this->capability_check( $post_id );
		if ( $cap_error instanceof CliResult ) {
			return $cap_error;
		}

		return $this->ensure_not_locked( $post_id );
	}

	/**
	 * @since 0.5.0
	 * @param array<string, mixed> $assoc_args Associative CLI flags.
	 * @return int|null
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical timezone-boundary accessor.
	 */
	private function parsed_timestamp( array $assoc_args ): ?int {
		$at_flag = (string) Utils\get_flag_value( $assoc_args, 'at', '' );

		return ScheduleTime::to_timestamp( $at_flag );
	}

	/**
	 * @since 0.5.0
	 * @param int $post_id   The post ID to schedule.
	 * @param int $timestamp UTC epoch the post is due to archive.
	 * @return CliResult
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical timezone-boundary accessor.
	 */
	private function write_schedule( int $post_id, int $timestamp ): CliResult {
		if ( ! aps_schedule_archive( $post_id, $timestamp, 'manual' ) ) {
			return new CliResult( false, "Failed to schedule post {$post_id}." );
		}

		return new CliResult( true, "Scheduled post {$post_id} to archive at " . ScheduleTime::to_display( $timestamp ) . '.' );
	}
}
