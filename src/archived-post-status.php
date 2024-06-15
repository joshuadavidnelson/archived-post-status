<?php
/**
 * The file that defines the core plugin functions
 *
 * @link    https://github.com/joshuadavidnelson/archived-post-status
 * @since   0.3.8
 * @package ArchivedPostStatus
 * @license GPL-2.0+
 */

/**
 * Exit if accessed directly, prevent direct access to this file.
 *
 * @since 0.3.9
 */
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Load languages.
 *
 * @action plugins_loaded
 */
function aps_i18n() {
	load_plugin_textdomain( 'archived-post-status', false, ARCHIVED_POST_STATUS_LANG_PATH );
}
add_action( 'plugins_loaded', 'aps_i18n' );

/**
 * Translations strings placeholder function.
 *
 * Translation strings that are not used elsewhere but
 * Plugin Title and Description are helt here to be
 * picked up by Poedit. Keep these in sync with the
 * actual plugin's title and description.
 */
function aps_i18n_strings() {

	// translators: The plugin title.
	__( 'Archived Post Status', 'archived-post-status' );

	// translators: The plugin description.
	__( 'Allows posts and pages to be archived so you can unpublish content without having to trash it.', 'archived-post-status' );
}

/**
 * Filter the archived string.
 *
 * @since 0.3.9
 * @return string
 */
function aps_archived_label_string() {

	// translators: The label used for the status.
	$label = __( 'Archived', 'archived-post-status' );

	/**
	 * Filter the label used in the plugin for archived content
	 *
	 * @since 0.3.9
	 * @param string $label The "Archived" label.
	 * @return string
	 */
	return (string) esc_attr( apply_filters( 'aps_archived_label_string', $label ) );
}

/**
 * The slug used for the Archived post status.
 *
 * @since 0.3.9
 * @return string
 */
function aps_post_status_slug() {

	/**
	 * Filter the slug used for the Archived post status.
	 *
	 * @since 0.3.9
	 * @param string $slug The slug for the post status.
	 * @return string
	 */
	$slug = (string) apply_filters( 'aps_post_status_slug', 'archive' );

	return empty( esc_attr( $slug ) ) ? 'archive' : esc_attr( $slug );
}

/**
 * Register a custom post status for Archived.
 *
 * @action init
 */
function aps_register_archive_post_status() {

	$args = array(
		// translators: The post status label for Archived posts.
		'label'                     => aps_archived_label_string(),
		'public'                    => (bool) apply_filters( 'aps_status_arg_public', aps_current_user_can_view() ),
		'private'                   => (bool) apply_filters( 'aps_status_arg_private', true ),
		'exclude_from_search'       => (bool) apply_filters( 'aps_status_arg_exclude_from_search', ! aps_current_user_can_view() ),
		'show_in_admin_all_list'    => (bool) apply_filters( 'aps_status_arg_show_in_admin_all_list', aps_current_user_can_view() ),
		'show_in_admin_status_list' => (bool) apply_filters( 'aps_status_arg_show_in_admin_status_list', aps_current_user_can_view() ),
		// translators: The post status label count for Archived posts.
		'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'archived-post-status' ),
	);

	$slug = aps_post_status_slug();

	register_post_status( $slug, $args );
}
add_action( 'init', 'aps_register_archive_post_status' );

/**
 * Check if we are on the frontend.
 *
 * @filter aps_status_arg_exclude_from_search
 *
 * @return bool
 */
function aps_is_frontend() {
	return ! is_admin();
}
add_filter( 'aps_status_arg_exclude_from_search', 'aps_is_frontend' );

/**
 * Check if the current user can view Archived content.
 *
 * @return bool
 */
function aps_current_user_can_view() {

	/**
	 * Default capability to grant ability to view Archived content.
	 *
	 * @since 0.3.0
	 * @param string $capability The user capability to view archived content.
	 * @return string
	 */
	$capability = (string) apply_filters( 'aps_default_read_capability', 'read_private_posts' );

	return current_user_can( $capability );
}

/**
 * Check if Archived content is read-only.
 *
 * @return bool
 */
