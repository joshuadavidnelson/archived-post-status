<?php
/**
 * AutoArchive\ChildMode Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\ChildMode
 *
 * Pins the three case values as a storage contract: NetworkStore, Store, and
 * term meta all persist them verbatim (per the plan's §4.6 storage table), so
 * a mutation to any of these strings would silently break every existing
 * stored 'auto_archive_child_mode' row on upgrade.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\ChildMode
 */
class ChildModeTest extends TestCase {

	public function test_open_case_value_is_open() {
		$this->assertSame( 'open', ChildMode::Open->value );
	}

	public function test_locked_case_value_is_locked() {
		$this->assertSame( 'locked', ChildMode::Locked->value );
	}

	public function test_off_case_value_is_off() {
		$this->assertSame( 'off', ChildMode::Off->value );
	}

	public function test_try_from_returns_null_for_unknown_value() {
		$this->assertNull( ChildMode::tryFrom( 'not-a-real-mode' ) );
	}

	public function test_try_from_resolves_known_value_to_matching_case() {
		$this->assertSame( ChildMode::Locked, ChildMode::tryFrom( 'locked' ) );
	}

	// -----------------------------------------------------------------------
	// freezes_children() — Locked and Off both freeze; Open does not.
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\AutoArchive\ChildMode::freezes_children
	 */
	public function test_open_does_not_freeze_children() {
		$this->assertFalse( ChildMode::Open->freezes_children() );
	}

	/**
	 * @covers ArchivedPostStatus\AutoArchive\ChildMode::freezes_children
	 */
	public function test_locked_freezes_children() {
		$this->assertTrue( ChildMode::Locked->freezes_children() );
	}

	/**
	 * @covers ArchivedPostStatus\AutoArchive\ChildMode::freezes_children
	 */
	public function test_off_freezes_children() {
		$this->assertTrue( ChildMode::Off->freezes_children() );
	}
}
