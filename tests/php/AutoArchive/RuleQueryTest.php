<?php
/**
 * AutoArchive\RuleQuery Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RuleQuery
 *
 * Pins the exact query-args shape for both of RuleStamper's passes, key by
 * key — a wrong `date_query` column or a missing `meta_query` clause would
 * silently stamp the wrong posts (or none at all) without ever throwing.
 */

use ArchivedPostStatus\AutoArchive\RuleQuery;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleQuery
 */
class RuleQueryTest extends TestCase {

	use BoundaryStubs;

	/**
	 * Stub the two settings RuleQuery::candidates()/stale_refreshes() read
	 * directly: `auto_archive_types` and `auto_archive_age_basis`.
	 *
	 * @param string[] $types The post types the filter replies with.
	 * @param string   $basis 'modified' | 'published'.
	 */
	private function stubQuerySettings( array $types, string $basis = 'modified' ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( $types );
		\WP_Mock::onFilter( 'aps_auto_archive_age_basis' )->with( 'modified' )->reply( $basis );
	}

	// Tests that do not stub `aps_auto_archive_query_args` at all rely on
	// WP_Mock's default (non-strict-mode) filter behavior: an
	// apply_filters() call for a tag with no registered expectation simply
	// returns its incoming value unchanged -- see WP_Mock\Filter::apply().

	// -----------------------------------------------------------------------
	// candidates()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::candidates
	 */
	public function test_candidates_produces_the_exact_query_shape_for_the_modified_basis() {
		$this->stubArchivableStatusesBoundary( array( 'publish', 'future', 'draft', 'pending', 'private' ) );
		$this->stubQuerySettings( array( 'post' ), 'modified' );

		$now     = 1700000000;
		$cutoff  = $now - ( 5 * DAY_IN_SECONDS );
		$before  = gmdate( 'Y-m-d H:i:s', $cutoff );
		$expected = array(
			'post_type'           => array( 'post' ),
			'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'meta_query'          => array(
				array(
					'key'     => ScheduleMeta::META_SOURCE,
					'compare' => 'NOT EXISTS',
				),
			),
			'date_query'          => array(
				array(
					'column'    => 'post_modified_gmt',
					'before'    => $before,
					'inclusive' => true,
				),
			),
			'orderby'             => 'modified',
			'order'               => 'ASC',
			'fields'              => 'ids',
			'posts_per_page'      => 10,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		\WP_Mock::onFilter( 'aps_auto_archive_query_args' )
			->with( $expected, 'candidates', $now )
			->reply( $expected );

		$this->assertSame( $expected, RuleQuery::candidates( $now, 5, 10 ) );
	}

	/**
	 * The 'published' basis swaps both the date_query column and orderby to
	 * the publish date rather than the modified date.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::candidates
	 */
	public function test_candidates_uses_the_publish_date_column_and_orderby_for_the_published_basis() {
		$this->stubArchivableStatusesBoundary();
		$this->stubQuerySettings( array( 'post', 'page' ), 'published' );

		$now    = 1700000000;
		$before = gmdate( 'Y-m-d H:i:s', $now - ( 3 * DAY_IN_SECONDS ) );

		$args = RuleQuery::candidates( $now, 3, 25 );

		$this->assertSame( 'post_date_gmt', $args['date_query'][0]['column'] );
		$this->assertSame( $before, $args['date_query'][0]['before'] );
		$this->assertSame( 'date', $args['orderby'] );
	}

	/**
	 * `$min_days` is the caller-computed floor documented at length on
	 * RuleQuery's class docblock — this pins that it is actually used to
	 * build the cutoff, not silently ignored.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::candidates
	 */
	public function test_candidates_cutoff_is_now_minus_min_days() {
		$this->stubArchivableStatusesBoundary();
		$this->stubQuerySettings( array( 'post' ), 'modified' );
		$now  = 2000000000;
		$args = RuleQuery::candidates( $now, 365, 10 );

		$this->assertSame(
			gmdate( 'Y-m-d H:i:s', $now - ( 365 * DAY_IN_SECONDS ) ),
			$args['date_query'][0]['before']
		);
	}

	// -----------------------------------------------------------------------
	// stale_refreshes()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::stale_refreshes
	 */
	public function test_stale_refreshes_produces_the_exact_query_shape() {
		$this->stubArchivableStatusesBoundary( array( 'publish', 'future', 'draft', 'pending', 'private' ) );
		$this->stubQuerySettings( array( 'post' ) );

		$now      = 1700000000;
		$expected = array(
			'post_type'           => array( 'post' ),
			'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'meta_query'          => array(
				array(
					'key'     => ScheduleMeta::META_SOURCE,
					'value'   => ScheduleSource::Rule->value,
					'compare' => '=',
				),
				array(
					'key'     => ScheduleMeta::META_RULE_VERSION,
					'value'   => 4,
					'compare' => '!=',
					'type'    => 'NUMERIC',
				),
			),
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'fields'              => 'ids',
			'posts_per_page'      => 20,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		\WP_Mock::onFilter( 'aps_auto_archive_query_args' )
			->with( $expected, 'stale_refreshes', $now )
			->reply( $expected );

		$this->assertSame( $expected, RuleQuery::stale_refreshes( $now, 4, 20 ) );
	}

	// -----------------------------------------------------------------------
	// batch_size()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::batch_size
	 */
	public function test_batch_size_defaults_to_100() {
		\WP_Mock::onFilter( 'aps_auto_archive_batch_size' )->with( 100 )->reply( 100 );

		$this->assertSame( 100, RuleQuery::batch_size() );
	}

	/**
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::batch_size
	 */
	public function test_batch_size_is_filterable() {
		\WP_Mock::onFilter( 'aps_auto_archive_batch_size' )->with( 100 )->reply( 5 );

		$this->assertSame( 5, RuleQuery::batch_size() );
	}

	// -----------------------------------------------------------------------
	// aps_auto_archive_query_args
	// -----------------------------------------------------------------------

	/**
	 * A site can override the assembled args wholesale via
	 * `aps_auto_archive_query_args` — this pins that the filter's return
	 * value is what actually comes back, not merely applied and discarded.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::candidates
	 */
	public function test_candidates_returns_whatever_the_query_args_filter_replies_with() {
		$this->stubArchivableStatusesBoundary( array( 'publish', 'future', 'draft', 'pending', 'private' ) );
		$this->stubQuerySettings( array( 'post' ) );

		$now      = 1700000000;
		$built    = array(
			'post_type'           => array( 'post' ),
			'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'meta_query'          => array(
				array(
					'key'     => ScheduleMeta::META_SOURCE,
					'compare' => 'NOT EXISTS',
				),
			),
			'date_query'          => array(
				array(
					'column'    => 'post_modified_gmt',
					'before'    => gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ),
					'inclusive' => true,
				),
			),
			'orderby'             => 'modified',
			'order'               => 'ASC',
			'fields'              => 'ids',
			'posts_per_page'      => 10,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);
		$replaced = array( 'post_type' => array( 'custom' ) );

		\WP_Mock::onFilter( 'aps_auto_archive_query_args' )
			->with( $built, 'candidates', $now )
			->reply( $replaced );

		$this->assertSame( $replaced, RuleQuery::candidates( $now, 1, 10 ) );
	}
}
