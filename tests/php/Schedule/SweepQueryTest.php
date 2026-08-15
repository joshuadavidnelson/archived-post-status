<?php
/**
 * Schedule\SweepQuery Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\SweepQuery
 */

use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\SweepQuery;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\SweepQuery
 */
class SweepQueryTest extends TestCase {

	use BoundaryStubs;

	// -----------------------------------------------------------------------
	// args()
	// -----------------------------------------------------------------------

	/**
	 * Pin the exact args array, key by key. A wrong `meta_compare` or a
	 * missing `meta_type` would silently archive the wrong posts (or none at
	 * all) without ever throwing — this is the test that catches it.
	 *
	 * @covers ArchivedPostStatus\Schedule\SweepQuery::args
	 */
	public function test_args_produces_the_exact_query_shape() {
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );
		$this->stubArchivableStatusesBoundary( array( 'publish', 'future', 'draft', 'pending', 'private' ) );

		\WP_Mock::onFilter( 'aps_schedule_sweep_query_args' )
			->with(
				array(
					'post_type'           => array( 'post', 'page' ),
					'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'meta_key'            => ScheduleMeta::META_TIME,
					'meta_value'          => 1700000000,
					'meta_compare'        => '<=',
					'meta_type'           => 'NUMERIC',
					'orderby'             => 'meta_value_num',
					'order'               => 'ASC',
					'posts_per_page'      => 25,
					'fields'              => 'ids',
					'no_found_rows'       => false,
					'ignore_sticky_posts' => true,
				),
				1700000000,
				25
			)
			->reply(
				array(
					'post_type'           => array( 'post', 'page' ),
					'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'meta_key'            => ScheduleMeta::META_TIME,
					'meta_value'          => 1700000000,
					'meta_compare'        => '<=',
					'meta_type'           => 'NUMERIC',
					'orderby'             => 'meta_value_num',
					'order'               => 'ASC',
					'posts_per_page'      => 25,
					'fields'              => 'ids',
					'no_found_rows'       => false,
					'ignore_sticky_posts' => true,
				)
			);

		$args = SweepQuery::args( 1700000000, 25 );

		$this->assertSame(
			array(
				'post_type'           => array( 'post', 'page' ),
				'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'meta_key'            => '_aps_schedule_meta_time',
				'meta_value'          => 1700000000,
				'meta_compare'        => '<=',
				'meta_type'           => 'NUMERIC',
				'orderby'             => 'meta_value_num',
				'order'               => 'ASC',
				'posts_per_page'      => 25,
				'fields'              => 'ids',
				'no_found_rows'       => false,
				'ignore_sticky_posts' => true,
			),
			$args
		);
	}

	/**
	 * The aps_schedule_sweep_query_args filter can alter the assembled args.
	 *
	 * @covers ArchivedPostStatus\Schedule\SweepQuery::args
	 */
	public function test_args_filter_can_alter_the_query_args() {
		$this->stubSupportedPostTypesBoundary( array( 'post' ), array( 'post' ) );
		$this->stubArchivableStatusesBoundary( array( 'publish' ) );

		\WP_Mock::onFilter( 'aps_schedule_sweep_query_args' )
			->with(
				array(
					'post_type'           => array( 'post' ),
					'post_status'         => array( 'publish' ),
					'meta_key'            => ScheduleMeta::META_TIME,
					'meta_value'          => 1000,
					'meta_compare'        => '<=',
					'meta_type'           => 'NUMERIC',
					'orderby'             => 'meta_value_num',
					'order'               => 'ASC',
					'posts_per_page'      => 10,
					'fields'              => 'ids',
					'no_found_rows'       => false,
					'ignore_sticky_posts' => true,
				),
				1000,
				10
			)
			->reply( array( 'posts_per_page' => 999 ) );

		$args = SweepQuery::args( 1000, 10 );

		$this->assertSame( array( 'posts_per_page' => 999 ), $args );
	}

	// -----------------------------------------------------------------------
	// batch_size()
	// -----------------------------------------------------------------------

	/**
	 * The default batch size is 50 when nothing filters it.
	 *
	 * @covers ArchivedPostStatus\Schedule\SweepQuery::batch_size
	 */
	public function test_batch_size_defaults_to_fifty() {
		\WP_Mock::onFilter( 'aps_schedule_sweep_batch_size' )
			->with( 50 )
			->reply( 50 );

		$this->assertSame( 50, SweepQuery::batch_size() );
	}

	/**
	 * aps_schedule_sweep_batch_size can override the default.
	 *
	 * @covers ArchivedPostStatus\Schedule\SweepQuery::batch_size
	 */
	public function test_batch_size_filter_can_override_the_default() {
		\WP_Mock::onFilter( 'aps_schedule_sweep_batch_size' )
			->with( 50 )
			->reply( 200 );

		$this->assertSame( 200, SweepQuery::batch_size() );
	}
}
