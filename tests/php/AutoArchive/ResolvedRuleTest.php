<?php
/**
 * AutoArchive\ResolvedRule Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\ResolvedRule
 */

use ArchivedPostStatus\AutoArchive\ResolvedRule;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\ResolvedRule
 */
class ResolvedRuleTest extends TestCase {

	public function test_constructor_exposes_all_four_properties_readonly() {
		$resolved = new ResolvedRule( 3, 'term', 'Category: News', 'term' );

		$this->assertSame( 3, $resolved->days );
		$this->assertSame( 'term', $resolved->origin_level );
		$this->assertSame( 'Category: News', $resolved->origin_label );
		$this->assertSame( 'term', $resolved->frozen_by );
	}

	/**
	 * A fully-null ResolvedRule is how the resolver reports "nothing is
	 * auto-archived" — no level in the chain set a value.
	 */
	public function test_all_fields_are_independently_nullable() {
		$resolved = new ResolvedRule( null, null, null, null );

		$this->assertNull( $resolved->days );
		$this->assertNull( $resolved->origin_level );
		$this->assertNull( $resolved->origin_label );
		$this->assertNull( $resolved->frozen_by );
	}

	// -----------------------------------------------------------------------
	// is_scheduled()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\AutoArchive\ResolvedRule::is_scheduled
	 */
	public function test_is_scheduled_is_true_when_days_is_set() {
		$resolved = new ResolvedRule( 12, 'site', 'Site default', null );

		$this->assertTrue( $resolved->is_scheduled() );
	}

	/**
	 * @covers ArchivedPostStatus\AutoArchive\ResolvedRule::is_scheduled
	 */
	public function test_is_scheduled_is_false_when_days_is_null() {
		$resolved = new ResolvedRule( null, null, null, null );

		$this->assertFalse( $resolved->is_scheduled() );
	}

	/**
	 * A frozen level with no days set still reports "not scheduled" — the
	 * freeze stops the walk, it does not itself supply a value.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\ResolvedRule::is_scheduled
	 */
	public function test_is_scheduled_is_false_when_frozen_with_no_days() {
		$resolved = new ResolvedRule( null, null, null, 'network' );

		$this->assertFalse( $resolved->is_scheduled() );
	}
}
