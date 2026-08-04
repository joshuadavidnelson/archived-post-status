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
 * showing archived date, user, and previous status for each row, and supports
 * sorting by archived date.
 *
 * @since 0.4.0
 */
final class ArchiveColumn implements HookableInterface {

	/** The column key used in all column hook names and checks. */
	private const COLUMN_KEY = 'aps_archived';

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
	 * @since 0.4.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'pre_get_posts', array( $this, 'handle_sort' ) ),
			HookDescriptor::filter( 'the_posts', array( $this, 'prime_archive_user_cache' ), 10, 2 ),
			HookDescriptor::action( 'wp_loaded', array( $this, 'register_post_type_hooks' ) ),
		);
	}

	/**
	 * Register the per-post-type column hooks once custom post types exist.
	 *
	 * `hooks()` runs on `plugins_loaded`, before custom post types register on
	 * `init`, so enumerating supported post types there would permanently miss
	 * them. `wp_loaded` postdates every `init` priority, not just the
	 * conventional 10.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	public function add_column( array $columns ): array {
		if ( ! in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
			return $columns;
		}

		// Replaces core's Date column: its "Published"/"Last Modified" labels
		// are misleading for archived rows.
		unset( $columns['date'] );

		// core's print_column_headers() echoes header values as raw HTML.
		$columns[ self::COLUMN_KEY ] = esc_html( self::column_label() );

		return $columns;
	}

	/**
	 * Register the column as sortable — only when viewing the archived filter.
	 *
	 * @param array<string, string> $columns Sortable column map (column key => orderby key).
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	public function register_sortable( array $columns ): array {
		if ( ! in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
			return $columns;
		}

		$columns[ self::COLUMN_KEY ] = self::COLUMN_KEY;

		return $columns;
	}

	// -----------------------------------------------------------------------
	// User-cache priming
	// -----------------------------------------------------------------------

	/**
	 * Prime the user cache for the whole page of rows in one query.
	 *
	 * Core primes the author cache but not this plugin's separate archive-user
	 * meta, so without this {@see resolve_archive_agent_name()}'s
	 * `get_userdata()` runs one uncached lookup per row. Scoped to the admin
	 * archived-list main query — the only place the archived-by cell renders —
	 * so no other request pays for a cache_users() call with no reader.
	 *
	 * @since 0.4.0
	 * @param array<int, \WP_Post> $posts The posts about to be rendered.
	 * @param \WP_Query            $query The query that produced them.
	 * @return array<int, \WP_Post>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	public function prime_archive_user_cache( array $posts, \WP_Query $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}

		if ( ! in_array( PostStatusValue::resolved_slug(), (array) $query->get( 'post_status' ), true ) ) {
			return $posts;
		}

		$user_ids = array();
		foreach ( $posts as $post ) {
			$archive_user = (int) get_post_meta( $post->ID, ArchiveMeta::META_ARCHIVE_USER, true );
			if ( $archive_user ) {
				$user_ids[] = $archive_user;
			}
		}

		if ( $user_ids ) {
			cache_users( array_unique( $user_ids ) );
		}

		return $posts;
	}

	// -----------------------------------------------------------------------
	// Cell rendering
	// -----------------------------------------------------------------------

	/**
	 * Render the cell content for each row — WP filter callback.
	 *
	 * @param string $column_name The current column key.
	 * @param int    $post_id     The current post ID.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object factory.
	 */
	public function render_cell( string $column_name, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column_name ) {
			return;
		}

		$meta = ArchiveMeta::for_post( $post_id );
		if ( null === $meta ) {
			return;
		}

		$this->render_archive_cell( $meta );
	}

	/**
	 * Render the per-row HTML for an archived post's metadata.
	 *
	 * Three observable states:
	 *
	 *   1. No archive_date — pre-0.4.0 archive, written before archive metadata
	 *      existed. Nothing to show beyond a bare "Archived".
	 *   2. Date but no user — archived where get_current_user_id() returned 0
	 *      (anonymous WP-CLI, cron, server-side call). "Archived by system".
	 *   3. Both — "Archived by NAME".
	 */
	private function render_archive_cell( ArchiveMeta $meta ): void {
		if ( ! $meta->archive_date ) {
			echo '<span>' . esc_html( self::column_label() ) . '</span>';
			return;
		}

		// Matches the format of core's Date column.
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
	 * @param int $archive_user Archive user id (0 for anonymous / system context).
	 */
	private function resolve_archive_agent_name( int $archive_user ): string {
		if ( ! $archive_user ) {
			return self::system_attribution_label();
		}

		// "Unknown" covers a user record deleted since archiving.
		$user = get_userdata( $archive_user );

		return $user ? $user->display_name : self::unknown_attribution_label();
	}

	// -----------------------------------------------------------------------
	// Sorting
	// -----------------------------------------------------------------------

	/**
	 * Alias for the wp_postmeta row LEFT JOINed in filter_sort_join().
	 * Prefixed so it cannot collide with a join core or another plugin added.
	 *
	 * @since 0.4.0
	 */
	private const SORT_JOIN_ALIAS = 'aps_archive_sort';

	/**
	 * The query handle_sort() opted into meta-aware sorting for. Compared by
	 * identity inside the posts_join / posts_orderby filters so they are a
	 * no-op for every other query on the page.
	 *
	 * @since 0.4.0
	 * @var \WP_Query|null
	 */
	private ?\WP_Query $sort_query = null;

	/**
	 * Apply meta-aware ordering when sorting by the Archived column.
	 *
	 * Do not replace this with `$query->set( 'meta_key', ... )`. That routes
	 * ordering through WP_Query's meta_query machinery, whose JOIN + WHERE only
	 * matches posts that have a row for the key — silently dropping every post
	 * that doesn't. Pre-0.4.0 archives wrote no archive postmeta at all, so
	 * clicking the column header would make that content vanish with no error.
	 *
	 * The LEFT JOIN in filter_sort_join() plus the COALESCE in
	 * filter_sort_orderby() sort meta-less rows to one end instead.
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
	 * LEFT, not INNER, so posts with no archive-date row stay in the result set
	 * for filter_sort_orderby() to sort rather than being excluded.
	 *
	 * Once registered this filter runs for every WP_Query on the page, so the
	 * identity check against $this->sort_query is what keeps it from leaking
	 * onto a secondary query in the same request.
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
	 * `COALESCE( …, 0 )` replaces the NULL the LEFT JOIN leaves on meta-less
	 * rows so they sort to one end. `+ 0` numeric-casts the stored value the
	 * way `meta_value_num` would, archive_date being a Unix timestamp. The
	 * direction comes from the query's own `order` var so the column header's
	 * toggle-on-click keeps working.
	 *
	 * Scoped by identity like filter_sort_join().
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
