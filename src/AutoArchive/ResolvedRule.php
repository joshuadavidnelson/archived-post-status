<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The outcome of walking a cascade chain through {@see RuleResolver}.
 *
 * Carries everything a UI needs to render the resolved outcome — e.g. "3
 * March 2027 — from Category: News (3 days)" on the post editor — without
 * recomputing anything against the chain again. `$origin_level` and
 * `$origin_label` name whichever level actually supplied `$days`, which is
 * not necessarily the level named by `$frozen_by`: a level can freeze the
 * walk while contributing no value of its own, in which case an earlier
 * level keeps origin ownership while a later one holds `$frozen_by`.
 *
 * @since 0.5.0
 */
final class ResolvedRule {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param ?int    $days         The resolved days value; null if no level
	 *                              in the chain set one — nothing is
	 *                              auto-archived.
	 * @param ?string $origin_level Which level supplied `$days`; null when
	 *                              `$days` is null.
	 * @param ?string $origin_label That level's label, for UI provenance;
	 *                              null when `$days` is null.
	 * @param ?string $frozen_by    The level whose non-Open child_mode
	 *                              stopped the walk, or null if nothing
	 *                              froze it.
	 */
	public function __construct(
		public readonly ?int $days,
		public readonly ?string $origin_level,
		public readonly ?string $origin_label,
		public readonly ?string $frozen_by
	) {}

	/**
	 * Whether anything is actually scheduled by this outcome.
	 *
	 * False both when no level ever set a value and when a level froze the
	 * walk without itself contributing one — a freeze alone never schedules
	 * anything on its own.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public function is_scheduled(): bool {
		return null !== $this->days;
	}
}
