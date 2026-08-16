<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The pure staleness judgment behind the settings screen's cron health
 * notice (plan §5.4). A site whose host set `DISABLE_WP_CRON` without
 * configuring real cron gets no error today — just posts that never
 * archive, and then a support ticket; this is what lets the settings screen
 * catch that before it becomes one.
 *
 * No WordPress calls: {@see SettingsPage} reads the `aps_last_sweep` option,
 * the `DISABLE_WP_CRON` constant, and the `aps_schedule_sweep_interval`
 * filter, and hands the results here as plain values.
 *
 * @since 0.5.0
 */
final class CronHealthCheck {

	/**
	 * How many sweep intervals of silence before the sweep is considered
	 * stale — "several times the configured sweep interval" per §5.4. The
	 * default a caller falls back to when it does not apply its own
	 * `aps_schedule_stale_multiplier` filter.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	public const DEFAULT_STALE_MULTIPLIER = 3;

	/**
	 * Whether the settings screen should warn about the sweep's health.
	 *
	 * Two independent triggers: a sweep that has run before but has since
	 * gone quiet for several intervals, or a sweep that has never run at
	 * all while `DISABLE_WP_CRON` is in effect — the latter never
	 * self-resolves, since nothing will trigger WP-Cron to run it.
	 *
	 * A sweep that has simply never run yet on a fresh install, with real
	 * cron available, is not stale — it has not had the chance to fail.
	 *
	 * This class stays pure and WP-call-free by design (see the class
	 * docblock), so `$multiplier` is a plain parameter rather than an
	 * `apply_filters()` call here — {@see \ArchivedPostStatus\Settings\SettingsPage}
	 * resolves `aps_schedule_stale_multiplier` and passes the result in.
	 *
	 * @since 0.5.0
	 * @param int  $last_sweep       UTC epoch of the last recorded sweep, or
	 *                                0 if it has never run.
	 * @param bool $disable_wp_cron  Whether `DISABLE_WP_CRON` is defined and
	 *                                truthy.
	 * @param int  $now              Current UTC epoch.
	 * @param int  $interval_seconds The configured sweep interval, in seconds.
	 * @param int  $multiplier       How many intervals of silence before
	 *                                staleness; floored to 1.
	 * @return bool
	 */
	public static function is_stale(
		int $last_sweep,
		bool $disable_wp_cron,
		int $now,
		int $interval_seconds,
		int $multiplier = self::DEFAULT_STALE_MULTIPLIER
	): bool {
		if ( $last_sweep <= 0 ) {
			return $disable_wp_cron;
		}

		return ( $now - $last_sweep ) > ( max( 1, $interval_seconds ) * max( 1, $multiplier ) );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
