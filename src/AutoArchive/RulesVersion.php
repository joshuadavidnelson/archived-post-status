<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * A counter, bumped whenever any cascade level writes a rule.
 *
 * Per the plan's §4.7: a rule change invalidates every schedule that rule
 * produced. Each rule-sourced schedule stores the counter's value at the
 * moment it was stamped; the stamper (a later phase) finds stale schedules
 * by comparing that stored value against {@see self::current()} and
 * re-stamps only those, rather than re-walking every scheduled post on
 * every rule change.
 *
 * A plain option-backed counter, not a hookable — {@see
 * \ArchivedPostStatus\Settings\HookAdapter} calls {@see self::bump()}
 * directly from the same action callbacks that already flush {@see
 * \ArchivedPostStatus\Settings\Store}'s cache on a settings write.
 *
 * @since 0.5.0
 */
final class RulesVersion {

	/** The option key this counter is stored under. Autoload is off. */
	public const OPTION_KEY = 'aps_rules_version';

	/**
	 * The current version.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	public static function current(): int {
		return absint( get_option( self::OPTION_KEY, 0 ) );
	}

	/**
	 * Increment the version and persist it.
	 *
	 * @since 0.5.0
	 * @return int The new version.
	 */
	public static function bump(): int {
		$next = self::current() + 1;

		update_option( self::OPTION_KEY, $next, false );

		/**
		 * Fires after the rules version counter is bumped.
		 *
		 * @since 0.5.0
		 * @param int $version The new version.
		 */
		do_action( 'aps_rules_version_bumped', $next );

		return $next;
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
