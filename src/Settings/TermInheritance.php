<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleResolver;

/**
 * What every term inherits: the network level (if network-activated and
 * enabled) walked into the site level, resolved through
 * {@see RuleResolver} exactly as the real cascade would.
 *
 * Term-agnostic — the same {@see CascadeInheritance} applies to every
 * taxonomy and every term {@see TermFields} renders, so this is computed
 * once per request rather than once per term form. Split out of
 * `TermFields` to keep that class's own coupling under PHPMD's
 * `CouplingBetweenObjects` ceiling; see the phase-7 ledger entry for the
 * identical `RuleQuery`/`RuleStamper` split under the same pressure.
 *
 * @since 0.5.0
 */
final class TermInheritance {

	/**
	 * Resolve what a term inherits from network + site.
	 *
	 * `RuleResolver::resolve()` only reports which level's `child_mode`
	 * froze the walk by name (`$frozen_by`), not whether that mode was
	 * Locked or Off — so the freezing Rule is looked back up in the same
	 * chain afterward to recover that distinction, rather than
	 * re-implementing the walk here a second time.
	 *
	 * @since 0.5.0
	 * @return CascadeInheritance
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- RuleResolver is the canonical pure-resolver accessor.
	 */
	public static function resolve(): CascadeInheritance {
		$chain = array_values(
			array_filter(
				array( self::network_rule(), self::site_rule() ),
				static fn ( ?Rule $rule ): bool => null !== $rule
			)
		);

		$resolved = RuleResolver::resolve( $chain );

		$frozen_rule = null;
		foreach ( $chain as $rule ) {
			if ( $rule->level === $resolved->frozen_by ) {
				$frozen_rule = $rule;
			}
		}

		return new CascadeInheritance(
			$resolved->days,
			$resolved->origin_label,
			$frozen_rule instanceof Rule && ChildMode::Locked === $frozen_rule->child_mode,
			$frozen_rule instanceof Rule && ChildMode::Off === $frozen_rule->child_mode,
			$frozen_rule?->label
		);
	}

	/**
	 * The network level's own contribution, independent of any post — the
	 * same call {@see \ArchivedPostStatus\AutoArchive\RuleQuery} makes for
	 * its own network aggregate.
	 *
	 * @since 0.5.0
	 * @return ?Rule
	 */
	private static function network_rule(): ?Rule {
		$rules = ( new NetworkRuleProvider() )->rules_for( 0 );

		return array() === $rules ? null : $rules[0];
	}

	/**
	 * The site level's own contribution, read directly through
	 * {@see Store} the same way {@see SettingsPage} reads its own level —
	 * deliberately not gated on a post-type opt-in the way
	 * {@see \ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider} is:
	 * a term applies across every post type in it, so there is no single
	 * post type to check here.
	 *
	 * @since 0.5.0
	 * @return ?Rule
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/store/enum accessors.
	 */
	private static function site_rule(): ?Rule {
		if ( ! (bool) Store::get( 'auto_archive_enabled', Schema::default_for( 'auto_archive_enabled' ) ) ) {
			return null;
		}

		$days = Store::get( 'auto_archive_days', Schema::default_for( 'auto_archive_days' ) );
		$days = null === $days ? null : (int) $days;

		$child_mode = ChildMode::tryFrom( (string) Store::get( 'auto_archive_child_mode', Schema::default_for( 'auto_archive_child_mode' ) ) )
			?? ChildMode::Open;

		return new Rule( 'site', $days, $child_mode, __( 'Site default', 'archived-post-status' ) );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
