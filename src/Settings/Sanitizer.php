<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The one sanitization boundary for stored plugin settings.
 *
 * {@see Store::update()} and {@see Store::save()} write straight to
 * `update_option()` with no sanitization of their own, so every settings
 * form and REST write must route the incoming array through
 * {@see self::sanitize()} before it reaches Store. Entirely schema-driven —
 * this class holds no per-key logic itself, only the loop.
 *
 * @since 0.5.0
 */
final class Sanitizer {

	/**
	 * Sanitize a settings array key by key.
	 *
	 * Keys {@see Schema} does not recognize are dropped, not passed through
	 * — an unknown key reaching `update_option()` unsanitized is exactly the
	 * hole this class exists to close.
	 *
	 * @since 0.5.0
	 * @param array<string, mixed> $input Raw incoming values, e.g. from a
	 *                                     settings form or a REST request.
	 * @return array<string, mixed> Sanitized values, unknown keys removed.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	public static function sanitize( array $input ): array {
		$sanitized = array();

		foreach ( $input as $key => $value ) {
			$sanitizer = Schema::sanitizer_for( $key );

			if ( null === $sanitizer ) {
				continue;
			}

			$sanitized[ $key ] = $sanitizer( $value );
		}

		return $sanitized;
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
