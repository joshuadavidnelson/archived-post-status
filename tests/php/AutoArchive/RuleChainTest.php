<?php
/**
 * AutoArchive\RuleChain Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RuleChain
 *
 * RuleChain is the WordPress boundary the pure phase-3 resolver core (Rule,
 * ResolvedRule, RuleReducer, RuleResolver) never touches. These tests use
 * fake RuleProviderInterface implementations -- anonymous classes returning
 * canned Rule[] arrays -- rather than Mockery doubles wherever a fixed
 * return value is all a test needs.
 *
 * WP_Mock::onFilter()->with() matches an apply_filters() call's arguments
 * by exact value; for an object argument that means identity
 * (spl_object_hash), not field-equality (verified directly against the
 * vendored WP_Mock source: Hook::safe_offset() hashes any non-scalar,
 * non-null object by identity, and Filter::apply() does a plain
 * isset()-on-that-key lookup with no matcher support -- confirmed
 * empirically that WP_Mock\Matcher\AnyInstance does not bridge this gap for
 * apply_filters(), unlike Mockery's own argument matchers). Most filters
 * here are matched by registering against object references this suite
 * itself constructs and hands to a fake provider, so the "reduced" object a
 * production filter receives is exactly one this test already holds.
 * RuleReducer::reduce() always constructs a brand-new Rule for any
 * *non-empty* rules_for() array, so wherever a test needs to intercept a
 * specific level's filter precisely, it gives that level an EMPTY
 * rules_for() -- reduce() returns the literal `null` for an empty array
 * without allocating anything, and `null` is a value WP_Mock matches
 * exactly. A provider's replacement Rule, once injected this way, is then a
 * reference the test does hold, and downstream filters (e.g.
 * aps_auto_archive_rule_chain) can be matched against it precisely.
 *
 * The one filter this technique cannot reach is aps_auto_archive_resolved_rule
 * itself: RuleResolver::resolve() unconditionally constructs a fresh
 * ResolvedRule with no injection point, so no test can hold a reference to
 * it in advance. {@see forceFilterReply()} documents and works around that
 * one case.
 *
 * The headline deliverable is
 * test_aps_auto_archive_rule_chain_lets_a_third_party_add_a_level_no_provider_produced()
 * -- the plan's §4.5 promise that a level can be added with no core change
 * and no new resolver.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\ResolvedRule;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleChain;
use ArchivedPostStatus\AutoArchive\RuleProviderInterface;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleChain
 */
class RuleChainTest extends TestCase {

	/**
	 * A fake provider returning a fixed Rule[] for every post -- these
	 * tests are not exercising per-post logic inside a provider, only how
	 * RuleChain consumes what one returns.
	 *
	 * @param string $level The level this provider reports.
	 * @param Rule[] $rules What rules_for() always returns.
	 */
	private function fakeProvider( string $level, array $rules ): RuleProviderInterface {
		return new class( $level, $rules ) implements RuleProviderInterface {
			public function __construct( private string $level, private array $rules ) {}

			public function level(): string {
				return $this->level;
			}

			public function rules_for( int $post_id ): array {
				return $this->rules;
			}
		};
	}

