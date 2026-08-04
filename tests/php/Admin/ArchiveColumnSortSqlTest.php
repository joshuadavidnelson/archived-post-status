<?php
/**
 * Admin\ArchiveColumnSortSql tests.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql
 *
 * Direct unit coverage on the static join() / order_by() SQL-fragment
 * builders. Both are pure functions of their arguments, so these tests pass
 * an inline anonymous $wpdb double rather than manipulating the `global
 * $wpdb` or standing up a WP_Query — see {@see ArchiveColumnSortTest} for the
 * ArchiveColumnSort::filter_sort_join() / filter_sort_orderby() pins that
 * prove the delegation still produces byte-identical output.
 */

use ArchivedPostStatus\Admin\ArchiveColumnSortSql;
use ArchivedPostStatus\Archive\ArchiveMeta;

/**
 * ArchiveColumnSortSql test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql
 */
class ArchiveColumnSortSqlTest extends TestCase {

	/**
	 * Build an inline $wpdb double whose prepare() returns the query
	 * template unchanged (mirroring the double ArchiveColumnTest's sort
	 * pins use) and records every call for inspection.
	 *
	 * @return object
	 */
	private function wpdb_double(): object {
		return new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';

			/** @var array<int, array{query: string, args: array<int, mixed>}> */
			public array $prepared_queries = array();

			/**
			 * @param string $query   Prepared query template.
			 * @param mixed  ...$args Bound parameters.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->prepared_queries[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}
		};
	}

	/**
	 * join() must produce the exact LEFT JOIN fragment byte for byte —
	 * the same contract ArchiveColumnTest's filter_sort_join() pin asserts
	 * once the leading original $join content is stripped away.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql::join
	 */
	public function test_join_builds_the_exact_left_join_fragment() {
		$wpdb = $this->wpdb_double();

		$joined = ArchiveColumnSortSql::join( $wpdb );

		$this->assertSame(
			' LEFT JOIN wp_postmeta AS aps_archive_sort'
			. ' ON ( aps_archive_sort.post_id = wp_posts.ID AND aps_archive_sort.meta_key = %s )',
			$joined
		);
	}

	/**
	 * join() must escape the meta key through $wpdb->prepare() rather than
	 * interpolating it directly — the same guarantee the "Preserve exactly"
	 * list in the refactor plan calls out.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql::join
	 */
	public function test_join_prepares_the_archive_date_meta_key_through_wpdb() {
		$wpdb = $this->wpdb_double();

		ArchiveColumnSortSql::join( $wpdb );

		$this->assertCount( 1, $wpdb->prepared_queries, 'expected exactly one prepared LEFT JOIN fragment' );
		$this->assertSame(
			ArchiveMeta::META_ARCHIVE_DATE,
			$wpdb->prepared_queries[0]['args'][0],
			'the JOIN must match on the archive-date meta key'
		);
	}

	/**
	 * order_by() sorts ascending when given 'ASC'.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql::order_by
	 */
	public function test_order_by_returns_the_exact_fragment_for_asc() {
		$orderby = ArchiveColumnSortSql::order_by( 'ASC' );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) ASC', $orderby );
	}

	/**
	 * order_by() sorts descending when given 'DESC'.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql::order_by
	 */
	public function test_order_by_returns_the_exact_fragment_for_desc() {
		$orderby = ArchiveColumnSortSql::order_by( 'DESC' );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) DESC', $orderby );
	}

	/**
	 * order_by() is case-insensitive on its input, matching the
	 * strtoupper() normalisation the original implementation performed
	 * on the query's raw `order` var.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql::order_by
	 */
	public function test_order_by_is_case_insensitive() {
		$orderby = ArchiveColumnSortSql::order_by( 'asc' );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) ASC', $orderby );
	}

	/**
	 * Anything outside the ASC/DESC allow-list — including an empty string
	 * — falls back to DESC rather than emitting an incomplete/invalid
	 * ORDER BY fragment.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql::order_by
	 */
	public function test_order_by_falls_back_to_desc_for_an_unrecognized_value() {
		$orderby = ArchiveColumnSortSql::order_by( 'banana' );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) DESC', $orderby );
	}

	/**
	 * The empty-string boundary specifically — the value handle_sort()'s
	 * query var resolves to when WordPress has no `order` set at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnSortSql::order_by
	 */
	public function test_order_by_falls_back_to_desc_for_an_empty_string() {
		$orderby = ArchiveColumnSortSql::order_by( '' );

		$this->assertSame( 'COALESCE( aps_archive_sort.meta_value + 0, 0 ) DESC', $orderby );
	}
}
