<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The WordPress boundary for the cascade: assembles an ordered `Rule[]` from
 * injected providers and resolves it, firing every filter the plan's §4.5
 * extensibility promise depends on.
 *
 * Unlike {@see RuleResolver} and {@see RuleReducer}, this class is not pure —
 * calling `apply_filters()` is its entire job. It stays unit-testable anyway
 * because providers arrive through the constructor rather than being looked
 * up here; a test hands it fakes and asserts on the resolved outcome exactly
 * as a real caller would.
 *
 * Two of the four filters this class fires hand a site a bare array it can
 * return anything from (`aps_auto_archive_levels`, `aps_auto_archive_rule_chain`).
 * Both are filtered back down to instances of the expected type before use —
 * silently dropping anything else — because {@see RuleResolver::resolve()}
 * takes a typed `Rule[]` and would fatal with a `TypeError` on a live page
 * load if a stray string or unrelated object reached it. Silence over a hard
 * error is a deliberate choice: a filter misbehaving on one site must not be
 * able to take auto-archive resolution down for every post on that site, and
 * there is no admin-facing surface at resolution time to report the mistake
 * to. `aps_auto_archive_resolved_rule` gets no equivalent guard — it is
 * genuinely the last word on the outcome, the same trust `aps_pre_archive_post`
 * gets at `Archive\ArchiveOperation::perform()`, and a site replacing it
 * wholesale is expected to return the type it was handed.
 *
 * When the level under consultation is `term`, this class also fires
 * `aps_auto_archive_term_rule_days` and `aps_auto_archive_term_rule_child_mode`
 * (plan §4.2/§5.11) on the two independent halves of {@see RuleReducer}'s
 * already-collapsed result, before the generic per-level filter below sees
 * it — the only seam that lets a site override just the days-minimum tie-
 * break, or just the child_mode-strictest one, without replacing the other.
 *
 * @since 0.5.0
 */
final class RuleChain {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param RuleProviderInterface[] $providers Ordered general -> specific
	 *                                            (e.g. network, site, term,
	 *                                            post).
	 */
	public function __construct( private readonly array $providers ) {}

	/**
	 * Resolve the cascade for one post: assemble the chain, then resolve it.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return ResolvedRule
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical pure-resolver accessor.
	 */
	public function resolve_for( int $post_id ): ResolvedRule {
		$chain = $this->assemble_chain( $post_id );

		/**
		 * Filters the assembled Rule chain before it is resolved.
		 *
		 * This is the extensibility seam the plan's §4.5 describes: a third
		 * party appends (or inserts, or removes) a `Rule` here to add a
		 * level — author, post type, whatever it needs — with no core
		 * change and no new resolver. Position in the returned array is
		 * significance: {@see RuleResolver::resolve()} treats the array as
		 * ordered general to specific, exactly as it does the levels this
		 * release ships.
		 *
		 * @since 0.5.0
		 * @param Rule[] $chain   The chain assembled from every provider's
		 *                        (already filtered) per-level contribution.
		 * @param int    $post_id The post ID being resolved.
		 */
		$chain = self::only_rules( apply_filters( 'aps_auto_archive_rule_chain', $chain, $post_id ) );

		$resolved = RuleResolver::resolve( $chain );

		/**
		 * Filters the final resolved auto-archive outcome for one post.
		 *
		 * The last word: nothing in {@see RuleChain} or {@see RuleResolver}
		 * runs after this. Unlike `aps_auto_archive_rule_chain`, above, this
		 * value is not defensively re-typed — a site replacing it wholesale
		 * is expected to return a {@see ResolvedRule}, the same trust
		 * `aps_pre_archive_post` is given at
		 * {@see \ArchivedPostStatus\Archive\ArchiveOperation::perform()}.
		 *
		 * @since 0.5.0
		 * @param ResolvedRule $resolved The resolved outcome.
		 * @param int          $post_id  The post ID being resolved.
		 */
		return apply_filters( 'aps_auto_archive_resolved_rule', $resolved, $post_id );
	}

