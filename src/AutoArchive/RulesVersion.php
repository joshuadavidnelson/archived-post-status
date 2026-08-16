<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * A counter, bumped whenever any cascade level writes a rule.
 *
 * Per the plan's §4.7: a rule change invalidates every schedule that rule
 * produced. Each rule-sourced schedule stores the counter's value at the
 * moment it was stamped; {@see RuleStamper} finds stale schedules by
 * comparing that stored value against {@see self::current()} and re-stamps
 * only those, rather than re-walking every scheduled post on every rule
 * change.
 *
 * A plain option-backed counter, not a hookable — {@see
 * \ArchivedPostStatus\Settings\HookAdapter} calls the bump methods directly
 * from the same action callbacks that already flush {@see
 * \ArchivedPostStatus\Settings\Store}'s cache on a settings write.
 *
 * ## Why there are two counters
 *
 * A site rule is stored per site, but a NETWORK rule is stored once and
 * applies to every site on the network. Bumping a per-site counter from the
 * network admin screen would only ever reach the one site that request
 * happened to run on — every other site would keep serving its old counter,
 * and every schedule those sites had already stamped would stay pointing at
 * a due date the network admin thought they had just changed. Silently, and
 * on exactly the installs where the blast radius is largest.
 *
 * The alternative — looping every site on the network to bump each one — is
 * a non-transactional fan-out that gets slower and less safe as the network
 * grows, on a request that is otherwise O(1).
 *
 * So {@see self::current()} is the SUM of a per-site counter and a
 * network-wide one. Both only ever increase, so the sum only ever increases,
 * and a bump at either level changes what every site reads without any site
 * having to be visited.
 *
 * @since 0.5.0
 */
final class RulesVersion {

	/** The per-site counter's option key. Autoload is off. */
	public const OPTION_KEY = 'aps_rules_version';

	/** The network-wide counter's option key, on multisite only. */
	public const NETWORK_OPTION_KEY = 'aps_network_rules_version';

	/**
	 * The current version, as every site on the network sees it.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	public static function current(): int {
		return self::site_version() + self::network_version();
	}

	/**
	 * Increment the per-site counter, for a site-level rule change.
	 *
	 * @since 0.5.0
	 * @return int The new combined version.
	 */
	public static function bump(): int {
		$next = self::site_version() + 1;

		update_option( self::OPTION_KEY, $next, false );

		// Composed from the value just written rather than re-read, so the
		// return value cannot depend on whether the option cache has caught up
		// within this request.
		return self::bumped( $next + self::network_version() );
	}

	/**
	 * Increment the network-wide counter, for a network-level rule change.
	 *
	 * A no-op on single-site, where there is no network level to change.
	 *
	 * @since 0.5.0
	 * @return int The new combined version.
	 */
	public static function bump_network(): int {
		if ( ! is_multisite() ) {
			return self::current();
		}

		$next = self::network_version() + 1;

		update_network_option( null, self::NETWORK_OPTION_KEY, $next );

		return self::bumped( self::site_version() + $next );
	}

	/**
	 * The per-site half of the counter.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	private static function site_version(): int {
		return absint( get_option( self::OPTION_KEY, 0 ) );
	}

	/**
	 * The network-wide half of the counter. Always 0 on single-site.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	private static function network_version(): int {
		if ( ! is_multisite() ) {
			return 0;
		}

		return absint( get_network_option( null, self::NETWORK_OPTION_KEY, 0 ) );
	}

	/**
	 * Announce a bump and return the new combined version.
	 *
	 * @since 0.5.0
	 * @param int $version The new combined version.
	 * @return int
	 */
	private static function bumped( int $version ): int {

		/**
		 * Fires after the rules version counter is bumped.
		 *
		 * @since 0.5.0
		 * @param int $version The new version.
		 */
		do_action( 'aps_rules_version_bumped', $version );

		return $version;
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
