<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveMeta;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Hooks\HookLoader;
use ArchivedPostStatus\Status\PostStatusValue;

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
	public const COLUMN_KEY = 'aps_archived';

	/**
	 * @since 0.4.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor and label accessor.
	 */
	public function add_column( array $columns ): array {
		if ( ! in_array( PostStatusValue::resolved_slug(), (array) get_query_var( 'post_status' ), true ) ) {
			return $columns;
		}

		// Replaces core's Date column: its "Published"/"Last Modified" labels
		// are misleading for archived rows.
		unset( $columns['date'] );

		// core's print_column_headers() echoes header values as raw HTML.
		$columns[ self::COLUMN_KEY ] = esc_html( ArchiveColumnCellRenderer::column_label() );

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
	 * meta, so without this {@see ArchiveColumnCellRenderer::resolve_archive_agent_name()}'s
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object factory and cell renderer.
	 */
	public function render_cell( string $column_name, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column_name ) {
			return;
		}

		$meta = ArchiveMeta::for_post( $post_id );
		if ( null === $meta ) {
			return;
		}

		// ArchiveColumnCellRenderer::render() already escapes its return value
		// for HTML text context (esc_html() around both the format string and
		// each substituted value) -- escaping it again here would double-escape
		// and break the cell output.
		echo ArchiveColumnCellRenderer::render( $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped, see comment above.
	}

}
