<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Status\ArchiveLabel;

/**
 * Builds the escaped HTML for the Archived column's per-row cell.
 *
 * A pure function of its arguments — no hook plumbing, no echo. Callers
 * ({@see ArchiveColumn::render_cell()}) do the echo at the use site. Matches
 * the ArchiveColumnSortSql / PostListUrlBuilder idiom.
 *
 * @since 0.4.0
 */
final class ArchiveColumnCellRenderer {

	/**
	 * Label of the Archived column header.
	 *
	 * Returns the label unescaped; callers escape for their own output context.
	 *
	 * @since 0.4.0
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable label accessor.
	 */
	public static function column_label(): string {
		return ArchiveLabel::value();
	}

	/**
	 * Attribution label used when a post was archived without a user context
	 * (anonymous WP-CLI, cron).
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function system_attribution_label(): string {
		/* translators: attribution shown when a post was archived with no user context (anonymous WP-CLI, cron). */
		return _x( 'system', 'archive agent', 'archived-post-status' );
	}

	/**
	 * Attribution label used when the archiving user record no longer exists.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function unknown_attribution_label(): string {
		/* translators: attribution shown when the archiving user's account no longer exists. */
		return __( 'Unknown', 'archived-post-status' );
	}

	/**
	 * Format template for the fully-attributed cell (`%1$s` is the display name).
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function attribution_template(): string {
		/* translators: %1$s: user display name */
		return __( 'Archived by %1$s', 'archived-post-status' );
	}

	/**
	 * Build the per-row HTML for an archived post's metadata.
	 *
	 * Three observable states:
	 *
	 *   1. No archive_date — pre-0.4.0 archive, written before archive metadata
	 *      existed. Nothing to show beyond a bare "Archived".
	 *   2. Date but no user — archived where get_current_user_id() returned 0
	 *      (anonymous WP-CLI, cron, server-side call). "Archived by system".
	 *   3. Both — "Archived by NAME".
	 *
	 * @since 0.4.0
	 * @param ArchiveMeta $meta The archive metadata for the post.
	 * @return string Escaped HTML, ready to echo as-is.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical label/name accessors.
	 */
	public static function render( ArchiveMeta $meta ): string {
		if ( ! $meta->archive_date ) {
			return '<span>' . esc_html( self::column_label() ) . '</span>';
		}

		// Matches the format of core's Date column.
		$date_format = get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' );
		$date_time   = wp_date( $date_format, $meta->archive_date );

		$name = self::resolve_archive_agent_name( $meta->archive_user );

		return sprintf(
			'<span>' . esc_html( self::attribution_template() ) . '</span>'
			. '<br><span class="aps-archive-datetime">'
			. '%2$s'
			. '</span>',
			esc_html( $name ),
			esc_html( $date_time )
		);
	}

	/**
	 * Resolve a display-ready name for the archiver of a post.
	 *
	 * Its `get_userdata()` lookups are served by
	 * {@see ArchiveColumn::prime_archive_user_cache()}'s cache warm — without
	 * that priming this would be one uncached lookup per row.
	 *
	 * @since 0.4.0
	 * @param int $archive_user Archive user id (0 for anonymous / system context).
	 * @return string
	 */
	public static function resolve_archive_agent_name( int $archive_user ): string {
		if ( ! $archive_user ) {
			return self::system_attribution_label();
		}

		// "Unknown" covers a user record deleted since archiving.
		$user = get_userdata( $archive_user );

		return $user ? $user->display_name : self::unknown_attribution_label();
	}
}
