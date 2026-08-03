<?php
/**
 * Label added to the post title.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\Frontend;

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Status\PostStatusValue;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Filters post titles to add archived label.
 *
 * @since 0.4.0
 */
final class ArchiveTitle implements HookableInterface {

	/**
	 * Return an array of HookDescriptor objects.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::filter() is a
	 * named-constructor factory for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::filter( 'the_title', array( $this, 'filter_title' ), 10, 2 ),
		);
	}

	/**
	 * Filter Archived post titles on the frontend.
	 *
	 * @param  string $title
	 * @param  int    $post_id (optional)
	 *
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor.
	 */
	public function filter_title( $title, $post_id = null ) {

		// Cheapest possible bail-out first — this runs on EVERY the_title()
		// call on a page, and is_admin() is a resolved boolean flag with no
		// DB/cache lookup at all, unlike resolving $post_id/$post below. Only
		// the frontend ever needs the label, so admin requests never even
		// reach the post lookup.
		if ( is_admin() ) {
			return $title;
		}

		// Get the post id.
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		// Get the post object.
		$post = get_post( $post_id );

		// Only filter archived posts.
		if ( ! isset( $post->post_status ) || PostStatusValue::resolved_slug() !== $post->post_status ) {
			return $title;
		}

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

			// Sanitize the strings. esc_html is correct here: the label and
			// separator are concatenated into $title which is itself returned
			// to a text-context `the_title` filter consumer (theme output uses
			// the_title()/get_the_title()). esc_attr would over-escape ampersands,
			// quotes, etc. that should render as literal characters in body text.
			$safe_strings = array_filter( array_map( 'esc_html', array( $label, $sep ) ) );

			// Add the strings to the title.
			$title = $before ? implode( '', $safe_strings ) . $title : $title . implode( '', array_reverse( $safe_strings ) );
		}

		return $title;
	}
}
