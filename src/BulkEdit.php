<?php
/**
 * Bulk Edit functions.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Bulk Edit functions.
 *
 * @since 0.4.0
 */
class BulkEdit extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'bulk_edit';

	/**
	 * Register the feature.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register() {

		// Add the bulk actions.
		$post_types = aps_get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			$screen = 'edit-' . $post_type;
			add_filter( "bulk_actions-{$screen}", array( $this, 'bulk_actions' ) );
			add_filter( "handle_bulk_actions-{$screen}", array( $this, 'handle_bulk_action' ), 10, 3 );

		}

		// Add the admin notices.
		add_action( 'admin_notices',  array( $this, 'admin_notices' ) );

	}

	/**
	 * Add the custom bulk actions.
	 *
	 * @since 0.4.0
	 * @param array $actions Array of the available bulk actions.
	 *                       Each action is an associative array of
	 *                       'slug' => 'Visible Title'
	 * @return array
	 */
	public function bulk_actions( $actions ) {

		// If it's the "All" view or "Published" post type filter,
		// then show the "Archive" bulk action
		if ( ! isset( $_REQUEST['post_status'] )
			|| ( isset( $_REQUEST['post_status'] )
				&& 'publish' === $_REQUEST['post_status'] ) ) {
					$actions['archive'] = __( 'Archive', 'archived-post-status' );
		}

		// If it's the "Archived" post type filter,
		// then show the "Unarchive" bulk action
		if ( isset( $_REQUEST['post_status'] ) && 'archive' === $_REQUEST['post_status'] ) {
			$actions['unarchive'] = __( 'Unarchive', 'archived-post-status' );
		}

		return $actions;

	}

	/**
	 * Display the admin notices.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	function admin_notices() {

		// check that we're on the edit screen
		if ( get_current_screen()->base !== 'edit' ) {
			return;
		}

		/**
		 * @global string $post_type
		 */
		global $post_type;
		if ( ! $post_type ) {
			return;
		}

		$messages = array();
		if ( isset( $_REQUEST['archived'] ) ) {

			$messages[] = sprintf(
				_n(
					'%s post moved to the Archive.',
					'%s posts moved to the Archive.',
					absint( $_REQUEST['archived'] ),
					'archived-post-status'
				),
				number_format_i18n( absint( $_REQUEST['archived'] ) )
			);
		}

		if ( isset( $_REQUEST['archived'] ) && isset( $_REQUEST['ids'] ) ) {

			$ids = preg_replace( '/[^0-9,]/', '', $_REQUEST['ids'] );

			$messages[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( wp_nonce_url( "edit.php?post_type=$post_type&doaction=undo&action=unarchive&ids=$ids", 'bulk-posts' ) ),
				__( 'Undo' )
			);

		}

		// Unarchive messages.
		if ( isset( $_REQUEST['unarchived'] ) ) {

			$messages[] = sprintf(
				_n(
					'%s post restored from the Archive.',
					'%s posts restored from the Archive.',
					absint( $_REQUEST['unarchived'] ),
					'archived-post-status'
				),
				number_format_i18n( absint( $_REQUEST['unarchived'] ) )
			);
		}

		if ( isset( $_REQUEST['unarchived'] ) && isset( $_REQUEST['ids'] ) ) {

			$ids = preg_replace( '/[^0-9,]/', '', $_REQUEST['ids'] );

			$messages[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( wp_nonce_url( "edit.php?post_type=$post_type&doaction=undo&action=archive&ids=$ids", 'bulk-posts' ) ),
				__( 'Undo' )
			);

			$ids = explode( ',', $_REQUEST['ids'] );
			if ( 1 === count( $ids ) && current_user_can( 'edit_post', $ids[0] ) ) {
				$messages[] = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( get_edit_post_link( $ids[0] ) ),
					esc_html( get_post_type_object( get_post_type( $ids[0] ) )->labels->edit_item )
				);
			}

		}

		// Do the messages.
		if ( $messages ) {
			$args = array(
				'id'                 => 'message',
				'additional_classes' => array( 'updated' ),
				'dismissible'        => true,
			);

			wp_admin_notice( implode( ' ', $messages ), $args );
		}
	}

	/**
	 * Handle the bulk actions.
	 *
	 * The redirect link should be modified with success or failure feedback
	 * from the action to be used to display feedback to the user.
	 *
	 * @since 0.4.0
	 * @param string $sendback The redirect URL.
	 * @param string $doaction The action being taken.
	 * @param array  $items    The items to take the action on.
	 *                         Array of post IDs.
	 * @return string
	 */
	function handle_bulk_action( $sendback, $doaction, $post_ids ) {

		// If there are no post IDs, bail.
		// If the action is not 'archive' or 'unarchive', bail.
		if ( empty( $post_ids )
			|| ! in_array( $doaction, [ 'archive', 'unarchive' ] ) ) {
				return $sendback;
		}

		$sendback = remove_query_arg( array( 'archived', 'unarchived', 'ids' ), $sendback );

		switch ( $doaction ) {
			case 'archive':
				$archived = 0;
				$locked  = 0;

				foreach ( (array) $post_ids as $post_id ) {
					if ( ! current_user_can( 'delete_post', $post_id ) ) {
						wp_die( __( 'Sorry, you are not allowed to move this item to the Archive.' ) );
					}

					if ( wp_check_post_lock( $post_id ) ) {
						++$locked;
						continue;
					}

					if ( ! aps_archive_post( $post_id ) ) {
						wp_die( __( 'Error in moving the item to Archive.' ) );
					}

					++$archived;
				}

				$sendback = add_query_arg(
					array(
						'archived' => $archived,
						'ids'      => implode( ',', $post_ids ),
						'locked'   => $locked,
					),
					$sendback
				);
				break;

			case 'unarchive':
				$unarchived = 0;

				if ( isset( $_GET['doaction'] ) && ( 'undo' === $_GET['doaction'] ) ) {
					add_filter( 'aps_unarchive_post_status', 'aps_unarchive_post_set_previous_status', 10, 3 );
				}

				foreach ( (array) $post_ids as $post_id ) {
					if ( ! aps_current_user_can_unarchive( $post_id ) ) {
						wp_die( __( 'Sorry, you are not allowed to restore this item from the Archive.', 'archived-post-status' ) );
					}

					if ( ! aps_unarchive_post( $post_id ) ) {
						wp_die( __( 'Error in restoring the item from Archive.', 'archived-post-status' ) );
					}

					++$unarchived;
				}
				$sendback = add_query_arg( 'unarchived', $unarchived, $sendback );

				remove_filter( 'aps_unarchive_post_status', 'aps_unarchive_post_set_previous_status', 10 );

				break;
		}

		return $sendback;

	}
}