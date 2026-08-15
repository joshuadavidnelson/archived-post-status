<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * How a cascade level's own value is exposed to the levels below it.
 *
 * `Locked` and `Off` resolve to the exact same value in {@see RuleResolver}
 * — both freeze every level below them — and differ only in what a
 * downstream settings screen renders: `Locked` shows the value read-only
 * with its provenance, `Off` hides the control entirely. They are kept as
 * two cases rather than collapsed into one because that UI distinction is
 * real; do not merge them on the theory that they "do the same thing".
 *
 * @since 0.5.0
 */
enum ChildMode: string {

	/** Children may set their own value. */
	case Open = 'open';

	/** Children see this value, read-only, with its source. */
	case Locked = 'locked';

	/** Children do not see the control at all. */
	case Off = 'off';

	/**
	 * Whether this mode freezes every cascade level below it.
	 *
	 * True for both `Locked` and `Off` — see the class docblock for why
	 * those two share this outcome despite rendering differently.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public function freezes_children(): bool {
		return self::Open !== $this;
	}
}
