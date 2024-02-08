<?php
/**
 * Inline un/archive button and related post actions.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * The inline archive buttons class.
 *
 * @since 0.4.0
 */
class RowActions extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'row_actions';

	/**
	 * Register the feature.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register() {

		// add the actions to the inline post actions.

		$post_types = aps_get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			add_filter( $post_type . '_row_actions', array( $this, 'row_actions' ), 10, 2 );
		}

		// The archive post action.
		add_action( 'post_action_archive', array( $this,'post_action_archive' ) );

		// The unarchive post action.
		add_action( 'post_action_unarchive', array( $this, 'post_action_unarchive' ) );

	}

	/**
	 * Add an Unarchive & Archive link to the post row actions.
	 *
	 * @since 0.4.0
	 * @filter post_row_actions
	 * @param  array   $actions
	 * @param  WP_Post $post
	 * @return array
	 */
	function row_actions( $actions, $post ) {

		if ( ! aps_is_supported_post_type( $post->post_type ) ) {
			return $actions;
		}

		if ( in_array( $post->post_status, _aps_get_archivable_statuses(), true )
			&& aps_current_user_can_archive( $post->ID ) ) {

			$actions['archive']  = '<a href="' . aps_get_archive_post_link( $post->ID ).'" title="' . esc_attr( __( 'Archive this post' , 'archived-post-status') ) . '">' . __( 'Archive', 'archived-post-status' ) . '</a>';

		} elseif ( $post->post_status == 'archive'
			&& aps_current_user_can_unarchive( $post->ID ) ) {

			// Remove actions that don't apply to Archived posts.
			$removed_actions = array(
				'inline hide-if-no-js',
				'edit',
			);

			if ( ! aps_current_user_can_view( $post->ID ) ) {
				$removed_actions[] = 'view';
			}

			foreach ( $removed_actions as $action ) {
				if ( isset( $actions[ $action ] ) ) {
					unset( $actions[ $action ] );
				}
			}

			$actions['unarchive']  = '<a href="' . aps_get_unarchive_post_link( $post->ID ).'" title="' . esc_attr( __( 'Unarchive this post' , 'archived-post-status' ) ) . '">' . __( 'Unarchive', 'archived-post-status' ) . '</a>';

		}

		return $actions;
	}

	/**
	 * Do the 'archive' post action.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/post_action_action/
	 *
	 * @since 0.4.0
	 * @param int    $post_id The post ID.
	 * @param string $action  Optional. The action. Default is 'archive'.
	 * @return void
	 */
	function post_action_archive( $post_id, $action = 'archive' ) {

		check_admin_referer( _aps_nonce_key( $action, $post_id ) );

		if ( ! aps_current_user_can_archive( $post_id ) ) {
			return;
		}

		/**
		 * @global string  $post_type
		 * @global object  $post_type_object
		 * @global WP_Post $post             Global post object.
		 */
		global $post_type, $post_type_object, $post;

		if ( $post_id ) {
			$post = get_post( $post_id );
		}

		if ( $post ) {
			$post_type        = $post->post_type;
			$post_type_object = get_post_type_object( $post_type );
		}

		$sendback = wp_get_referer();
		if ( ! $sendback ||
			str_contains( $sendback, 'post.php' ) ||
			str_contains( $sendback, 'post-new.php' ) ) {
				$sendback = admin_url( 'edit.php' );
				if ( ! empty( $post_type ) ) {
					$sendback = add_query_arg( 'post_type', $post_type, $sendback );
				}
		} else {
			$sendback = remove_query_arg( array( 'archived', 'unarchived', 'ids' ), $sendback );
		}

		if ( ! $post ) {
			wp_die( __( 'The item you are trying archive no longer exists.' ) );
		}

		if ( ! $post_type_object ) {
			wp_die( __( 'Invalid post type.' ) );
		}

		if ( ! aps_current_user_can_archive( $post_id ) ) {
			wp_die( __( 'Sorry, you are not allowed to archive this item.' ) );
		}

		$user_id = wp_check_post_lock( $post_id );
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			/* translators: %s: User's display name. */
			wp_die( sprintf( __( 'You cannot archive this item. %s is currently editing.' ), $user->display_name ) );
		}

		// Do the thing.
		$function = "aps_{$action}_post";
		if ( ! call_user_func( $function, $post_id ) ) {
			wp_die( __( 'Error in archiving this item.' ) );
		}

		// Past tense the action for the query args.
		$actioned = $action . 'd';

		wp_redirect(
			add_query_arg(
				array(
					$actioned => 1,
					'ids'     => $post_id,
				),
				$sendback
			)
		);
		exit;

	}

	/**
	 * Do the 'unarchive' post action.
	 *
	 * @since 0.4.0
	 * @param int $post_id The post ID.
	 * @return void
	 */
	function post_action_unarchive( $post_id ) {
		$this->post_action_archive( $post_id, 'unarchive' );
	}

}
