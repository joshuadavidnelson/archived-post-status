<?php
/**
 * Admin\ScheduleColumnCellRenderer Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer
 *
 * Direct unit coverage on render() and the label statics as pure functions of
 * their arguments -- ScheduleMeta objects are constructed directly rather
 * than routed through get_post_meta stubbing, and render() returns its HTML
 * rather than echoing it. {@see ScheduleColumnTest}'s render_cell() suite is
 * the complementary end-to-end proof that
 * ScheduleColumn::render_cell() -> render() -> echo still emits
 * byte-identical output.
 *
 * Per the phase brief, this renderer must NEVER resolve the auto-archive
 * cascade (aps_get_auto_archive_rule() and everything under it) -- it works
 * from the stored ScheduleMeta alone. Several tests below stub cascade-only
 * boundary functions with ->never() to prove that.
 */

use ArchivedPostStatus\Admin\ScheduleColumnCellRenderer;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;

/**
 * ScheduleColumnCellRenderer test case.
 *
 * @since 0.5.0
 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer
 */
class ScheduleColumnCellRendererTest extends TestCase {

	/**
	 * The hidden per-row Quick Edit data span every cell carries, for the
	 * common "no manual schedule to pre-fill" case (no record, Exempt,
	 * Rule -- everything except Manual).
	 *
	 * @var string
	 */
	private const INLINE_DATA_EMPTY = '<span class="aps-schedule-inline-data" data-local="" aria-hidden="true" style="display:none"></span>';

	/**
	 * Build a ScheduleMeta value object directly.
	 *
	 * @param int             $time         UTC epoch.
	 * @param ScheduleSource  $source       Schedule origin.
	 * @param int             $user         User id (0 for rule/system).
	 * @param int             $rule_version Rule version the schedule was stamped from.
	 * @return ScheduleMeta
	 */
	private function meta(
		int $time,
		ScheduleSource $source,
		int $user = 0,
		int $rule_version = 0
	): ScheduleMeta {
		return new ScheduleMeta( $time, $source, $user, $rule_version, 0 );
	}

	// -----------------------------------------------------------------------
	// render() -- no record
	// -----------------------------------------------------------------------