function aps_is_read_only() {

	/**
	 * Archived content is read-only by default.
	 *
	 * @since 0.3.5
	 * @param bool $is_read_only True by default.
	 * @return bool
	 */
	return (bool) apply_filters( 'aps_is_read_only', true );
}

/**
 * Filter Archived post titles on the frontend.
 *
 * @param  string $title
 * @param  int    $post_id (optional)
 *
 * @return string
 */
function aps_the_title( $title, $post_id = null ) {

	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	$post = get_post( $post_id );

	if ( ! is_admin() && isset( $post->post_status )
			&& 'archive' === $post->post_status ) {

		/**
		 * Filter the label text for archived posts.
		 *
		 * @since 0.3.9
		 * @param string $label_text The label text for archived posts.
		 * @param int    $post_id    Optionally passed, the post object.
		 * @param string $title      Optionally passed, the post title.
		 * @return string
		 */
		$label = (string) apply_filters( 'aps_title_label', aps_archived_label_string(), $post_id, $title );

		/**
		 * Change the location of the label text.
		 *
		 * @since 0.3.9
		 * @param bool $before  True to place the before the title,
		 *                      false to place it after.
		 * @param int  $post_id Optionally passed, the post object.
		 * @return bool
		 */
		$before = (bool) apply_filters( 'aps_title_label_before', true, $post_id );

		// Set the separator.
		$sep = ( true === $before ) ? ': ' : ' - ';

		/**
		 * Filter the separator used between the label and title.
		 *
		 * Defaults to a colon where before is true, and a dash where
		 * before is false. Includes spaces as needed.
		 *
		 * @since 0.3.9
		 * @param string $label_text The label text for archived posts.
		 * @param int    $post_id    Optionally passed, the post object.
		 * @return string
		 */
		$sep = (string) apply_filters( 'aps_title_separator', $sep, $post_id );

		// Add label to title.
		if ( ! empty( $label ) ) {

			// Sanitize the strings.
			$safe_strings = array_filter(
				array( $label, $sep ),
				function( $value ): bool {
					return (bool) esc_attr( $value );
				}
			);

			// Add the strings to the title.
			$title = $before ? implode( '', $safe_strings ) . $title : $title . implode( '', array_reverse( $safe_strings ) );

		}
	}

	return $title;
}
add_filter( 'the_title', 'aps_the_title', 10, 2 );

/**
 * Check if a post type should NOT be using the Archived status.
 *
 * @param  string $post_type
 *
 * @return bool
 */
function aps_is_excluded_post_type( $post_type ) {

	/**
	 * Prevent the Archived status from being used on these post types.
	 *
	 * @since 0.1.0
	 * @param array $post_types An array of strings, the slugs for post types excluded.
	 * @return array
	 */
	$excluded = (array) apply_filters( 'aps_excluded_post_types', array( 'attachment' ) );

	return in_array( $post_type, $excluded, true );
}

/**
 * Modify the DOM on post screens.
 *
 * @action admin_footer-post.php
 */
function aps_post_screen_js() {

	global $post;
	if ( aps_is_excluded_post_type( $post->post_type ) ) {
		return;
	}

	if ( 'draft' !== $post->post_status && 'pending' !== $post->post_status ) {

		?>
		<script>
		jQuery( document ).ready( function( $ ) {
			$( '#post_status' ).append( '<option value="archive"><?php esc_html_e( 'Archived', 'archived-post-status' ); ?></option>' );
		} );
		</script>
		<?php

	}

	if ( 'archive' === $post->post_status ) {

		?>
		<script>
		jQuery( document ).ready( function( $ ) {
			$( '#post-status-display' ).text( '<?php esc_html_e( 'Archived', 'archived-post-status' ); ?>' );
		} );
		</script>
		<?php

	}
}
add_action( 'admin_footer-post.php', 'aps_post_screen_js' );

/**
 * Modify the DOM on edit screens.
 *
 * @action admin_footer-edit.php
 */
