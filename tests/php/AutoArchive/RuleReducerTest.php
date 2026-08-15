<?php
/**
 * AutoArchive\RuleReducer Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RuleReducer
 *
 * Per the plan's §4.2, the term level is the only one that can produce more
 * than one Rule for a single post — a post can belong to several terms
 * across several opted-in taxonomies. RuleReducer collapses that set to one
 * Rule before RuleResolver ever sees it. Pinned here, level-agnostically,
 * against the exact semantics §4.2 specifies: minimum days wins, the
 * strictest child_mode wins independently, and the label follows whichever
 * rule supplied the winning days.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleReducer;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleReducer
 */
class RuleReducerTest extends TestCase {

	// -----------------------------------------------------------------------
	// Empty input.
	// -----------------------------------------------------------------------

	public function test_reduce_returns_null_for_empty_array() {
		$this->assertNull( RuleReducer::reduce( array(), 'term' ) );
	}

	// -----------------------------------------------------------------------
	// Value: minimum days among rules that have one ("soonest wins").
	// -----------------------------------------------------------------------

	public function test_reduce_selects_minimum_days_among_rules_that_have_one() {
		$rules = array(
			new Rule( 'term', 6, ChildMode::Open, 'Category: News' ),
			new Rule( 'term', 3, ChildMode::Open, 'Category: Features' ),
			new Rule( 'term', 9, ChildMode::Open, 'Category: Archive' ),
		);

		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertSame( 3, $reduced->days );
	}

	public function test_reduce_ignores_null_days_when_selecting_minimum() {
		$rules = array(
			new Rule( 'term', null, ChildMode::Open, 'Category: Uncategorized' ),
			new Rule( 'term', 5, ChildMode::Open, 'Category: News' ),
		);

		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertSame( 5, $reduced->days );
	}

	public function test_reduce_days_is_null_when_no_rule_has_one() {
		$rules = array(
			new Rule( 'term', null, ChildMode::Open, 'Category: News' ),
			new Rule( 'term', null, ChildMode::Locked, 'Category: Features' ),
		);

		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertNull( $reduced->days );
	}

	// -----------------------------------------------------------------------
	// Child mode: strictest present wins, Off > Locked > Open.
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider provider_child_mode_ordering
	 *
	 * @param ChildMode[] $modes    Child modes across the rule set, in the
	 *                               order given — order must not matter.
	 * @param ChildMode   $expected The strictest mode that must win.
	 */
	public function test_reduce_child_mode_strictest_wins( array $modes, ChildMode $expected ) {
		$rules = array();
		foreach ( $modes as $i => $mode ) {
			$rules[] = new Rule( 'term', 5, $mode, "Term {$i}" );
		}

		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertSame( $expected, $reduced->child_mode );
	}

	public function provider_child_mode_ordering(): array {
		return array(
			'all open'                     => array( array( ChildMode::Open, ChildMode::Open ), ChildMode::Open ),
			'one locked among open'        => array( array( ChildMode::Open, ChildMode::Locked ), ChildMode::Locked ),
			'one off among open'           => array( array( ChildMode::Open, ChildMode::Off ), ChildMode::Off ),
			'off outranks locked'          => array( array( ChildMode::Locked, ChildMode::Off ), ChildMode::Off ),
			'off outranks locked, reverse' => array( array( ChildMode::Off, ChildMode::Locked ), ChildMode::Off ),
			'all three present'            => array( array( ChildMode::Open, ChildMode::Locked, ChildMode::Off ), ChildMode::Off ),
			'single open'                  => array( array( ChildMode::Open ), ChildMode::Open ),
			'single locked'                => array( array( ChildMode::Locked ), ChildMode::Locked ),
			'single off'                   => array( array( ChildMode::Off ), ChildMode::Off ),
		);
	}

	// -----------------------------------------------------------------------
	// Label: follows the rule that supplied the winning days, else the
	// first rule's label.
	// -----------------------------------------------------------------------

	public function test_reduce_label_follows_the_rule_that_supplied_the_winning_days() {
		$rules = array(
			new Rule( 'term', 6, ChildMode::Open, 'Category: News' ),
			new Rule( 'term', 3, ChildMode::Open, 'Category: Features' ),
		);

		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertSame( 'Category: Features', $reduced->label );
	}

	public function test_reduce_label_falls_back_to_first_rules_label_when_no_rule_has_days() {
		$rules = array(
			new Rule( 'term', null, ChildMode::Locked, 'Category: News' ),
			new Rule( 'term', null, ChildMode::Open, 'Category: Features' ),
		);

		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertSame( 'Category: News', $reduced->label );
	}

	// -----------------------------------------------------------------------
	// The level argument is carried through unchanged.
	// -----------------------------------------------------------------------

	public function test_reduce_carries_through_the_level_argument() {
		$rules   = array( new Rule( 'term', 5, ChildMode::Open, 'Category: News' ) );
		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertSame( 'term', $reduced->level );
	}

	// -----------------------------------------------------------------------
	// The documented consequence, by name: value and freeze resolve
	// independently. A post in News (6d, Locked) and Features (3d, Open)
	// reduces to 3 days, frozen — stricter than either term states alone.
	// -----------------------------------------------------------------------

	public function test_reduce_news_locked_six_days_and_features_open_three_days_resolves_to_three_days_frozen() {
		$rules = array(
			new Rule( 'term', 6, ChildMode::Locked, 'Category: News' ),
			new Rule( 'term', 3, ChildMode::Open, 'Category: Features' ),
		);

		$reduced = RuleReducer::reduce( $rules, 'term' );

		$this->assertSame( 3, $reduced->days );
		$this->assertSame( ChildMode::Locked, $reduced->child_mode );
		$this->assertSame( 'Category: Features', $reduced->label );
	}
}