	/**
	 * No schedule record (null meta) renders a bare em dash -- no
	 * attribution, no date markup.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_returns_a_bare_dash_for_no_record() {
		$result = ScheduleColumnCellRenderer::render( null );

		$this->assertSame( '<span>—</span>' . self::INLINE_DATA_EMPTY, $result );
	}

	/**
	 * The no-record cell must not touch the auto-archive cascade at all --
	 * it has no post id to resolve against in the first place.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_does_not_resolve_the_cascade_for_no_record() {
		\WP_Mock::userFunction( 'get_the_terms' )->never();
		\WP_Mock::userFunction( 'get_post_meta' )->never();

		ScheduleColumnCellRenderer::render( null );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// render() -- Exempt tombstone
	// -----------------------------------------------------------------------

	/**
	 * An Exempt tombstone also renders an em dash, but titled so the
	 * distinction from "merely unscheduled" is legible on hover -- the tests
	 * pin the title text and the dedicated CSS hook separately from the
	 * bare-dash pin above so a refactor collapsing the two states is caught.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_returns_a_titled_dash_for_an_exempt_tombstone() {
		$result = ScheduleColumnCellRenderer::render( $this->meta( 0, ScheduleSource::Exempt ) );

		$this->assertSame(
			'<span class="aps-schedule-exempt" title="Exempt from automatic archiving">—</span>' . self::INLINE_DATA_EMPTY,
			$result
		);
	}

	/**
	 * Regression: the exempt title must be escaped for the HTML attribute
	 * context via esc_attr(), not esc_html(). A distinguishable esc_attr()
	 * marker proves the escaping call actually ran.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_escapes_the_exempt_title_with_esc_attr() {
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $s ) => "(({$s}))"
		);

		$result = ScheduleColumnCellRenderer::render( $this->meta( 0, ScheduleSource::Exempt ) );

		$this->assertSame(
			'<span class="aps-schedule-exempt" title="((Exempt from automatic archiving))">—</span>'
			. '<span class="aps-schedule-inline-data" data-local="(())" aria-hidden="true" style="display:none"></span>',
			$result
		);
	}

	/**
	 * An Exempt tombstone must not resolve the cascade either -- rendering
	 * "deliberately excluded" only needs the stored source, not a fresh
	 * cascade run.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_does_not_resolve_the_cascade_for_an_exempt_tombstone() {
		\WP_Mock::userFunction( 'get_the_terms' )->never();

		ScheduleColumnCellRenderer::render( $this->meta( 0, ScheduleSource::Exempt ) );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// render() -- Manual
	// -----------------------------------------------------------------------

	/**
	 * A manually-scheduled post renders "Scheduled by NAME" plus the display
	 * date, resolving the name via
	 * ArchiveColumnCellRenderer::resolve_archive_agent_name() -- the shared
	 * "who did this, or the system" resolver.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_returns_the_exact_output_for_a_manual_schedule() {
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027 at 9:00 am' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );

		$user               = new \stdClass();
		$user->display_name = 'Alice Editor';
		\WP_Mock::userFunction( 'get_userdata' )->with( 7 )->andReturn( $user );

		$result = ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Manual, 7 ) );

		$this->assertSame(
			'<span>Scheduled by Alice Editor</span><br><span class="aps-schedule-datetime">March 3, 2027 at 9:00 am</span>'
			. '<span class="aps-schedule-inline-data" data-local="2027-01-15T08:00" aria-hidden="true" style="display:none"></span>',
			$result
		);
	}

	/**
	 * A manual schedule set anonymously (user id 0 -- WP-CLI, cron,
	 * server-side call) falls back to the shared "system" attribution
	 * instead of a named user.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_returns_system_attribution_for_a_manual_schedule_with_no_user() {
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027 at 9:00 am' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'get_userdata' )->never();

		$result = ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Manual, 0 ) );

		$this->assertSame(
			'<span>Scheduled by system</span><br><span class="aps-schedule-datetime">March 3, 2027 at 9:00 am</span>'
			. '<span class="aps-schedule-inline-data" data-local="2027-01-15T08:00" aria-hidden="true" style="display:none"></span>',
			$result
		);
	}

	/**
	 * XSS regression: HTML in a user's display_name must never reach the
	 * output unescaped. A real htmlspecialchars() stand-in for esc_html()
	 * makes the escaping observable.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_escapes_html_in_the_user_display_name_for_a_manual_schedule() {
		\WP_Mock::userFunction( 'get_option' )->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);

		$user               = new \stdClass();
		$user->display_name = '<script>alert(1)</script>';
		\WP_Mock::userFunction( 'get_userdata' )->with( 13 )->andReturn( $user );

		$result = ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Manual, 13 ) );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $result );
	}

	/**
	 * A manual schedule does not resolve the cascade either -- attribution
	 * comes only from the stored user id.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_does_not_resolve_the_cascade_for_a_manual_schedule() {
		\WP_Mock::userFunction( 'get_option' )->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'get_userdata' )->andReturn( false );
		\WP_Mock::userFunction( 'get_the_terms' )->never();

		ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Manual, 7 ) );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// render() -- Rule
	// -----------------------------------------------------------------------

	/**
	 * A rule-stamped schedule renders "Scheduled by rule" -- marked as
	 * machine-set rather than "Scheduled by NAME" -- plus the display date.
	 * get_userdata() must never be consulted: a rule stamp carries no editor
	 * to attribute to.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_returns_the_exact_output_for_a_rule_schedule() {
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027 at 9:00 am' );
		\WP_Mock::userFunction( 'get_userdata' )->never();

		$result = ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Rule, 0, 3 ) );

		$this->assertSame(
			'<span>Scheduled by rule</span><br><span class="aps-schedule-datetime">March 3, 2027 at 9:00 am</span>' . self::INLINE_DATA_EMPTY,
			$result
		);
	}

	/**
	 * The critical negative: a rule-sourced cell must NOT re-resolve the
	 * cascade to show provenance (e.g. "from Category: News") -- that would
	 * mean four provider queries, including term lookups, per row on every
	 * posts-list page load. get_the_terms() (a term-provider boundary call)
	 * and get_term() must never fire.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_does_not_resolve_the_cascade_for_a_rule_schedule() {
		\WP_Mock::userFunction( 'get_option' )->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027' );
		\WP_Mock::userFunction( 'get_the_terms' )->never();
		\WP_Mock::userFunction( 'get_term' )->never();
		\WP_Mock::userFunction( 'get_post_type' )->never();

		ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Rule, 0, 3 ) );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// render() -- the hidden Quick Edit data span
	// -----------------------------------------------------------------------

	/**
	 * The manual-schedule local wall-clock value written into the hidden
	 * data span must be escaped for the HTML attribute context via
	 * esc_attr() -- a distinguishable esc_attr() marker proves the escaping
	 * call actually ran on this value, not just on the visible cell markup.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_escapes_the_inline_edit_data_span_local_value_with_esc_attr() {
		\WP_Mock::userFunction( 'get_option' )->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'get_userdata' )->andReturn( false );
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $s ) => "(({$s}))"
		);

		$result = ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Manual, 7 ) );

		$this->assertStringContainsString( 'data-local="((2027-01-15T08:00))"', $result );
	}

	/**
	 * Every non-Manual state (no record, Exempt, Rule) carries an empty
	 * `data-local` -- so Quick Edit's row hydration always finds a span to
	 * read and always starts the field blank rather than stale, per
	 * {@see \ArchivedPostStatus\Admin\ScheduleMetaBox::render_date_field()}'s
	 * identical "never pre-fill from a rule stamp" rule.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_includes_an_empty_inline_edit_data_span_for_a_rule_schedule() {
		\WP_Mock::userFunction( 'get_option' )->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'March 3, 2027' );

		$result = ScheduleColumnCellRenderer::render( $this->meta( 1800000000, ScheduleSource::Rule, 0, 3 ) );

		$this->assertStringContainsString( 'data-local=""', $result );
	}

	// -----------------------------------------------------------------------
	// aps_schedule_cell_content filter
	// -----------------------------------------------------------------------

	/**
	 * The default cell HTML is run through the aps_schedule_cell_content
	 * filter before it is returned, letting a site override the markup
	 * entirely.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::render
	 */
	public function test_render_applies_the_cell_content_filter() {
		\WP_Mock::onFilter( 'aps_schedule_cell_content' )
			->with( '<span>—</span>' . self::INLINE_DATA_EMPTY, null )
			->reply( '<span>custom</span>' );

		$result = ScheduleColumnCellRenderer::render( null );

		$this->assertSame( '<span>custom</span>', $result );
	}

	// -----------------------------------------------------------------------
	// column_label()
	// -----------------------------------------------------------------------

	/**
	 * column_label() returns the default "Scheduled" string under WP_Mock's
	 * inert __() passthrough, run through the aps_schedule_column_label
	 * filter.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::column_label
	 */
	public function test_column_label_returns_scheduled_by_default() {
		\WP_Mock::onFilter( 'aps_schedule_column_label' )
			->with( 'Scheduled' )
			->reply( 'Scheduled' );

		$this->assertSame( 'Scheduled', ScheduleColumnCellRenderer::column_label() );
	}

	/**
	 * A site can override the header label entirely via
	 * aps_schedule_column_label.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleColumnCellRenderer::column_label
	 */
	public function test_column_label_honors_the_filter_override() {
		\WP_Mock::onFilter( 'aps_schedule_column_label' )
			->with( 'Scheduled' )
			->reply( 'Auto-archive date' );

		$this->assertSame( 'Auto-archive date', ScheduleColumnCellRenderer::column_label() );
	}
}
