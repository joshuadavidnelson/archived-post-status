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
 * Order is load-bearing and matches the historical CLI::handle_action
 * sequence: supported-post-type → already-archived → capability →
 * archivable-status (with --force bypass). The existing test suite pins
 * this ordering.
 *
 * @since 0.4.0
 */
final class ArchiveCommand extends Command {

	protected function action(): ArchiveAction {
		return ArchiveAction::Archive;
	}

	public function progress_label(): string {
		return 'Archiving';
	}

	/**
	 * Run the archive-specific gates.
	 *
	 * @param int                  $post_id    The post ID to validate.
	 * @param array<string, mixed> $assoc_args Associative CLI flags (--force, --defer-term-counting).
	 * @return CliResult|null Error result, or null if validation passes.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * and {@see ArchivableStatuses::includes()} are the canonical
	 * vocabulary lookups (Phase 3B).
	 */
	protected function validate( int $post_id, array $assoc_args ): ?CliResult {
		$pt_error = $this->ensure_supported_post_type( $post_id );
		if ( $pt_error instanceof CliResult ) {
			return $pt_error;
		}

		// Already-archived guard: short-circuits before any capability check
		// so messages stay specific ("already archived" beats "no permission
		// to archive" when the post is in fact already in the archive).
		if ( PostStatusValue::resolved_slug() === get_post_status( $post_id ) ) {
			return new CliResult( false, "Post {$post_id} is already archived." );
		}

		$cap_error = $this->capability_check( $post_id );
		if ( $cap_error instanceof CliResult ) {
			return $cap_error;
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
