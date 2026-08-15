<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * One cascade level's contribution to the resolved auto-archive rule.
 *
 * The post level has no children to freeze, so it has no meaningful
 * `child_mode` of its own — its `$child_mode` is always {@see
 * ChildMode::Open}. This is documented rather than special-cased: {@see
 * RuleResolver} treats every level identically, and giving the post level a
 * fourth `child_mode`-less shape would be exactly the kind of special case
 * the resolver's algorithm is written to avoid.
 *
 * @since 0.5.0
 */
final class Rule {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param string    $level      Which cascade level this Rule represents:
	 *                              'network' | 'site' | 'term' | 'post'.
	 * @param ?int      $days       Days after the basis date this level says
	 *                              to archive at; null if this level sets
	 *                              nothing.
	 * @param ChildMode $child_mode What this level exposes to the levels
	 *                              below it.
	 * @param string    $label      UI provenance for this level's value,
	 *                              e.g. "Category: News".
	 */
	public function __construct(
		public readonly string $level,
		public readonly ?int $days,
		public readonly ChildMode $child_mode,
		public readonly string $label
	) {}
}
