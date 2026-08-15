<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Walks a cascade chain to its resolved auto-archive outcome.
 *
 * THE single most important class in the release: get this wrong and the
 * plugin silently archives the wrong content on real sites. The algorithm
 * is exactly the plan's §4.1 rule, translated one-to-one with no special
 * cases added: walk the chain from most general to most specific; the last
 * level that set an explicit `$days` value wins; a level whose `child_mode`
 * is not {@see ChildMode::Open} freezes every level below it, so the walk
 * stops there.
 *
 * A level that freezes the walk but sets no `$days` of its own does NOT
 * supply a value — it only stops the walk. The origin stays with whichever
 * earlier level last set `$days`; only `$frozen_by` moves to the freezing
 * level. This class never mentions "network", "site", "term", or "post" by
 * name: the chain is data, so a caller may hand it any ordered Rule[] and
 * this resolver treats every entry identically.
 *
 * @since 0.5.0
 */
final class RuleResolver {

	/**
	 * Resolve an ordered chain of Rules to one outcome.
	 *
	 * @since 0.5.0
	 * @param Rule[] $chain Rules ordered from most general to most specific
	 *                      (e.g. network, site, term, post).
	 * @return ResolvedRule
	 */
	public static function resolve( array $chain ): ResolvedRule {
		$days         = null;
		$origin_level = null;
		$origin_label = null;
		$frozen_by    = null;

		foreach ( $chain as $rule ) {
			if ( null !== $frozen_by ) {
				break;
			}

			if ( null !== $rule->days ) {
				$days         = $rule->days;
				$origin_level = $rule->level;
				$origin_label = $rule->label;
			}

			if ( $rule->child_mode->freezes_children() ) {
				$frozen_by = $rule->level;
			}
		}

		return new ResolvedRule( $days, $origin_level, $origin_label, $frozen_by );
	}
}
