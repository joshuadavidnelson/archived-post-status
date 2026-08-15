<?php
/**
 * Schedule\ScheduleSource Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\ScheduleSource
 *
 * Pins the three case values as a storage contract: ScheduleMeta persists
 * them verbatim to post meta, and aps_schedule_archive() hydrates them back
 * from a plain string at the public API boundary. A mutation to any of
 * these strings would silently break every existing '_aps_schedule_meta_source'
 * row on upgrade.
 */

use ArchivedPostStatus\Schedule\ScheduleSource;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\ScheduleSource
 */
class ScheduleSourceTest extends TestCase {

	public function test_manual_case_value_is_manual() {
		$this->assertSame( 'manual', ScheduleSource::Manual->value );
	}

	public function test_rule_case_value_is_rule() {
		$this->assertSame( 'rule', ScheduleSource::Rule->value );
	}

	public function test_exempt_case_value_is_exempt() {
		$this->assertSame( 'exempt', ScheduleSource::Exempt->value );
	}

	/**
	 * tryFrom() is how aps_schedule_archive() hydrates the public string
	 * boundary; an unrecognized value must resolve to null so the caller
	 * can fall back to Manual rather than silently accepting garbage.
	 */
	public function test_try_from_returns_null_for_unknown_value() {
		$this->assertNull( ScheduleSource::tryFrom( 'not-a-real-source' ) );
	}

	public function test_try_from_resolves_known_value_to_matching_case() {
		$this->assertSame( ScheduleSource::Rule, ScheduleSource::tryFrom( 'rule' ) );
	}
}
