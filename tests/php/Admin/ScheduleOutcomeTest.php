<?php
/**
 * Admin\ScheduleOutcome Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ScheduleOutcome
 *
 * Every case exercises `describe()` end to end, through the REAL
 * `aps_get_scheduled_archive_time()` / `aps_get_auto_archive_rule()` public
 * API and a REAL four-provider RuleChain — not a hand-built ResolvedRule —
 * so this suite is what pins the exact plain-language wording an editor
 * actually sees on both the classic metabox and the block editor panel, and
 * that `origin_label` (the "and why" half, per the phase brief) actually
 * appears in the rendered line.
 */

use ArchivedPostStatus\Admin\ScheduleOutcome;
use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\Schedule\ScheduleMeta;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Admin\ScheduleOutcome
 */
class ScheduleOutcomeTest extends TestCase {

	/**
	 * Stub the cascade down to "nothing set at any level" — network,
	 * site, term, and post all contribute nothing.
	 *
	 * @param int $post_id
	 */
	private function stubNothingScheduledOrResolved( int $post_id ): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( $post_id, 'category' )->andReturn( array() );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, PostRuleProvider::META_DAYS, true )->andReturn( '' );
	}

	/**
	 * Stub a post with no schedule record at all (ScheduleMeta::for_post()
	 * returns null).
	 *
	 * @param int $post_id
	 */
	private function stubNoScheduleRecord( int $post_id ): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_SOURCE, true )->andReturn( '' );
	}

	/**
	 * Stub a full ScheduleMeta record with the given source/time.
	 *
	 * @param int    $post_id
	 * @param string $source
	 * @param int    $time
	 */
	private function stubScheduleRecord( int $post_id, string $source, int $time ): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_SOURCE, true )->andReturn( $source );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_TIME, true )->andReturn( (string) $time );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_USER, true )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );
	}

	/**
	 * Stub wp_date()/get_option() so ScheduleTime::to_display() returns a
	 * deterministic, easily-asserted string rather than a real formatted date.
	 *
	 * @param int    $timestamp
	 * @param string $display
	 */
	private function stubDisplayDate( int $timestamp, string $display ): void {
		\WP_Mock::userFunction( 'get_option' )->with( 'date_format' )->andReturn( 'Y-m-d' );
		\WP_Mock::userFunction( 'get_option' )->with( 'time_format' )->andReturn( 'H:i' );
		\WP_Mock::userFunction( 'wp_date' )->with( 'Y-m-d H:i', $timestamp )->andReturn( $display );
	}

	/**
	 * No schedule, no cascade rule: the plain "not scheduled" line.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleOutcome::describe
	 */
	public function test_describe_reports_not_scheduled_when_nothing_applies() {
		$this->stubNoScheduleRecord( 42 );
		$this->stubNothingScheduledOrResolved( 42 );

		$this->assertSame( 'Not scheduled to archive.', ScheduleOutcome::describe( 42 ) );
	}

	/**
	 * A manually-set date is described as manual, with no cascade text --
	 * an editor's direct instruction, not a rule outcome.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleOutcome::describe
	 */
	public function test_describe_reports_a_manual_schedule() {
		$this->stubScheduleRecord( 42, 'manual', 1700000000 );
		$this->stubDisplayDate( 1700000000, '3 March 2027, 10:00 am' );

		$this->assertSame(
			'Archiving on 3 March 2027, 10:00 am (set manually).',
			ScheduleOutcome::describe( 42 )
		);
	}

	/**
	 * A rule-stamped schedule whose CURRENT cascade resolution still agrees
	 * shows the payoff line the plan's §5.9 example is built around: the
	 * date, the origin label ("and why"), and the day count.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleOutcome::describe
	 */
	public function test_describe_reports_a_rule_stamped_schedule_with_origin_label_and_days() {
		$this->stubScheduleRecord( 42, 'rule', 1700000000 );
		$this->stubDisplayDate( 1700000000, '3 March 2027' );

		// The post's own override is the most specific level with a value.
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, PostRuleProvider::META_DAYS, true )->andReturn( '3' );

		$this->assertSame(
			'Auto archive: 3 March 2027 — from Post override (3 days).',
			ScheduleOutcome::describe( 42 )
		);
	}

	/**
	 * A rule-stamped schedule whose cascade no longer resolves for this post
	 * (the rule was removed/disabled since the stamp) falls back to the
	 * date alone -- the stale-refresh pass, not this line, is what corrects
	 * the stored date.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleOutcome::describe
	 */
	public function test_describe_reports_a_rule_stamped_schedule_whose_rule_no_longer_resolves() {
		$this->stubScheduleRecord( 42, 'rule', 1700000000 );
		$this->stubDisplayDate( 1700000000, '3 March 2027' );
		$this->stubNothingScheduledOrResolved( 42 );

		$this->assertSame(
			'Archiving on 3 March 2027 (from an automatic-archive rule).',
			ScheduleOutcome::describe( 42 )
		);
	}

	/**
	 * A tombstoned (exempt) post is described distinctly, without ever
	 * consulting the cascade.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleOutcome::describe
	 */
	public function test_describe_reports_exempt_without_consulting_the_cascade() {
		// A tombstone: source = exempt, time dropped (0).
		$this->stubScheduleRecord( 42, 'exempt', 0 );

		\WP_Mock::userFunction( 'is_multisite' )->never();
		\WP_Mock::userFunction( 'wp_get_post_terms' )->never();

		$this->assertSame( 'Exempt from automatic archiving.', ScheduleOutcome::describe( 42 ) );
	}

	/**
	 * Nothing stamped yet, but the cascade currently resolves to a rule for
	 * this post -- described as pending, not yet a firm date, with the
	 * origin label.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleOutcome::describe
	 */
	public function test_describe_reports_a_pending_rule_when_nothing_is_stamped_yet() {
		$this->stubNoScheduleRecord( 42 );

		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'category' )->andReturn( array() );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, PostRuleProvider::META_DAYS, true )->andReturn( '6' );

		$this->assertSame(
			'Will auto archive 6 days after publish/modified — from Post override, once the schedule is next applied.',
			ScheduleOutcome::describe( 42 )
		);
	}
}
