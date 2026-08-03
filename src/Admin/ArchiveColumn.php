<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Hooks\HookLoader;
use ArchivedPostStatus\Status\PostStatusValue;
use ArchivedPostStatus\Status\ArchiveLabel;

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
	 * Label of the Archived column header. Exposed so callers (and tests)
	 * have a single source of truth instead of duplicating the copy.
	 *
	 * Returns the label unescaped — see {@see ArchiveLabel::value()}.
	 * Callers must escape for their own output context; see add_column()
	 * and render_archive_cell() below.
	 *
	 * @since 0.4.0
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see ArchiveLabel::value()}
	 * is the canonical filterable label accessor; every "Archived" label
	 * consumer routes through it rather than duplicating the copy.
	 */
	public static function column_label(): string {
		return ArchiveLabel::value();
	}

	/**
	 * Attribution label used when a post was archived without a user context
	 * (anonymous WP-CLI, cron). Exposed so callers (and tests) have a single
	 * source of truth instead of duplicating the copy.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function system_attribution_label(): string {
		/* translators: attribution shown when a post was archived with no user context (anonymous WP-CLI, cron). */
		return _x( 'system', 'archive agent', 'archived-post-status' );
	}

	/**
	 * Attribution label used when the archiving user record no longer
	 * exists. Exposed so callers (and tests) have a single source of truth
	 * instead of duplicating the copy.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function unknown_attribution_label(): string {
		/* translators: attribution shown when the archiving user's account no longer exists. */
		return __( 'Unknown', 'archived-post-status' );
	}

	/**
	 * Format template for the fully-attributed cell (`%1$s` is the display
	 * name). Exposed so callers (and tests) have a single source of truth
	 * instead of duplicating the copy.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function attribution_template(): string {
		/* translators: %1$s: user display name */
		return __( 'Archived by %1$s', 'archived-post-status' );
	}

	/**
	 * @since 0.4.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action()/::filter()
	 * are named-constructor factories for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'pre_get_posts', array( $this, 'handle_sort' ) ),
			HookDescriptor::action( 'wp_loaded', array( $this, 'register_post_type_hooks' ) ),
		);
	}

	/**
	 * Register the per-post-type column hooks once custom post types exist.
	 *
	 * `hooks()` runs on `plugins_loaded`, before third-party custom post
	 * types register — conventionally on `init` at the default priority
	 * 10. Enumerating `aps_get_supported_post_types()` directly inside
	 * `hooks()` would therefore permanently miss any custom post type on
	 * every request. See `PostList::register_post_type_hooks()` for the
	 * full rationale behind deferring to `wp_loaded` specifically (it
	 * postdates every `init` priority, not just the conventional one) and
	 * for going through `HookLoader::register()` rather than calling
	 * add_action()/add_filter() directly — both apply here unchanged.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register_post_type_hooks(): void {
		( new HookLoader() )->register( $this->post_type_hooks() );
	}

	/**
	 * Build the column hook descriptors for each supported post type.
	 *
	 * @since 0.4.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action()/::filter()
	 * are named-constructor factories for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 * Same rationale as hooks() above — this is the same construction moved to a
	 * second, deferred call site, not a new pattern.
	 */
	private function post_type_hooks(): array {
		$descriptors = array();

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
	 * is the canonical filterable slug accessor.
	 */
	public function add_column( array $columns ): array {
		if ( ! in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
			return $columns;
		}

		// The Archived column replaces core's Date column here: its
		// "Published"/"Last Modified" labels are misleading for archived
		// rows, and the archive date is the one that matters in this view.
		unset( $columns['date'] );

		// esc_html() here — not in column_label() — because this is the
		// actual output site: core's WP_List_Table::print_column_headers()
		// echoes each header value as raw HTML with no escaping of its own.
		$columns[ self::COLUMN_KEY ] = esc_html( self::column_label() );

		return $columns;
	}

	/**
	 * Register the column as sortable — only when viewing the archived filter.
	 *
	 * @param array<string, string> $columns Sortable column map (column key => orderby key).
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
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
	 * Kept out of `render_cell()` so the WP-filter adapter stays terse and
	 * the HTML-build path is independently testable. The archive_user check
	 * is an early return, so the legacy-case + system-case branches read
	 * top-to-bottom.
	 */
	private function render_archive_cell( ArchiveMeta $meta ): void {
		// Case 1: true legacy — no archive_date means pre-0.4.0 metadata
		// was never written. Nothing to show beyond the plain label.
		if ( ! $meta->archive_date ) {
			echo '<span>' . esc_html( self::column_label() ) . '</span>';
			return;
		}

		// Format date and time like WordPress core date column.
		$date_format = get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' );
		$date_time   = wp_date( $date_format, $meta->archive_date );

		$name = $this->resolve_archive_agent_name( $meta->archive_user );

		printf(
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
	 * Case 2 (anonymous / system context, archive_user === 0) returns the
	 * localised "system" string. Case 3 (fully attributed) returns the
	 * user's display_name, falling back to a localised "Unknown" if the
	 * user record was deleted between archiving and now.
	 *
	 * Both cases are early returns, so the case-2 and case-3 paths each read
	 * as a single linear flow.
	 *
	 * @param int $archive_user Archive user id (0 for anonymous / system context).
	 */
	private function resolve_archive_agent_name( int $archive_user ): string {
		// Case 2: system-context archive — archive_date is known but
		// archive_user is 0 because get_current_user_id() returned 0
		// (anonymous WP-CLI / cron / server-side aps_archive_post() call).
		if ( ! $archive_user ) {
			return self::system_attribution_label();
		}

		// Case 3: fully attributed. Fall back to "Unknown" if the
		// user record was deleted between archiving and now.
		$user = get_userdata( $archive_user );

		return $user ? $user->display_name : self::unknown_attribution_label();
	}

	// -----------------------------------------------------------------------
	// Sorting
	// -----------------------------------------------------------------------

	/**
	 * Alias for the wp_postmeta row LEFT JOINed in filter_sort_join().
	 * Prefixed and specific enough that it cannot collide with a join core
	 * or another plugin has already added under its own alias.
	 *
	 * @since 0.4.0
	 */
	private const SORT_JOIN_ALIAS = 'aps_archive_sort';

	/**
	 * The query handle_sort() opted into meta-aware sorting for. Set right
	 * before the posts_join / posts_orderby filters are added below and
	 * checked by identity inside them, so those filters are a guaranteed
	 * no-op for every other query on the page — see handle_sort() for the
	 * full rationale.
	 *
	 * @since 0.4.0
	 * @var \WP_Query|null
	 */
	private ?\WP_Query $sort_query = null;

	/**
	 * Apply meta-aware ordering when sorting by the Archived column.
	 *
	 * Only acts on admin list-table queries that are explicitly ordering
	 * by our column key.
	 *
	 * A naive `$query->set( 'meta_key', ... )` here hands ordering off to
	 * WP_Query's meta_query machinery, which builds a JOIN + WHERE that
	 * only matches posts that HAVE a postmeta row for that key — silently
	 * dropping every post that doesn't. That population is not
	 * hypothetical: posts archived under pre-0.4.0 releases wrote no
	 * archive postmeta at all (see ArchiveMeta::for_post()'s legacy
	 * branch), and Plugin::has_pre_040_content() exists specifically
	 * because that population is expected to be there after an upgrade.
	 * Clicking the Archived column header would make that content vanish
	 * from the list with no error and no explanation.
	 *
	 * Instead this LEFT JOINs the meta table and orders with COALESCE so
	 * meta-less rows sort to one end rather than disappearing — see
	 * filter_sort_join() / filter_sort_orderby() for the JOIN/ORDER BY
	 * themselves and exactly how they are scoped to this one query so they
	 * cannot leak onto any other query on the page.
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

		$this->sort_query = $query;

		add_filter( 'posts_join', array( $this, 'filter_sort_join' ), 10, 2 );
		add_filter( 'posts_orderby', array( $this, 'filter_sort_orderby' ), 10, 2 );
	}

	/**
	 * LEFT JOIN wp_postmeta on the archive-date key.
	 *
	 * A LEFT JOIN — instead of the INNER-JOIN-like WHERE that
	 * `$query->set( 'meta_key', ... )` would have produced — keeps every
	 * post in the result set regardless of whether it has an archive-date
	 * row; filter_sort_orderby() then sorts the meta-less rows instead of
	 * excluding them.
	 *
	 * Scoped to the one query handle_sort() opted in: this filter runs for
	 * every WP_Query on the page once registered, but it only ever
	 * modifies $join when $query is identical to $this->sort_query — the
	 * exact object handle_sort() was called with. Any other query gets
	 * $join back untouched, so this cannot leak onto e.g. a later
	 * secondary query on the same admin page load.
	 *
	 * @param string    $join  The current JOIN clause.
	 * @param \WP_Query $query The query being filtered.
	 * @return string
	 */
	public function filter_sort_join( string $join, \WP_Query $query ): string {
		if ( $query !== $this->sort_query ) {
			return $join;
		}

		global $wpdb;

		return $join
			. ' LEFT JOIN ' . $wpdb->postmeta . ' AS ' . self::SORT_JOIN_ALIAS
			. ' ON ( ' . self::SORT_JOIN_ALIAS . '.post_id = ' . $wpdb->posts . '.ID AND '
			. self::SORT_JOIN_ALIAS . '.meta_key = '
			. $wpdb->prepare( '%s )', ArchiveMeta::META_ARCHIVE_DATE );
	}

	/**
	 * Order by the LEFT JOINed archive-date value.
	 *
	 * `COALESCE( ..., 0 )` gives meta-less rows a value to sort by instead
	 * of the NULL a plain LEFT JOIN would leave them with, so they land at
	 * one end of the list instead of being excluded. `+ 0` numeric-casts
	 * the stored value the way `meta_value_num` did, since archive_date is
	 * a Unix timestamp integer (see ArchiveMeta::META_ARCHIVE_DATE).
	 * Direction comes from the query's own `order` var rather than a
	 * hardcoded ASC/DESC, so the column header's normal toggle-on-click
	 * behavior keeps working.
	 *
	 * Scoped identically to filter_sort_join() — see its docblock.
	 *
	 * @param string    $orderby The current ORDER BY clause.
	 * @param \WP_Query $query   The query being filtered.
	 * @return string
	 */
	public function filter_sort_orderby( string $orderby, \WP_Query $query ): string {
		if ( $query !== $this->sort_query ) {
			return $orderby;
		}

		$order = strtoupper( (string) $query->get( 'order' ) );
		if ( 'ASC' !== $order && 'DESC' !== $order ) {
			$order = 'DESC';
		}

		return 'COALESCE( ' . self::SORT_JOIN_ALIAS . '.meta_value + 0, 0 ) ' . $order;
	}
}
