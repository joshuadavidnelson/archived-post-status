<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Schedule\ScheduleMeta;

/**
 * Builds the SQL fragments ScheduleColumnSort's sort filters need to order
 * the post list by scheduled archive time.
 *
 * Pure functions of their arguments — no hook plumbing, no WP_Query. Callers
 * pass $wpdb in rather than this class reaching for the global itself, which
 * is what keeps it a plain unit test. Matches the ArchiveColumnSortSql idiom.
 *
 * @since 0.5.0
 */
final class ScheduleColumnSortSql {

	/**
	 * Alias for the wp_postmeta row LEFT JOINed in join().
	 * Prefixed, and distinct from
	 * {@see ArchiveColumnSortSql}'s own join alias, so both column sorts can
	 * be active on the same list-table screen without their JOINs colliding.
	 *
	 * @since 0.5.0
	 */
	private const SORT_JOIN_ALIAS = 'aps_schedule_sort';

	/**
	 * LEFT JOIN wp_postmeta on the schedule-time key.
	 *
	 * LEFT, not INNER, so posts with no schedule row stay in the result set
	 * for order_by() to sort rather than being excluded.
	 *
	 * @since 0.5.0
	 *
	 * $wpdb is untyped natively on purpose: the characterization tests pin
	 * this SQL against a bare anonymous double that doesn't extend \wpdb, and
	 * a native hint would TypeError on it. The @param below still gives
	 * PHPStan (level 6) the type to check statically, so nothing is lost.
	 *
	 * @param \wpdb $wpdb The WordPress database access object.
	 * @return string
	 */
	public static function join( $wpdb ): string {
		return ' LEFT JOIN ' . $wpdb->postmeta . ' AS ' . self::SORT_JOIN_ALIAS
			. ' ON ( ' . self::SORT_JOIN_ALIAS . '.post_id = ' . $wpdb->posts . '.ID AND '
			. self::SORT_JOIN_ALIAS . '.meta_key = '
			. $wpdb->prepare( '%s )', ScheduleMeta::META_TIME );
	}

	/**
	 * Order by the LEFT JOINed schedule-time value.
	 *
	 * `COALESCE( …, 0 )` replaces the NULL the LEFT JOIN leaves on
	 * schedule-less rows so they sort to one end. `+ 0` numeric-casts the
	 * stored value the way `meta_value_num` would, the schedule time being a
	 * Unix timestamp.
	 *
	 * @since 0.5.0
	 *
	 * @param string $order The requested sort direction; anything other than
	 *                       'ASC' or 'DESC' (case-insensitive) falls back to 'DESC'.
	 * @return string
	 */
	public static function order_by( string $order ): string {
		$order = strtoupper( $order );
		if ( 'ASC' !== $order && 'DESC' !== $order ) {
			$order = 'DESC';
		}

		return 'COALESCE( ' . self::SORT_JOIN_ALIAS . '.meta_value + 0, 0 ) ' . $order;
	}
}
