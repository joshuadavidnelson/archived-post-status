<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Registers the sweep's cron recurrence and self-repairs the recurring event.
 *
 * A lost event -- a bad deactivate, a migration that skipped the
 * deactivation hook, a host that flushed the cron option -- heals on the
 * very next `init` rather than silently never archiving again; that is the
 * whole reason this runs on `init` rather than only at plugin activation
 * (this plugin has no activation hook at all -- see
 * `archived-post-status.php`).
 *
 * {@see RECURRING_EVENTS} is the single source both {@see maybe_schedule_events()}
 * and {@see recurring_hooks()} read from, so the deactivation cleanup in
 * `archived-post-status.php` never drifts out of sync with what this class
 * actually schedules. The daily rule-apply event added in 0.5.0's stamp
 * queue is one more entry there -- nothing else in this class, or in the
 * deactivation hook that reads {@see recurring_hooks()}, needed to change to
 * pick it up.
 *
 * @since 0.5.0
 */
final class CronRegistrar implements HookableInterface {

	/**
	 * The recurring sweep event's cron hook.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const HOOK_RUN_SCHEDULED_ARCHIVES = 'aps_run_scheduled_archives';

	/**
	 * The recurring stamp event's cron hook -- applies the auto-archive
	 * rule cascade, stamping new matches and refreshing stale-version
	 * stamps (plan §4.7).
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const HOOK_APPLY_AUTO_ARCHIVE_RULES = 'aps_apply_auto_archive_rules';

	/**
	 * The custom `cron_schedules` recurrence key the sweep event runs on.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const RECURRENCE_KEY = 'aps_sweep_interval';

	/**
	 * Default sweep recurrence, in seconds (5 minutes).
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const DEFAULT_INTERVAL_SECONDS = 300;

	/**
	 * Every recurring event this plugin schedules: cron hook => the
	 * `cron_schedules` recurrence key it runs on.
	 *
	 * The stamp event runs on WordPress core's built-in `daily` recurrence
	 * rather than a custom one -- unlike the sweep interval, its cadence is
	 * not filterable, so there is no need for {@see register_interval()} to
	 * register anything for it.
	 *
	 * @since 0.5.0
	 * @var array<string, string>
	 */
	private const RECURRING_EVENTS = array(
		self::HOOK_RUN_SCHEDULED_ARCHIVES   => self::RECURRENCE_KEY,
		self::HOOK_APPLY_AUTO_ARCHIVE_RULES => 'daily',
	);

	/**
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::filter( 'cron_schedules', array( $this, 'register_interval' ) ),
			HookDescriptor::action( 'init', array( $this, 'maybe_schedule_events' ) ),
		);
	}

	/**
	 * Every recurring cron hook this plugin schedules.
	 *
	 * Read by the `register_deactivation_hook()` callback in
	 * `archived-post-status.php`, which clears exactly this list plus the
	 * separate `aps_continue_queue` continuation hook it does not own.
	 *
	 * @since 0.5.0
	 * @return string[]
	 */
	public static function recurring_hooks(): array {
		return array_keys( self::RECURRING_EVENTS );
	}

	/**
	 * `cron_schedules` filter callback: registers this plugin's sweep
	 * recurrence.
	 *
	 * @since 0.5.0
	 * @param array<string, array{interval: int, display: string}> $schedules Existing recurrences.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function register_interval( array $schedules ): array {
		$schedules[ self::RECURRENCE_KEY ] = array(
			'interval' => $this->sweep_interval_seconds(),
			'display'  => __( 'Archived Post Status sweep interval', 'archived-post-status' ),
		);

		return $schedules;
	}

	/**
	 * `init` callback: schedules any recurring event this plugin owns that
	 * has gone missing.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function maybe_schedule_events(): void {
		foreach ( self::RECURRING_EVENTS as $hook => $recurrence ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	/**
	 * Resolve the sweep recurrence's interval, in seconds.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	private function sweep_interval_seconds(): int {

		/**
		 * Filters the sweep cron event's recurrence interval, in seconds.
		 *
		 * @since 0.5.0
		 * @param int $seconds Default 300 (5 minutes).
		 */
		return (int) apply_filters( 'aps_schedule_sweep_interval', self::DEFAULT_INTERVAL_SECONDS );
	}
}
