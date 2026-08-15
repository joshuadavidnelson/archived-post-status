<?php
/**
 * Settings\Sanitizer Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\Sanitizer
 * @covers ArchivedPostStatus\Settings\Schema
 *
 * Sanitizer is schema-driven — its own logic is just the loop, so these
 * tests necessarily exercise Schema's per-key sanitizers through it. The
 * per-sanitizer edge cases (null vs 0 clamping, enum fall-back, etc.) are
 * pinned in SchemaTest; this file pins the array-level contract: unknown
 * keys dropped, known keys sanitized, nothing else.
 */

use ArchivedPostStatus\Settings\Sanitizer;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\Sanitizer
 * @covers ArchivedPostStatus\Settings\Schema
 */
class SanitizerTest extends TestCase {

	use BoundaryStubs;

	/**
	 * A key Schema does not define is dropped entirely — not passed through
	 * unsanitized, and not present in the output at all.
	 *
	 * @covers ArchivedPostStatus\Settings\Sanitizer::sanitize
	 */
	public function test_sanitize_drops_unknown_keys() {
		$result = Sanitizer::sanitize(
			array(
				'is_read_only'        => true,
				'not_a_real_setting'  => 'whatever',
				'__internal_junk'     => 12345,
			)
		);

		$this->assertArrayNotHasKey( 'not_a_real_setting', $result );
		$this->assertArrayNotHasKey( '__internal_junk', $result );
		$this->assertArrayHasKey( 'is_read_only', $result );
	}

	/**
	 * Every recognized key present in the input is run through its own
	 * sanitizer and kept, key for key.
	 *
	 * @covers ArchivedPostStatus\Settings\Sanitizer::sanitize
	 */
	public function test_sanitize_runs_each_known_key_through_its_own_sanitizer() {
		$result = Sanitizer::sanitize(
			array(
				'is_read_only'            => 1,
				'auto_archive_enabled'    => 0,
				'auto_archive_grace_days' => '14',
				'auto_archive_days'       => null,
			)
		);

		$this->assertSame(
			array(
				'is_read_only'            => true,
				'auto_archive_enabled'    => false,
				'auto_archive_grace_days' => 14,
				'auto_archive_days'       => null,
			),
			$result
		);
	}

	/**
	 * A key Schema does not recognize inside an otherwise-valid enum value
	 * falls back to the schema default, exercised end-to-end through
	 * sanitize() rather than the sanitizer callable directly.
	 *
	 * @covers ArchivedPostStatus\Settings\Sanitizer::sanitize
	 */
	public function test_sanitize_falls_back_unrecognized_enum_value_to_default() {
		$result = Sanitizer::sanitize( array( 'auto_archive_child_mode' => 'not-a-real-mode' ) );

		$this->assertSame( 'open', $result['auto_archive_child_mode'] );
	}

	/**
	 * A hostile post-type slug in an array-typed key is filtered out by the
	 * intersection against aps_get_supported_post_types(), exercised
	 * end-to-end through sanitize().
	 *
	 * @covers ArchivedPostStatus\Settings\Sanitizer::sanitize
	 */
	public function test_sanitize_filters_hostile_post_type_slug() {
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		\WP_Mock::userFunction( 'sanitize_key' )
			->andReturnUsing( static fn ( $value ) => strtolower( (string) $value ) );

		$result = Sanitizer::sanitize(
			array( 'auto_archive_types' => array( 'page', '"; DROP TABLE wp_posts; --' ) )
		);

		$this->assertSame( array( 'page' ), $result['auto_archive_types'] );
	}

	/**
	 * An empty input array sanitizes to an empty array — no keys means no
	 * output, not a defaults fallback (Store, not Sanitizer, owns defaults).
	 *
	 * @covers ArchivedPostStatus\Settings\Sanitizer::sanitize
	 */
	public function test_sanitize_returns_empty_array_for_empty_input() {
		$this->assertSame( array(), Sanitizer::sanitize( array() ) );
	}
}
