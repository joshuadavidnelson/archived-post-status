<?php
/**
 * Plugin Name: Archived Post Status
 * Description: Allows posts and pages to be archived so you can unpublish content without having to trash it.
 * Version: 0.3.7
 * Author: Frankie Jarrett
 * Author URI: https://frankiejarrett.com
 * Text Domain: archived-post-status
 * License: GPL-2.0
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright © 2016 Frankie Jarrett. All Rights Reserved.
 */

/**
 * Define plugin constants.
 */
define( 'ARCHIVED_POST_STATUS_VERSION', '0.3.7' );
define( 'ARCHIVED_POST_STATUS_PLUGIN', plugin_basename( __FILE__ ) );
define( 'ARCHIVED_POST_STATUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARCHIVED_POST_STATUS_URL', plugin_dir_url( __FILE__ ) );
define( 'ARCHIVED_POST_STATUS_LANG_PATH', dirname( ARCHIVED_POST_STATUS_PLUGIN ) . '/languages' );

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

	__( 'Archived Post Status', 'archived-post-status' );
	__( 'Allows posts and pages to be archived so you can unpublish content without having to trash it.', 'archived-post-status' );

}

/**
 * Register a custom post status for Archived.
 *
 * @action init
 */
function aps_register_archive_post_status() {

	$args = array(
		'label'                     => __( 'Archived', 'archived-post-status' ),
		'public'                    => (bool) apply_filters( 'aps_status_arg_public', aps_current_user_can_view() ),
		'private'                   => (bool) apply_filters( 'aps_status_arg_private', true ),
		'exclude_from_search'       => (bool) apply_filters( 'aps_status_arg_exclude_from_search', ! aps_current_user_can_view() ),
		'show_in_admin_all_list'    => (bool) apply_filters( 'aps_status_arg_show_in_admin_all_list', aps_current_user_can_view() ),
		'show_in_admin_status_list' => (bool) apply_filters( 'aps_status_arg_show_in_admin_status_list', aps_current_user_can_view() ),
		'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'archived-post-status' ),
	);

	register_post_status( 'archive', $args );

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
	 *
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
	 *
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

	$post = get_post( $post_id );

	if (
		! is_admin()
		&&
		isset( $post->post_status )
		&&
		'archive' === $post->post_status
	) {

		$title = sprintf( '%s: %s', __( 'Archived', 'archived-post-status' ), $title );

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
	 *
	 * @return array
	 */
	$excluded = (array) apply_filters( 'aps_excluded_post_types', array( 'attachment' ) );

	return in_array( $post_type, $excluded );

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
			$( '#post_status' ).append( '<option value="archive"><?php esc_html_e( 'Archived', 'archived-post-status' ) ?></option>' );
		} );
		</script>
		<?php

	}

	if ( 'archive' === $post->post_status ) {

		?>
		<script>
		jQuery( document ).ready( function( $ ) {
			$( '#post-status-display' ).text( '<?php esc_html_e( 'Archived', 'archived-post-status' ) ?>' );
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

		$( 'select[name="_status"]' ).append( '<option value="archive"><?php esc_html_e( 'Archived', 'archived-post-status' ) ?></option>' );

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

	$post_id = (int) filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT );
	$post    = get_post( $post_id );

	if (
		is_null( $post )
		||
		aps_is_excluded_post_type( $post->post_type )
		||
		'archive' !== $post->post_status
	) {

		return;

	}

	$action  = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_STRING );
	$message = (int) filter_input( INPUT_GET, 'message', FILTER_SANITIZE_NUMBER_INT );

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

	wp_die(
		__( "You can't edit this item because it has been Archived. Please change the post status and try again.", 'archived-post-status' ),
		translate( 'WordPress &rsaquo; Error' )
	);

}
add_action( 'load-post.php', 'aps_load_post_screen' );

/**
 * Display custom post state text next to post titles that are Archived.
 *
 * @filter display_post_states
 *
 * @param  array   $post_states
 * @param  WP_Post $post
 *
 * @return array
 */
function aps_display_post_states( $post_states, $post ) {

	if (
		aps_is_excluded_post_type( $post->post_type )
		||
		'archive' !== $post->post_status
		||
		'archive' === get_query_var( 'post_status' )
	) {

		return $post_states;

	}

	return array_merge(
		$post_states,
		array(
			'archive' => __( 'Archived', 'archived-post-status' ),
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

	if (
		aps_is_excluded_post_type( $post->post_type )
		||
		wp_is_post_revision( $post )
	) {

		return;

	}

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
