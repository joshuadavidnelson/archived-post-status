<?php
/**
 * AutoArchive\RuleResolver Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RuleResolver
 *
 * THE most important test file in the release. RuleResolver is the pure
 * algorithm the plan's §4.1 specifies verbatim: walk the chain from most
 * general to most specific; the last explicit value seen wins; a level that
 * is not ChildMode::Open freezes every level below it. Every scenario below
 * is one row of the plan's §4.1 worked table, pinned exactly, plus the edge
 * cases §4.1/§4.2 call out by name. Get this file wrong and a wrong
 * precedence rule ships silently — the highest-severity risk in the release.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\ResolvedRule;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleResolver;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleResolver
 */
class RuleResolverTest extends TestCase {

	// -----------------------------------------------------------------------
	// The six rows of the plan's §4.1 table, table-driven. Every row
	// asserts days AND origin_level/origin_label AND frozen_by.
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider provider_section_4_1_table_rows
	 *
	 * @param Rule[]  $chain                 Ordered general -> specific.
	 * @param ?int    $expected_days         Expected ResolvedRule::$days.
	 * @param ?string $expected_origin_level Expected ResolvedRule::$origin_level.
	 * @param ?string $expected_origin_label Expected ResolvedRule::$origin_label.
	 * @param ?string $expected_frozen_by    Expected ResolvedRule::$frozen_by.
	 */
	public function test_resolve_pins_section_4_1_table_row(
		array $chain,
		?int $expected_days,
		?string $expected_origin_level,
		?string $expected_origin_label,
		?string $expected_frozen_by
	) {
		$resolved = RuleResolver::resolve( $chain );

		$this->assertInstanceOf( ResolvedRule::class, $resolved );
		$this->assertSame( $expected_days, $resolved->days );
		$this->assertSame( $expected_origin_level, $resolved->origin_level );
		$this->assertSame( $expected_origin_label, $resolved->origin_label );
		$this->assertSame( $expected_frozen_by, $resolved->frozen_by );
	}

	public function provider_section_4_1_table_rows(): array {
		return array(
			// Row 1: Site 12d (Open), News 3d (Open), post 6d -> 12 -> 3 -> 6 -> 6, most specific wins.
			'row 1: most specific level wins outright'                        => array(
				array(
					new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
					new Rule( 'term', 3, ChildMode::Open, 'Category: News' ),
					new Rule( 'post', 6, ChildMode::Open, 'Post override' ),
				),
				6,
				'post',
				'Post override',
				null,
			),
			// Row 2: Site 12d (Open), News 3d (Open), no post value -> 12 -> 3 -> 3.
			'row 2: most specific level with no value falls back to term'     => array(
				array(
					new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
					new Rule( 'term', 3, ChildMode::Open, 'Category: News' ),
					new Rule( 'post', null, ChildMode::Open, 'Post override' ),
				),
				3,
				'term',
				'Category: News',
				null,
			),
			// Row 3: Site 12d (Open), News 3d (Locked), post 6d -> 12 -> 3, freeze; the lock holds.
			'row 3: a Locked term freezes the post level below it'            => array(
				array(
					new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
					new Rule( 'term', 3, ChildMode::Locked, 'Category: News' ),
					new Rule( 'post', 6, ChildMode::Open, 'Post override' ),
				),
				3,
				'term',
				'Category: News',
				'term',
			),
			// Row 4: Network 365d (Locked), site 12d -> 365, freeze; site is read-only.
			'row 4: a Locked network freezes site, term, and post'            => array(
				array(
					new Rule( 'network', 365, ChildMode::Locked, 'Network default' ),
					new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
					new Rule( 'term', null, ChildMode::Open, 'Category: News' ),
					new Rule( 'post', null, ChildMode::Open, 'Post override' ),
				),
				365,
				'network',
				'Network default',
				'network',
			),
			// Row 5: Network 365d (Off), site controls hidden -> 365, freeze; site never sees the field.
			'row 5: an Off network freezes site, term, and post'              => array(
				array(
					new Rule( 'network', 365, ChildMode::Off, 'Network default' ),
					new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
					new Rule( 'term', null, ChildMode::Open, 'Category: News' ),
					new Rule( 'post', null, ChildMode::Open, 'Post override' ),
				),
				365,
				'network',
				'Network default',
				'network',
			),
			// Row 6: Site 12d, no term rule, no post value -> 12.
			'row 6: site value stands alone with nothing below it'            => array(
				array(
					new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
					new Rule( 'term', null, ChildMode::Open, 'Category: News' ),
					new Rule( 'post', null, ChildMode::Open, 'Post override' ),
				),
				12,
				'site',
				'Site default',
				null,
			),
		);
	}

