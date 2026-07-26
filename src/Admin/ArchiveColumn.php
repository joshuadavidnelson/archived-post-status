<?php

namespace ArchivedPostStatus\Admin;

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Adds an "Archived" column to the post list table.
 *
 * The column appears only when viewing the archived post status filter,
 * showing archived date, user, and previous status for each row.
 * Supports sorting by archived date via post meta query.
 *
 * Separated from PostList because column display and action handling
 * are independent concerns that change for different reasons.
 *
 * @since 0.4.0
 */
final class ArchiveColumn implements HookableInterface {

	/** The column key used in all column hook names and checks. */
	private const COLUMN_KEY = 'aps_archived';

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action()/::filter()
	 * are named-constructor factories for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		$descriptors = array(
			HookDescriptor::action( 'pre_get_posts', array( $this, 'handle_sort' ) ),
		);

		foreach ( aps_get_supported_post_types() as $post_type ) {
			$descriptors[] = HookDescriptor::filter(
				"manage_{$post_type}_posts_columns",
				array( $this, 'add_column' )
			);
			$descriptors[] = HookDescriptor::action(
				"manage_{$post_type}_posts_custom_column",
				array( $this, 'render_cell' ),
				10,
				2
			);
			$descriptors[] = HookDescriptor::filter(
				"manage_edit-{$post_type}_sortable_columns",
				array( $this, 'register_sortable' )
			);
		}

		return $descriptors;
	}

	// -----------------------------------------------------------------------
	// Column registration
	// -----------------------------------------------------------------------

	/**
	 * Add the Archived column header — only when viewing the archived filter.
	 *
	 * Gating on post_status prevents the column appearing on the 'All' view
	 * or other status filters where the cells would all be empty.
	 *
	 * @param array<string, string> $columns Column header map (column key => label).
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor (Phase 3B leak fix).
	 */
	public function add_column( array $columns ): array {
		if ( ! in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
			return $columns;
		}

		$columns[ self::COLUMN_KEY ] = __( 'Archived', 'archived-post-status' );

		return $columns;
	}

	/**
	 * Register the column as sortable — only when viewing the archived filter.
	 *
	 * @param array<string, string> $columns Sortable column map (column key => orderby key).
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor (Phase 3B leak fix).
	 */
	public function register_sortable( array $columns ): array {
		if ( ! in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
			return $columns;
		}

		$columns[ self::COLUMN_KEY ] = self::COLUMN_KEY;

		return $columns;
	}

	// -----------------------------------------------------------------------
	// Cell rendering
	// -----------------------------------------------------------------------

	/**
	 * Render the cell content for each row — WP filter callback.
	 *
	 * Thin adapter that gates on the column key and delegates the actual
	 * HTML build to {@see render_archive_cell()}. Keeps registration (this
	 * callback) and per-row rendering (the helper) at distinct levels of
	 * abstraction.
	 *
	 * @param string $column_name The current column key.
	 * @param int    $post_id     The current post ID.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchiveMeta::for_post()} is a
	 * named-constructor factory for the ArchiveMeta value object; static access is
	 * the canonical WP convention for value-object hydration in hook callbacks.
	 */
	public function render_cell( string $column_name, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column_name ) {
			return;
		}

		$meta = ArchiveMeta::for_post( $post_id );
		if ( null === $meta ) {
			return; // Not archived
		}

		$this->render_archive_cell( $meta );
	}

	/**
	 * Render the per-row HTML for an archived post's metadata.
	 *
	 * Three observable states based on the stored {@see ArchiveMeta} value object:
	 *
	 *   1. archive_date === 0  (true legacy)
	 *      Post archived in a pre-0.4.0 version before archive metadata
	 *      existed. No date, no user — render bare "Archived".
	 *
	 *   2. archive_date > 0 && archive_user === 0  (system context)
	 *      Post archived in 0.4.0+ via anonymous WP-CLI, cron, or a
	 *      server-side aps_archive_post() call where get_current_user_id()
	 *      returned 0. We know WHEN; there is no user to attribute.
	 *      Render "Archived by system" + the date. Distinct from case 1.
	 *
	 *   3. archive_date > 0 && archive_user > 0  (fully attributed)
	 *      Render "Archived by NAME" + the date.
	 *
	 * Phase 4: extracted from `render_cell()` so the WP-filter adapter
	 * stays terse and the HTML-build path is independently testable.
	 * The `else` branch on the archive_user check was inverted into an
	 * early-return so the legacy-case + system-case branches read top-to-bottom.
	 */
	private function render_archive_cell( ArchiveMeta $meta ): void {
		// Case 1: true legacy — no archive_date means pre-0.4.0 metadata
		// was never written. Nothing to show beyond the plain label.
		if ( ! $meta->archive_date ) {
			echo '<span>' . esc_html__( 'Archived', 'archived-post-status' ) . '</span>';
			return;
		}

		// Format date and time like WordPress core date column.
		$date_format = get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' );
		$date_time   = wp_date( $date_format, $meta->archive_date );

		$name = $this->resolve_archive_agent_name( $meta->archive_user );

		printf(
			/* translators: 1: user display name, 2: date and time */
			'<span>' . esc_html__( 'Archived by %1$s', 'archived-post-status' ) . '</span>'
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
	 * Case 2 (anonymous / system context, archive_user === 0) returns the
	 * localised "system" string. Case 3 (fully attributed) returns the
	 * user's display_name, falling back to a localised "Unknown" if the
	 * user record was deleted between archiving and now.
	 *
	 * Phase 4: inverted the original `else` branch into early returns so
	 * the case-2 and case-3 paths each read as a single linear flow.
	 *
	 * @param int $archive_user Archive user id (0 for anonymous / system context).
	 */
	private function resolve_archive_agent_name( int $archive_user ): string {
		// Case 2: system-context archive — archive_date is known but
		// archive_user is 0 because get_current_user_id() returned 0
		// (anonymous WP-CLI / cron / server-side aps_archive_post() call).
		if ( ! $archive_user ) {
			return _x( 'system', 'archive agent', 'archived-post-status' );
		}

		// Case 3: fully attributed. Fall back to "Unknown" if the
		// user record was deleted between archiving and now.
		$user = get_userdata( $archive_user );

		return $user ? $user->display_name : __( 'Unknown', 'archived-post-status' );
	}

	// -----------------------------------------------------------------------
	// Sorting
	// -----------------------------------------------------------------------

	/**
	 * Apply meta query ordering when sorting by the Archived column.
	 *
	 * Only acts on admin list table queries that are explicitly ordering
	 * by our column key. Uses meta_value_num since archive_date is a Unix
	 * timestamp integer.
	 *
	 * @param \WP_Query $query The current query object.
	 */
	public function handle_sort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::COLUMN_KEY !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', ArchiveMeta::META_ARCHIVE_DATE );
		$query->set( 'orderby', 'meta_value_num' );
	}
}
