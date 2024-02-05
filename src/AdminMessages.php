<?php
/**
 * Admin Messages.
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
 * Admin Messages.
 *
 * @since 0.4.0
 */
class AdminMessages extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'admin_messages';

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

		/**
		 * @global string $post_type
		 */
		global $post_type;
		if ( ! $post_type ) {
			return;
		}

		$messages = array();

		// Archived Messages
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
}
