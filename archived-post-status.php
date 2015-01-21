<?php
/**
 * Plugin Name: Archived Post Status
 * Description: Allows posts and pages to be archived so you can unpublish content without having to trash it.
 * Version: 0.2.0
 * Author: Frankie Jarrett
 * Author URI: http://frankiejarrett.com
 * License: GPLv2+
 * Text Domain: archived-post-status
 */

/**
 * Register a custom post status for Archived
 *
 * @action init
 *
 * @return void
 */
function aps_register_archive_post_status() {
	$args = array(
		'label'                     => __( 'Archived', 'archived-post-status' ),
		'public'                    => apply_filters( 'aps_status_arg_public', false ),
		'exclude_from_search'       => apply_filters( 'aps_status_arg_exclude_from_search', true ),
		'show_in_admin_all_list'    => apply_filters( 'aps_status_arg_show_in_admin_all_list', true ),
		'show_in_admin_status_list' => apply_filters( 'aps_status_arg_show_in_admin_status_list', true ),
		'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'archived-post-status' ),
	);

	register_post_status( 'archive', $args );
}
add_action( 'init', 'aps_register_archive_post_status' );

/**
 * Returns TRUE if in the WP Admin, otherwise FALSE
 *
 * @filter aps_status_arg_public
 * @filter aps_status_arg_show_in_admin_all_list
 * @filter aps_status_arg_show_in_admin_status_list
 *
 * @return bool
 */
function aps_is_admin() {
	return is_admin();
}
add_filter( 'aps_status_arg_public', 'aps_is_admin' );
add_filter( 'aps_status_arg_show_in_admin_all_list', 'aps_is_admin' );
add_filter( 'aps_status_arg_show_in_admin_status_list', 'aps_is_admin' );

/**
 * Returns TRUE if on the frontend, otherwise FALSE
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
 * Modify the DOM on post screens
 *
 * @action admin_footer-post.php
 *
 * @return void
 */
function aps_post_screen_js() {
	global $post;

	$excluded = apply_filters( 'aps_excluded_post_types', array( 'attachment' ) );

	if ( in_array( $post->post_type, $excluded ) ) {
		return;
	}

	if ( 'draft' !== $post->post_status && 'pending' !== $post->post_status ) {
		?>
		<script>
		jQuery( document ).ready( function( $ ) {
			$( '#post_status' ).append( '<option value="archive"><?php esc_html_e( 'Archived', 'archived-post-status' ) ?></option>' );
		});
		</script>
		<?php
	}
}
add_action( 'admin_footer-post.php', 'aps_post_screen_js' );

/**
 * Modify the DOM on edit screens
 *
 * @action admin_footer-edit.php
 *
 * @return void
 */
function aps_edit_screen_js() {
	?>
	<script>
	jQuery( document ).ready( function( $ ) {
		$rows = $( '#the-list tr.status-archive' );

		$.each( $rows, function() {
			disallowEditing( $( this ) );
		});

		$( 'select[name="_status"]' ).append( '<option value="archive"><?php esc_html_e( 'Archived', 'archived-post-status' ) ?></option>' );

		$( '.editinline' ).on( 'click', function() {
			var $row        = $( this ).closest( 'tr' ),
			    $option     = $( '.inline-edit-row' ).find( 'select[name="_status"] option[value="archive"]' ),
			    is_archived = $row.hasClass( 'status-archive' );

			$option.prop( 'selected', is_archived );
		});

		$( '.inline-edit-row' ).on( 'remove', function() {
			var id   = $( this ).prop( 'id' ).replace( 'edit-', '' ),
			    $row = $( '#post-' + id );

			if ( $row.hasClass( 'status-archive' ) ) {
				disallowEditing( $row );
			}
		});

		function disallowEditing( $row ) {
			var title = $row.find( '.column-title a.row-title' ).text();

			$row.find( '.column-title a.row-title' ).remove();
			$row.find( '.column-title strong' ).prepend( title );
			$row.find( '.row-actions .edit' ).remove();
			$row.find( '.row-actions .view' ).remove();
			$row.find( '.row-actions .trash' ).contents().filter( function() {
				return this.nodeType === Node.TEXT_NODE;
			}).remove();
		}
	});
	</script>
	<?php
}
add_action( 'admin_footer-edit.php', 'aps_edit_screen_js' );

/**
 * Prevent archived content from being edited
 *
 * @action load-post.php
 *
 * @return void
 */
function aps_load_post_screen() {
	$action  = isset( $_GET['action'] ) ? $_GET['action'] : null;
	$message = isset( $_GET['message'] ) ? absint( $_GET['message'] ) : null;
	$post_id = isset( $_GET['post'] ) ? $_GET['post'] : null;
	$post    = get_post( $post_id );

	if (
		! isset( $post->post_status )
		||
		'archive' !== $post->post_status
	) {
		return;
	}

	// Redirect to list table after saving as Archived
	if ( 'edit' === $action && 1 === $message ) {
		wp_safe_redirect(
			add_query_arg(
				array( 'post_type' => $post->post_type ),
				admin_url( 'edit.php' )
			),
			302
		);

		exit;
	}

	wp_die(
		__( "You can't edit this item because it has been Archived. Please change the post status and try again.", 'archived-post-status' ),
		__( 'WordPress &rsaquo; Error' )
	);
}
add_action( 'load-post.php', 'aps_load_post_screen' );

/**
 * Display custom post state text next to post titles that are Archived
 *
 * @filter display_post_states
 *
 * @param array  $post_states  An array of post display states
 * @param object $post         WP_Post
 *
 * @return array
 */
function aps_display_post_states( $post_states, $post ) {
	if (
		'archive' !== $post->post_status
		||
		'archive' === get_query_var( 'post_status' )
	) {
		return $post_states;
	}

	return array( __( 'Archived', 'archived-post-status' ) );
}
add_filter( 'display_post_states', 'aps_display_post_states', 10, 2 );
