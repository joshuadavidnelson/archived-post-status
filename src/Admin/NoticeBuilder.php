<?php

namespace ArchivedPostStatus\Admin;

/**
 * Builds admin notice message strings for archive/unarchive operations.
 *
 * Pure value-builder: no WordPress hook registration, no HTML rendering.
 * Reads the bulk-result counters from the query vars (`archived`,
 * `unarchived`, `locked`, `denied`, `not_found`, `wrong_status`, `ids`)
 * and returns an array of formatted, translation-ready notice strings.
 *
 * The redirect URL also carries a `skipped=N` aggregate — the sum of every
 * skip bucket, added by {@see BulkActionResult::apply_to_url()} — but this
 * builder does not read it and produces no aggregate line. Skips are
 * reported one notice per reason bucket ({@see BUCKET_NAMES}) only.
 *
 * Invariant: NoticeBuilder NEVER performs bucket determination via
 * capability checks. The bucketed `denied` count arrives pre-computed
 * from {@see BulkActionHandler} via the redirect URL; the builder only
 * formats. The remaining capability check inside the builder — the
 * edit-link gate on the single-post unarchive branch — routes through
 * the centralized `aps_current_user_can_edit()` helper (filterable via
 * `aps_default_edit_capability`) so every cap question the plugin asks
 * ("can this user view / archive / unarchive / edit") resolves through
 * one filter surface per action. No raw `current_user_can()` calls
 * remain in this class.
 *
 * @since 0.4.0
 */
final class NoticeBuilder {

	/**
	 * Declaration order of the reason-skip buckets emitted by
	 * {@see build_notices()}. Iterated to drive the dispatcher table in
	 * {@see format_bucket_notice()}.
	 *
	 * Phase 4 of the 0.4.0 cleanup extracted the five near-identical
	 * sprintf+_n blocks from `build_notices()` into a per-bucket strategy
	 * so the orchestration reads as a single loop. The string literals
	 * stay inline inside the dispatcher (i18n scanners require literal
	 * arguments to `_n()`).
	 *
	 * Order matters — pinned by
	 * `test_build_notices_emits_per_bucket_notices_for_phase_1_reason_buckets`.
	 *
	 * @var string[]
	 */
	private const BUCKET_NAMES = array( 'locked', 'denied', 'not_found', 'wrong_status' );

	/**
	 * Build notices from query variables.
	 *
	 * @since 0.4.0
	 * @param string $post_type The current post type.
	 * @return array<int, string> List of formatted notice message strings.
	 */
	public function build_notices( string $post_type ): array {
		$notices = array();
		$ids     = get_query_var( 'ids', false );

		$archived = get_query_var( 'archived', false );
		if ( $archived ) {
			$notices[] = $this->build_archive_notice( absint( $archived ), $this->parse_ids( $ids ), $post_type );
		}

		$unarchived = get_query_var( 'unarchived', false );
		if ( $unarchived ) {
			$notices[] = $this->build_unarchive_notice( absint( $unarchived ), $this->parse_ids( $ids ) );
		}

		foreach ( self::BUCKET_NAMES as $bucket ) {
			$count = (int) get_query_var( $bucket, false );
			if ( $count <= 0 ) {
				continue;
			}
			$notices[] = $this->format_bucket_notice( $bucket, $count );
		}

		return $notices;
	}

	/**
	 * Format a single per-bucket reason-skip notice.
	 *
	 * Strategy dispatcher for the five reason-bucket notice lines that
	 * share a uniform shape: `_n(singular, plural, $count, 'archived-post-status')`
	 * piped through `sprintf( …, number_format_i18n( $count ) )`. The
	 * string literals are inline inside each `match` arm so the i18n
	 * scanner (xgettext / WPCS WordPress.WP.I18n) can extract them.
	 *
	 * Extracted in Phase 4 of the 0.4.0 cleanup.
	 *
	 * @since 0.4.0
	 *
	 * @param string $bucket Bucket name (must be one of {@see BUCKET_NAMES}).
	 * @param int    $count  Non-zero post count for this bucket.
	 * @return string The translation-ready notice line.
	 */
	private function format_bucket_notice( string $bucket, int $count ): string {
		$message = match ( $bucket ) {
			'locked' =>
				/* translators: %s: Number of locked posts */
				_n( '%s post not archived, somebody is editing it.', '%s posts not archived, somebody is editing them.', $count, 'archived-post-status' ),
			'denied' =>
				/* translators: %s: Number of posts skipped because the user lacks the capability */
				_n( '%s post skipped: you are not allowed to perform this action on it.', '%s posts skipped: you are not allowed to perform this action on them.', $count, 'archived-post-status' ),
			'not_found' =>
				/* translators: %s: Number of posts skipped because they could not be located */
				_n( '%s post skipped: it no longer exists or its type is unsupported.', '%s posts skipped: they no longer exist or their types are unsupported.', $count, 'archived-post-status' ),
			'wrong_status' =>
				/* translators: %s: Number of posts skipped because their status disqualifies them */
				_n( '%s post skipped: its status is not eligible for this action.', '%s posts skipped: their status is not eligible for this action.', $count, 'archived-post-status' ),
			// Defensive default — kept exhaustive for phpstan; this arm is
			// unreachable because callers iterate {@see BUCKET_NAMES}.
			default => '',
		};

		return '' === $message ? '' : sprintf( $message, number_format_i18n( $count ) );
	}

