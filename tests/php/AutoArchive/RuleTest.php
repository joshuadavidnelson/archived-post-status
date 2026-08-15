<?php
/**
 * AutoArchive\Rule Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\Rule
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Rule;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\Rule
 */
class RuleTest extends TestCase {

	public function test_constructor_exposes_all_four_properties_readonly() {
		$rule = new Rule( 'term', 3, ChildMode::Open, 'Category: News' );

		$this->assertSame( 'term', $rule->level );
		$this->assertSame( 3, $rule->days );
		$this->assertSame( ChildMode::Open, $rule->child_mode );
		$this->assertSame( 'Category: News', $rule->label );
	}

	/**
	 * null days is how a level declares it sets nothing, distinct from a
	 * level that sets a value of 0.
	 */
	public function test_null_days_is_representable() {
		$rule = new Rule( 'site', null, ChildMode::Open, 'Site default' );

		$this->assertNull( $rule->days );
	}

	/**
	 * A post-level rule carries ChildMode::Open even though the post level
	 * has no children to freeze — the plan's Rule spec deliberately gives
	 * the post level no special-cased child_mode representation.
	 */
	public function test_post_level_rule_carries_open_child_mode() {
		$rule = new Rule( 'post', 6, ChildMode::Open, 'Post override' );

		$this->assertSame( ChildMode::Open, $rule->child_mode );
	}
}
