<?php
/**
 * Settings\Schema Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\Schema
 *
 * Schema is the single source of truth for every plugin setting. The plan's
 * §5.8 table is the contract; every default here is pinned literally against
 * it, not re-derived from the implementation.
 */

use ArchivedPostStatus\Settings\Schema;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\Schema
 */
class SchemaTest extends TestCase {

	use BoundaryStubs;

	/**
	 * The plan's §5.8 table, key => default, transcribed exactly.
	 *
	 * @var array<string, mixed>
	 */
	private const EXPECTED_DEFAULTS = array(
		'is_read_only'                 => true,
		'scheduled_archive_enabled'    => true,
		'scheduled_archive_post_types' => array(),
		'auto_archive_enabled'         => false,
		'auto_archive_days'            => null,
		'auto_archive_child_mode'      => 'open',
		'auto_archive_types'           => array(),
		'auto_archive_taxonomies'      => array( 'category' ),
		'auto_archive_age_basis'       => 'modified',
		'auto_archive_grace_days'      => 7,
	);

	/**
	 * The plan's §5.8 "Levels" column, transcribed exactly.
	 *
	 * @var array<string, string[]>
	 */
	private const EXPECTED_LEVELS = array(
		'is_read_only'                 => array( Schema::LEVEL_SITE ),
		'scheduled_archive_enabled'    => array( Schema::LEVEL_NETWORK, Schema::LEVEL_SITE ),
		'scheduled_archive_post_types' => array( Schema::LEVEL_SITE ),
		'auto_archive_enabled'         => array( Schema::LEVEL_NETWORK, Schema::LEVEL_SITE ),
		'auto_archive_days'            => array( Schema::LEVEL_NETWORK, Schema::LEVEL_SITE, Schema::LEVEL_TERM, Schema::LEVEL_POST ),
		'auto_archive_child_mode'      => array( Schema::LEVEL_NETWORK, Schema::LEVEL_SITE, Schema::LEVEL_TERM ),
		'auto_archive_types'           => array( Schema::LEVEL_SITE ),
		'auto_archive_taxonomies'      => array( Schema::LEVEL_SITE ),
		'auto_archive_age_basis'       => array( Schema::LEVEL_SITE ),
		'auto_archive_grace_days'      => array( Schema::LEVEL_SITE ),
	);

	/**
	 * keys() lists exactly the ten §5.8 settings, in table order.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::keys
	 */
	public function test_keys_lists_every_settings_key_in_table_order() {
		$this->assertSame( array_keys( self::EXPECTED_DEFAULTS ), Schema::keys() );
	}

	/**
	 * default_for() matches the plan's §5.8 table exactly, key by key —
	 * the contract this whole class exists to pin.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::default_for
	 */
	public function test_default_for_matches_the_plan_table_for_every_key() {
		foreach ( self::EXPECTED_DEFAULTS as $key => $expected ) {
			$this->assertSame( $expected, Schema::default_for( $key ), "default for '{$key}'" );
		}
	}

	/**
	 * auto_archive_days defaults to null, not a number — the value that lets
	 * the cascade fall through to another level. A regression here (e.g. a
	 * stray `365`) would silently give every site a site-level rule the
	 * moment auto-archive is switched on.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::default_for
	 */
	public function test_auto_archive_days_default_is_null_not_zero() {
		$default = Schema::default_for( 'auto_archive_days' );

		$this->assertNull( $default );
		$this->assertNotSame( 0, $default );
	}

	/**
	 * default_for() returns null for a key the schema does not define.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::default_for
	 */
	public function test_default_for_returns_null_for_unknown_key() {
		$this->assertNull( Schema::default_for( 'not_a_real_setting' ) );
	}

