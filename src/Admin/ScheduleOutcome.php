<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ResolvedRule;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Schedule\ScheduleTime;

/**
 * The plain-language resolved-outcome line (plan §5.9): "Auto archive: 3
 * March 2027 — from Category: News (3 days)". The payoff of the whole
 * cascade, rendered once here so {@see ScheduleMetaBox} (classic editor) and
 * the block editor panel (via {@see PostEditor}'s localized string) show
 * IDENTICAL text — two independent formatters would only be one wording
 * change away from drifting apart.
 *
 * Built entirely from existing public reads — {@see \aps_get_scheduled_archive_time()}
 * for the actual stored due instant (whatever its source) and
 * {@see \aps_get_auto_archive_rule()} for the cascade's current answer — so,
 * per the plan's own note, this "costs nothing to render": no new query, no
 * date arithmetic of its own.
 *
 * @since 0.5.0
 */
final class ScheduleOutcome {

	/**
	 * Describe one post's current schedule state in plain language.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return string Plain text, not yet escaped for HTML output.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object/public-API accessors.
	 */
	public static function describe( int $post_id ): string {
		$meta      = ScheduleMeta::for_post( $post_id );
		$scheduled = aps_get_scheduled_archive_time( $post_id );

		if ( null !== $scheduled ) {
			return self::describe_active_schedule( $post_id, $scheduled, $meta );
		}

		if ( $meta instanceof ScheduleMeta && ScheduleSource::Exempt === $meta->source ) {
			return __( 'Exempt from automatic archiving.', 'archived-post-status' );
		}

		return self::describe_pending_rule( aps_get_auto_archive_rule( $post_id ) );
	}

	/**
	 * A real due instant is on record — either a manual date, or one the
	 * auto-archive rule stamper already wrote.
	 *
	 * @since 0.5.0
	 * @param int           $post_id   The post ID.
	 * @param int           $scheduled UTC epoch the post is due to archive.
	 * @param ?ScheduleMeta $meta      The post's schedule record.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical display-formatting/public-API accessors.
	 */
	private static function describe_active_schedule( int $post_id, int $scheduled, ?ScheduleMeta $meta ): string {
		$date = ScheduleTime::to_display( $scheduled );

		if ( $meta instanceof ScheduleMeta && ScheduleSource::Manual === $meta->source ) {
			return sprintf(
				/* translators: %s: the formatted archive date and time. */
				__( 'Archiving on %s (set manually).', 'archived-post-status' ),
				$date
			);
		}

		return self::describe_rule_stamped( $date, aps_get_auto_archive_rule( $post_id ) );
	}

	/**
	 * A rule-stamped due instant. Shows the CURRENT cascade's label/days
	 * alongside the already-stored date — accurate in the common case (the
	 * date was stamped from this same resolution). When a rule change since
	 * the stamp means the cascade no longer resolves at all for this post,
	 * the date alone is shown; the stale-refresh pass (plan §4.7) is what
	 * corrects the stored date itself on the next daily rule run.
	 *
	 * @since 0.5.0
	 * @param string       $date     The already-formatted due date/time.
	 * @param ResolvedRule $resolved The post's CURRENT cascade resolution.
	 * @return string
	 */
	private static function describe_rule_stamped( string $date, ResolvedRule $resolved ): string {
		if ( ! $resolved->is_scheduled() ) {
			return sprintf(
				/* translators: %s: the formatted archive date and time. */
				__( 'Archiving on %s (from an automatic-archive rule).', 'archived-post-status' ),
				$date
			);
		}

		return sprintf(
			/* translators: 1: the formatted archive date and time. 2: the cascade level/label that set the rule, e.g. "Category: News". 3: number of days. */
			__( 'Auto archive: %1$s — from %2$s (%3$d days).', 'archived-post-status' ),
			$date,
			(string) $resolved->origin_label,
			(int) $resolved->days
		);
	}

	/**
	 * Nothing is stamped yet, but the cascade currently resolves to a rule
	 * for this post — it will be stamped the next time the daily rule pass
	 * runs, not immediately.
	 *
	 * @since 0.5.0
	 * @param ResolvedRule $resolved The post's current cascade resolution.
	 * @return string
	 */
	private static function describe_pending_rule( ResolvedRule $resolved ): string {
		if ( ! $resolved->is_scheduled() ) {
			return __( 'Not scheduled to archive.', 'archived-post-status' );
		}

		return sprintf(
			/* translators: 1: number of days. 2: the cascade level/label that set the rule, e.g. "Category: News". */
			__( 'Will auto archive %1$d days after publish/modified — from %2$s, once the schedule is next applied.', 'archived-post-status' ),
			(int) $resolved->days,
			(string) $resolved->origin_label
		);
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
