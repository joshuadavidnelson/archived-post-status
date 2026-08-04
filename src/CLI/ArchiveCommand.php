<?php
/**
 * `wp post archive` command — validation + execution.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\Status\PostStatusValue;
use WP_CLI\Utils;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Validation pipeline for the archive command.
 *
 * Gate order is load-bearing: supported-post-type → already-archived →
 * capability → lock → archivable-status (bypassed by --force).
 *
 * @since 0.4.0
 */
final class ArchiveCommand extends Command {

	/**
	 * Get the archive action.
	 *
	 * @since 0.4.0
	 *
	 * @return ArchiveAction The archive action.
	 */
	protected function action(): ArchiveAction {
		return ArchiveAction::Archive;
	}

	/**
	 * Get the WP-CLI progress label.
	 *
	 * @since 0.4.0
	 *
	 * @return string The WP-CLI command name.
	 */
	public function progress_label(): string {
		// translators: progress message for the WP-CLI archive command.
		return __( 'Archiving', 'archived-post-status' );
	}

	/**
	 * Run the archive-specific gates.
	 *
	 * @since 0.4.0
	 *
	 * @param int                  $post_id    The post ID to validate.
	 * @param array<string, mixed> $assoc_args Associative CLI flags (--force, --defer-term-counting).
	 * @return CliResult|null Error result, or null if validation passes.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical vocabulary lookups.
	 */
	protected function validate( int $post_id, array $assoc_args ): ?CliResult {
		$pt_error = $this->ensure_supported_post_type( $post_id );
		if ( $pt_error instanceof CliResult ) {
			return $pt_error;
		}

		// Before the capability check, so an already-archived post reports that
		// rather than a less specific "no permission to archive".
		if ( PostStatusValue::resolved_slug() === get_post_status( $post_id ) ) {
			return new CliResult( false, "Post {$post_id} is already archived." );
		}

		$cap_error = $this->capability_check( $post_id );
		if ( $cap_error instanceof CliResult ) {
			return $cap_error;
		}

		$lock_error = $this->ensure_not_locked( $post_id );
		if ( $lock_error instanceof CliResult ) {
			return $lock_error;
		}

		// --force bypasses the archivable-status whitelist.
		if ( Utils\get_flag_value( $assoc_args, 'force', false ) ) {
			return null;
		}

		$status = (string) get_post_status( $post_id );
		if ( ! ArchivableStatuses::includes( $status ) ) {
			return new CliResult(
				false,
				"Post {$post_id} cannot be archived, '{$status}' is not an archivable status."
			);
		}

		return null;
	}
}
