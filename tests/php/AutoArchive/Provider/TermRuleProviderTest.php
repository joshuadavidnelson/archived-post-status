<?php
/**
 * AutoArchive\Provider\TermRuleProvider Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider
 * @covers ArchivedPostStatus\AutoArchive\RuleChain
 *
 * The last test in this file exercises the §4.2 tie case end to end through
 * a REAL RuleChain over a REAL TermRuleProvider instance — not RuleReducer
 * called in isolation. No filter is explicitly registered for RuleChain's
 * own internal hooks (`aps_auto_archive_levels`, `aps_auto_archive_term_rule`,
 * `aps_auto_archive_term_rule_days`, `aps_auto_archive_term_rule_child_mode`,
 * `aps_auto_archive_rule_chain`, `aps_auto_archive_resolved_rule`): an
 * unregistered WP_Mock filter passes its value through unchanged, exactly
 * like a real, unhooked apply_filters() — see NetworkSiteCascadeTest's own
 * class docblock for the same technique. The two tie-break filters
 * themselves are pinned in isolation in RuleChainTest, against RuleChain
 * directly — this file's job is proving they compose correctly with a real
 * TermRuleProvider, not re-pinning their own behavior.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleChain;
use ArchivedPostStatus\AutoArchive\TermMeta;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider
 */
class TermRuleProviderTest extends TestCase {

	private TermRuleProvider $provider;

	public function set_up() {
		parent::set_up();
		$this->provider = new TermRuleProvider();
	}

	/**
	 * @param string[] $taxonomies
	 */
	private function stubTaxonomies( array $taxonomies ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( $taxonomies );
	}

	/**
	 * level() is literally 'term' -- the dynamic aps_auto_archive_{$level}_rule
	 * hook in RuleChain depends on this exact string.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::level
	 */
	public function test_level_is_literally_term() {
		$this->assertSame( 'term', $this->provider->level() );
	}

	/**
	 * No opted-in taxonomies -- rules_for() returns [] without ever reading
	 * the post's terms.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::rules_for
	 */
	public function test_rules_for_returns_empty_array_with_no_opted_in_taxonomies() {
		$this->stubTaxonomies( array() );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->never();

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * One term with a stored days value produces exactly one Rule, correctly
	 * populated and labeled "Category: News".
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::rules_for
	 */
	public function test_rules_for_returns_one_rule_per_term_that_sets_days() {
		$this->stubTaxonomies( array( 'category' ) );

		$news = $this->createMockTerm( array( 'term_id' => 10, 'name' => 'News', 'taxonomy' => 'category' ) );

		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'category' )
			->andReturn( array( $news ) );

		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_DAYS, true )
			->andReturn( '6' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_CHILD_MODE, true )
			->andReturn( 'locked' );

