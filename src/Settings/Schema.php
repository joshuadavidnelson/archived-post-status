<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;

/**
 * The single source of truth for every plugin setting: key, default,
 * sanitizer, label, description, and which cascade levels it applies to.
 *
 * Five things derive from this table rather than restating it — {@see
 * Store::defaults()}, {@see Sanitizer::sanitize()}, the site settings screen
 * ({@see SettingsPage}, this phase's consumer for `label`/`description`),
 * the network settings screen, and the REST schema plus the CLI's key
 * validation. The last two are later phases. Adding a setting means adding
 * one row here, not editing five files.
 *
 * `auto_archive_days` defaults to `null`, not a number. `null` means "this
 * level sets nothing" and is what lets the cascade fall through to another
 * level — a numeric default here would mean every site silently carries a
 * site-level rule the moment auto-archive is switched on.
 *
 * @since 0.5.0
 */
final class Schema {

	/** Cascade level constants, matching {@see \ArchivedPostStatus\AutoArchive\RuleProviderInterface::level()}. */
	public const LEVEL_NETWORK = 'network';
	public const LEVEL_SITE    = 'site';
	public const LEVEL_TERM    = 'term';
	public const LEVEL_POST    = 'post';

	/**
	 * Every setting key, in table order.
	 *
	 * @since 0.5.0
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * The default value for one key.
	 *
	 * Returns `null` both for an unknown key and for a known key whose real
	 * default is `null` (`auto_archive_days`) — callers that need to tell
	 * those apart should check {@see self::keys()} first.
	 *
	 * @since 0.5.0
	 * @param string $key The setting key.
	 * @return mixed
	 */
	public static function default_for( string $key ): mixed {
		return self::definitions()[ $key ]['default'] ?? null;
	}

	/**
	 * The sanitizer for one key: raw stored/incoming value in, sanitized
	 * value out. `null` for an unknown key.
	 *
	 * @since 0.5.0
	 * @param string $key The setting key.
	 * @return ?callable
	 */
	public static function sanitizer_for( string $key ): ?callable {
		return self::definitions()[ $key ]['sanitizer'] ?? null;
	}

	/**
	 * The human-readable label for one key, e.g. a settings field's
	 * `<label>` text. Empty string for an unknown key.
	 *
	 * @since 0.5.0
	 * @param string $key The setting key.
	 * @return string
	 */
	public static function label_for( string $key ): string {
		return self::definitions()[ $key ]['label'] ?? '';
	}

	/**
	 * The explanatory description for one key, e.g. a settings field's
	 * helper text. Empty string for an unknown key.
	 *
	 * @since 0.5.0
	 * @param string $key The setting key.
	 * @return string
	 */
	public static function description_for( string $key ): string {
		return self::definitions()[ $key ]['description'] ?? '';
	}

	/**
	 * The keys that apply at a given cascade level.
	 *
	 * @since 0.5.0
	 * @param string $level One of self::LEVEL_*.
	 * @return string[]
	 */
	public static function keys_for_level( string $level ): array {
		return array_keys(
			array_filter(
				self::definitions(),
				static fn ( array $definition ): bool => in_array( $level, $definition['levels'], true )
			)
		);
	}