function aps_edit_screen_js() {

	global $typenow;

	if ( aps_is_excluded_post_type( $typenow ) ) {
		return;
	}

	?>
	<script>
	jQuery( document ).ready( function( $ ) {
	<?php if ( aps_is_read_only() ) : ?>
		$rows = $( '#the-list tr.status-archive' );

		$.each( $rows, function() {
			disallowEditing( $( this ) );
		} );
	<?php endif; ?>

		$( 'select[name="_status"]' ).append( '<option value="archive"><?php esc_html_e( 'Archived', 'archived-post-status' ); ?></option>' );

		$( '.editinline' ).on( 'click', function() {
			var $row        = $( this ).closest( 'tr' ),
				$option     = $( '.inline-edit-row' ).find( 'select[name="_status"] option[value="archive"]' ),
				is_archived = $row.hasClass( 'status-archive' );

			$option.prop( 'selected', is_archived );
		} );

	<?php if ( aps_is_read_only() ) : ?>
		$( '.inline-edit-row' ).on( 'remove', function() {
			var id   = $( this ).prop( 'id' ).replace( 'edit-', '' ),
				$row = $( '#post-' + id );

			if ( $row.hasClass( 'status-archive' ) ) {
				disallowEditing( $row );
			}
		} );

		function disallowEditing( $row ) {
			var title = $row.find( '.column-title a.row-title' ).text();

			$row.find( '.column-title a.row-title' ).replaceWith( title );
			$row.find( '.row-actions .edit' ).remove();
		}
	<?php endif; ?>
	} );
	</script>
	<?php
}
add_action( 'admin_footer-edit.php', 'aps_edit_screen_js' );

/**
 * Prevent Archived content from being edited.
 *
 * @action load-post.php
 */
function aps_load_post_screen() {

	if ( ! aps_is_read_only() ) {
		return;
	}

	$post_id = absint( get_query_var( 'post' ) );
	$post    = get_post( $post_id );

	if ( is_null( $post )
		|| aps_is_excluded_post_type( $post->post_type )
		|| 'archive' !== $post->post_status ) {
			return;
	}

	$action  = esc_attr( get_query_var( 'action' ) );
	$message = absint( get_query_var( 'message' ) );

	// Redirect to list table after saving as Archived
	if ( 'edit' === $action && 1 === $message ) {

		wp_safe_redirect(
			add_query_arg(
				'post_type',
				$post->post_type,
				self_admin_url( 'edit.php' )
			),
			302
		);

		exit;

	}

	// translators: Error message when trying to edit an Archived post.
	wp_die(
		__( "You can't edit this item because it has been Archived. Please change the post status and try again.", 'archived-post-status' ),
		__( 'WordPress &rsaquo; Error' )
	);
}
add_action( 'load-post.php', 'aps_load_post_screen' );

/**
 * Display custom post state text next to post titles that are Archived.
 *
 * @filter display_post_states
 *
 * @param array   $post_states
 * @param WP_Post $post
 *
 * @return array
 */
function aps_display_post_states( $post_states, $post ) {

	if ( aps_is_excluded_post_type( $post->post_type )
		|| 'archive' !== $post->post_status
		|| 'archive' === get_query_var( 'post_status' ) ) {
			return $post_states;
	}

	return array_merge(
		$post_states,
		array(
			// translators: The post status label for Archived posts.
			'archive' => aps_archived_label_string(),
		)
	);
}
add_filter( 'display_post_states', 'aps_display_post_states', 10, 2 );

/**
 * Close comments and pings when content is Archived.
 *
 * @action save_post
 *
 * @param int     $post_id
 * @param WP_Post $post
 * @param bool    $update
 */
function aps_save_post( $post_id, $post, $update ) {

	// Bail out if running an autosave, ajax, cron, or revision.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Only posts that we're okay with
	if ( aps_is_excluded_post_type( $post->post_type ) ) {
			return;
	}

	// Only posts that are being Archived.
	if ( 'archive' === $post->post_status ) {

		// Unhook to prevent infinite loop
		remove_action( 'save_post', __FUNCTION__ );

		$args = array(
			'ID'             => $post->ID,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);

		wp_update_post( $args );

		// Add hook back again
		add_action( 'save_post', __FUNCTION__, 10, 3 );
	}
}
add_action( 'save_post', 'aps_save_post', 10, 3 );