	/**
	 * Force a filter to reply with whatever $reply computes, regardless of
	 * the arguments a real apply_filters() call passes it.
	 *
	 * Only test_aps_auto_archive_resolved_rule_can_override_the_final_answer_wholesale()
	 * needs this -- see the class docblock for why WP_Mock::onFilter()->with()
	 * cannot intercept that one filter by normal means (its value argument
	 * is a ResolvedRule RuleResolver::resolve() constructs fresh every call,
	 * so no test can hold a reference to match against in advance). This
	 * injects a minimal WP_Mock\Filter subclass directly into WP_Mock's
	 * EventManager via reflection, bypassing the exact-value lookup
	 * entirely. Every other filter in this suite is matched through
	 * WP_Mock's ordinary with()/reply() API against real references this
	 * test already holds -- this helper exists for the one case that isn't
	 * possible that way, not as a general-purpose substitute for it.
	 *
	 * @param string   $tag   The filter hook name.
	 * @param callable $reply Receives the real apply_filters() arguments,
	 *                        returns the value apply_filters() reports.
	 */
	private function forceFilterReply( string $tag, callable $reply ): void {
		$filter = new class( $tag, $reply ) extends \WP_Mock\Filter {
			private $reply;

			public function __construct( string $name, callable $reply ) {
				parent::__construct( $name );
				$this->reply = $reply;
			}

			public function apply( $args ) {
				return ( $this->reply )( ...$args );
			}
		};

		$event_manager_prop = ( new \ReflectionClass( \WP_Mock::class ) )->getProperty( 'event_manager' );
		$event_manager_prop->setAccessible( true );
		$manager = $event_manager_prop->getValue();

		$filters_prop = ( new \ReflectionClass( $manager ) )->getProperty( 'filters' );
		$filters_prop->setAccessible( true );
		$filters         = $filters_prop->getValue( $manager );
		$filters[ $tag ] = $filter;
		$filters_prop->setValue( $manager, $filters );
	}

	// -----------------------------------------------------------------------
	// Providers are consulted in order; the resolved outcome matches what
	// RuleResolver would give for that chain directly. No filter is
	// registered in these -- an unregistered WP_Mock filter passes its
	// value through unchanged, exactly like a real, unhooked apply_filters().
	// -----------------------------------------------------------------------

