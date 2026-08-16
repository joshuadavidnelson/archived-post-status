<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Schedule\ScheduleTime;

/**
 * Builds the escaped HTML for the Scheduled column's per-row cell.
 *
 * A pure function of its arguments — no hook plumbing, no echo. Callers
 * ({@see ScheduleColumn::render_cell()}) do the echo at the use site.
 * Matches the ArchiveColumnCellRenderer idiom.
 *
 * Renders from the stored {@see ScheduleMeta} alone — never from
 * `aps_get_auto_archive_rule()`. Resolving the cascade here to show
 * provenance (e.g. "from Category: News") would run all four providers,
 * including term queries, once per row on every posts-list page load. The
 * full provenance belongs on the post editor (0.5.0 phase 11), where it is
 * one post, not twenty.
 *
 * @since 0.5.0
 */
final class ScheduleColumnCellRenderer {

	/**
	 * Label of the Scheduled column header.
	 *
	 * Returns the label unescaped; callers escape for their own output context.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	public static function column_label(): string {

		// translators: header of the column showing a post's pending scheduled archive date.
		$label = __( 'Scheduled', 'archived-post-status' );

		/**
		 * Filters the Scheduled column's header label.
		 *
		 * @since 0.5.0
		 * @param string $label The default "Scheduled" label.
		 * @return string
		 */
		return (string) apply_filters( 'aps_schedule_column_label', $label );
	}

	/**
	 * Title text for an Exempt tombstone's em dash, explaining that the post
	 * is deliberately excluded from the cascade rather than merely
	 * unscheduled.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	public static function exempt_title(): string {
		return __( 'Exempt from automatic archiving', 'archived-post-status' );
	}

	/**
	 * Format template for a manually-scheduled cell (`%1$s` is the display name).
	 *
	 * @since 0.5.0
	 * @return string
	 */
	public static function manual_attribution_template(): string {
		/* translators: %1$s: user display name */
		return __( 'Scheduled by %1$s', 'archived-post-status' );
	}

	/**
	 * Attribution label for a schedule stamped by the auto-archive rule
	 * cascade rather than picked by an editor.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	public static function rule_attribution_label(): string {
		return __( 'Scheduled by rule', 'archived-post-status' );
	}

	/**
	 * Build the per-row HTML for a post's schedule metadata.
	 *
	 * Four observable states:
	 *
	 *   1. No record — nothing pending. A bare em dash.
	 *   2. Exempt tombstone — rule-stamped, then explicitly cleared. An em
	 *      dash too, but titled so "deliberately excluded" reads differently
	 *      from "merely unscheduled" on hover.
	 *   3. Manual — "Scheduled by NAME" (or "system" for an anonymous
	 *      context) plus the display date.
	 *   4. Rule — "Scheduled by rule" plus the display date, so an editor can
	 *      tell at a glance the date was not hand-picked.
	 *
	 * @since 0.5.0
	 * @param ScheduleMeta|null $meta The post's schedule metadata, or null when no record exists.
	 * @return string Escaped HTML, ready to echo as-is.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical label/name accessors.
	 */
	public static function render( ?ScheduleMeta $meta ): string {
		$html = self::build( $meta );

		/**
		 * Filters the escaped HTML for the Scheduled column's per-row cell.
		 *
		 * @since 0.5.0
		 * @param string             $html The default cell HTML, already escaped for HTML text context.
		 * @param ScheduleMeta|null  $meta The post's schedule metadata, or null when no record exists.
		 * @return string
		 */
		return (string) apply_filters( 'aps_schedule_cell_content', $html, $meta );
	}

	/**
	 * Build the default (unfiltered) cell HTML for one of the four states.
	 *
	 * @since 0.5.0
	 * @param ScheduleMeta|null $meta The post's schedule metadata, or null when no record exists.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical label/name accessors.
	 */
	private static function build( ?ScheduleMeta $meta ): string {
		if ( null === $meta ) {
			return '<span>—</span>';
		}

		if ( ScheduleSource::Exempt === $meta->source ) {
			return '<span class="aps-schedule-exempt" title="' . esc_attr( self::exempt_title() ) . '">—</span>';
		}

		$date_time = ScheduleTime::to_display( $meta->time );

		if ( ScheduleSource::Manual === $meta->source ) {
			return sprintf(
				'<span>' . esc_html( self::manual_attribution_template() ) . '</span>'
				. '<br><span class="aps-schedule-datetime">'
				. '%2$s'
				. '</span>',
				esc_html( ArchiveColumnCellRenderer::resolve_archive_agent_name( $meta->user ) ),
				esc_html( $date_time )
			);
		}

		// ScheduleSource::Rule -- the only case left. ScheduleMeta::for_post()
		// falls back an unrecognized stored source value to Manual, so Rule
		// is never a guess here.
		return '<span>' . esc_html( self::rule_attribution_label() ) . '</span>'
			. '<br><span class="aps-schedule-datetime">' . esc_html( $date_time ) . '</span>';
	}
}