	// -----------------------------------------------------------------------
	// Edge cases the phase brief requires beyond the six table rows.
	// -----------------------------------------------------------------------

	public function test_resolve_returns_all_null_resolved_rule_for_empty_chain() {
		$resolved = RuleResolver::resolve( array() );

		$this->assertNull( $resolved->days );
		$this->assertNull( $resolved->origin_level );
		$this->assertNull( $resolved->origin_label );
		$this->assertNull( $resolved->frozen_by );
	}

	/**
	 * No level in the chain sets a days value: nothing is auto-archived.
	 */
	public function test_resolve_returns_null_days_when_no_level_sets_one() {
		$chain = array(
			new Rule( 'site', null, ChildMode::Open, 'Site default' ),
			new Rule( 'term', null, ChildMode::Open, 'Category: News' ),
			new Rule( 'post', null, ChildMode::Open, 'Post override' ),
		);

		$resolved = RuleResolver::resolve( $chain );

		$this->assertNull( $resolved->days );
		$this->assertNull( $resolved->origin_level );
		$this->assertNull( $resolved->origin_label );
		$this->assertNull( $resolved->frozen_by );
	}

	/**
	 * The documented subtlety: a level that FREEZES but sets no days does
	 * not supply a value — it only stops the walk. The last level that
	 * actually set days keeps ownership of the origin.
	 */
	public function test_resolve_freezing_level_with_no_days_leaves_origin_with_the_earlier_level() {
		$chain = array(
			new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
			new Rule( 'term', null, ChildMode::Locked, 'Category: News' ),
			new Rule( 'post', 6, ChildMode::Open, 'Post override' ),
		);

		$resolved = RuleResolver::resolve( $chain );

		$this->assertSame( 12, $resolved->days );
		$this->assertSame( 'site', $resolved->origin_level );
		$this->assertSame( 'Site default', $resolved->origin_label );
		$this->assertSame( 'term', $resolved->frozen_by );
	}

	/**
	 * Off and Locked produce identical resolved days — they differ only in
	 * what a settings screen renders downstream, never in the value.
	 */
	public function test_resolve_off_and_locked_produce_identical_days() {
		$locked_chain = array(
			new Rule( 'network', 365, ChildMode::Locked, 'Network default' ),
			new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
		);
		$off_chain    = array(
			new Rule( 'network', 365, ChildMode::Off, 'Network default' ),
			new Rule( 'site', 12, ChildMode::Open, 'Site default' ),
		);

		$resolved_locked = RuleResolver::resolve( $locked_chain );
		$resolved_off    = RuleResolver::resolve( $off_chain );

		$this->assertSame( $resolved_locked->days, $resolved_off->days );
		$this->assertSame( $resolved_locked->origin_level, $resolved_off->origin_level );
		$this->assertSame( 365, $resolved_locked->days );
	}

	/**
	 * A single-level chain resolves to that level's own value with nothing
	 * to freeze.
	 */
	public function test_resolve_single_level_chain_resolves_to_that_levels_value() {
		$resolved = RuleResolver::resolve( array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) );

		$this->assertSame( 12, $resolved->days );
		$this->assertSame( 'site', $resolved->origin_level );
		$this->assertNull( $resolved->frozen_by );
	}

	/**
	 * A freeze at the very first level in the chain means nothing after it
	 * is ever consulted, even if later Rule objects would otherwise win.
	 */
	public function test_resolve_freeze_at_first_level_stops_the_walk_immediately() {
		$chain = array(
			new Rule( 'network', 365, ChildMode::Off, 'Network default' ),
			new Rule( 'site', 1, ChildMode::Open, 'Site default' ),
		);

		$resolved = RuleResolver::resolve( $chain );

		$this->assertSame( 365, $resolved->days );
		$this->assertSame( 'network', $resolved->frozen_by );
	}
}
