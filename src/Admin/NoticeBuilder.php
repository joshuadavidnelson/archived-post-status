<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Builds admin notice message strings for archive/unarchive operations.
 *
 * Pure value-builder: no hook registration, no HTML rendering. Reads the
 * bulk-result counters from the query vars and returns formatted,
 * translation-ready notice strings.
 *
 * The URL's `skipped=N` aggregate is not read here — skips are reported one
 * notice per reason bucket ({@see bucket_names()}).
 *
 * Bucket determination never happens here: the `denied` count arrives
 * pre-computed from {@see BulkActionHandler}. The one capability check in this
 * class, the edit-link gate on single-post unarchive, routes through
 * `aps_current_user_can_edit()` rather than a raw `current_user_can()`.
 *
 * @since 0.4.0
 */
final class NoticeBuilder {

	/**
	 * The reason-skip buckets emitted by {@see build_notices()}, in render
	 * order. Drives the dispatcher in {@see format_bucket_notice()}.
	 *
	 * A method rather than a `const`: an enum case's `->value` fetch is not
	 * a valid constant expression on this plugin's 8.1 floor.
	 *
	 * @since 0.4.0
	 * @return string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical query-arg-name source.
	 */
	private static function bucket_names(): array {
		return array(
			NoticeQueryArg::Locked->value,
			NoticeQueryArg::Denied->value,
			NoticeQueryArg::NotFound->value,
			NoticeQueryArg::WrongStatus->value,
		);
	}

	/**
	 * Label of the undo link appended to the archive success notice.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function undo_label(): string {
		/* translators: label for the undo link appended to the archive success notice. */
		return __( 'Undo', 'archived-post-status' );
	}

	/**
	 * Build notices from query variables.
	 *
	 * @since 0.4.0
	 * @param string $post_type The current post type.
	 * @return array<int, string> List of formatted notice message strings.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical query-arg-name source.
	 */
	public function build_notices( string $post_type ): array {
		$notices = array();
		$ids     = get_query_var( NoticeQueryArg::Ids->value, false );

		$archived = get_query_var( NoticeQueryArg::Archived->value, false );
		if ( $archived ) {
			$notices[] = $this->build_archive_notice( absint( $archived ), $this->parse_ids( $ids ), $post_type );
		}

		$unarchived = get_query_var( NoticeQueryArg::Unarchived->value, false );
		if ( $unarchived ) {
			$notices[] = $this->build_unarchive_notice( absint( $unarchived ), $this->parse_ids( $ids ) );
		}

		foreach ( self::bucket_names() as $bucket ) {
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
	 * The strings stay inline in each `match` arm because i18n scanners require
	 * literal arguments to `_n()`.
	 *
	 * @since 0.4.0
	 *
	 * @param string $bucket Bucket name (must be one of {@see bucket_names()}).
	 * @param int    $count  Non-zero post count for this bucket.
	 * @return string The translation-ready notice line.
	 */
	public function format_bucket_notice( string $bucket, int $count ): string {
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
			// Unreachable — callers iterate bucket_names(). Present for phpstan.
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

		if ( ! empty( $ids ) ) {
			$ids_string = implode( ',', array_map( 'absint', $ids ) );
			$undo_url   = wp_nonce_url(
				admin_url( "edit.php?post_type={$post_type}&doaction=undo&action=unarchive&ids={$ids_string}" ),
				'bulk-posts'
			);
			$message   .= sprintf(
				' <a href="%s">%s</a>',
				esc_url( $undo_url ),
				self::undo_label()
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
	 * WordPress may hand this a CSV string, a real int array, or an array whose
	 * first element is itself a CSV string; all three normalize to int[].
	 *
	 * @since 0.4.0
	 * @param mixed $ids The IDs from query variable.
	 * @return array<int, int> Sanitized post IDs (absint-filtered, non-zero).
	 */
	public function parse_ids( $ids ): array {
		if ( ! $ids ) {
			return array();
		}

		if ( is_string( $ids ) ) {
			$ids = preg_replace( '/[^0-9,]/', '', $ids );
			$ids = explode( ',', $ids );
		}

		// An array whose first element is itself a CSV string.
		if ( is_array( $ids ) && isset( $ids[0] ) && is_string( $ids[0] ) && str_contains( $ids[0], ',' ) ) {
			$ids_string = preg_replace( '/[^0-9,]/', '', $ids[0] );
			$ids        = explode( ',', $ids_string );
		}

		$ids = (array) $ids;
		return array_filter( array_map( 'absint', $ids ) );
	}
}
