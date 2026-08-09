<?php
/**
 * Admin\ArchiveColumnCellRenderer Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer
 *
 * Direct unit coverage on render(), resolve_archive_agent_name(), and the
 * four label statics as pure functions of their arguments -- no
 * get_post_meta stubbing (ArchiveMeta is constructed directly) and no
 * output buffering (render() returns its HTML rather than echoing it).
 *
 * {@see ArchiveColumnTest}'s render_cell() suite is the complementary
 * end-to-end proof that ArchiveColumn::render_cell() -> render() -> echo
 * still emits byte-identical output; those tests are untouched by the
 * Step 3 restructure and stay where they are.
 */

use ArchivedPostStatus\Admin\ArchiveColumnCellRenderer;
use ArchivedPostStatus\Archive\ArchiveMeta;

/**
 * ArchiveColumnCellRenderer test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer
 */
class ArchiveColumnCellRendererTest extends TestCase {

	/**
	 * Build an ArchiveMeta value object directly, without touching
	 * get_post_meta() -- render() only cares about the object's public
	 * properties.
	 *
	 * @param string $previous_status Previous post status.
	 * @param int    $archive_date    Archive timestamp (0 for the legacy/no-date state).
	 * @param int    $archive_user    Archiving user id (0 for anonymous/system context).
	 * @return ArchiveMeta
	 */
	private function meta(
		string $previous_status = 'publish',
		int $archive_date = 0,
		int $archive_user = 0
	): ArchiveMeta {
		return new ArchiveMeta( $previous_status, $archive_date, $archive_user, 'open', 'open' );
	}

	// -----------------------------------------------------------------------
	// render() -- legacy (no archive_date) state
	// -----------------------------------------------------------------------

	/**
	 * No archive_date (pre-0.4.0 archive) renders the bare label, one
	 * esc_html() layer, no name/date markup at all.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::render
	 */
	public function test_render_returns_plain_archived_span_for_legacy_meta() {
		$result = ArchiveColumnCellRenderer::render( $this->meta() );

		$this->assertSame( '<span>Archived</span>', $result );
	}

	/**
	 * Regression: the legacy branch escapes column_label() exactly once.
	 * A distinguishable esc_html() marker proves the escaping call actually
	 * ran rather than the input merely passing through unchanged.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::render
	 */
	public function test_render_escapes_legacy_label_exactly_once() {
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => "[[{$s}]]"
		);

		$result = ArchiveColumnCellRenderer::render( $this->meta() );

