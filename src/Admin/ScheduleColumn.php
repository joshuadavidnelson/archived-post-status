<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Hooks\HookLoader;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Settings\Schema;
use ArchivedPostStatus\Status\PostStatusValue;

/**
 * Adds a "Scheduled" column to the post list table.
 *
 * The visibility predicate is the deliberate INVERSE of {@see ArchiveColumn}'s:
 * ArchiveColumn shows its column only under the archived-status filter,
 * because its cells are empty everywhere else. A pending schedule is the
 * opposite — only interesting on the views where the post is NOT yet
 * archived, since an archived post cannot also have a schedule due in the
 * future. So this column shows on the normal views and hides under the
 * archived filter, where every cell would be empty.
 *
 * @since 0.5.0
 */
final class ScheduleColumn implements HookableInterface {

	/** The column key used in all column hook names and checks. */
	public const COLUMN_KEY = 'aps_scheduled';

	/**
	 * @since 0.5.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::filter( 'the_posts', array( $this, 'prime_schedule_user_cache' ), 10, 2 ),
			HookDescriptor::action( 'wp_loaded', array( $this, 'register_post_type_hooks' ) ),
		);
	}

	/**
	 * Register the per-post-type column hooks once custom post types exist.
	 *
	 * `hooks()` runs on `plugins_loaded`, before custom post types register on
	 * `init`, so enumerating post types there would permanently miss them.
	 * `wp_loaded` postdates every `init` priority, not just the conventional
	 * 10. Mirrors {@see ArchiveColumn::register_post_type_hooks()} exactly.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function register_post_type_hooks(): void {
		( new HookLoader() )->register( $this->post_type_hooks() );
	}

	/**
	 * Build the column hook descriptors for each schedulable post type.
	 *
	 * @since 0.5.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	private function post_type_hooks(): array {
		$descriptors = array();

		foreach ( $this->schedulable_post_types() as $post_type ) {
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

	/**
	 * The post types the column registers for.
	 *
	 * `scheduled_archive_post_types` is a site setting whose empty value
	 * means "every supported post type" — the opposite convention from
	 * `auto_archive_types`, where an empty list opts nothing in. See the
	 * setting's own description in {@see Schema}. Read through its
	 * `aps_scheduled_archive_post_types` filter with the schema default as
	 * the incoming value, the same pair every settings reader in this
	 * plugin resolves against.
	 *
	 * @since 0.5.0
	 * @return array<int, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-default and supported-post-types accessors.
	 */
	private function schedulable_post_types(): array {
		$configured = apply_filters(
			'aps_scheduled_archive_post_types',
			Schema::default_for( 'scheduled_archive_post_types' )
		);
		$configured = is_array( $configured ) ? $configured : array();

		return $configured ? $configured : aps_get_supported_post_types();
	}

	// -----------------------------------------------------------------------
	// Column registration
	// -----------------------------------------------------------------------

	/**
	 * Add the Scheduled column header — everywhere EXCEPT the archived
	 * filter, where every cell would be empty (an archived post cannot also
	 * have a schedule pending). This is the deliberate inversion of
	 * {@see ArchiveColumn::add_column()}'s gate.
	 *
	 * @param array<string, string> $columns Column header map (column key => label).
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor and label accessor.
	 */
	public function add_column( array $columns ): array {
		if ( in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
			return $columns;
		}

		// core's print_column_headers() echoes header values as raw HTML.
		$columns[ self::COLUMN_KEY ] = esc_html( ScheduleColumnCellRenderer::column_label() );

		return $columns;
	}

	/**
	 * Register the column as sortable — everywhere EXCEPT the archived
	 * filter. Same inverted gate as add_column().
	 *
	 * @param array<string, string> $columns Sortable column map (column key => orderby key).
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	public function register_sortable( array $columns ): array {
		if ( in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
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
	 * Mirrors {@see ArchiveColumn::prime_archive_user_cache()}, gated by the
	 * same inverted predicate as add_column(): this column's cells render on
	 * every view except the archived filter, so that is where the priming
	 * must run too. Without it, {@see ScheduleColumnCellRenderer::render()}'s
	 * Manual-source branch -- via
	 * {@see ArchiveColumnCellRenderer::resolve_archive_agent_name()} -- runs
	 * one uncached `get_userdata()` lookup per distinct scheduling user, on
	 * the plugin's highest-traffic screens (All Posts, Pages, ...), on every
	 * page load. Only Manual-sourced rows read a user at all: Rule attributes
	 * to "rule" with no lookup, and Exempt/no-record rows render no name.
	 *
	 * @since 0.5.0
	 * @param array<int, \WP_Post> $posts The posts about to be rendered.
	 * @param \WP_Query            $query The query that produced them.
	 * @return array<int, \WP_Post>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor and schedule-meta value-object factory.
	 */
	public function prime_schedule_user_cache( array $posts, \WP_Query $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}

		if ( in_array( PostStatusValue::resolved_slug(), (array) $query->get( 'post_status' ), true ) ) {
			return $posts;
		}

		$user_ids = array();
		foreach ( $posts as $post ) {
			$meta = ScheduleMeta::for_post( $post->ID );
			if ( null === $meta || ScheduleSource::Manual !== $meta->source ) {
				continue;
			}

			if ( $meta->user ) {
				$user_ids[] = $meta->user;
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
	 * Unlike {@see ArchiveColumn::render_cell()}, a null schedule is not a
	 * reason to emit nothing here: {@see ScheduleColumnCellRenderer::render()}
	 * renders an em dash for the no-record state, so every row shown on this
	 * column's (normal-view) screens gets a cell either way.
	 *
	 * @param string $column_name The current column key.
	 * @param int    $post_id     The current post ID.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object factory and cell renderer.
	 */
	public function render_cell( string $column_name, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column_name ) {
			return;
		}

		$meta = ScheduleMeta::for_post( $post_id );

		// ScheduleColumnCellRenderer::render() already escapes its return
		// value for HTML text context -- escaping it again here would
		// double-escape and break the cell output.
		echo ScheduleColumnCellRenderer::render( $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped, see comment above.
	}
}
