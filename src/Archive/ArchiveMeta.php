<?php

namespace ArchivedPostStatus\Archive;

/**
 * Represents the metadata saved when a post is archived.
 *
 * A readonly value object that centralizes all knowledge about what gets stored
 * when a post is archived. In the original code, these meta key strings appear
 * scattered across multiple files.
 *
 * @since 0.4.0
 */
final class ArchiveMeta {

	/** @var string The meta key for storing the previous post status */
	public const META_PREVIOUS_STATUS = '_aps_archive_meta_status';

	/** @var string The meta key for storing the archive date */
	public const META_ARCHIVE_DATE = '_aps_archive_meta_time';

	/** @var string The meta key for storing the user who archived the post */
	public const META_ARCHIVE_USER = '_aps_archive_meta_user';

	/** @var string The meta key for storing the previous comment status */
	public const META_COMMENT_STATUS = '_aps_archive_meta_comment_status';

	/** @var string The meta key for storing the previous ping status */
	public const META_PING_STATUS = '_aps_archive_meta_ping_status';

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 * @param string $previous_status The post status before archiving.
	 * @param int    $archive_date The timestamp when archived.
	 * @param int    $archive_user The user ID who archived the post.
	 * @param string $comment_status The comment status before archiving.
	 * @param string $ping_status The ping status before archiving.
	 */
	public function __construct(
		public readonly string $previous_status,
		public readonly int $archive_date,
		public readonly int $archive_user,
		public readonly string $comment_status,
		public readonly string $ping_status
	) {}

	/**
	 * Create ArchiveMeta from a WP_Post object.
	 *
	 * @since 0.4.0
	 * @param \WP_Post $post The post to create meta for.
	 * @return self
	 */
	public static function from_post( \WP_Post $post ): self {
		return new self(
			$post->post_status,
			time(),
			get_current_user_id(),
			$post->comment_status,
			$post->ping_status
		);
	}

	/**
	 * Create ArchiveMeta from stored post meta.
	 *
	 * Returns actual values from database. For legacy archives,
	 * archive_date and archive_user will be 0.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to read meta from.
	 * @return self|null Returns null if post was never archived.
	 */
	public static function for_post( int $post_id ): ?self {
		$previous_status = get_post_meta( $post_id, self::META_PREVIOUS_STATUS, true );

		if ( empty( $previous_status ) ) {
			return null; // Post was never archived
		}

		$archive_date   = (int) get_post_meta( $post_id, self::META_ARCHIVE_DATE, true );
		$archive_user   = (int) get_post_meta( $post_id, self::META_ARCHIVE_USER, true );
		$comment_status = get_post_meta( $post_id, self::META_COMMENT_STATUS, true );
		$ping_status    = get_post_meta( $post_id, self::META_PING_STATUS, true );

		return new self(
			$previous_status,
			$archive_date, // 0 for legacy archives
			$archive_user, // 0 for legacy archives
			$comment_status ?: 'closed',
			$ping_status ?: 'closed'
		);
	}

	/**
	 * Save this archive meta to post meta.
	 *
	 * update_post_meta() keeps the write idempotent: if a prior cycle left
	 * stale rows behind (meta cleanup on unarchive is best-effort), a
	 * re-archive overwrites them instead of appending duplicates that
	 * get_post_meta( ..., true ) would resolve to the oldest row.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to save meta to.
	 * @return void
	 */
	public function save( int $post_id ): void {
		update_post_meta( $post_id, self::META_PREVIOUS_STATUS, $this->previous_status );
		update_post_meta( $post_id, self::META_ARCHIVE_DATE, $this->archive_date );
		update_post_meta( $post_id, self::META_ARCHIVE_USER, $this->archive_user );
		update_post_meta( $post_id, self::META_COMMENT_STATUS, $this->comment_status );
		update_post_meta( $post_id, self::META_PING_STATUS, $this->ping_status );
	}

	/**
	 * Delete archive meta for a post.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID to delete meta from.
	 * @return void
	 */
	public function delete( int $post_id ): void {
		delete_post_meta( $post_id, self::META_PREVIOUS_STATUS );
		delete_post_meta( $post_id, self::META_ARCHIVE_DATE );
		delete_post_meta( $post_id, self::META_ARCHIVE_USER );
		delete_post_meta( $post_id, self::META_COMMENT_STATUS );
		delete_post_meta( $post_id, self::META_PING_STATUS );
	}
}