	/**
	 * Every key has a sanitizer.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_every_key_has_a_sanitizer() {
		foreach ( Schema::keys() as $key ) {
			$this->assertIsCallable( Schema::sanitizer_for( $key ), "sanitizer for '{$key}'" );
		}
	}

	/**
	 * sanitizer_for() returns null for a key the schema does not define —
	 * this is what lets {@see \ArchivedPostStatus\Settings\Sanitizer} tell
	 * "known key" from "unknown key" without a separate lookup.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_sanitizer_for_returns_null_for_unknown_key() {
		$this->assertNull( Schema::sanitizer_for( 'not_a_real_setting' ) );
	}

	/**
	 * keys_for_level() matches the plan's §5.8 "Levels" column for every
	 * level, in both directions: every key claiming a level is returned for
	 * it, and no key is returned for a level it does not claim.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::keys_for_level
	 */
	public function test_keys_for_level_matches_the_plan_table() {
		foreach ( array( Schema::LEVEL_NETWORK, Schema::LEVEL_SITE, Schema::LEVEL_TERM, Schema::LEVEL_POST ) as $level ) {
			$expected = array_keys(
				array_filter(
					self::EXPECTED_LEVELS,
					static fn ( array $levels ): bool => in_array( $level, $levels, true )
				)
			);

			$this->assertSame( $expected, Schema::keys_for_level( $level ), "keys for level '{$level}'" );
		}
	}

	/**
	 * The post level carries no `auto_archive_child_mode` control — the post
	 * level has no children to freeze (mirrors {@see
	 * \ArchivedPostStatus\AutoArchive\Rule}'s own documented decision).
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::keys_for_level
	 */
	public function test_post_level_does_not_include_child_mode() {
		$this->assertNotContains( 'auto_archive_child_mode', Schema::keys_for_level( Schema::LEVEL_POST ) );
	}

