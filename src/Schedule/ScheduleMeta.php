<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Represents the metadata saved when a post's archive is scheduled.
 *
 * Mirrors {@see \ArchivedPostStatus\Archive\ArchiveMeta}. A record's presence
 * is decided solely by {@see META_SOURCE}: a post with `source = exempt` and
 * no time is a valid tombstone state, not a missing schedule — see
 * {@see ScheduleOperation::clear()}.
 *
 * @since 0.5.0
 */
final class ScheduleMeta {

	/** @var string The meta key for the UTC epoch the post is due to archive. */
	public const META_TIME = '_aps_schedule_meta_time';

	/** @var string The meta key for the schedule's origin (a ScheduleSource value). */
	public const META_SOURCE = '_aps_schedule_meta_source';

	/** @var string The meta key for the user who set the schedule; 0 for rule/system. */
	public const META_USER = '_aps_schedule_meta_user';

	/** @var string The meta key for the rule version this schedule was stamped from. */
	public const META_RULE_VERSION = '_aps_schedule_meta_rule_version';

	/** @var string The meta key for the count of failed archive attempts. */
	public const META_ATTEMPTS = '_aps_schedule_meta_attempts';

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param int            $time         UTC epoch the post is due to archive.
	 * @param ScheduleSource $source       Origin of this schedule.
	 * @param int            $user         User ID who set the schedule; 0 for rule/system.
	 * @param int            $rule_version The rule version this schedule was stamped from; only
	 *                                     meaningful when $source is ScheduleSource::Rule.
	 * @param int            $attempts     Count of failed archive attempts.
	 */
	public function __construct(
		public readonly int $time,
		public readonly ScheduleSource $source,
		public readonly int $user,
		public readonly int $rule_version,
		public readonly int $attempts
	) {}

	/**
	 * Read a post's schedule from stored post meta.
	 *
	 * Presence of {@see META_SOURCE} is what makes a record exist — not the
	 * time. A source value that no longer maps to a {@see ScheduleSource}
	 * case (e.g. a downgrade after a future release adds one) falls back to
	 * ScheduleSource::Manual rather than treating the record as absent.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID to read the schedule for.
	 * @return self|null Returns null if no schedule record exists.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical enum-hydration accessor.
	 */
	public static function for_post( int $post_id ): ?self {
		$source = get_post_meta( $post_id, self::META_SOURCE, true );

		if ( empty( $source ) ) {
			return null;
		}

		$source_enum = ScheduleSource::tryFrom( (string) $source ) ?? ScheduleSource::Manual;

		return new self(
			(int) get_post_meta( $post_id, self::META_TIME, true ),
			$source_enum,
			(int) get_post_meta( $post_id, self::META_USER, true ),
			(int) get_post_meta( $post_id, self::META_RULE_VERSION, true ),
			(int) get_post_meta( $post_id, self::META_ATTEMPTS, true )
		);
	}

	/**
	 * Save this schedule to post meta.
	 *
	 * update_post_meta() rather than add_post_meta() keeps this idempotent,
	 * the same rationale as {@see \ArchivedPostStatus\Archive\ArchiveMeta::save()}.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID to save the schedule to.
	 * @return void
	 */
	public function save( int $post_id ): void {
		update_post_meta( $post_id, self::META_TIME, $this->time );
		update_post_meta( $post_id, self::META_SOURCE, $this->source->value );
		update_post_meta( $post_id, self::META_USER, $this->user );
		update_post_meta( $post_id, self::META_RULE_VERSION, $this->rule_version );
		update_post_meta( $post_id, self::META_ATTEMPTS, $this->attempts );
	}

	/**
	 * Delete every schedule meta key for a post.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID to delete the schedule from.
	 * @return void
	 */
	public function delete( int $post_id ): void {
		delete_post_meta( $post_id, self::META_TIME );
		delete_post_meta( $post_id, self::META_SOURCE );
		delete_post_meta( $post_id, self::META_USER );
		delete_post_meta( $post_id, self::META_RULE_VERSION );
		delete_post_meta( $post_id, self::META_ATTEMPTS );
	}
}
