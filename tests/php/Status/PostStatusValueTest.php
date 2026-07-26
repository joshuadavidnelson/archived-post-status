<?php
/**
 * Status\PostStatusValue Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Status\PostStatusValue
 *
 * Phase 3A extraction (0.4.0): centralises the bare-string vocabulary items
 * `'archive'` (slug) and `'Archived'` (label) that the procedural layer
 * scattered across the codebase. Cases are pinned here so Step 3B's
 * literal-replacement work can lean on a stable enum surface.
 */

use ArchivedPostStatus\Status\PostStatusValue;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Status\PostStatusValue
 */
class PostStatusValueTest extends TestCase {

	/**
	 * The Slug case carries the canonical default registered slug. WP core's
	 * register_post_status() expects this string; sites that override via
	 * `aps_post_status_slug` still feed this default into the filter.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue
	 */
	public function test_slug_case_value_is_archive() {
		$this->assertSame( 'archive', PostStatusValue::Slug->value );
	}

	/**
	 * The Label case carries the canonical English source string used by the
	 * `aps_archived_label_string` filter's `__()` call. Translation tooling
	 * extracts the literal `'Archived'` from the codebase; the enum exposes
	 * the same string so Step 3B can replace literal usages without changing
	 * the gettext source set.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue
	 */
	public function test_label_case_value_is_archived() {
		$this->assertSame( 'Archived', PostStatusValue::Label->value );
	}

	/**
	 * String-backed enums round-trip through `tryFrom()` — site code that
	 * receives an arbitrary string from a query var, filter callback, etc.
	 * can normalise it into the enum surface without an explicit
	 * `in_array()`. Pin the contract end-to-end.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue
	 */
	public function test_try_from_round_trips_the_slug_string() {
		$this->assertSame( PostStatusValue::Slug, PostStatusValue::tryFrom( 'archive' ) );
		$this->assertSame( PostStatusValue::Label, PostStatusValue::tryFrom( 'Archived' ) );
		$this->assertNull( PostStatusValue::tryFrom( 'not-a-case' ) );
	}

	/**
	 * Belt-and-suspenders: the enum currently exposes exactly two cases. A
	 * future case addition is a deliberate API change — this test forces the
	 * change to be made consciously rather than slipping in unnoticed.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue
	 */
	public function test_enum_has_two_cases_only() {
		$cases = PostStatusValue::cases();

		$this->assertCount( 2, $cases );
		$this->assertSame( PostStatusValue::Slug, $cases[0] );
		$this->assertSame( PostStatusValue::Label, $cases[1] );
	}

	/**
	 * Without an `aps_post_status_slug` filter callback in place, the runtime
	 * accessor returns the canonical default — `Slug->value` (`'archive'`).
	 * Pins the contract that the default reaches the consumer unchanged when
	 * no override is registered.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue::resolved_slug
	 */
	public function test_resolved_slug_returns_default_archive_when_no_filter() {
		$this->assertSame( 'archive', PostStatusValue::resolved_slug() );
	}

	/**
	 * The `aps_post_status_slug` filter is consulted at every call — sites
	 * that registered the status under a custom slug ('archived', etc.) see
	 * the override threaded through to every consumer (status comparisons,
	 * wp_update_post calls, register_post_status). This is the slug-leak fix:
	 * stable 0.3.x applied the filter only at registration; 0.4.0's lifted
	 * vocabulary class consults it at every internal site.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue::resolved_slug
	 */
	public function test_resolved_slug_applies_aps_post_status_slug_filter() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( 'archived' );

		$this->assertSame( 'archived', PostStatusValue::resolved_slug() );
	}
}
