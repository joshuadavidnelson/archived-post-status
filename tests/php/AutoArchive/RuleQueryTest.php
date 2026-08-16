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
use ArchivedPostStatus\Settings\NetworkStore;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleQuery
 */
class RuleQueryTest extends TestCase {

	use BoundaryStubs;

	public function set_up() {
		parent::set_up();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
		NetworkStore::flush_cache();
		parent::tear_down();
	}

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

	/**
	 * Stub the site-level `auto_archive_enabled`/`auto_archive_days`
	 * filters {@see RuleQuery::min_days()}'s site half reads.
	 */
	private function stubSiteMinDays( bool $enabled, ?int $days ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( $enabled );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( $days );
	}

	/**
	 * Stub the network-activation check plus the stored network option
	 * {@see RuleQuery::min_days()}'s network half reads (via
	 * {@see \ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider}).
	 *
	 * @param array<string, mixed> $stored The stored network option array.
	 */
	private function stubNetworkActivatedWith( array $stored ): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => true ) );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( $stored );
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

	// -----------------------------------------------------------------------
	// min_days()
	// -----------------------------------------------------------------------

	/**
	 * Network 30 + site 365: min_days() must return the SMALLER value, 30
	 * -- not 365. The non-negotiable case from the phase-7 brief.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::min_days
	 */
	public function test_min_days_takes_the_minimum_across_network_and_site() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 30,
			)
		);
		$this->stubSiteMinDays( true, 365 );

		$this->assertSame( 30, RuleQuery::min_days() );
	}

	/**
	 * The reverse: site 30, network 365 -- still 30, proving this is a
	 * genuine min(), not "prefer the network value".
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::min_days
	 */
	public function test_min_days_takes_the_minimum_regardless_of_which_level_is_smaller() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 365,
			)
		);
		$this->stubSiteMinDays( true, 30 );

		$this->assertSame( 30, RuleQuery::min_days() );
	}

	/**
	 * Network alone, site disabled entirely: min_days() is not site-only --
	 * the network level's own value is still returned.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::min_days
	 */
	public function test_min_days_uses_the_network_value_alone_when_site_is_disabled() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 30,
			)
		);
		$this->stubSiteMinDays( false, null );

		$this->assertSame( 30, RuleQuery::min_days() );
	}

	/**
	 * Site alone, not network-activated: min_days() is exactly the site
	 * value -- the pre-phase-7 behavior, unbroken.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::min_days
	 */
	public function test_min_days_uses_the_site_value_alone_when_not_network_activated() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		$this->stubSiteMinDays( true, 12 );

		$this->assertSame( 12, RuleQuery::min_days() );
	}

	/**
	 * Neither level currently schedules anything: null, and the candidate
	 * pass does not run this batch (see RuleStamperTest).
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::min_days
	 */
	public function test_min_days_is_null_when_neither_level_has_a_value() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		$this->stubSiteMinDays( false, null );

		$this->assertNull( RuleQuery::min_days() );
	}
}
