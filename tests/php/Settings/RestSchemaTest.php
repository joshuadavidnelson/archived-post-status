<?php
/**
 * Settings\RestSchema Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\RestSchema
 */

use ArchivedPostStatus\Settings\RestSchema;
use ArchivedPostStatus\Settings\Schema;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\RestSchema
 */
class RestSchemaTest extends TestCase {

	/**
	 * The schema is a JSON Schema object with one property per site-level
	 * settings key — no more, no fewer.
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_site_declares_object_type_with_every_site_level_key() {
		$schema = RestSchema::site();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame(
			Schema::keys_for_level( Schema::LEVEL_SITE ),
			array_keys( $schema['properties'] )
		);
	}

	/**
	 * A boolean key declares a boolean JSON Schema type.
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_boolean_key_declares_boolean_type() {
		$schema = RestSchema::site();

		$this->assertSame( 'boolean', $schema['properties']['is_read_only']['type'] );
	}

	/**
	 * auto_archive_days is the one nullable key — its type must allow both
	 * integer and null, matching the schema default of null (§4's "this
	 * level sets nothing" sentinel).
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_auto_archive_days_declares_nullable_integer_type() {
		$schema = RestSchema::site();

		$this->assertSame( array( 'integer', 'null' ), $schema['properties']['auto_archive_days']['type'] );
	}

	/**
	 * An array key (a multi-select) declares `array` with a string `items`
	 * schema, so a hostile non-string entry cannot pass REST validation.
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_array_key_declares_string_items() {
		$schema = RestSchema::site();

		$this->assertSame( 'array', $schema['properties']['auto_archive_types']['type'] );
		$this->assertSame( array( 'type' => 'string' ), $schema['properties']['auto_archive_types']['items'] );
	}

	/**
	 * auto_archive_child_mode declares its finite enum, matching
	 * ChildMode's own backed values exactly.
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_child_mode_key_declares_child_mode_enum() {
		$schema = RestSchema::site();

		$this->assertSame( array( 'open', 'locked', 'off' ), $schema['properties']['auto_archive_child_mode']['enum'] );
	}

	/**
	 * auto_archive_age_basis declares its own literal two-value enum.
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_age_basis_key_declares_modified_published_enum() {
		$schema = RestSchema::site();

		$this->assertSame( array( 'modified', 'published' ), $schema['properties']['auto_archive_age_basis']['enum'] );
	}

	/**
	 * A key with no enum (e.g. a plain integer) carries no `enum` entry at
	 * all, rather than an empty array — REST schema validation treats
	 * "no enum key" and "empty enum" very differently.
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_non_enum_key_has_no_enum_entry() {
		$schema = RestSchema::site();

		$this->assertArrayNotHasKey( 'enum', $schema['properties']['auto_archive_grace_days'] );
	}

	/**
	 * Every property's description matches Schema::description_for() —
	 * the REST schema does not restate the copy independently.
	 *
	 * @covers ArchivedPostStatus\Settings\RestSchema::site
	 */
	public function test_every_property_description_matches_schema() {
		$schema = RestSchema::site();

		foreach ( $schema['properties'] as $key => $property ) {
			$this->assertSame( Schema::description_for( $key ), $property['description'], "description for '{$key}'" );
		}
	}
}
