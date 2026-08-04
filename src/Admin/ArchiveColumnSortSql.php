<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveMeta;

/**
 * Builds the SQL fragments ArchiveColumn's sort filters need to order the
 * post list by archive date.
 *
 * Pure functions of their arguments — no hook plumbing, no WP_Query. Callers
 * pass $wpdb in rather than this class reaching for the global itself, which
 * is what keeps it a plain unit test. Matches the PostListUrlBuilder idiom.
 *
 * @since 0.4.0
 */
final class ArchiveColumnSortSql {

	/**
	 * Alias for the wp_postmeta row LEFT JOINed in join().
	 * Prefixed so it cannot collide with a join core or another plugin added.
	 *
	 * @since 0.4.0
	 */
	private const SORT_JOIN_ALIAS = 'aps_archive_sort';

	/**
	 * LEFT JOIN wp_postmeta on the archive-date key.
	 *
	 * LEFT, not INNER, so posts with no archive-date row stay in the result set
	 * for order_by() to sort rather than being excluded.
	 *
	 * @since 0.4.0
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
			. $wpdb->prepare( '%s )', ArchiveMeta::META_ARCHIVE_DATE );
	}

	/**
	 * Order by the LEFT JOINed archive-date value.
	 *
	 * `COALESCE( …, 0 )` replaces the NULL the LEFT JOIN leaves on meta-less
	 * rows so they sort to one end. `+ 0` numeric-casts the stored value the
	 * way `meta_value_num` would, archive_date being a Unix timestamp.
	 *
	 * @since 0.4.0
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
