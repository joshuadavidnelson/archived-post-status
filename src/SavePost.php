<?php
/**
 * The save post flow.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * The save post flow.
 *
 * @since 0.4.0
 */
class SavePost extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'save_post';

	/**
	 * Register the feature.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register() {

		// Close ping and comment status on archived posts.
		add_action( 'save_post', array( $this, 'save_post' ), 10, 3 );

	}

	/**
	 * Close comments and pings when content is Archived.
	 *
	 * @since 0.4.0
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param bool    $update
	 */
	public function save_post( $post_id, $post, $update ) {

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
		if ( ! aps_is_supported_post_type( $post->post_type ) ) {
			return;
		}

		// Only posts that are being Archived.
		if ( 'archive' === $post->post_status ) {

			// Unhook to prevent infinite loop
			remove_action( 'save_post', array( $this, 'save_post' ) );

			$args = array(
				'ID'             => $post->ID,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			);

			wp_update_post( $args );

			// Add hook back again
			add_action( 'save_post', array( $this, 'save_post' ), 10, 3 );
		}
	}
}