	/**
	 * Collect one Rule per provider, general -> specific, skipping any level
	 * with nothing to contribute.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return Rule[]
	 */
	private function assemble_chain( int $post_id ): array {
		$chain = array();

		foreach ( $this->providers() as $provider ) {
			$rule = $this->level_rule( $provider, $post_id );

			if ( $rule instanceof Rule ) {
				$chain[] = $rule;
			}
		}

		return $chain;
	}

	/**
	 * Resolve the provider list through `aps_auto_archive_levels`.
	 *
	 * @since 0.5.0
	 * @return RuleProviderInterface[]
	 */
	private function providers(): array {

		/**
		 * Filters the ordered list of cascade rule providers.
		 *
		 * A site can reorder, remove, or insert a level here — reordering
		 * changes which level is "most specific" and therefore which one
		 * wins under §4.1's resolution rule.
		 *
		 * @since 0.5.0
		 * @param RuleProviderInterface[] $providers Ordered general ->
		 *                                            specific.
		 */
		$providers = apply_filters( 'aps_auto_archive_levels', $this->providers );

		return self::only_providers( $providers );
	}

	/**
	 * One provider's reduced Rule, after its per-level filter.
	 *
	 * Applying `aps_auto_archive_{$level}_rule` here, generically, rather
	 * than inside each provider, is deliberate: it means every provider
	 * phases 5-9 add stays a dumb reader and none of them re-implements its
	 * own filter.
	 *
	 * @since 0.5.0
	 * @param RuleProviderInterface $provider The provider to consult.
	 * @param int                   $post_id  The post ID.
	 * @return ?Rule
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical pure-reducer accessor.
	 */
	private function level_rule( RuleProviderInterface $provider, int $post_id ): ?Rule {
		$level   = $provider->level();
		$reduced = RuleReducer::reduce( $provider->rules_for( $post_id ), $level );

		if ( 'term' === $level && $reduced instanceof Rule ) {
			$reduced = self::apply_term_tie_filters( $reduced, $post_id );
		}

		/**
		 * Filters one cascade level's own contribution to the rule chain.
		 *
		 * The dynamic portion of the hook name, `$level`, is the value
		 * {@see RuleProviderInterface::level()} returns for the provider
		 * currently being consulted. For the four levels this release ships
		 * the possible hook names are:
		 *
		 *  - `aps_auto_archive_network_rule`
		 *  - `aps_auto_archive_site_rule`
		 *  - `aps_auto_archive_term_rule`
		 *  - `aps_auto_archive_post_rule`
		 *
		 * A third-party provider naming any other level fires the matching
		 * `aps_auto_archive_{$level}_rule` hook for it.
		 *
		 * @since 0.5.0
		 * @param ?Rule $rule    This level's Rule, already reduced from
		 *                       everything {@see RuleProviderInterface::rules_for()}
		 *                       returned via {@see RuleReducer::reduce()}, or
		 *                       null if this level has nothing to contribute.
		 * @param int   $post_id The post ID being resolved.
		 */
		$rule = apply_filters( "aps_auto_archive_{$level}_rule", $reduced, $post_id );

		return $rule instanceof Rule ? $rule : null;
	}

