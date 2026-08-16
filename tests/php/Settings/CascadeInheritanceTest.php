<?php
/**
 * Settings\CascadeInheritance Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\CascadeInheritance
 *
 * CascadeInheritance is exercised extensively as a plain value object from
 * CascadeFieldTest, but PHPUnit's `@covers` annotation attributes coverage
 * only to the class(es) a test declares — a dedicated file, mirroring
 * AutoArchive\RuleTest's precedent for an equally small value object, is
 * what makes this class's own coverage visible rather than an artifact of
 * whichever renderer test happens to construct it.
 */

use ArchivedPostStatus\Settings\CascadeInheritance;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\CascadeInheritance
 */
class CascadeInheritanceTest extends TestCase {

	/**
	 * @covers ArchivedPostStatus\Settings\CascadeInheritance::__construct
	 */
	public function test_constructor_exposes_all_five_properties_readonly() {
		$inheritance = new CascadeInheritance( 12, 'Site', true, false, 'Network' );

		$this->assertSame( 12, $inheritance->days );
		$this->assertSame( 'Site', $inheritance->origin_label );
		$this->assertTrue( $inheritance->locked );
		$this->assertFalse( $inheritance->off );
		$this->assertSame( 'Network', $inheritance->frozen_by_label );
	}

	/**
	 * none() is the top-of-chain shape: nothing inherited, nothing frozen.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeInheritance::none
	 */
	public function test_none_returns_the_top_of_chain_shape() {
		$inheritance = CascadeInheritance::none();

		$this->assertNull( $inheritance->days );
		$this->assertNull( $inheritance->origin_label );
		$this->assertFalse( $inheritance->locked );
		$this->assertFalse( $inheritance->off );
		$this->assertNull( $inheritance->frozen_by_label );
	}

	/**
	 * frozen() is true when either Locked or Off is set.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeInheritance::frozen
	 */
	public function test_frozen_true_when_locked() {
		$inheritance = new CascadeInheritance( 365, 'Network', true, false, 'Network' );

		$this->assertTrue( $inheritance->frozen() );
	}

	/**
	 * @covers ArchivedPostStatus\Settings\CascadeInheritance::frozen
	 */
	public function test_frozen_true_when_off() {
		$inheritance = new CascadeInheritance( 365, 'Network', false, true, 'Network' );

		$this->assertTrue( $inheritance->frozen() );
	}

	/**
	 * @covers ArchivedPostStatus\Settings\CascadeInheritance::frozen
	 */
	public function test_frozen_false_when_neither_locked_nor_off() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );

		$this->assertFalse( $inheritance->frozen() );
	}
}