	/**
	 * Build archive success notice.
	 *
	 * @since 0.4.0
	 * @param int             $count     The number of posts archived.
	 * @param array<int, int> $ids       Array of post IDs archived.
	 * @param string          $post_type The post type.
	 * @return string
	 */
	public function build_archive_notice( int $count, array $ids, string $post_type ): string {
		$message = sprintf(
			/* translators: %s: Number of posts archived */
			_n(
				'%s post moved to the Archive.',
				'%s posts moved to the Archive.',
				$count,
				'archived-post-status'
			),
			number_format_i18n( $count )
		);

		// Add undo link for both single and bulk operations
		if ( ! empty( $ids ) ) {
			$ids_string = implode( ',', array_map( 'absint', $ids ) );
			$undo_url   = wp_nonce_url(
				admin_url( "edit.php?post_type={$post_type}&doaction=undo&action=unarchive&ids={$ids_string}" ),
				'bulk-posts'
			);
			$message   .= sprintf(
				' <a href="%s">%s</a>',
				esc_url( $undo_url ),
				__( 'Undo', 'archived-post-status' )
			);
		}

		return $message;
	}

	/**
	 * Build unarchive success notice.
	 *
	 * @since 0.4.0
	 * @param int             $count The number of posts unarchived.
	 * @param array<int, int> $ids   Array of post IDs unarchived.
	 * @return string
	 */
	public function build_unarchive_notice( int $count, array $ids ): string {
		$message = sprintf(
			/* translators: %s: Number of posts unarchived */
			_n(
				'%s post restored from the Archive.',
				'%s posts restored from the Archive.',
				$count,
				'archived-post-status'
			),
			number_format_i18n( $count )
		);

		// Add edit link for single post unarchive
		if ( 1 === count( $ids ) ) {
			$post_id = absint( $ids[0] );
			if ( aps_current_user_can_edit( $post_id ) ) {
				$post_type_object = get_post_type_object( get_post_type( $post_id ) );
				if ( $post_type_object ) {
					$edit_link = sprintf(
						' <a href="%s">%s</a>',
						esc_url( get_edit_post_link( $post_id ) ),
						esc_html( $post_type_object->labels->edit_item )
					);
					$message  .= $edit_link;
				}
			}
		}

		return $message;
	}

	/**
	 * Parse IDs from query variable.
	 *
	 * Normalizes the `ids` query var into an int[] regardless of whether
	 * WordPress hands us a CSV string, a real int array, or an array whose
	 * first element is itself a CSV string. All non-numeric tokens are
	 * dropped; the result is always `array<int, int>` with the absint
	 * filter applied.
	 *
	 * Public so the builder can be tested directly without reflection.
	 *
	 * @since 0.4.0
	 * @param mixed $ids The IDs from query variable.
	 * @return array<int, int> Sanitized post IDs (absint-filtered, non-zero).
	 */
	public function parse_ids( $ids ): array {
		if ( ! $ids ) {
			return array();
		}

		// Handle string of comma-separated IDs
		if ( is_string( $ids ) ) {
			$ids = preg_replace( '/[^0-9,]/', '', $ids );
			$ids = explode( ',', $ids );
		}

		// Handle array but check for comma-separated string in first element
		if ( is_array( $ids ) && isset( $ids[0] ) && is_string( $ids[0] ) && str_contains( $ids[0], ',' ) ) {
			$ids_string = preg_replace( '/[^0-9,]/', '', $ids[0] );
			$ids        = explode( ',', $ids_string );
		}

		// Ensure we have an array and sanitize
		$ids = (array) $ids;
		return array_filter( array_map( 'absint', $ids ) );
	}
}