	public function test_resolve_for_matches_phase_3_resolver_for_the_assembled_chain() {
		$chain = new RuleChain(
			array(
				$this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) ),
				$this->fakeProvider( 'term', array( new Rule( 'term', 3, ChildMode::Open, 'Category: News' ) ) ),
				$this->fakeProvider( 'post', array( new Rule( 'post', 6, ChildMode::Open, 'Post override' ) ) ),
			)
		);

		$resolved = $chain->resolve_for( 42 );

		$this->assertSame( 6, $resolved->days );
		$this->assertSame( 'post', $resolved->origin_level );
		$this->assertSame( 'Post override', $resolved->origin_label );
		$this->assertNull( $resolved->frozen_by );
	}

	public function test_resolve_for_honors_a_locked_level_freezing_everything_below_it() {
		$chain = new RuleChain(
			array(
				$this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) ),
				$this->fakeProvider( 'term', array( new Rule( 'term', 3, ChildMode::Locked, 'Category: News' ) ) ),
				$this->fakeProvider( 'post', array( new Rule( 'post', 6, ChildMode::Open, 'Post override' ) ) ),
			)
		);

		$resolved = $chain->resolve_for( 42 );

		$this->assertSame( 3, $resolved->days );
		$this->assertSame( 'term', $resolved->origin_level );
		$this->assertSame( 'term', $resolved->frozen_by );
	}

	/**
	 * Confirms providers are consulted general -> specific, not merely that
	 * the final answer happens to match -- Mockery doubles assert they were
	 * each actually called exactly once, and the outcome (term, the later
	 * provider, wins over site) proves the order the constructor was given
	 * governs precedence.
	 */
	public function test_resolve_for_consults_providers_in_constructor_order() {
		$site = \Mockery::mock( RuleProviderInterface::class );
		$site->shouldReceive( 'level' )->andReturn( 'site' );
		$site->shouldReceive( 'rules_for' )->once()->with( 7 )
			->andReturn( array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) );

		$term = \Mockery::mock( RuleProviderInterface::class );
		$term->shouldReceive( 'level' )->andReturn( 'term' );
		$term->shouldReceive( 'rules_for' )->once()->with( 7 )
			->andReturn( array( new Rule( 'term', 3, ChildMode::Open, 'Category: News' ) ) );

		$resolved = ( new RuleChain( array( $site, $term ) ) )->resolve_for( 7 );

		// term is more specific than site, so it wins -- proving the term
		// provider's contribution was actually consulted and placed after
		// site's, not merely registered.
		$this->assertSame( 3, $resolved->days );
		$this->assertSame( 'term', $resolved->origin_level );
	}

	// -----------------------------------------------------------------------
	// A provider returning many rules (the term case) is reduced via
	// RuleReducer before it ever enters the chain.
	// -----------------------------------------------------------------------

	public function test_a_provider_returning_many_rules_is_reduced_before_entering_the_chain() {
		// News: 6 days, Locked. Features: 3 days, Open. Per §4.2 this
		// reduces to 3 days, Locked -- min days, strictest mode, independent
		// of each other. If RuleChain forwarded the raw array instead of
		// reducing it, RuleReducer::reduce() would never have run and this
		// assertion would fail.
		$term = $this->fakeProvider(
			'term',
			array(
				new Rule( 'term', 6, ChildMode::Locked, 'Category: News' ),
				new Rule( 'term', 3, ChildMode::Open, 'Category: Features' ),
			)
		);

		$resolved = ( new RuleChain( array( $term ) ) )->resolve_for( 42 );

		$this->assertSame( 3, $resolved->days );
		$this->assertSame( 'term', $resolved->frozen_by );
		$this->assertSame( 'Category: Features', $resolved->origin_label );
	}

	// -----------------------------------------------------------------------
	// §5.11: aps_auto_archive_term_rule_days and
	// aps_auto_archive_term_rule_child_mode let a site override either half
	// of the term level's §4.2 tie-break independently -- the generic
	// aps_auto_archive_term_rule filter only ever sees the two already
	// combined into one Rule, so it cannot express "change the days
	// tie-break without also touching the freeze" or the reverse.
	// -----------------------------------------------------------------------

	public function test_aps_auto_archive_term_rule_days_overrides_just_the_days_half_of_the_tie() {
		// News: 6d Locked. Features: 3d Open. §4.2 alone resolves this to
		// 3d, Locked. The filter replaces only the days half with 1 -- if
		// the freeze half were reachable from here too, this test could not
		// tell the difference from the child_mode filter below.
		\WP_Mock::onFilter( 'aps_auto_archive_term_rule_days' )->with( 3, 42 )->reply( 1 );

		$term = $this->fakeProvider(
			'term',
			array(
				new Rule( 'term', 6, ChildMode::Locked, 'Category: News' ),
				new Rule( 'term', 3, ChildMode::Open, 'Category: Features' ),
			)
		);

		$resolved = ( new RuleChain( array( $term ) ) )->resolve_for( 42 );

		$this->assertSame( 1, $resolved->days );
		// The freeze half is untouched by this filter -- still frozen by
		// the term level, per News's Locked mode.
		$this->assertSame( 'term', $resolved->frozen_by );
	}

	public function test_aps_auto_archive_term_rule_child_mode_overrides_just_the_freeze_half_of_the_tie() {
		// Both terms Open -- §4.2 alone resolves this to Open, unfrozen.
		// The filter forces the freeze half to Locked without touching the
		// days half, which the generic per-level filter cannot express.
		\WP_Mock::onFilter( 'aps_auto_archive_term_rule_child_mode' )->with( ChildMode::Open, 42 )->reply( ChildMode::Locked );

		$term = $this->fakeProvider(
			'term',
			array(
				new Rule( 'term', 6, ChildMode::Open, 'Category: News' ),
				new Rule( 'term', 3, ChildMode::Open, 'Category: Features' ),
			)
		);

		$resolved = ( new RuleChain( array( $term ) ) )->resolve_for( 42 );

		// The days half is untouched -- still 3, the §4.2 minimum.
		$this->assertSame( 3, $resolved->days );
		$this->assertSame( 'term', $resolved->frozen_by );
	}

	public function test_term_tie_filters_do_not_fire_for_a_non_term_level() {
		// Registered against the site level's reduced Rule; if this class
		// fired the term-only filters regardless of $level, this would
		// intercept the site level's value too and the assertion below
		// would see 999 instead of 12.
		\WP_Mock::onFilter( 'aps_auto_archive_term_rule_days' )->with( 12, 42 )->reply( 999 );

		$resolved = ( new RuleChain(
			array( $this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) ) )
		) )->resolve_for( 42 );

		$this->assertSame( 12, $resolved->days );
	}

	public function test_a_non_int_reply_from_aps_auto_archive_term_rule_days_falls_back_to_null_not_a_fatal() {
		\WP_Mock::onFilter( 'aps_auto_archive_term_rule_days' )->with( 3, 42 )->reply( 'not an int' );

		$resolved = ( new RuleChain(
			array( $this->fakeProvider( 'term', array( new Rule( 'term', 3, ChildMode::Open, 'Category: News' ) ) ) )
		) )->resolve_for( 42 );

		$this->assertNull( $resolved->days );
	}

	public function test_a_non_childmode_reply_from_aps_auto_archive_term_rule_child_mode_falls_back_to_the_reduced_value() {
		\WP_Mock::onFilter( 'aps_auto_archive_term_rule_child_mode' )->with( ChildMode::Locked, 42 )->reply( 'not a ChildMode' );

		$resolved = ( new RuleChain(
			array(
				$this->fakeProvider( 'term', array( new Rule( 'term', 3, ChildMode::Locked, 'Category: News' ) ) ),
				$this->fakeProvider( 'post', array( new Rule( 'post', 9, ChildMode::Open, 'Post override' ) ) ),
			)
		) )->resolve_for( 42 );

		// The junk reply is discarded in favour of the reduced Locked mode,
		// which still freezes the post level below it.
		$this->assertSame( 3, $resolved->days );
		$this->assertSame( 'term', $resolved->frozen_by );
	}

	// -----------------------------------------------------------------------
	// A provider returning an empty array contributes nothing and does not
	// break the walk.
	// -----------------------------------------------------------------------

	public function test_a_provider_returning_an_empty_array_contributes_nothing() {
		$chain = new RuleChain(
			array(
				$this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) ),
				$this->fakeProvider( 'term', array() ),
				$this->fakeProvider( 'post', array() ),
			)
		);

		$resolved = $chain->resolve_for( 42 );

		$this->assertSame( 12, $resolved->days );
		$this->assertSame( 'site', $resolved->origin_level );
		$this->assertNull( $resolved->frozen_by );
	}

	public function test_every_provider_returning_an_empty_array_resolves_to_an_unscheduled_outcome() {
		$chain = new RuleChain(
			array(
				$this->fakeProvider( 'site', array() ),
				$this->fakeProvider( 'term', array() ),
				$this->fakeProvider( 'post', array() ),
			)
		);

		$resolved = $chain->resolve_for( 42 );

		$this->assertFalse( $resolved->is_scheduled() );
		$this->assertNull( $resolved->days );
	}

	// -----------------------------------------------------------------------
	// THE EXTENSIBILITY CONTRACT -- the phase's headline deliverable: a
	// third party inserts a level no provider produced, at the most
	// specific position, via aps_auto_archive_rule_chain, and the resolver
	// honours it exactly as if it had always been part of the chain.
	// -----------------------------------------------------------------------

	public function test_aps_auto_archive_rule_chain_lets_a_third_party_add_a_level_no_provider_produced() {
		// site's provider contributes nothing itself (empty rules_for()), so
		// its reduced value is the literal null -- matchable exactly -- and
		// the per-level filter replaces it with a Rule this test holds a
		// reference to, giving the next filter something precise to match.
		$known_site_rule = new Rule( 'site', 12, ChildMode::Open, 'Site default' );
		\WP_Mock::onFilter( 'aps_auto_archive_site_rule' )->with( null, 42 )->reply( $known_site_rule );

		// Nothing in RuleChain, RuleResolver, or any provider here knows the
		// word "author" -- the whole point is that this level is invented
		// entirely by the filter callback below, appended after site's
		// contribution -- the most-specific position.
		$author_rule = new Rule( 'author', 1, ChildMode::Open, 'Author: jane' );
		\WP_Mock::onFilter( 'aps_auto_archive_rule_chain' )
			->with( array( $known_site_rule ), 42 )
			->reply( array( $known_site_rule, $author_rule ) );

		$chain = new RuleChain( array( $this->fakeProvider( 'site', array() ) ) );

		$resolved = $chain->resolve_for( 42 );

		$this->assertSame( 1, $resolved->days );
		$this->assertSame( 'author', $resolved->origin_level );
		$this->assertSame( 'Author: jane', $resolved->origin_label );
	}

	// -----------------------------------------------------------------------
	// aps_auto_archive_levels can reorder providers, changing which is
	// "most specific" and therefore which wins.
	// -----------------------------------------------------------------------

	public function test_aps_auto_archive_levels_reordering_providers_changes_the_outcome() {
		$site = $this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) );
		$term = $this->fakeProvider( 'term', array( new Rule( 'term', 3, ChildMode::Open, 'Category: News' ) ) );

		// Registered general -> specific: site, then term. The filter
		// reverses that -- site becomes the "most specific" position and
		// must now win, the opposite of
		// test_resolve_for_consults_providers_in_constructor_order()'s
		// un-reordered outcome for the same two providers.
		\WP_Mock::onFilter( 'aps_auto_archive_levels' )
			->with( array( $site, $term ) )
			->reply( array( $term, $site ) );

		$resolved = ( new RuleChain( array( $site, $term ) ) )->resolve_for( 42 );

		$this->assertSame( 'site', $resolved->origin_level );
		$this->assertSame( 12, $resolved->days );
	}

	// -----------------------------------------------------------------------
	// The per-level dynamic filter fires with the right hook name for each
	// level, and can supply that level's rule.
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider provider_levels
	 */
	public function test_the_per_level_filter_fires_with_the_correct_dynamic_hook_name( string $level ) {
		$injected = new Rule( $level, 77, ChildMode::Open, "Injected: {$level}" );

		\WP_Mock::onFilter( "aps_auto_archive_{$level}_rule" )->with( null, 42 )->reply( $injected );

		$resolved = ( new RuleChain( array( $this->fakeProvider( $level, array() ) ) ) )->resolve_for( 42 );

		// Only a match on the EXACT hook name for THIS level would have
		// replied with $injected; a wrong hook name leaves the level's
		// contribution null (see the empty-array-contributes-nothing test),
		// which this assertion would fail to see.
		$this->assertSame( 77, $resolved->days );
		$this->assertSame( $level, $resolved->origin_level );
	}

	public function provider_levels(): array {
		return array(
			'network' => array( 'network' ),
			'site'    => array( 'site' ),
			'term'    => array( 'term' ),
			'post'    => array( 'post' ),
		);
	}

	// -----------------------------------------------------------------------
	// The per-level filter can null out a level's contribution: whatever it
	// returns that isn't a Rule instance drops that level from the chain,
	// exactly as an unset filter (nothing to contribute) would.
	// -----------------------------------------------------------------------

	public function test_the_per_level_filter_can_null_out_a_levels_contribution_by_returning_a_non_rule_value() {
		\WP_Mock::onFilter( 'aps_auto_archive_site_rule' )->with( null, 42 )->reply( 'not a rule' );

		$chain = new RuleChain(
			array(
				$this->fakeProvider( 'site', array() ),
				$this->fakeProvider( 'term', array( new Rule( 'term', 3, ChildMode::Open, 'Category: News' ) ) ),
			)
		);

		$resolved = $chain->resolve_for( 42 );

		// site's filter replied with junk, not a Rule, so site contributes
		// nothing -- term, the only remaining contributor, supplies the
		// resolved outcome.
		$this->assertSame( 3, $resolved->days );
		$this->assertSame( 'term', $resolved->origin_level );
	}

	// -----------------------------------------------------------------------
	// aps_auto_archive_resolved_rule can override the final answer wholesale.
	// -----------------------------------------------------------------------

	public function test_aps_auto_archive_resolved_rule_can_override_the_final_answer_wholesale() {
		$override = new ResolvedRule( 1, 'override', 'Overridden entirely', null );

		$this->forceFilterReply(
			'aps_auto_archive_resolved_rule',
			static fn ( ResolvedRule $resolved, int $post_id ): ResolvedRule => $override
		);

		$chain = new RuleChain(
			array( $this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) ) )
		);

		$resolved = $chain->resolve_for( 42 );

		// The naturally-resolved value (12 days, from site) is discarded
		// entirely in favour of the override -- the filter is genuinely the
		// last word, not merely consulted.
		$this->assertSame( $override, $resolved );
		$this->assertSame( 1, $resolved->days );
		$this->assertSame( 'override', $resolved->origin_level );
	}

	// -----------------------------------------------------------------------
	// Junk returned from aps_auto_archive_rule_chain does not reach
	// RuleResolver and does not fatal.
	// -----------------------------------------------------------------------

	public function test_junk_entries_from_aps_auto_archive_rule_chain_do_not_reach_the_resolver() {
		$known_site_rule = new Rule( 'site', 12, ChildMode::Open, 'Site default' );
		\WP_Mock::onFilter( 'aps_auto_archive_site_rule' )->with( null, 42 )->reply( $known_site_rule );

		\WP_Mock::onFilter( 'aps_auto_archive_rule_chain' )
			->with( array( $known_site_rule ), 42 )
			->reply( array( $known_site_rule, 'not a rule', 42, new \stdClass() ) );

		$chain = new RuleChain( array( $this->fakeProvider( 'site', array() ) ) );

		// No TypeError, no fatal -- and the junk entries contribute nothing
		// to the resolved outcome, which still reflects only the real Rule.
		$resolved = $chain->resolve_for( 42 );

		$this->assertSame( 12, $resolved->days );
		$this->assertSame( 'site', $resolved->origin_level );
	}

	public function test_a_non_array_returned_from_aps_auto_archive_rule_chain_does_not_fatal() {
		\WP_Mock::onFilter( 'aps_auto_archive_rule_chain' )->with( array(), 42 )->reply( 'not even an array' );

		$resolved = ( new RuleChain( array() ) )->resolve_for( 42 );

		$this->assertNull( $resolved->days );
		$this->assertFalse( $resolved->is_scheduled() );
	}

	// -----------------------------------------------------------------------
	// aps_auto_archive_levels: junk provider entries are dropped silently,
	// mirroring the rule_chain guard.
	// -----------------------------------------------------------------------

	public function test_junk_entries_from_aps_auto_archive_levels_do_not_reach_a_provider_call() {
		$site = $this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) );

		\WP_Mock::onFilter( 'aps_auto_archive_levels' )
			->with( array( $site ) )
			->reply( array( 'not a provider', $site, 123, null ) );

		$resolved = ( new RuleChain( array( $site ) ) )->resolve_for( 42 );

		$this->assertSame( 12, $resolved->days );
		$this->assertSame( 'site', $resolved->origin_level );
	}

	public function test_a_non_array_returned_from_aps_auto_archive_levels_does_not_fatal() {
		$site = $this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) );

		\WP_Mock::onFilter( 'aps_auto_archive_levels' )->with( array( $site ) )->reply( 'not even an array' );

		// Every provider was discarded along with the malformed return value,
		// so nothing contributes and the outcome is unscheduled rather than
		// a fatal error.
		$resolved = ( new RuleChain( array( $site ) ) )->resolve_for( 42 );

		$this->assertFalse( $resolved->is_scheduled() );
	}
}
