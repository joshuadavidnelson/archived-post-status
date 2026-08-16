<?php
/**
 * AutoArchive\TermMeta Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\TermMeta
 *
 * Tests the readonly value object that centralizes the two term meta keys a
 * term's own cascade rule lives in. Verifies the null-vs-zero distinction on
 * for_term() (an unset days row must never read as 0), the delete-on-null
 * write behavior of save(), and that delete() removes both keys.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\TermMeta;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\TermMeta
 */
class TermMetaTest extends TestCase {

	/**
	 * An unset days row (WordPress's `''` return from
	 * `get_term_meta( …, true )`) reads as null, not 0 — the null-vs-zero
	 * hazard the class docblock documents.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\TermMeta::for_term
	 */
	public function test_for_term_reads_unset_days_as_null_not_zero() {
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_DAYS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_CHILD_MODE, true )
			->andReturn( '' );

		$meta = TermMeta::for_term( 10 );

		$this->assertNull( $meta->days );
		$this->assertSame( ChildMode::Open, $meta->child_mode );
	}

	/**
	 * A term with a genuine stored days value of 0 reads as 0, not null —
	 * the other half of the null-vs-zero distinction.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\TermMeta::for_term
	 */
	public function test_for_term_reads_a_stored_zero_as_zero_not_null() {
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_DAYS, true )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_CHILD_MODE, true )
			->andReturn( 'open' );

		$meta = TermMeta::for_term( 10 );

		$this->assertSame( 0, $meta->days );
	}

	/**
	 * A fully configured term reads both keys correctly.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\TermMeta::for_term
	 */
	public function test_for_term_reads_both_keys_when_present() {
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_DAYS, true )
			->andReturn( '6' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_CHILD_MODE, true )
			->andReturn( 'locked' );

		$meta = TermMeta::for_term( 10 );

		$this->assertSame( 6, $meta->days );
		$this->assertSame( ChildMode::Locked, $meta->child_mode );
	}

	/**
	 * An unrecognized stored child_mode value falls back to Open rather than
	 * fataling on a null enum — same defensive shape as every other
	 * ChildMode-hydrating reader in this release.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\TermMeta::for_term
	 */
	public function test_for_term_falls_back_to_open_for_an_unrecognized_child_mode() {
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_DAYS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_CHILD_MODE, true )
			->andReturn( 'not-a-real-mode' );

		$meta = TermMeta::for_term( 10 );

		$this->assertSame( ChildMode::Open, $meta->child_mode );
	}

	/**
	 * save() with a non-null days value writes both keys via
	 * update_term_meta() and never deletes the days row.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\TermMeta::save
	 */
	public function test_save_writes_both_keys_when_days_is_set() {
		$meta = new TermMeta( 3, ChildMode::Open );

		\WP_Mock::userFunction( 'update_term_meta' )
			->once()
			->with( 10, TermMeta::META_DAYS, 3 )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_term_meta' )
			->once()
			->with( 10, TermMeta::META_CHILD_MODE, 'open' )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_term_meta' )->never();

		$meta->save( 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * save() with a null days value deletes the days row instead of storing
	 * an empty string, so a stale row can never masquerade as a stored 0 —
	 * the property {@see test_for_term_reads_a_stored_zero_as_zero_not_null()}
	 * depends on holding.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\TermMeta::save
	 */
	public function test_save_deletes_the_days_row_when_days_is_null() {
		$meta = new TermMeta( null, ChildMode::Locked );

		\WP_Mock::userFunction( 'delete_term_meta' )
			->once()
			->with( 10, TermMeta::META_DAYS )
			->andReturn( true );
		\WP_Mock::userFunction( 'update_term_meta' )
			->once()
			->with( 10, TermMeta::META_CHILD_MODE, 'locked' )
			->andReturn( true );

		$meta->save( 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * delete() removes both term meta keys.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\TermMeta::delete
	 */
	public function test_delete_removes_both_keys() {
		$meta = new TermMeta( 3, ChildMode::Open );

		\WP_Mock::userFunction( 'delete_term_meta' )
			->once()
			->with( 10, TermMeta::META_DAYS )
			->andReturn( true );
		\WP_Mock::userFunction( 'delete_term_meta' )
			->once()
			->with( 10, TermMeta::META_CHILD_MODE )
			->andReturn( true );

		$meta->delete( 10 );

		$this->addToAssertionCount( 1 );
	}
}
