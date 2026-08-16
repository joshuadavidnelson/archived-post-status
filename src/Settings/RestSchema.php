<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;

/**
 * Derives the JSON Schema {@see SettingsPage} passes to `register_setting()`
 * as `show_in_rest => [ 'schema' => … ]` — the entire REST requirement for
 * site settings (§5.8); core exposes the option at `GET`/`POST
 * /wp/v2/settings` gated on `manage_options` once this schema is attached,
 * no custom REST controller needed.
 *
 * The JSON Schema `type` per key is not part of {@see Schema}'s own table —
 * that table's `sanitizer` closures encode the same information
 * procedurally, not declaratively, so there is nothing to read a `type`
 * string from. Rather than teach `Schema::definitions()` a second parallel
 * shape, the mapping is declared once here, next to its only consumer.
 *
 * @since 0.5.0
 */
final class RestSchema {

	/**
	 * Key => JSON Schema `type`. `auto_archive_days` is the one nullable
	 * case — `null` is the schema's own "this level sets nothing" sentinel
	 * (see {@see Schema}'s class docblock), so the REST type must allow it.
	 *
	 * @since 0.5.0
	 * @var array<string, string|string[]>
	 */
	private const TYPES = array(
		'is_read_only'                 => 'boolean',
		'scheduled_archive_enabled'    => 'boolean',
		'scheduled_archive_post_types' => 'array',
		'auto_archive_enabled'         => 'boolean',
		'auto_archive_days'            => array( 'integer', 'null' ),
		'auto_archive_child_mode'      => 'string',
		'auto_archive_types'           => 'array',
		'auto_archive_taxonomies'      => 'array',
		'auto_archive_age_basis'       => 'string',
		'auto_archive_grace_days'      => 'integer',
	);

	/**
	 * Keys whose JSON Schema type is `array` — these get a string `items`
	 * schema; everything else does not.
	 *
	 * @since 0.5.0
	 * @var string[]
	 */
	private const ARRAY_KEYS = array( 'scheduled_archive_post_types', 'auto_archive_types', 'auto_archive_taxonomies' );

	/**
	 * The JSON Schema for the site-level `aps_settings` option.
	 *
	 * @since 0.5.0
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	public static function site(): array {
		$properties = array();

		foreach ( Schema::keys_for_level( Schema::LEVEL_SITE ) as $key ) {
			$properties[ $key ] = self::property_for( $key );
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * @since 0.5.0
	 * @param string $key
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private static function property_for( string $key ): array {
		$property = array(
			'type'        => self::TYPES[ $key ] ?? 'string',
			'description' => Schema::description_for( $key ),
		);

		if ( in_array( $key, self::ARRAY_KEYS, true ) ) {
			$property['items'] = array( 'type' => 'string' );
		}

		$enum = self::enum_for( $key );
		if ( null !== $enum ) {
			$property['enum'] = $enum;
		}

		return $property;
	}

	/**
	 * The finite value set for the two enum-backed keys, or null for
	 * everything else. Not derived from {@see Schema} — see the class
	 * docblock for why.
	 *
	 * @since 0.5.0
	 * @param string $key
	 * @return ?string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- ChildMode's own cases()/value are the canonical way to read an enum's backed values.
	 */
	private static function enum_for( string $key ): ?array {
		return match ( $key ) {
			'auto_archive_child_mode' => array_map( static fn ( ChildMode $case ): string => $case->value, ChildMode::cases() ),
			'auto_archive_age_basis'  => array( 'modified', 'published' ),
			default                   => null,
		};
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
