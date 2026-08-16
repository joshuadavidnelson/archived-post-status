<?php
/**
 * AutoArchive\Provider\PostRuleProvider Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\AutoArchive\Rule;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider
 */
class PostRuleProviderTest extends TestCase {

	private PostRuleProvider $provider;

	public function set_up() {
		parent::set_up();
		$this->provider = new PostRuleProvider();
	}

	/**
	 * level() is literally 'post' -- the dynamic aps_auto_archive_{$level}_rule
	 * hook in RuleChain depends on this exact string.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::level
	 */
	public function test_level_is_literally_post() {
		$this->assertSame( 'post', $this->provider->level() );
	}

	/**
	 * No override stored at all -- get_post_meta()'s "no such row" empty
	 * string reads as "this post sets nothing", never a stored `0`.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::rules_for
	 */
	public function test_rules_for_returns_empty_array_with_no_override() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, PostRuleProvider::META_DAYS, true )
			->andReturn( '' );

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * A stored override produces exactly one Rule, carrying ChildMode::Open
	 * -- the post level has no children of its own, so this is a convention
	 * of the level, not a value that was ever written.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::rules_for
	 */
	public function test_rules_for_returns_one_rule_with_the_override_value() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, PostRuleProvider::META_DAYS, true )
			->andReturn( '6' );

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertInstanceOf( Rule::class, $rules[0] );
		$this->assertSame( 'post', $rules[0]->level );
		$this->assertSame( 6, $rules[0]->days );
		$this->assertSame( ChildMode::Open, $rules[0]->child_mode );
		$this->assertSame( 'Post override', $rules[0]->label );
	}

	/**
	 * A post that has explicitly stored a `0`-day override is distinct from
	 * one that has never set anything at all -- the same null-vs-zero
	 * hazard {@see \ArchivedPostStatus\AutoArchive\TermMeta} and the site
	 * level's own `auto_archive_days` already guard against. `0` is a
	 * meaningful value here (archive immediately), not "unset".
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::rules_for
	 */
	public function test_rules_for_treats_a_stored_zero_as_a_real_override_not_unset() {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, PostRuleProvider::META_DAYS, true )
			->andReturn( '0' );

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertSame( 0, $rules[0]->days );
	}
}
