<?php
/**
 * Settings\CascadeField Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\CascadeField
 *
 * Pins the plan's §5.9 contract: what is inherited is always visible, a
 * Locked ancestor renders read-only with its origin named, an Off ancestor
 * renders nothing editable, and the downstream selector appears only when
 * the caller passes one.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\Settings\CascadeField;
use ArchivedPostStatus\Settings\CascadeInheritance;
use ArchivedPostStatus\Settings\DownstreamSelector;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\CascadeField
 */
class CascadeFieldTest extends TestCase {

	/**
	 * The always-visible inheritance line names the origin level and the
	 * inherited value, regardless of whether this level is frozen.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_always_shows_what_is_inherited_and_from_where() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, null );

		$this->assertStringContainsString( 'Inherited from Site: 12 days', $html );
	}

	/**
	 * With nothing inherited (top of the chain), the inheritance line still
	 * renders — never a blank field the user has to interpret.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_shows_a_neutral_line_when_nothing_is_inherited() {
		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', CascadeInheritance::none(), null );

		$this->assertStringContainsString( 'No value is inherited from a higher level.', $html );
	}

	/**
	 * The open, unfrozen path renders an editable number input, pre-filled
	 * with this level's own stored value.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_open_ancestor_renders_editable_input_with_own_value() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, 6 );

		$this->assertStringContainsString( '<input type="number"', $html );
		$this->assertStringContainsString( 'name="auto_archive_days"', $html );
		$this->assertStringContainsString( 'value="6"', $html );
	}

	/**
	 * A Locked ancestor renders the value read-only, badged with its
	 * origin — the literal wording the plan's §5.9 example uses.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_locked_ancestor_renders_read_only_value_with_origin() {
		$inheritance = new CascadeInheritance( 365, 'Network', true, false, 'Network' );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, 999 );

		$this->assertStringContainsString( '365 days', $html );
		$this->assertStringContainsString( 'locked by Network', $html );
		$this->assertStringNotContainsString( '<input type="number"', $html, 'a Locked ancestor must not render an editable input' );
		$this->assertStringNotContainsString( 'value="999"', $html, "the frozen level's own stored value must not leak into a read-only render" );
	}

	/**
	 * An Off ancestor renders nothing at all for this level's own control —
	 * no editable input, no read-only badge.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_off_ancestor_renders_no_control_at_all() {
		$inheritance = new CascadeInheritance( 365, 'Network', false, true, 'Network' );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, 999 );

		$this->assertStringContainsString( 'Hidden by Network.', $html );
		$this->assertStringNotContainsString( '<input type="number"', $html );
		$this->assertStringNotContainsString( 'aps-cascade-locked-value', $html );
	}

	/**
	 * No `DownstreamSelector` argument means no downstream control renders
	 * — the post-level shape, which has no children.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_omits_downstream_selector_when_none_given() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, null );

		$this->assertStringNotContainsString( 'aps-cascade-downstream', $html );
	}

	/**
	 * With a `DownstreamSelector`, all three outcome-phrased options render,
	 * using the caller's own children_label rather than cascade jargon —
	 * the literal phrasing the plan's §5.9 spec quotes.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_downstream_selector_renders_outcome_phrased_options() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );
		$downstream  = new DownstreamSelector( 'auto_archive_child_mode', 'sites', ChildMode::Locked );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, 6, $downstream );

		$this->assertStringContainsString( 'Sites may set their own', $html );
		$this->assertStringContainsString( 'Sites see this value, read-only', $html );
		$this->assertStringContainsString( 'Hide this from sites', $html );
	}

	/**
	 * The downstream selector marks the currently-stored mode's own radio
	 * `checked`, and no other.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_downstream_selector_checks_only_the_current_mode() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );
		$downstream  = new DownstreamSelector( 'auto_archive_child_mode', 'sites', ChildMode::Locked );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, 6, $downstream );

		$this->assertMatchesRegularExpression(
			'/value="locked" checked="checked"/',
			$html,
			'the stored mode (Locked) must be the checked radio'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/value="open" checked="checked"/',
			$html,
			'a non-current mode (Open) must not be checked'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/value="off" checked="checked"/',
			$html,
			'a non-current mode (Off) must not be checked'
		);
	}

	/**
	 * The downstream option label capitalizes a multibyte leading character
	 * correctly. `ucfirst()` is byte-based and silently leaves a non-ASCII
	 * leading character (an accented Latin letter here, standing in for
	 * Cyrillic/Greek/etc. in a real translation) lowercase.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_downstream_selector_capitalizes_a_multibyte_children_label() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );
		$downstream  = new DownstreamSelector( 'auto_archive_child_mode', 'îles', ChildMode::Open );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, 6, $downstream );

		$this->assertStringContainsString( 'Îles may set their own', $html );
		$this->assertStringNotContainsString( 'îles may set their own', $html );
	}

	/**
	 * A Locked ancestor suppresses the downstream selector too — a
	 * downstream mode this level sets is inert while an ancestor already
	 * freezes everything below it, so showing an editable-but-meaningless
	 * control would mislead an admin into thinking it has an effect.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_locked_ancestor_also_suppresses_downstream_selector() {
		$inheritance = new CascadeInheritance( 365, 'Network', true, false, 'Network' );
		$downstream  = new DownstreamSelector( 'auto_archive_child_mode', 'sites', ChildMode::Open );

		$html = CascadeField::render( 'auto_archive_days', 'Archive after', 'help text', $inheritance, null, $downstream );

		$this->assertStringNotContainsString( 'aps-cascade-downstream', $html );
	}

	/**
	 * The label always routes through esc_html() — a hostile/mistaken label
	 * containing markup must not reach the page unescaped.
	 *
	 * @covers ArchivedPostStatus\Settings\CascadeField::render
	 */
	public function test_render_escapes_label_through_esc_html() {
		$inheritance = new CascadeInheritance( 12, 'Site', false, false, null );

		\WP_Mock::userFunction( 'esc_html' )
			->andReturnUsing( static fn ( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );

		$html = CascadeField::render(
			'auto_archive_days',
			'Archive <script>alert(1)</script>',
			'help text',
			$inheritance,
			null
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
