<?php
/**
 * `wp post unschedule-archive` command — validation + execution.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Archive\ArchiveAction;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Clears a post's scheduled archive.
 *
 * Same shape as {@see ScheduleCommand} — see that class's docblock for why
 * this extends {@see Command} for its shared gates only, never reaches the
 * inherited `execute()`, and returns {@see ArchiveAction::Archive} from
 * `action()` purely to resolve `aps_current_user_can_archive()` as the
 * capability, per plan §5.7.
 *
 * @since 0.5.0
 */
final class UnscheduleCommand extends Command {

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
		// translators: progress message for the WP-CLI unschedule-archive command.
		return __( 'Unscheduling', 'archived-post-status' );
	}

	/**
	 * Run the shared gates, then clear the schedule.
	 *
	 * Narrows the parent's `?CliResult` return type covariantly — this
	 * override truly never returns null, per the class docblock, so the
	 * signature says so rather than merely commenting it.
	 *
	 * @since 0.5.0
	 * @param int                  $post_id    The post ID to unschedule.
	 * @param array<string, mixed> $assoc_args Unread by this method; CommandRunner::run() reads
	 *                                         --defer-term-counting out of it uniformly for every
	 *                                         Command, this one included.
	 * @return CliResult
	 */
	protected function validate( int $post_id, array $assoc_args ): CliResult {
		$gate_error = $this->shared_gates( $post_id );
		if ( $gate_error instanceof CliResult ) {
			return $gate_error;
		}

		if ( ! aps_unschedule_archive( $post_id ) ) {
			return new CliResult( false, "Post {$post_id} has no schedule to clear." );
		}

		return new CliResult( true, "Unscheduled post {$post_id}." );
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
}
