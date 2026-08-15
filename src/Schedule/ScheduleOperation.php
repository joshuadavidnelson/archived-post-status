<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Sets and clears a post's scheduled archive time.
 *
 * Deliberately does not check capabilities — mirrors
 * {@see \ArchivedPostStatus\Archive\ArchiveOperation}, which stays usable
 * from privileged contexts like cron and WP-CLI. Callers (admin UI, CLI) do
 * the checking, via {@see \ArchivedPostStatus\Archive\ArchiveCapability}.
 *
 * @since 0.5.0
 */
final class ScheduleOperation {

	/**
	 * Set a post's scheduled archive time.
	 *
	 * @since 0.5.0
	 * @param int            $post_id      The post ID to schedule.
	 * @param int            $timestamp    UTC epoch the post is due to archive.
	 * @param ScheduleSource $source       Origin of this schedule.
	 * @param int            $rule_version The rule version this schedule was stamped from;
	 *                                     only meaningful when $source is ScheduleSource::Rule.
	 * @return bool True on success, false if a guard rejected the schedule or a
	 *              filter vetoed it.
	 */
	public static function set( int $post_id, int $timestamp, ScheduleSource $source, int $rule_version = 0 ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( ! aps_is_supported_post_type( $post->post_type ) ) {
			return false;
		}

		if ( $timestamp <= 0 ) {
			return false;
		}

		/**
		 * Filters whether a post's scheduled archive time should be set.
		 *
		 * Mirrors the `aps_pre_archive_post` contract
		 * ({@see \ArchivedPostStatus\Archive\ArchiveOperation::perform()}):
		 * `null` lets the schedule continue; any other value short-circuits
		 * and is returned to the caller in its place.
		 *
		 * @since 0.5.0
		 * @param bool|null      $should_schedule Whether to proceed. Null continues.
		 * @param int            $post_id         The post ID being scheduled.
		 * @param int            $timestamp       UTC epoch the post is due to archive.
		 * @param ScheduleSource $source          Origin of this schedule.
		 */
		$check = apply_filters( 'aps_pre_schedule_archive', null, $post_id, $timestamp, $source );
		if ( null !== $check ) {
			return (bool) $check;
		}

		$meta = new ScheduleMeta( $timestamp, $source, get_current_user_id(), $rule_version, 0 );
		$meta->save( $post_id );

		/**
		 * Fires after a post's scheduled archive time is set.
		 *
		 * @since 0.5.0
		 * @param int    $post_id   The post ID that was scheduled.
		 * @param int    $timestamp UTC epoch the post is due to archive.
		 * @param string $source    The schedule's origin: 'manual', 'rule', or 'exempt'.
		 */
		do_action( 'aps_scheduled_archive', $post_id, $timestamp, $source->value );

		return true;
	}

	/**
	 * Clear a post's scheduled archive.
	 *
	 * A rule-stamped schedule is tombstoned by default rather than deleted
	 * outright: {@see ScheduleMeta::META_SOURCE} is set to
	 * ScheduleSource::Exempt and the time is dropped, but the record stays
	 * present. Without the tombstone, an editor who clears a rule-generated
	 * schedule would get it back on the next nightly stamp — the stamper
	 * only stamps posts with no `source` at all, and a fully deleted rule
	 * schedule looks unstamped again. A manually-set schedule has no such
	 * ambiguity to guard against, so it is deleted outright.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID to clear the schedule for.
	 * @return bool True on success, false if the post does not exist or has
	 *              no schedule to clear.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object accessor.
	 */
	public static function clear( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$existing = ScheduleMeta::for_post( $post_id );
		if ( null === $existing ) {
			return false;
		}

		$tombstone = self::should_tombstone( $post_id, $existing->source );

		if ( $tombstone ) {
			self::tombstone_schedule( $post_id );
		}

		if ( ! $tombstone ) {
			$existing->delete( $post_id );
		}

		/**
		 * Fires after a post's scheduled archive is cleared.
		 *
		 * @since 0.5.0
		 * @param int $post_id The post ID that was unscheduled.
		 */
		do_action( 'aps_unscheduled_archive', $post_id );

		return true;
	}

	/**
	 * Tombstone a schedule: keep the record, but mark it exempt and drop
	 * the time — see {@see clear()} for why.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID to tombstone.
	 * @return void
	 */
	private static function tombstone_schedule( int $post_id ): void {
		update_post_meta( $post_id, ScheduleMeta::META_SOURCE, ScheduleSource::Exempt->value );
		delete_post_meta( $post_id, ScheduleMeta::META_TIME );
	}

	/**
	 * Decide whether clear() tombstones the schedule instead of deleting it
	 * outright. Defaults to true only for a rule-stamped schedule.
	 *
	 * @since 0.5.0
	 * @param int                 $post_id The post ID being cleared.
	 * @param ScheduleSource|null $current The schedule's current source, or
	 *                                     null if it has none.
	 * @return bool
	 */
	private static function should_tombstone( int $post_id, ?ScheduleSource $current ): bool {
		$default = ScheduleSource::Rule === $current;

		/**
		 * Filters whether clearing a schedule tombstones it (source set to
		 * `exempt`) rather than deleting its meta outright.
		 *
		 * @since 0.5.0
		 * @param bool                $tombstone Whether to tombstone instead of deleting outright.
		 * @param int                 $post_id   The post ID being cleared.
		 * @param ScheduleSource|null $current   The schedule's current source, or null if it has none.
		 * @return bool
		 */
		return (bool) apply_filters( 'aps_schedule_tombstone_on_clear', $default, $post_id, $current );
	}
}