		$this->assertSame( '<span>[[Archived]]</span>', $result );
	}

	// -----------------------------------------------------------------------
	// render() -- populated states
	// -----------------------------------------------------------------------

	/**
	 * Both an archive date and a resolvable user render the rich
	 * "Archived by NAME" line plus the timestamp span.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::render
	 */
	public function test_render_returns_the_exact_output_for_date_and_user() {
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'November 14, 2023 at 10:13 pm' );

		$user               = new \stdClass();
		$user->display_name = 'Alice Editor';
		\WP_Mock::userFunction( 'get_userdata' )->with( 7 )->andReturn( $user );

		$result = ArchiveColumnCellRenderer::render( $this->meta( 'publish', 1700000000, 7 ) );

		$this->assertSame(
			'<span>Archived by Alice Editor</span><br><span class="aps-archive-datetime">November 14, 2023 at 10:13 pm</span>',
			$result
		);
	}

	/**
	 * System-context archives (archive_date set, archive_user 0) render
	 * "Archived by system" and must never reach get_userdata().
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::render
	 */
	public function test_render_returns_the_exact_output_for_date_and_system_context() {
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'November 14, 2023 at 10:13 pm' );
		\WP_Mock::userFunction( 'get_userdata' )->never();

		$result = ArchiveColumnCellRenderer::render( $this->meta( 'publish', 1700000000, 0 ) );

		$this->assertSame(
			'<span>Archived by system</span><br><span class="aps-archive-datetime">November 14, 2023 at 10:13 pm</span>',
			$result
		);
	}

	/**
	 * A user record deleted since archiving falls back to the localised
	 * "Unknown" attribution rather than a fatal or blank name.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::render
	 */
	public function test_render_returns_the_exact_output_for_deleted_user_fallback() {
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'November 14, 2023 at 10:13 pm' );
		\WP_Mock::userFunction( 'get_userdata' )->with( 7 )->andReturn( false );

		$result = ArchiveColumnCellRenderer::render( $this->meta( 'publish', 1700000000, 7 ) );

		$this->assertSame(
			'<span>Archived by Unknown</span><br><span class="aps-archive-datetime">November 14, 2023 at 10:13 pm</span>',
			$result
		);
	}

	/**
	 * XSS regression: HTML in a user's display_name must never reach the
	 * output unescaped. A real htmlspecialchars() stand-in for esc_html()
	 * makes the escaping observable -- WP_Mock's default passthrough would
	 * let an unescaped string through this test undetected.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::render
	 */
	public function test_render_returns_the_exact_output_for_the_xss_escaping_case() {
		\WP_Mock::userFunction( 'get_option' )->andReturn( 'F j, Y' );
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'November 14, 2023' );
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);

		$user               = new \stdClass();
		$user->display_name = '<script>alert(1)</script>';
		\WP_Mock::userFunction( 'get_userdata' )->with( 13 )->andReturn( $user );

		$result = ArchiveColumnCellRenderer::render( $this->meta( 'publish', 1700000000, 13 ) );

		$this->assertSame(
			'<span>Archived by &lt;script&gt;alert(1)&lt;/script&gt;</span><br><span class="aps-archive-datetime">November 14, 2023</span>',
			$result
		);
	}

	/**
	 * Pins the double esc_html() layer around the populated branch:
	 * esc_html() wraps BOTH the attribution_template() format string itself
	 * AND each substituted value ($name, $date_time) separately. A
	 * distinguishable `[[...]]` marker makes the format-string layer
	 * observable -- attribution_template() contains no HTML metacharacters,
	 * so an inert passthrough would make deleting that wrapper invisible to
	 * every other assertion here. esc_attr() is stubbed with a distinct
	 * `((...))` marker and must never appear, proving it is not used in
	 * this path.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::render
	 */
	public function test_render_pins_both_esc_html_layers_around_the_attribution_template() {
		\WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $key ) => 'date_format' === $key ? 'F j, Y' : 'g:i a'
		);
		\WP_Mock::userFunction( 'wp_date' )->andReturn( 'November 14, 2023 at 10:13 pm' );

		$user               = new \stdClass();
		$user->display_name = 'Alice Editor';
		\WP_Mock::userFunction( 'get_userdata' )->with( 7 )->andReturn( $user );

		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $s ) => "(({$s}))"
		);
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $s ) => "[[{$s}]]"
		);

		$result = ArchiveColumnCellRenderer::render( $this->meta( 'publish', 1700000000, 7 ) );

		$this->assertSame(
			'<span>[[Archived by [[Alice Editor]]]]</span><br><span class="aps-archive-datetime">[[November 14, 2023 at 10:13 pm]]</span>',
			$result
		);
	}

	// -----------------------------------------------------------------------
	// resolve_archive_agent_name()
	// -----------------------------------------------------------------------

	/**
	 * Zero archive_user (anonymous WP-CLI / cron / server-side call)
	 * resolves to the system label without consulting get_userdata().
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::resolve_archive_agent_name
	 */
	public function test_resolve_archive_agent_name_returns_system_label_for_zero_user() {
		\WP_Mock::userFunction( 'get_userdata' )->never();

		$result = ArchiveColumnCellRenderer::resolve_archive_agent_name( 0 );

		$this->assertSame( 'system', $result );
	}

	/**
	 * A resolvable user id returns that user's display_name.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::resolve_archive_agent_name
	 */
	public function test_resolve_archive_agent_name_returns_the_display_name_for_a_resolvable_user() {
		$user               = new \stdClass();
		$user->display_name = 'Alice Editor';
		\WP_Mock::userFunction( 'get_userdata' )->with( 7 )->andReturn( $user );

		$result = ArchiveColumnCellRenderer::resolve_archive_agent_name( 7 );

		$this->assertSame( 'Alice Editor', $result );
	}

	/**
	 * A deleted user record (get_userdata() returns false) falls back to
	 * the "Unknown" label.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::resolve_archive_agent_name
	 */
	public function test_resolve_archive_agent_name_returns_unknown_for_a_deleted_user() {
		\WP_Mock::userFunction( 'get_userdata' )->with( 7 )->andReturn( false );

		$result = ArchiveColumnCellRenderer::resolve_archive_agent_name( 7 );

		$this->assertSame( 'Unknown', $result );
	}

	// -----------------------------------------------------------------------
	// Label statics
	// -----------------------------------------------------------------------

	/**
	 * column_label() delegates to ArchiveLabel::value() -- the canonical
	 * filterable label accessor, exhaustively tested in ArchiveLabelTest.
	 * This pins the delegation only.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::column_label
	 */
	public function test_column_label_delegates_to_archive_label_value() {
		\WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( 'Archived' );

		$result = ArchiveColumnCellRenderer::column_label();

		$this->assertSame( 'Archived', $result );
	}

	/**
	 * system_attribution_label() returns the untranslated 'system' string
	 * under WP_Mock's inert `_x()` passthrough.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::system_attribution_label
	 */
	public function test_system_attribution_label_returns_system() {
		$this->assertSame( 'system', ArchiveColumnCellRenderer::system_attribution_label() );
	}

	/**
	 * unknown_attribution_label() returns the untranslated 'Unknown'
	 * string under WP_Mock's inert `__()` passthrough.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::unknown_attribution_label
	 */
	public function test_unknown_attribution_label_returns_unknown() {
		$this->assertSame( 'Unknown', ArchiveColumnCellRenderer::unknown_attribution_label() );
	}

	/**
	 * attribution_template() returns the format string with the `%1$s`
	 * placeholder intact -- render() depends on this surviving translation.
	 *
	 * @covers ArchivedPostStatus\Admin\ArchiveColumnCellRenderer::attribution_template
	 */
	public function test_attribution_template_returns_the_format_string_with_placeholder() {
		$this->assertSame( 'Archived by %1$s', ArchiveColumnCellRenderer::attribution_template() );
	}
}
