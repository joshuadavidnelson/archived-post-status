<?php
/**
 * Archive\ArchivableStatuses Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Archive\ArchivableStatuses
 *
 * Mirrors the facade tests in FunctionsTest
 * (`test_aps_archivable_statuses_default`, `test_aps_archivable_statuses_custom`)
 * but exercises the lifted SUT directly. Adds an `includes()` test for the
 * new convenience method (no direct procedural ancestor).
 */

use ArchivedPostStatus\Archive\ArchivableStatuses;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Archive\ArchivableStatuses
 */
class ArchivableStatusesTest extends TestCase {

	/**
	 * Default-path: with no filter override, the SUT returns the canonical
	 * list of statuses that may transition into the archive.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchivableStatuses::all
	 */
	public function test_all_returns_default_list_when_no_filter_registered() {
		\WP_Mock::expectFilter( 'aps_archivable_statuses', array( 'publish', 'future', 'draft', 'pending', 'private' ) );

		$result = ArchivableStatuses::all();

		$this->assertSame( array( 'publish', 'future', 'draft', 'pending', 'private' ), $result );
	}

	/**
	 * Filter-override path: `aps_archivable_statuses` can shrink or rename
	 * the set. The SUT applies `sanitize_key()` to each item (sanitization contract —
	 * slug-context normalisation, not HTML escaping).
	 *
	 * @covers ArchivedPostStatus\Archive\ArchivableStatuses::all
	 */
	public function test_all_honours_filter_override() {
		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
			->reply( array( 'publish', 'custom_status' ) );

		$result = ArchivableStatuses::all();

		$this->assertSame( array( 'publish', 'custom_status' ), $result );
	}

	/**
	 * Edge case: `sanitize_key()` strips uppercase / disallowed characters,
	 * matching the sanitization contract. Pin so a future change can't silently
	 * regress back to `esc_attr()` (HTML-context, which would let `<` etc.
	 * through into slug comparison).
	 *
	 * @covers ArchivedPostStatus\Archive\ArchivableStatuses::all
	 */
	public function test_all_sanitises_status_slugs_via_sanitize_key() {
		\WP_Mock::onFilter( 'aps_archivable_statuses' )
			->with( array( 'publish', 'future', 'draft', 'pending', 'private' ) )
			->reply( array( 'PUBLISH', 'cust om_stat<us', 'draft' ) );

		$result = ArchivableStatuses::all();

		// sanitize_key() lowercases + strips invalid chars; 'PUBLISH' → 'publish',
		// 'cust om_stat<us' → 'custom_status' (spaces + '<' stripped), 'draft' stays.
		$this->assertContains( 'publish', $result );
		$this->assertContains( 'draft', $result );
		$this->assertContains( 'custom_status', $result );
	}

	/**
	 * `includes()` returns true for slugs in the resolved set, false
	 * otherwise. Mirrors the shape of `SupportedPostTypes::includes()`.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchivableStatuses::includes
	 */
	public function test_includes_returns_true_for_listed_statuses_and_false_otherwise() {
		\WP_Mock::expectFilter( 'aps_archivable_statuses', array( 'publish', 'future', 'draft', 'pending', 'private' ) );

		$this->assertTrue( ArchivableStatuses::includes( 'publish' ) );
	}

	/**
	 * Sister to the above — false branch — kept as a separate test so the
	 * WP_Mock filter expectation is paired 1:1 with the call.
	 *
	 * @covers ArchivedPostStatus\Archive\ArchivableStatuses::includes
	 */
	public function test_includes_returns_false_for_unknown_status() {
		\WP_Mock::expectFilter( 'aps_archivable_statuses', array( 'publish', 'future', 'draft', 'pending', 'private' ) );

		$this->assertFalse( ArchivableStatuses::includes( 'archive' ) );
	}
}
