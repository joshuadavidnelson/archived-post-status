<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The only place in PHP where a wall clock becomes a UTC epoch, or back.
 *
 * Every other class in this feature passes `int` epochs and never touches a
 * timezone. Compare stored epochs against `time()`, never
 * `current_time( 'timestamp' )` — that returns an offset-shifted integer
 * that is not a real Unix epoch and would silently corrupt every comparison
 * against a value this class produced.
 *
 * DST edge cases (a wall-clock time that does not exist on spring-forward,
 * or occurs twice on fall-back) resolve to PHP's own deterministic choice;
 * this class does not special-case them.
 *
 * @since 0.5.0
 */
final class ScheduleTime {

	/**
	 * Accepted wall-clock input formats, tried in order.
	 *
	 * @since 0.5.0
	 * @var string[]
	 */
	private const FORMATS = array( 'Y-m-d\TH:i', 'Y-m-d H:i:s' );

	/**
	 * Parse a local wall-clock string, in the site's timezone, to a UTC epoch.
	 *
	 * Accepts the `datetime-local` input format ("Y-m-d\TH:i") or a full
	 * "Y-m-d H:i:s" string. Malformed input — wrong shape, out-of-range
	 * components, trailing characters — returns null rather than letting
	 * DateTimeImmutable coerce it into a nearby valid date.
	 *
	 * @since 0.5.0
	 * @param string $local The wall-clock string to parse.
	 * @return int|null The UTC epoch, or null if $local is not parseable.
	 */
	public static function to_timestamp( string $local ): ?int {
		foreach ( self::FORMATS as $format ) {
			$date = self::parse_strict( $local, $format );
			if ( null !== $date ) {
				return $date->getTimestamp();
			}
		}

		return null;
	}

	/**
	 * Format a UTC epoch as local wall-clock parts, for a form field value.
	 *
	 * @since 0.5.0
	 * @param int $timestamp UTC epoch.
	 * @return string "Y-m-d\TH:i" in the site's timezone.
	 */
	public static function to_local( int $timestamp ): string {
		return ( new \DateTimeImmutable( '@' . $timestamp ) )
			->setTimezone( wp_timezone() )
			->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Format a UTC epoch for human display, using the site's date/time formats.
	 *
	 * @since 0.5.0
	 * @param int $timestamp UTC epoch.
	 * @return string The formatted, localized date/time string.
	 */
	public static function to_display( int $timestamp ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return (string) wp_date( $format, $timestamp );
	}

	/**
	 * Parse $local against $format in the site timezone, rejecting anything
	 * DateTimeImmutable had to warn or error about.
	 *
	 * The leading '!' resets every field the format does not specify to the
	 * Unix epoch instead of the current date/time, so an incomplete match
	 * can never inherit today's date. `getLastErrors()` is what catches
	 * out-of-range components (month 13, trailing characters) that
	 * `createFromFormat()` would otherwise silently roll into a nearby date.
	 *
	 * @since 0.5.0
	 * @param string $local  The wall-clock string to parse.
	 * @param string $format The format to parse it against.
	 * @return \DateTimeImmutable|null
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- core \DateTimeImmutable factory
	 * and error accessor; not a project class the StaticAccess rule's WP_Query /
	 * WP_Error / WP_CLI exceptions were meant to cover, but the same reasoning
	 * applies.
	 */
	private static function parse_strict( string $local, string $format ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!' . $format, $local, wp_timezone() );

		if ( false === $date ) {
			return null;
		}

		$errors = \DateTimeImmutable::getLastErrors();
		if ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			return null;
		}

		return $date;
	}
}
