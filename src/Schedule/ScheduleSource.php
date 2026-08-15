<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The origin of a post's scheduled archive time.
 *
 * The value stored under {@see ScheduleMeta::META_SOURCE} — a wire format
 * `ScheduleMeta::for_post()` reads back, so these case values are a storage
 * contract, not an implementation detail.
 *
 * @since 0.5.0
 */
enum ScheduleSource: string {

	/** Set by an editor picking a date on a single post. */
	case Manual = 'manual';

	/** Stamped by the auto-archive rule cascade. */
	case Rule = 'rule';

	/** A tombstone: the post was rule-stamped, then explicitly cleared. */
	case Exempt = 'exempt';

}
