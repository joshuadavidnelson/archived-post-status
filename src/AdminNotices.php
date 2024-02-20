<?php
/**
 * Admin Notices.
 *
 * These appear when a user performs a bulk or row action.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Admin Notices.
 *
 * @since 0.4.0
 */
class AdminNotices extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'admin_notices';

	/**
	 * Register the feature.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register() {

		// Add the admin notices.
		add_action( 'admin_notices',  array( $this, 'admin_notices' ) );

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

		// get the post type.
		global $post_type;
		if ( ! $post_type ) {
			$post_type = get_post_type();

			if ( ! $post_type ) {
				return;
			}
		}

		$notices = array();

		$archived   = get_query_var( 'archived', false );
		$unarchived = get_query_var( 'unarchived', false );
		$ids        = get_query_var( 'ids', false );

		// Archived Notices
		if ( $archived ) {

			$notices[] = sprintf(
				_n(
					'%s post moved to the Archive.',
					'%s posts moved to the Archive.',
					absint( $archived ),
					'archived-post-status'
				),
				number_format_i18n( absint( $archived ) )
			);

			if ( $ids ) {

				$ids = preg_replace( '/[^0-9,]/', '', $ids );

				$notices[] = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( wp_nonce_url( "edit.php?post_type=$post_type&doaction=undo&action=unarchive&ids=$ids", 'bulk-posts' ) ),
					__( 'Undo' )
				);
			}
		}

		// Unarchive notices.
		if ( $unarchived ) {

			$notices[] = sprintf(
				_n(
					'%s post restored from the Archive.',
					'%s posts restored from the Archive.',
					absint( $unarchived ),
					'archived-post-status'
				),
				number_format_i18n( absint( $unarchived ) )
			);

			if ( $ids ) {

				$ids = preg_replace( '/[^0-9,]/', '', $ids );
				$ids = explode( ',', $ids );

				if ( 1 === count( $ids ) && aps_current_user_can_unarchive( $ids[0] ) ) {
					$notices[] = sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( get_edit_post_link( $ids[0] ) ),
						esc_html( get_post_type_object( get_post_type( $ids[0] ) )->labels->edit_item )
					);
				}
			}
		}

		// Do the notices.
		if ( $notices ) {
			$args = array(
				'id'                 => 'message',
				'additional_classes' => array( 'updated' ),
				'dismissible'        => true,
			);

			wp_admin_notice( implode( ' ', $notices ), $args );
		}
	}
}
