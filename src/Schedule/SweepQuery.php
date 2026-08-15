<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchivableStatuses;

/**
 * Builds the WP_Query args for {@see Sweeper}'s due-posts query.
 *
 * A pure function of its arguments — no WP_Query, no globals, no side
 * effects — the same shape as
 * {@see \ArchivedPostStatus\Admin\ArchiveColumnSortSql}, which is what keeps
 * a wrong `meta_compare` or a missing `meta_type` a plain, exact-array unit
 * test instead of something only a live database would catch.
 *
 * @since 0.5.0
 */
final class SweepQuery {

	/**
	 * Default number of due posts a single sweep batch processes.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Build the due-posts query args.
	 *
	 * `meta_type => 'NUMERIC'` is load-bearing, not cosmetic: without it
	 * `meta_compare => '<='` compares the stored epoch as a string, which
	 * sorts and filters wrong the moment digit counts differ. `fields =>
	 * 'ids'` keeps the result set light; `no_found_rows => false` is the
	 * one deliberate exception to the usual "skip the count query"
	 * optimization, because {@see Sweeper} needs `found_posts` to compute
	 * `BatchResult::remaining`.
	 *
	 * @since 0.5.0
	 * @param int $now        Current UTC epoch; posts due at or before this are matched.
	 * @param int $batch_size Maximum number of posts this query returns.
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical archivable-statuses lookup.
	 */
	public static function args( int $now, int $batch_size ): array {
		$args = array(
			'post_type'           => aps_get_supported_post_types(),
			'post_status'         => ArchivableStatuses::all(),
			'meta_key'            => ScheduleMeta::META_TIME,
			'meta_value'          => $now, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the due-time comparison is the whole point of this query; there is no meta_query alternative that keeps NUMERIC <= semantics.
			'meta_compare'        => '<=',
			'meta_type'           => 'NUMERIC',
			'orderby'             => 'meta_value_num',
			'order'               => 'ASC',
			'posts_per_page'      => $batch_size,
			'fields'              => 'ids',
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		/**
		 * Filters the sweeper's due-posts WP_Query args.
		 *
		 * @since 0.5.0
		 * @param array<string, mixed> $args       The query args.
		 * @param int                  $now        Current UTC epoch used as the due-time cutoff.
		 * @param int                  $batch_size The batch size resolved for this run.
		 */
		return (array) apply_filters( 'aps_schedule_sweep_query_args', $args, $now, $batch_size );
	}

	/**
	 * Resolve the number of due posts a single sweep batch processes.
	 *
	 * Its own method, separate from {@see args()}, so the batch size is a
	 * testable unit on its own rather than only observable through the
	 * assembled args array.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	public static function batch_size(): int {

		/**
		 * Filters the number of due posts a single sweep batch processes.
		 *
		 * @since 0.5.0
		 * @param int $batch_size Default 50.
		 */
		return (int) apply_filters( 'aps_schedule_sweep_batch_size', self::DEFAULT_BATCH_SIZE );
	}
}
