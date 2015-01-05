<?php
/**
 * Plugin Name: Archived Post Status
 * Description: Allows posts and pages to be archived so you can unpublish content without having to trash them.
 * Version: 0.1.0
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

	if ( 'archive' === $post->post_status ) {
		?>
		<script>
		jQuery( document ).ready( function( $ ) {
			$( '#minor-publishing-actions' ).hide();
			$( '#preview-action' ).remove();
			$( '.save-post-status, .cancel-post-status' ).on( 'click', function() {
				if ( 'archive' === $( '#post_status' ).val() ) {
					$( '#minor-publishing-actions' ).hide();
				} else {
					$( '#minor-publishing-actions' ).show();
				}
			});
			$( '#post-status-display' ).html( '<?php esc_html_e( 'Archived', 'archived-post-status' ) ?>' );
			$( '#post_status' ).append( '<option value="archive" selected="selected"><?php esc_html_e( 'Archived', 'archived-post-status' ) ?></option>' );
		});
		</script>
		<?php
	} elseif ( 'draft' !== $post->post_status && 'pending' !== $post->post_status ) {
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
		$( 'select[name="_status"]' ).append( '<option value="archive"><?php esc_html_e( 'Archived', 'archived-post-status' ) ?></option>' );

		$( '.editinline' ).on( 'click', function() {
			var $row    = $( this ).closest( 'tr' ),
			    $option = $( '.inline-edit-row' ).find( 'select[name="_status"] option[value="archive"]' );

			if ( $row.hasClass( 'status-archive' ) ) {
				$option.prop( 'selected', true );
			} else {
				$option.prop( 'selected', false );
			}
		});
	});
	</script>
	<?php
}
add_action( 'admin_footer-edit.php', 'aps_edit_screen_js' );

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
