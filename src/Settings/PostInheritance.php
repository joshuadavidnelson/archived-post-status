<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider;
use ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider;
use ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleReducer;
use ArchivedPostStatus\AutoArchive\RuleResolver;

/**
 * What one post inherits from the levels above it: network, site, and term —
 * the argument {@see CascadeField} renders against for the post editor's own
 * "after N days" override field (plan §5.9's fourth host).
 *
 * Unlike {@see TermInheritance}, this class has real post context, so it
 * reads through the actual {@see NetworkRuleProvider}, {@see SiteRuleProvider},
 * and {@see TermRuleProvider} instances rather than hand-building `Rule`s —
 * that gets the post-type and taxonomy opt-in gates each of those already
 * implements for free, which `TermInheritance` cannot do (a term applies
 * across every post using it, so it has no single post to check a type
 * opt-in against).
 *
 * Deliberately does NOT reapply the generic `aps_auto_archive_{level}_rule`
 * / term-tie filters {@see \ArchivedPostStatus\AutoArchive\RuleChain} fires
 * when resolving the real cascade — the same simplification
 * {@see TermInheritance} already makes. This is an inheritance PREVIEW for
 * a UI, not the resolution that decides what actually happens to the post;
 * {@see \aps_get_auto_archive_rule()} (which DOES fire every filter, via
 * {@see \ArchivedPostStatus\AutoArchive\RuleChain::default()}) remains the
 * one authoritative answer to "what will this post actually do", rendered
 * separately as the resolved-outcome line.
 *
 * @since 0.5.0
 */
final class PostInheritance {

	/**
	 * Resolve what one post inherits from network + site + term, excluding
	 * its own post-level override.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return CascadeInheritance
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- RuleReducer/RuleResolver are the canonical pure-resolver accessors.
	 */
	public static function resolve( int $post_id ): CascadeInheritance {
		$chain    = self::ancestor_chain( $post_id );
		$resolved = RuleResolver::resolve( $chain );

		$frozen_rule = self::find_frozen_rule( $chain, $resolved->frozen_by );

		return new CascadeInheritance(
			$resolved->days,
			$resolved->origin_label,
			$frozen_rule instanceof Rule && ChildMode::Locked === $frozen_rule->child_mode,
			$frozen_rule instanceof Rule && ChildMode::Off === $frozen_rule->child_mode,
			$frozen_rule?->label
		);
	}

	/**
	 * The network, site, and term levels' own (already-reduced) contributions
	 * for this post, general to specific — the post level is deliberately
	 * excluded, since that is the level this class exists to describe what
	 * is inherited INTO.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return Rule[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- RuleReducer is the canonical pure-reducer accessor.
	 */
	private static function ancestor_chain( int $post_id ): array {
		$chain = array();

		foreach ( self::providers() as $provider ) {
			$reduced = RuleReducer::reduce( $provider->rules_for( $post_id ), $provider->level() );

			if ( $reduced instanceof Rule ) {
				$chain[] = $reduced;
			}
		}

		return $chain;
	}

	/**
	 * @since 0.5.0
	 * @return array<int, NetworkRuleProvider|SiteRuleProvider|TermRuleProvider>
	 */
	private static function providers(): array {
		return array(
			new NetworkRuleProvider(),
			new SiteRuleProvider(),
			new TermRuleProvider(),
		);
	}

	/**
	 * Find the chain entry whose level matches the resolver's `frozen_by`,
	 * the same look-back {@see TermInheritance::resolve()} performs — needed
	 * because {@see RuleResolver::resolve()} only reports the freezing
	 * level's name, not whether it was Locked or Off.
	 *
	 * @since 0.5.0
	 * @param Rule[]  $chain
	 * @param ?string $frozen_by
	 * @return ?Rule
	 */
	private static function find_frozen_rule( array $chain, ?string $frozen_by ): ?Rule {
		foreach ( $chain as $rule ) {
			if ( $rule->level === $frozen_by ) {
				return $rule;
			}
		}

		return null;
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
