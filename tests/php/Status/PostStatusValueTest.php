<?php
/**
 * Status\PostStatusValue Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Status\PostStatusValue
 *
 * centralises the bare-string slug literal `'archive'` that the procedural
 * layer scattered across the codebase. Cases are pinned here so Step 3B's
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
	 * String-backed enums round-trip through `tryFrom()` — site code that
	 * receives an arbitrary string from a query var, filter callback, etc.
	 * can normalise it into the enum surface without an explicit
	 * `in_array()`. Pin the contract end-to-end.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue
	 */
	public function test_try_from_round_trips_the_slug_string() {
		$this->assertSame( PostStatusValue::Slug, PostStatusValue::tryFrom( 'archive' ) );
		$this->assertNull( PostStatusValue::tryFrom( 'not-a-case' ) );
	}

	/**
	 * Belt-and-suspenders: the enum currently exposes exactly one case. A
	 * future case addition is a deliberate API change — this test forces the
	 * change to be made consciously rather than slipping in unnoticed.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue
	 */
	public function test_enum_has_one_case_only() {
		$cases = PostStatusValue::cases();

		$this->assertCount( 1, $cases );
		$this->assertSame( PostStatusValue::Slug, $cases[0] );
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

	/**
	 * §2.12 regression: 0.3.12's `aps_post_status_slug()` fell back to the
	 * default whenever the filter returned an empty/falsy value (see
	 * `git show stable:src/archived-post-status.php`). That fallback was
	 * dropped when the filter was lifted into this method — a filter
	 * callback returning `''` would register a broken, empty-slug status
	 * and every internal `$slug === $post->post_status` comparison would
	 * silently stop matching. This pins the restored fallback for the
	 * empty-string case.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue::resolved_slug
	 */
	public function test_resolved_slug_falls_back_to_default_when_filter_returns_empty_string() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( '' );

		$this->assertSame( 'archive', PostStatusValue::resolved_slug() );
	}

	/**
	 * §2.12: the fallback uses PHP's `empty()` semantics (matching
	 * 0.3.12), not a strict `'' === $slug` check — so a filter returning
	 * literal `false` (cast to the empty string) also falls back to the
	 * default rather than registering a status under a falsy non-string
	 * slug.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue::resolved_slug
	 */
	public function test_resolved_slug_falls_back_to_default_when_filter_returns_false() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( false );

		$this->assertSame( 'archive', PostStatusValue::resolved_slug() );
	}

	/**
	 * Coverage pin: a non-empty override consisting entirely of falsy-ish
	 * characters (e.g. the string `'0'`) is still `empty()` under PHP's
	 * rules, so it must fall back too — proving the fallback isn't
	 * accidentally narrowed to only the literal `''` case.
	 *
	 * @covers ArchivedPostStatus\Status\PostStatusValue::resolved_slug
	 */
	public function test_resolved_slug_falls_back_to_default_when_filter_returns_the_string_zero() {
		\WP_Mock::onFilter( 'aps_post_status_slug' )
			->with( 'archive' )
			->reply( '0' );

		$this->assertSame( 'archive', PostStatusValue::resolved_slug() );
	}
}
