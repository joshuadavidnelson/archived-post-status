<?php
/**
 * Label added to the post title.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Post editor functions.
 *
 * @since 0.4.0
 */
class ArchivedTitle extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'archived_title';

	/**
	 * Register the feature.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register() {

		// Add the label to the title.
		add_filter( 'the_title', array( $this, 'filter_title' ), 10, 2 );

	}

	/**
	 * Filter Archived post titles on the frontend.
	 *
	 * @param  string $title
	 * @param  int    $post_id (optional)
	 *
	 * @return string
	 */
	function filter_title( $title, $post_id = null ) {

		// Get the post id.
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		// Get the post object.
		$post = get_post( $post_id );

		// Only filter on the frontend, for archived posts.
		if ( ! is_admin() && isset( $post->post_status )
			&& 'archive' === $post->post_status ) {

			/**
			 * Filter the label / title separator.
			 *
			 * Defaults to a colon.
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
			 * @param string $sep     The separator string.
			 * @param int    $post_id Optionally passed, the post object.
			 * @return string
			 */
			$sep = (string) apply_filters( 'aps_title_separator', $sep, $post_id );

			// Add label to title.
			if ( ! empty( $label ) ) {

				// Sanitize the strings.
				$safe_strings = array_filter( array( $label, $sep ), 'esc_attr' );

				// Add the strings to the title.
				$title = $before ? implode( '', $safe_strings ) . $title : $title . implode( '', array_reverse( $safe_strings ) );
			}
		}

		return $title;
	}

}

