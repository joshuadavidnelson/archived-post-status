<?php
/**
 * AutoArchive\MatchCountPreview Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\MatchCountPreview
 */

use ArchivedPostStatus\AutoArchive\MatchCountPreview;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\MatchCountPreview
 */
class MatchCountPreviewTest extends TestCase {

	use BoundaryStubs;

	/**
	 * Stub the two settings RuleQuery::candidates() reads directly.
	 */
	private function stubQuerySettings(): void {
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::onFilter( 'aps_auto_archive_age_basis' )->with( 'modified' )->reply( 'modified' );
	}

	/**
	 * count() reads found_posts from the query RuleQuery::candidates()'s
	 * args produce, with posts_per_page pinned to 1 -- a cheap count-only
	 * query, never fetching more than one row's worth of work.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\MatchCountPreview::count
	 */
	public function test_count_reads_found_posts_from_a_posts_per_page_one_query() {
		$this->stubArchivableStatusesBoundary();
		$this->stubQuerySettings();

		$captured_args = null;
		$preview       = new MatchCountPreview(
			static function ( array $args ) use ( &$captured_args ) {
				$captured_args = $args;

				return (object) array( 'posts' => array( 1 ), 'found_posts' => 8412 );
			}
		);

		$count = $preview->count( 5, 1700000000 );

		$this->assertSame( 8412, $count );
		$this->assertSame( 1, $captured_args['posts_per_page'] );
		$this->assertSame( 'ids', $captured_args['fields'] );
	}

	/**
	 * With no query_factory supplied, count() builds a real \WP_Query.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\MatchCountPreview::__construct
	 * @covers ArchivedPostStatus\AutoArchive\MatchCountPreview::count
	 */
	public function test_count_default_query_factory_constructs_a_real_wp_query() {
		$this->stubArchivableStatusesBoundary();
		$this->stubQuerySettings();

		$preview = new MatchCountPreview();

		$this->assertSame( 0, $preview->count( 5, 1700000000 ) );
	}
}
