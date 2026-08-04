<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Registers the 'archive' post status and its admin display behavior.
 *
 * @since 0.4.0
 */
final class PostStatus implements HookableInterface {

	/**
	 * Return an array of HookDescriptor objects.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'init', array( $this, 'register_status' ) ),
			HookDescriptor::filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 ),
		);
	}

	/**
	 * Register a custom post status for Archived.
	 *
	 * @since 0.4.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	public function register_status(): void {
		register_post_status( PostStatusValue::resolved_slug(), $this->status_args() );
	}

	/**
	 * Build the args array for register_post_status().
	 *
	 * `label` is passed through unescaped: core escapes status labels at its
	 * own output sites, so escaping here would double-escape.
	 *
	 * @since 0.4.0
	 * @return array<string, mixed> Args ready for register_post_status().
	 */
	private function status_args(): array {
		/**
		 * Filter the `public` register_post_status() arg for the archived status.
		 *
		 * `public` and `private` are independent core flags, not opposites — a
		 * status may legitimately be both false, or both true. Here the
		 * `is_admin()` term keeps the status from claiming front-end-public
		 * registration in admin, where `public` is not what gates visibility.
		 *
		 * @since 0.1.0 Defaulted to `false`.
		 * @since 0.3.0 Defaulted to `aps_current_user_can_view()`.
		 * @since 0.4.0 Changed default to `! is_admin() && aps_current_user_can_view()`
		 * @param bool $public Whether posts of this status are shown on the
		 *                     front end. Default `! is_admin() && aps_current_user_can_view()`.
		 * @return bool
		 */
		$public = (bool) apply_filters( 'aps_status_arg_public', ! is_admin() && aps_current_user_can_view() );

		/**
		 * Filter the `private` register_post_status() arg for the archived status.
		 *
		 * Private on the front end (gated by the view capability), false in
		 * admin so the "All" list exclusion and the "Archived" status filter
		 * both work. Pre-0.4.0 this was hardcoded `true`, which broke both.
		 *
		 * @since 0.3.0 Defaulted to `true`.
		 * @since 0.4.0 Changed default to `! is_admin()`.
		 * @param bool $private Whether posts of this status are treated as
		 *                      private. Default `! is_admin()`.
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
		 * Archived posts stay out of the admin "All" list by default (as
		 * core does with trash); the "Archived" filter browses them.
		 *
		 * @since 0.3.0 Defaulted to `aps_current_user_can_view()`.
		 * @since 0.4.0 Changed default to `false`.
		 * @param bool $show True to show archived content in the
		 *                   admin all list, false to hide it. Default false.
		 * @return bool
		 */
		$show_in_all_list = (bool) apply_filters( 'aps_status_arg_show_in_admin_all_list', false );

		/**
		 * Filter the show in admin status list status parameter.
		 *
		 * @since 0.4.0
		 * @param bool $show True to show archived content in the
		 *                   admin status list, false to hide it.
		 *                   Defaults to true if the current user can view archived content.
		 * @return bool
		 */
		$show_in_status_list = (bool) apply_filters( 'aps_status_arg_show_in_admin_status_list', aps_current_user_can_view() );

		return array(
			'label'                     => aps_archived_label_string(),
			// Not a real register_post_status() arg — core ignores it. Kept so
			// third parties can introspect the supported-type mapping via
			// get_post_status_object(). Do not remove.
			'post_type'                 => aps_get_supported_post_types(),
			'public'                    => $public,
			'private'                   => $private,
			'protected'                 => $protected,
			'exclude_from_search'       => $exclude_from_search,
			'show_in_admin_all_list'    => $show_in_all_list,
			'show_in_admin_status_list' => $show_in_status_list,
			/* translators: %s: post count */
			'label_count'               => _n_noop(
				'Archived <span class="count">(%s)</span>',
				'Archived <span class="count">(%s)</span>',
				'archived-post-status'
			),
		);
	}

	/**
	 * Display custom post state text next to post titles that are Archived.
	 *
	 * @since 0.4.0
	 * @param array<string, string> $post_states The current post states (state key => label).
	 * @param \WP_Post              $post        The post object.
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical filterable slug accessor.
	 */
	public function display_post_states( array $post_states, \WP_Post $post ): array {
		$slug = PostStatusValue::resolved_slug();

		if ( ! aps_is_supported_post_type( $post->post_type )
			|| $slug !== $post->post_status
			|| in_array( $slug, (array) get_query_var( 'post_status' ), true ) ) {
				return $post_states;
		}

		return array_merge(
			$post_states,
			array(
				// core's _post_states() concatenates states into raw HTML
				// with no escaping of its own.
				$slug => esc_html( aps_archived_label_string() ),
			)
		);
	}
}