	/**
	 * The key => {default, levels, sanitizer, label, description} table —
	 * the plan's §5.8 list, transcribed exactly, plus the label/description
	 * pair this phase adds now that the settings screen is a real consumer.
	 *
	 * @since 0.5.0
	 * @return array<string, array{default: mixed, levels: string[], sanitizer: callable, label: string, description: string}>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- ChildMode's own cases()/value are the canonical way to read an enum's backed values.
	 */
	private static function definitions(): array {
		return array(
			'is_read_only'                 => array(
				'default'     => true,
				'levels'      => array( self::LEVEL_SITE ),
				'sanitizer'   => self::bool_sanitizer(),
				'label'       => __( 'Read-only archive', 'archived-post-status' ),
				'description' => __( 'Prevent edits to content while it is in the Archived status.', 'archived-post-status' ),
			),
			'scheduled_archive_enabled'    => array(
				'default'     => true,
				'levels'      => array( self::LEVEL_NETWORK, self::LEVEL_SITE ),
				'sanitizer'   => self::bool_sanitizer(),
				'label'       => __( 'Per-post scheduling', 'archived-post-status' ),
				'description' => __( 'Let editors pick a date and time to archive an individual post.', 'archived-post-status' ),
			),
			'scheduled_archive_post_types' => array(
				'default'     => array(),
				'levels'      => array( self::LEVEL_SITE ),
				'sanitizer'   => self::post_types_sanitizer(),
				'label'       => __( 'Schedulable post types', 'archived-post-status' ),
				'description' => __( 'Post types that show the archive date picker. Leave empty to allow every supported post type.', 'archived-post-status' ),
			),
			'auto_archive_enabled'         => array(
				'default'     => false,
				'levels'      => array( self::LEVEL_NETWORK, self::LEVEL_SITE ),
				'sanitizer'   => self::bool_sanitizer(),
				'label'       => __( 'Automatic archiving', 'archived-post-status' ),
				'description' => __( 'Archive posts automatically after a set number of days, with no editor needing to pick a date.', 'archived-post-status' ),
			),
			'auto_archive_days'            => array(
				'default'     => null,
				'levels'      => array( self::LEVEL_NETWORK, self::LEVEL_SITE, self::LEVEL_TERM, self::LEVEL_POST ),
				'sanitizer'   => self::nullable_int_sanitizer(),
				'label'       => __( 'Archive after (days)', 'archived-post-status' ),
				'description' => __( 'Days after the archive basis (published or modified date) before a post is archived automatically.', 'archived-post-status' ),
			),
			'auto_archive_child_mode'      => array(
				'default'     => ChildMode::Open->value,
				'levels'      => array( self::LEVEL_NETWORK, self::LEVEL_SITE, self::LEVEL_TERM ),
				'sanitizer'   => self::enum_sanitizer(
					array_map( static fn ( ChildMode $case ): string => $case->value, ChildMode::cases() ),
					ChildMode::Open->value
				),
				'label'       => __( 'Category and post control', 'archived-post-status' ),
				'description' => __( 'Whether categories and individual posts may set their own automatic-archive rule, or must use this one.', 'archived-post-status' ),
			),
			'auto_archive_types'           => array(
				'default'     => array(),
				'levels'      => array( self::LEVEL_SITE ),
				'sanitizer'   => self::post_types_sanitizer(),
				'label'       => __( 'Automatically archived post types', 'archived-post-status' ),
				'description' => __( 'Post types the automatic-archive rule applies to.', 'archived-post-status' ),
			),
			'auto_archive_taxonomies'      => array(
				'default'     => array( 'category' ),
				'levels'      => array( self::LEVEL_SITE ),
				'sanitizer'   => self::taxonomies_sanitizer(),
				'label'       => __( 'Category-level rules', 'archived-post-status' ),
				'description' => __( 'Taxonomies that can set their own automatic-archive rule, overriding the site default.', 'archived-post-status' ),
			),
			'auto_archive_age_basis'       => array(
				'default'     => 'modified',
				'levels'      => array( self::LEVEL_SITE ),
				'sanitizer'   => self::enum_sanitizer( array( 'modified', 'published' ), 'modified' ),
				'label'       => __( 'Archive age basis', 'archived-post-status' ),
				'description' => __( 'Whether the automatic-archive countdown is measured from the publish date or the last-modified date.', 'archived-post-status' ),
			),
			'auto_archive_grace_days'      => array(
				'default'     => 7,
				'levels'      => array( self::LEVEL_SITE ),
				'sanitizer'   => self::int_sanitizer(),
				'label'       => __( 'Backlog grace period (days)', 'archived-post-status' ),
				'description' => __( 'Minimum days before an already-old post is archived after a rule newly applies to it, so enabling a rule never empties the site immediately.', 'archived-post-status' ),
			),
		);
	}

	/**
	 * @since 0.5.0
	 * @return callable(mixed): bool
	 */
	private static function bool_sanitizer(): callable {
		return static fn ( mixed $value ): bool => (bool) $value;
	}

	/**
	 * @since 0.5.0
	 * @return callable(mixed): int
	 */
	private static function int_sanitizer(): callable {
		return static fn ( mixed $value ): int => absint( $value );
	}

	/**
	 * `null` passes through untouched — it is the "this level sets nothing"
	 * sentinel, not a value to clamp. Anything else runs through `absint()`
	 * (which also turns a negative string/int into its magnitude) and is
	 * then floored at 1, so a stored `0` cannot become "archive immediately".
	 *
	 * @since 0.5.0
	 * @return callable(mixed): ?int
	 */
	private static function nullable_int_sanitizer(): callable {
		return static function ( mixed $value ): ?int {
			if ( null === $value ) {
				return null;
			}

			return max( 1, absint( $value ) );
		};
	}

	/**
	 * @since 0.5.0
	 * @param string[] $allowed The recognized values.
	 * @param string   $default Fallback for anything not in $allowed.
	 * @return callable(mixed): string
	 */
	private static function enum_sanitizer( array $allowed, string $default ): callable {
		return static fn ( mixed $value ): string => in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * @since 0.5.0
	 * @return callable(mixed): string[]
	 */
	private static function post_types_sanitizer(): callable {
		return static function ( mixed $value ): array {
			if ( ! is_array( $value ) ) {
				return array();
			}

			return array_values( array_intersect( array_map( 'sanitize_key', $value ), aps_get_supported_post_types() ) );
		};
	}

	/**
	 * @since 0.5.0
	 * @return callable(mixed): string[]
	 */
	private static function taxonomies_sanitizer(): callable {
		return static function ( mixed $value ): array {
			if ( ! is_array( $value ) ) {
				return array();
			}

			return array_values( array_intersect( array_map( 'sanitize_key', $value ), get_taxonomies() ) );
		};
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
