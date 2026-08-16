<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Applies meta-aware ordering when the post list is sorted by the Scheduled
 * column.
 *
 * Mirrors {@see ArchiveColumnSort} exactly, over the schedule-time meta key
 * instead of the archive-date one. The $sort_query identity check in both
 * posts_join / posts_orderby filters is what stops them leaking onto a
 * secondary query in the same request — and, since both column sorts can be
 * registered on the same list-table screen, onto each other's query too:
 * ArchiveColumnSort only ever opts in the query it itself scoped, never this
 * one, and vice versa.
 *
 * @since 0.5.0
 */
final class ScheduleColumnSort implements HookableInterface {

	/**
	 * The query handle_sort() opted into meta-aware sorting for. Compared by
	 * identity inside the posts_join / posts_orderby filters so they are a
	 * no-op for every other query on the page.
	 *
	 * @since 0.5.0
	 * @var \WP_Query|null
	 */
	private ?\WP_Query $sort_query = null;

	/**
	 * @since 0.5.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'pre_get_posts', array( $this, 'handle_sort' ) ),
		);
	}

	/**
	 * Apply meta-aware ordering when sorting by the Scheduled column.
	 *
	 * Do not replace this with `$query->set( 'meta_key', ... )`. That routes
	 * ordering through WP_Query's meta_query machinery, whose JOIN + WHERE
	 * only matches posts that have a row for the key — silently dropping
	 * every post with no pending schedule, which on the normal (unfiltered)
	 * views {@see ScheduleColumn} shows this column on is most of them.
	 *
	 * The LEFT JOIN in filter_sort_join() plus the COALESCE in
	 * filter_sort_orderby() sort schedule-less rows to one end instead.
	 *
	 * @param \WP_Query $query The current query object.
	 */
	public function handle_sort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ScheduleColumn::COLUMN_KEY !== $query->get( 'orderby' ) ) {
			return;
		}

		$this->sort_query = $query;

		add_filter( 'posts_join', array( $this, 'filter_sort_join' ), 10, 2 );
		add_filter( 'posts_orderby', array( $this, 'filter_sort_orderby' ), 10, 2 );
	}

	/**
	 * LEFT JOIN wp_postmeta on the schedule-time key.
	 *
	 * Once registered this filter runs for every WP_Query on the page, so the
	 * identity check against $this->sort_query is what keeps it from leaking
	 * onto a secondary query in the same request.
	 *
	 * @param string    $join  The current JOIN clause.
	 * @param \WP_Query $query The query being filtered.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- stateless SQL-fragment builder.
	 */
	public function filter_sort_join( string $join, \WP_Query $query ): string {
		if ( $query !== $this->sort_query ) {
			return $join;
		}

		global $wpdb;

		return $join . ScheduleColumnSortSql::join( $wpdb );
	}

	/**
	 * Order by the LEFT JOINed schedule-time value.
	 *
	 * The direction comes from the query's own `order` var so the column
	 * header's toggle-on-click keeps working.
	 *
	 * Scoped by identity like filter_sort_join().
	 *
	 * @param string    $orderby The current ORDER BY clause.
	 * @param \WP_Query $query   The query being filtered.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- stateless SQL-fragment builder.
	 */
	public function filter_sort_orderby( string $orderby, \WP_Query $query ): string {
		if ( $query !== $this->sort_query ) {
			return $orderby;
		}

		return ScheduleColumnSortSql::order_by( (string) $query->get( 'order' ) );
	}
}