	/**
	 * is_read_only sanitizes as a bool, coercing a truthy/falsy non-bool.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_bool_sanitizer_coerces_to_bool() {
		$sanitizer = Schema::sanitizer_for( 'is_read_only' );

		$this->assertTrue( $sanitizer( 1 ) );
		$this->assertFalse( $sanitizer( 0 ) );
		$this->assertFalse( $sanitizer( '' ) );
	}

	/**
	 * auto_archive_grace_days sanitizes as absint().
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_int_sanitizer_uses_absint() {
		$sanitizer = Schema::sanitizer_for( 'auto_archive_grace_days' );

		$this->assertSame( 3, $sanitizer( '3' ) );
		$this->assertSame( 0, $sanitizer( 0 ) );
	}

	/**
	 * auto_archive_days: null passes through untouched, distinct from a
	 * value of 0. A positive numeric string is cast to int. A value below 1
	 * (0, or a negative number's absint() magnitude if that magnitude is
	 * still under 1 — impossible for absint(), but the floor is asserted
	 * explicitly here) clamps up to 1.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_nullable_int_sanitizer_distinguishes_null_from_zero_and_clamps_floor() {
		$sanitizer = Schema::sanitizer_for( 'auto_archive_days' );

		$this->assertNull( $sanitizer( null ) );
		$this->assertSame( 1, $sanitizer( 0 ) );
		$this->assertSame( 3, $sanitizer( '3' ) );
		$this->assertSame( 5, $sanitizer( -5 ) );
	}

	/**
	 * auto_archive_child_mode: a recognized ChildMode value passes through;
	 * anything else falls back to the schema default ('open') rather than
	 * storing garbage.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_enum_sanitizer_falls_back_to_default_on_unrecognized_value() {
		$sanitizer = Schema::sanitizer_for( 'auto_archive_child_mode' );

		$this->assertSame( 'locked', $sanitizer( 'locked' ) );
		$this->assertSame( 'open', $sanitizer( 'not-a-real-mode' ) );
		$this->assertSame( 'open', $sanitizer( null ) );
	}

	/**
	 * auto_archive_age_basis: same fall-back-on-unrecognized-value shape as
	 * child_mode, but its own literal allow-list ('modified' | 'published').
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_age_basis_sanitizer_falls_back_to_modified_on_unrecognized_value() {
		$sanitizer = Schema::sanitizer_for( 'auto_archive_age_basis' );

		$this->assertSame( 'published', $sanitizer( 'published' ) );
		$this->assertSame( 'modified', $sanitizer( 'whenever' ) );
	}

	/**
	 * auto_archive_types: sanitizes each entry via sanitize_key() and drops
	 * anything not in aps_get_supported_post_types() — a hostile or stale
	 * slug can never reach a query.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_post_types_sanitizer_intersects_against_supported_post_types() {
		$this->stubSupportedPostTypesBoundary( array( 'post', 'page' ), array( 'post', 'page' ) );

		\WP_Mock::userFunction( 'sanitize_key' )
			->andReturnUsing( static fn ( $value ) => strtolower( (string) $value ) );

		$sanitizer = Schema::sanitizer_for( 'auto_archive_types' );

		$this->assertSame(
			array( 'post' ),
			$sanitizer( array( 'post', 'DROP TABLE wp_posts;--' ) ),
			'a hostile slug not in the supported set must be dropped'
		);
	}

	/**
	 * A non-array value sanitizes to an empty array rather than fataling —
	 * a stray scalar from a hand-edited option must not reach the query
	 * boundary.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_post_types_sanitizer_returns_empty_array_for_non_array_input() {
		$sanitizer = Schema::sanitizer_for( 'auto_archive_types' );

		$this->assertSame( array(), $sanitizer( 'not-an-array' ) );
	}

	/**
	 * auto_archive_taxonomies: sanitizes each entry via sanitize_key() and
	 * drops anything not a registered taxonomy.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_taxonomies_sanitizer_intersects_against_registered_taxonomies() {
		\WP_Mock::userFunction( 'sanitize_key' )
			->andReturnUsing( static fn ( $value ) => strtolower( (string) $value ) );
		\WP_Mock::userFunction( 'get_taxonomies' )
			->andReturn( array( 'category', 'post_tag' ) );

		$sanitizer = Schema::sanitizer_for( 'auto_archive_taxonomies' );

		$this->assertSame(
			array( 'category' ),
			$sanitizer( array( 'category', 'not_a_real_taxonomy' ) )
		);
	}

	/**
	 * A non-array value sanitizes to an empty array rather than fataling —
	 * mirrors {@see self::test_post_types_sanitizer_returns_empty_array_for_non_array_input()}
	 * for the taxonomies sanitizer.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::sanitizer_for
	 */
	public function test_taxonomies_sanitizer_returns_empty_array_for_non_array_input() {
		$sanitizer = Schema::sanitizer_for( 'auto_archive_taxonomies' );

		$this->assertSame( array(), $sanitizer( 'not-an-array' ) );
	}

	/**
	 * Every key has a non-empty label — the settings screen this phase adds
	 * has no field it can render without one.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::label_for
	 */
	public function test_every_key_has_a_non_empty_label() {
		foreach ( Schema::keys() as $key ) {
			$this->assertNotSame( '', Schema::label_for( $key ), "label for '{$key}'" );
		}
	}

	/**
	 * Every key has a non-empty description — the settings screen's "what
	 * is inherited" text and field help both depend on this.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::description_for
	 */
	public function test_every_key_has_a_non_empty_description() {
		foreach ( Schema::keys() as $key ) {
			$this->assertNotSame( '', Schema::description_for( $key ), "description for '{$key}'" );
		}
	}

	/**
	 * label_for() returns an empty string for a key the schema does not
	 * define — mirrors {@see self::test_default_for_returns_null_for_unknown_key()}.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::label_for
	 */
	public function test_label_for_returns_empty_string_for_unknown_key() {
		$this->assertSame( '', Schema::label_for( 'not_a_real_setting' ) );
	}

	/**
	 * description_for() returns an empty string for a key the schema does
	 * not define.
	 *
	 * @covers ArchivedPostStatus\Settings\Schema::description_for
	 */
	public function test_description_for_returns_empty_string_for_unknown_key() {
		$this->assertSame( '', Schema::description_for( 'not_a_real_setting' ) );
	}
}
