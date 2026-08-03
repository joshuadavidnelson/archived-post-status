<?php
/**
 * Status\ArchiveLabel Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Status\ArchiveLabel
 *
 * Mirrors the existing facade tests in
 * FunctionsTest::test_archived_label_string_* but exercises the lifted
 * implementation directly. Once Step 3B rewires the facade as a one-line
 * delegate, these tests will be the canonical pin and the facade-side tests
 * become a smoke test.
 */

use ArchivedPostStatus\Status\ArchiveLabel;

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Status\ArchiveLabel
 */
class ArchiveLabelTest extends TestCase {

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();

		\WP_Mock::userFunction(
			'__',
			array(
				'return' => 'Archived',
			)
		);
	}

	/**
	 * Default-path: with no filter override, the SUT returns the translated
	 * default `'Archived'`.
	 *
	 * @covers ArchivedPostStatus\Status\ArchiveLabel::value
	 */
	public function test_value_returns_translated_default_when_no_filter_registered() {
		\WP_Mock::expectFilter( 'aps_archived_label_string', 'Archived' );

		$result = ArchiveLabel::value();

		$this->assertSame( 'Archived', $result );
	}

	/**
	 * Filter-override path: the `aps_archived_label_string` filter replaces
	 * the label. Mirrors `FunctionsTest::test_archived_label_string_custom`.
	 *
	 * @covers ArchivedPostStatus\Status\ArchiveLabel::value
	 */
	public function test_value_applies_filter_override() {
		\WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( 'Legacy Content' );

		$result = ArchiveLabel::value();

		$this->assertSame( 'Legacy Content', $result );
	}

	/**
	 * Edge case: a filter callback that returns a non-string value (e.g. an
	 * integer) is coerced via the explicit `(string)` cast that the SUT
	 * applies after `esc_attr()`. The facade body had this cast; the
	 * extraction preserves it.
	 *
	 * @covers ArchivedPostStatus\Status\ArchiveLabel::value
	 */
	public function test_value_casts_non_string_filter_return_to_string() {
		\WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( 42 );

		$result = ArchiveLabel::value();

		$this->assertSame( '42', $result );
	}

	/**
	 * §1.6 regression: value() must return the filtered label verbatim,
	 * unescaped. Escaping belongs at each consumer's own output site
	 * (esc_html() for HTML text, esc_attr() for an attribute, or none at
	 * all where core does its own escaping) — not here, where the method
	 * has no output context of its own. Before the fix this returned
	 * `esc_attr( 'Archived & Retired' )`, i.e. an already-escaped string,
	 * which is exactly what caused every text-context consumer that
	 * (correctly) escapes again on its own account to double-escape.
	 *
	 * A real esc_attr() stand-in (htmlspecialchars) is required to make a
	 * lingering esc_attr() call inside value() observable: WP_Mock's
	 * default esc_attr() stub is an inert passthrough, so without this
	 * override an un-fixed value() would return the input unchanged and
	 * this test would pass regardless of whether the fix landed.
	 *
	 * @covers ArchivedPostStatus\Status\ArchiveLabel::value
	 */
	public function test_value_returns_the_filtered_label_verbatim_without_escaping() {
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);

		\WP_Mock::onFilter( 'aps_archived_label_string' )
			->with( 'Archived' )
			->reply( 'Archived & Retired' );

		$result = ArchiveLabel::value();

		$this->assertSame( 'Archived & Retired', $result );
	}
}
