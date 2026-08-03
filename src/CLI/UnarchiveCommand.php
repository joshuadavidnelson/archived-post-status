<?php
/**
 * `wp post unarchive` command — validation + execution.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Status\PostStatusValue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Validation pipeline for the unarchive command.
 *
 * Order matches the historical CLI::handle_action sequence:
 * supported-post-type → capability → lock check → not-in-archive. There is
 * no already-archived short-circuit on this path (the inverse condition is
 * checked at the end).
 *
 * @since 0.4.0
 */
final class UnarchiveCommand extends Command {

	protected function action(): ArchiveAction {
		return ArchiveAction::Unarchive;
	}

	/**
	 * Get the WP-CLI progress label.
	 *
	 * @since 0.4.0
	 *
	 * @return string The WP-CLI command name.
	 */
	public function progress_label(): string {
		// translators: progress message for the WP-CLI unarchive command.
		return __( 'Unarchiving', 'archived-post-status' );
	}

	/**
	 * Run the unarchive-specific gates.
	 *
	 * @param int                  $post_id    The post ID to validate.
	 * @param array<string, mixed> $assoc_args Associative CLI flags (--status, --defer-term-counting).
	 * @return CliResult|null Error result, or null if validation passes.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
	 */
	protected function validate( int $post_id, array $assoc_args ): ?CliResult {
		$pt_error = $this->ensure_supported_post_type( $post_id );
		if ( $pt_error instanceof CliResult ) {
			return $pt_error;
		}

		$cap_error = $this->capability_check( $post_id );
		if ( $cap_error instanceof CliResult ) {
			return $cap_error;
		}

		$lock_error = $this->ensure_not_locked( $post_id );
		if ( $lock_error instanceof CliResult ) {
			return $lock_error;
		}

		if ( PostStatusValue::resolved_slug() !== get_post_status( $post_id ) ) {
			return new CliResult(
				false,
				"Post {$post_id} cannot be unarchived because it is not in the archive."
			);
		}

		return null;
	}
}
