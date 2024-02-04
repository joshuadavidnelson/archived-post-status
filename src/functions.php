<?php
/**
 * Functions.
 *
 * @since 0.3.9
 * @package ArchivedPostStatus
 */

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

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
	return esc_attr( apply_filters( 'aps_archived_label_string', $label ) );
}

/**
 * Register a custom post status for Archived.
 *
 * @action init
 */
function aps_register_archive_post_status() {

	/**
	 * Filter the public status parameter.
	 *
	 * @since 0.4.0
	 * @param bool $public True to make the status public,
	 *                     false to make it private.
	 *                     Defaults to true if the current user can view archived content.
	 * @return bool
	 */
	$public = (bool) apply_filters( 'aps_status_arg_public', ! is_admin() && aps_current_user_can_view() );

	/**
	 * Filter the private status parameter.
	 *
	 * @since 0.4.0
	 * @param bool $private True to make the status private,
	 *                      false to make it public.
	 *                      Defaults to true if the current
	 *                      user can't view archived content.
	 * @return bool
	 */
	$private = (bool) apply_filters( 'aps_status_arg_private', ! is_admin() );

	/**
	 * Filter the protected status parameter.
	 *
	 * @since 0.4.0
	 * @param bool $protected True to make the status protected,
	 *                        defaults to false.
	 * @return bool
	 */
	$protected = (bool) apply_filters( 'aps_status_arg_protected', false );

	/**
	 * Filter the exclude from search status parameter.
	 *
	 * @since 0.4.0
	 * @param bool $exclude True to exclude archived content from search,
	 *                      false to include it.
	 *                      Defaults to true if the current
	 *                      user can't view archived content.
	 * @return bool
	 */
	$exclude_from_search = (bool) apply_filters( 'aps_status_arg_exclude_from_search', ! ( is_admin() && aps_current_user_can_view() ) );

	/**
	 * Filter the show in admin all list status parameter.
	 *
	 * @since 0.4.0
	 * @param bool $show True to show archived content in the
	 *                   admin all list, false to hide it.
	 *                   Defaults to true if the current user can view archived content.
	 * @return bool
	 */
	$show_in_admin_all_list = (bool) apply_filters( 'aps_status_arg_show_in_admin_all_list', false );

	/**
	 * Filter the show in admin status list status parameter.
	 *
	 * @since 0.4.0
	 * @param bool $show True to show archived content in the
	 *                   admin status list, false to hide it.
	 *                   Defaults to true if the current user can view archived content.
	 * @return bool
	 */
	$show_in_admin_status_list = (bool) apply_filters( 'aps_status_arg_show_in_admin_status_list', aps_current_user_can_view() );

	/**
	 * Filter the hicon used for the Archived post status.
	 *
	 * @since 0.4.0
	 * @param string $icon The dashicon name.
	 * @return string
	 */
	$icon = (string) apply_filters( 'aps_status_arg_dashicon', 'dashicons-archive' );

	// Set the args for the post status.
	$args = array(
		// translators: The post status label for Archived posts.
		'label'                     => aps_archived_label_string(),
		'post_type'                 => aps_get_supported_post_types(),
		'public'                    => $public,
		'private'                   => $private,
		'protected'                 => $protected,
		'exclude_from_search'       => $exclude_from_search,
		'show_in_admin_all_list'    => $show_in_admin_all_list,
		'show_in_admin_status_list' => $show_in_admin_status_list,
		'dashicons'                 => $icon,
		// translators: %s: Number of Archived posts.
		'label_count'               => _n_noop(
			'Archived <span class="count">(%s)</span>',
			'Archived <span class="count">(%s)</span>',
			'archived-post-status'
		),
	);

	// Regiester the post status.
	register_post_status( 'archive', $args );
}

/**
 * Check if the current user can view Archived content.
 *
 * @since 0.4.0
 * @param int $post_id Optional. The post ID to check against.
 * @return bool
 */
function aps_current_user_can_view( $post_id = 0 ) {

	/**
	 * Default capability to grant ability to view Archived content.
	 *
	 * @since 0.3.0
	 * @param string $capability The user capability to view archived content.
	 * @return string
	 */
	$capability = (string) apply_filters( 'aps_default_read_capability', 'read_private_posts', $post_id );

	return current_user_can( $capability, $post_id );
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
 * Enqueue the edit screen javascript.
 *
 * @since 0.4.0
 * @action admin_enqueue_scripts
 * @param string $hook The current admin page.
 * @return void
 */
function aps_edit_screen_js( $hook ) {

	global $typenow;
	if ( aps_is_excluded_post_type( $typenow )
		|| ! aps_is_read_only()
		|| 'edit.php' !== $hook ) {
			return;
	}

	$src = ARCHIVED_POST_STATUS_URL . 'assets/js/edit-screen.js';
	wp_enqueue_script( 'aps-edit-screen', $src, array( 'jquery' ), ARCHIVED_POST_STATUS_VERSION );

}

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

/**
 * Check that the current user can archive content.
 *
 * @since 0.4.0
 * @param int $post_id
 * @return bool
 */
function aps_current_user_can_archive( $post_id = 0 ) {

	/**
	 * Default capability to grant ability to archive content.
	 *
	 * @since 0.4.0
	 * @param string $capability The user capability to archive content.
	 * @return string
	 */
	$capability = (string) apply_filters( 'aps_default_archive_capability', 'edit_others_posts', $post_id );

	return current_user_can( $capability, $post_id );
}

/**
 * Check that the current user can unarchive content.
 *
 * @since 0.4.0
 * @param int $post_id
 * @return bool
 */
function aps_current_user_can_unarchive( $post_id = 0 ) {

	/**
	 * Default capability to grant ability to unarchive content.
	 *
	 * @since 0.4.0
	 * @param string $capability The user capability to unarchive content.
	 * @return string
	 */
	$capability = (string) apply_filters( 'aps_default_unarchive_capability', 'edit_others_posts', $post_id );

	return current_user_can( $capability, $post_id );
}