		\WP_Mock::userFunction( 'get_taxonomy' )
			->with( 'category' )
			->andReturn( (object) array( 'labels' => (object) array( 'singular_name' => 'Category' ) ) );

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertInstanceOf( Rule::class, $rules[0] );
		$this->assertSame( 'term', $rules[0]->level );
		$this->assertSame( 6, $rules[0]->days );
		$this->assertSame( ChildMode::Locked, $rules[0]->child_mode );
		$this->assertSame( 'Category: News', $rules[0]->label );
	}

	/**
	 * A term in a taxonomy that is not opted in is never even queried --
	 * wp_get_post_terms() is called only for the opted-in taxonomies list.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::rules_for
	 */
	public function test_rules_for_ignores_terms_in_non_opted_in_taxonomies() {
		$this->stubTaxonomies( array( 'category' ) );

		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'category' )
			->andReturn( array() );
		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'post_tag' )
			->never();

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * A term with no meta at all -- both keys absent -- contributes no Rule.
	 * This is what makes "never configured" indistinguishable from "not
	 * present in the chain" rather than producing a Rule with null days and
	 * Open child_mode that would need special-case handling downstream.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::rules_for
	 */
	public function test_rules_for_ignores_terms_with_no_meta() {
		$this->stubTaxonomies( array( 'category' ) );

		$uncategorized = $this->createMockTerm( array( 'term_id' => 1, 'name' => 'Uncategorized' ) );

		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'category' )
			->andReturn( array( $uncategorized ) );

		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 1, TermMeta::META_DAYS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 1, TermMeta::META_CHILD_MODE, true )
			->andReturn( '' );

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * A term that sets ONLY a child_mode (no days value) still produces a
	 * Rule -- the freeze is a meaningful contribution on its own, independent
	 * of whether the term also supplies a days value (plan §4.2).
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::rules_for
	 */
	public function test_rules_for_returns_a_rule_for_a_term_that_only_sets_child_mode() {
		$this->stubTaxonomies( array( 'category' ) );

		$term = $this->createMockTerm( array( 'term_id' => 11, 'name' => 'Locked Down' ) );

		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'category' )
			->andReturn( array( $term ) );

		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 11, TermMeta::META_DAYS, true )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 11, TermMeta::META_CHILD_MODE, true )
			->andReturn( 'off' );

		\WP_Mock::userFunction( 'get_taxonomy' )
			->with( 'category' )
			->andReturn( (object) array( 'labels' => (object) array( 'singular_name' => 'Category' ) ) );

		$rules = $this->provider->rules_for( 42 );

		$this->assertCount( 1, $rules );
		$this->assertNull( $rules[0]->days );
		$this->assertSame( ChildMode::Off, $rules[0]->child_mode );
	}

	/**
	 * A misbehaving `wp_get_post_terms()` return (a WP_Error object in
	 * production, anything not an array) is tolerated as "no terms" rather
	 * than fataling. `false` stands in for the WP_Error case here since this
	 * isolated-unit-test runtime does not load WordPress's own class.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::rules_for
	 */
	public function test_rules_for_tolerates_a_non_array_wp_get_post_terms_return() {
		$this->stubTaxonomies( array( 'category' ) );

		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'category' )
			->andReturn( false );

		$this->assertSame( array(), $this->provider->rules_for( 42 ) );
	}

	/**
	 * THE §4.2 CASE, BY NAME, END TO END THROUGH THE REAL CHAIN. A post in
	 * News (6 days, Locked) and Features (3 days, Open) resolves to 3 days,
	 * frozen -- the strictest policy present governs both the value and the
	 * freeze, independently, per the plan's own worked example. Exercised
	 * through a real RuleChain over a real TermRuleProvider, which internally
	 * calls the real RuleReducer -- not RuleReducer asserted in isolation.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::rules_for
	 * @covers ArchivedPostStatus\AutoArchive\RuleChain::resolve_for
	 */
	public function test_news_locked_six_days_and_features_open_three_days_resolves_to_three_frozen_through_the_real_chain() {
		$this->stubTaxonomies( array( 'category' ) );

		$news     = $this->createMockTerm( array( 'term_id' => 10, 'name' => 'News' ) );
		$features = $this->createMockTerm( array( 'term_id' => 20, 'name' => 'Features' ) );

		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'category' )
			->andReturn( array( $news, $features ) );

		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_DAYS, true )->andReturn( '6' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, TermMeta::META_CHILD_MODE, true )->andReturn( 'locked' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 20, TermMeta::META_DAYS, true )->andReturn( '3' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 20, TermMeta::META_CHILD_MODE, true )->andReturn( 'open' );

		\WP_Mock::userFunction( 'get_taxonomy' )
			->with( 'category' )
			->andReturn( (object) array( 'labels' => (object) array( 'singular_name' => 'Category' ) ) );

		$chain    = new RuleChain( array( new TermRuleProvider() ) );
		$resolved = $chain->resolve_for( 42 );

		$this->assertSame( 3, $resolved->days, 'The soonest days value among applicable terms wins.' );
		$this->assertSame( 'term', $resolved->frozen_by, 'The strictest child_mode present (Locked, from News) freezes the term level itself.' );
	}
}