	/**
	 * Let a site override the two halves of the term level's §4.2 tie-break
	 * independently, before the generic `aps_auto_archive_term_rule` filter
	 * sees the result.
	 *
	 * {@see RuleReducer::reduce()} already collapsed every applicable term's
	 * `Rule` to the winning `days` (the minimum among terms that set one)
	 * and the winning `child_mode` (the strictest present) — this only
	 * exposes each half as its own filter. The generic per-level filter
	 * cannot do this: it only ever sees the single already-combined `Rule`,
	 * so a site wanting to change just the tie-break for days (e.g. "sum"
	 * instead of "min") without also touching the child_mode strictness, or
	 * the reverse, has no seam without this.
	 *
	 * @since 0.5.0
	 * @param Rule $reduced The term level's Rule, already reduced from every
	 *                       applicable term.
	 * @param int  $post_id The post ID being resolved.
	 * @return Rule
	 */
	private static function apply_term_tie_filters( Rule $reduced, int $post_id ): Rule {

		/**
		 * Filters the term level's resolved `days` value (plan §4.2): the
		 * minimum among every applicable term across every opted-in
		 * taxonomy that sets one.
		 *
		 * @since 0.5.0
		 * @param ?int $days    The minimum `days` among applicable terms, or
		 *                      null if no applicable term set one.
		 * @param int  $post_id The post ID being resolved.
		 */
		$days = apply_filters( 'aps_auto_archive_term_rule_days', $reduced->days, $post_id );

		/**
		 * Filters the term level's resolved `child_mode` (plan §4.2): the
		 * strictest mode among every applicable term — `Off` > `Locked` >
		 * `Open`.
		 *
		 * @since 0.5.0
		 * @param ChildMode $child_mode The strictest `child_mode` among
		 *                              applicable terms.
		 * @param int       $post_id    The post ID being resolved.
		 */
		$child_mode = apply_filters( 'aps_auto_archive_term_rule_child_mode', $reduced->child_mode, $post_id );

		return new Rule(
			$reduced->level,
			self::only_days( $days ),
			self::only_child_mode( $child_mode, $reduced->child_mode ),
			$reduced->label
		);
	}

	/**
	 * Retype whatever `aps_auto_archive_term_rule_days` returned down to a
	 * valid `?int`, dropping anything else silently — see the class
	 * docblock's rationale for the same treatment of the array-typed
	 * filters. A dedicated method, not an inline check, because PHPStan
	 * treats the hook docblock immediately above an `apply_filters()` call
	 * as authoritative for that call's return type; losing that narrowed
	 * type across a `mixed`-typed method boundary is what makes the
	 * fallback check meaningful to it, not a redundant `instanceof`/`is_int`
	 * against a type already known to be correct.
	 *
	 * @since 0.5.0
	 * @param mixed $days Whatever `aps_auto_archive_term_rule_days` returned.
	 * @return ?int
	 */
	private static function only_days( mixed $days ): ?int {
		return is_int( $days ) ? $days : null;
	}

	/**
	 * Retype whatever `aps_auto_archive_term_rule_child_mode` returned down
	 * to a valid `ChildMode`, falling back to the pre-filter value — see
	 * {@see self::only_days()} for why this is a dedicated method rather
	 * than an inline check.
	 *
	 * @since 0.5.0
	 * @param mixed     $child_mode Whatever `aps_auto_archive_term_rule_child_mode` returned.
	 * @param ChildMode $fallback   The reduced value to use when `$child_mode` is not a `ChildMode`.
	 * @return ChildMode
	 */
	private static function only_child_mode( mixed $child_mode, ChildMode $fallback ): ChildMode {
		return $child_mode instanceof ChildMode ? $child_mode : $fallback;
	}

	/**
	 * Filter a value down to only its `RuleProviderInterface` entries,
	 * dropping anything else silently — see the class docblock.
	 *
	 * @since 0.5.0
	 * @param mixed $providers Whatever `aps_auto_archive_levels` returned.
	 * @return RuleProviderInterface[]
	 */
	private static function only_providers( mixed $providers ): array {
		if ( ! is_array( $providers ) ) {
			return array();
		}

		return array_values( array_filter( $providers, static fn ( $provider ): bool => $provider instanceof RuleProviderInterface ) );
	}

	/**
	 * Filter a value down to only its `Rule` entries, dropping anything else
	 * silently — see the class docblock.
	 *
	 * @since 0.5.0
	 * @param mixed $chain Whatever `aps_auto_archive_rule_chain` returned.
	 * @return Rule[]
	 */
	private static function only_rules( mixed $chain ): array {
		if ( ! is_array( $chain ) ) {
			return array();
		}

		return array_values( array_filter( $chain, static fn ( $rule ): bool => $rule instanceof Rule ) );
	}
}
