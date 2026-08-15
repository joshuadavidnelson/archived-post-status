<?php

namespace ArchivedPostStatus\Schedule\Queue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The one place that reads the environment to build a {@see Budget}.
 *
 * Every other class in this feature receives a `Budget` already built;
 * this is the sole caller of `ini_get()`. Both ceilings deliberately stop
 * well short of the real PHP limits: finishing a batch early and letting
 * the queue resume on the next run costs nothing, while being killed
 * mid-write by `max_execution_time` or an exhausted `memory_limit` costs a
 * half-processed batch and, for the sweeper, a post stuck mid-transition.
 *
 * @since 0.5.0
 */
final class BudgetFactory {

	/**
	 * Seconds assumed when `max_execution_time` reports 0.
	 *
	 * PHP reports 0 to mean "no limit" — but that is also exactly what
	 * WP-CLI and several FPM pools report while running under a perfectly
	 * ordinary, finite process lifetime. Treating 0 as "unlimited" would
	 * let a batch run until it is killed by something else entirely
	 * (the OS, a process supervisor) with none of this feature's own
	 * bookkeeping able to stop it cleanly first.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const FALLBACK_TIME_LIMIT = 30;

	/**
	 * The hard ceiling applied after the fallback, regardless of what
	 * `max_execution_time` (or its fallback) reports.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const TIME_LIMIT_CAP = 20;

	/**
	 * Percentage of the parsed `memory_limit` this run may actually use.
	 *
	 * A whole-number percent rather than a 0.9-style float on purpose: a float
	 * passed through `apply_filters()` is unusable under WP_Mock, whose hook
	 * double keys its argument array by each argument's value and so trips PHP
	 * 8.1's float-to-array-key deprecation — which `phpunit.xml.dist` promotes
	 * to a hard failure. Filter arguments in this plugin stay int, string, bool,
	 * or array.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const DEFAULT_MEMORY_PERCENT = 90;

	/**
	 * Bytes assumed when `memory_limit` reports `-1` (unlimited).
	 *
	 * 256M matches `WP_MAX_MEMORY_LIMIT`, the ceiling WordPress core itself
	 * assumes for admin and cron contexts when nothing more specific is
	 * configured (`wp-includes/default-constants.php`) — the same contexts
	 * this queue runs in. An operator who set `-1` on purpose gets a
	 * concrete, still-generous budget instead of a memory check that can
	 * never fire.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const FALLBACK_MEMORY_BYTES = 268435456; // 256M.

	/**
	 * Build a Budget from the current PHP ini settings.
	 *
	 * @since 0.5.0
	 * @param int $started_at Epoch when this run began.
	 * @return Budget
	 */
	public static function build( int $started_at ): Budget {
		return new Budget( $started_at, self::time_limit(), self::memory_limit() );
	}

	/**
	 * Resolve the time ceiling, in seconds.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	private static function time_limit(): int {
		$configured = (int) ini_get( 'max_execution_time' );

		if ( $configured <= 0 ) {
			$configured = self::FALLBACK_TIME_LIMIT;
		}

		$capped = min( $configured, self::TIME_LIMIT_CAP );

		/**
		 * Filters the queue run's time budget, in seconds.
		 *
		 * Applied after the unlimited-fallback and the {@see TIME_LIMIT_CAP}
		 * cap, so a site restoring a higher ceiling is an explicit,
		 * deliberate override rather than a side effect of ini settings.
		 *
		 * @since 0.5.0
		 * @param int $capped     The capped time limit, in seconds.
		 * @param int $configured The ini-reported (or fallback) value before capping.
		 */
		return (int) apply_filters( 'aps_queue_time_limit', $capped, $configured );
	}

	/**
	 * Resolve the memory ceiling, in bytes.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	private static function memory_limit(): int {
		$bytes = self::ini_memory_bytes();

		/**
		 * Filters the percentage of the parsed `memory_limit` a queue run may use.
		 *
		 * @since 0.5.0
		 * @param int $percent The margin, as a whole-number percentage of $bytes.
		 * @param int $bytes   The parsed memory_limit, in bytes, before the margin.
		 */
		$percent = (int) apply_filters( 'aps_queue_memory_percent', self::DEFAULT_MEMORY_PERCENT, $bytes );

		return intdiv( $bytes * $percent, 100 );
	}

	/**
	 * Parse the raw `memory_limit` ini value into bytes.
	 *
	 * `wp_convert_hr_to_bytes()` handles the "256M" / "1G" shorthand;
	 * `-1` (unlimited) is intercepted first and substituted with
	 * {@see FALLBACK_MEMORY_BYTES} before it ever reaches that parser.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	private static function ini_memory_bytes(): int {
		$raw = (string) ini_get( 'memory_limit' );

		if ( '-1' === trim( $raw ) ) {
			return self::FALLBACK_MEMORY_BYTES;
		}

		return (int) wp_convert_hr_to_bytes( $raw );
	}
}
